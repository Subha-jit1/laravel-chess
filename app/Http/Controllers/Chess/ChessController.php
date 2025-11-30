<?php

namespace App\Http\Controllers\Chess;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\PlayerStat;
use App\Models\GameResult;
use App\Services\ChessService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;
use Exception;
use Inertia\Inertia;

class ChessController extends Controller
{
    protected ChessService $chessService;

    public function __construct(ChessService $chessService)
    {
        $this->chessService = $chessService;
    }

    public function index(Request $request)
    {
        return Inertia::render('chess\Index');
    }

    /**
     * Create a new game
     */
    public function create(Request $request): JsonResponse
    {
        $payload = $request->only(['white_player_id', 'black_player_id', 'fen']);

        $validator = Validator::make($payload, [
            'white_player_id' => ['nullable', 'integer', 'exists:users,id'],
            'black_player_id' => ['nullable', 'integer', 'exists:users,id'],
            'fen' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError(
                'Validation failed.',
                $validator->errors()->toArray()
            );
        }

        DB::beginTransaction();
        try {
            $game = $this->chessService->createGame(
                $payload['white_player_id'] ?? null,
                $payload['black_player_id'] ?? null
            );

            DB::commit();

            return $this->sendResponse(
                true,
                'Game created.',
                ['game' => $game],
                201
            );
        } catch (Throwable $e) {
            DB::rollBack();
            return $this->sendError(
                'Failed to create game.',
                $e,
            );
        }
    }

    /**
     * Show game
     */
    public function show($id): JsonResponse
    {
        try {
            $game = Game::with(['whitePlayer', 'blackPlayer'])->findOrFail($id);

            return $this->sendResponse(
                true,
                'Game fetched.',
                ['game' => $game],
                200
            );
        } catch (Throwable $e) {
            return $this->sendError(
                'Game not found.',
                $e,
                404,
            );
        }
    }

    /**
     * Make a move
     */
    public function move(Request $request, $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'from' => ['required', 'string', 'size:2'],
            'to' => ['required', 'string', 'size:2'],
            'promotion' => ['nullable', 'string', 'size:1', Rule::in(['q', 'r', 'b', 'n', 'Q', 'R', 'B', 'N'])],
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError(
                'Validation failed.',
                $validator->errors()->toArray()
            );
        }

        $user = $request->user();
        if (!$user) return $this->sendError(
            'Unauthenticated.',
        );

        $game = Game::find($id);
        if (!$game) return $this->sendError('Game not found.', 404);

        if ($game->white_player_id !== $user->id && $game->black_player_id !== $user->id) {
            return $this->sendError('You are not a participant of this game.', 403);
        }

        $move = [
            'from' => strtolower($request->input('from')),
            'to' => strtolower($request->input('to')),
            'promotion' => $request->filled('promotion') ? strtolower($request->input('promotion')) : null,
        ];

        DB::beginTransaction();
        try {
            $result = $this->chessService->makeMove($game, $move, $user->id);

            $updatedGame = $result['game'];
            $engine = $result['engine'];
            $uci = $result['uci'] ?? null;

            if ($updatedGame->status === 'finished' && $updatedGame->result) {
                DB::commit();

                try {
                    $this->finalizeGameWithStats($updatedGame, $engine);
                } catch (Throwable $e) {
                    return $this->sendError(
                        'Move applied but failed to finalize stats: ',
                        $e,
                    );
                }
            } else {
                DB::commit();
            }

            return $this->sendResponse(
                true,
                'Move applied.',
                [
                    'uci' => $uci,
                    'game' => $updatedGame,
                    'fen' => $engine->getFEN(),
                    'result' => $updatedGame->result,
                ],
                200
            );
        } catch (Throwable $e) {
            DB::rollBack();

            $msg = $e->getMessage();
            $status = 400;

            if (stripos($msg, 'not your turn') !== false) $status = 403;
            if (stripos($msg, 'illegal') !== false) $status = 422;

            return $this->sendError(
                'Failed to make move',
                $e,
                $status,
            );
        }
    }

    /**
     * Finalize game stats after checkmate/stalemate
     */
    protected function finalizeGameWithStats(Game $game, $engine): void
    {
        if (GameResult::where('game_id', $game->id)->exists()) {
            return; // idempotent
        }

        DB::beginTransaction();
        try {
            $whiteId = $game->white_player_id;
            $blackId = $game->black_player_id;

            $whiteStat = $whiteId
                ? PlayerStat::where('user_id', $whiteId)->lockForUpdate()->first()
                : null;

            $blackStat = $blackId
                ? PlayerStat::where('user_id', $blackId)->lockForUpdate()->first()
                : null;

            if (!$whiteStat && $whiteId) {
                $whiteStat = PlayerStat::create(['user_id' => $whiteId]);
            }
            if (!$blackStat && $blackId) {
                $blackStat = PlayerStat::create(['user_id' => $blackId]);
            }

            $whiteBefore = $whiteStat?->rating;
            $blackBefore = $blackStat?->rating;

            $result = $game->result;
            $whiteScore = $result === '1-0' ? 1 : ($result === '0-1' ? 0 : 0.5);
            $blackScore = 1 - $whiteScore;

            $K = 20;
            $whiteAfter = $whiteStat ? $this->eloNewRating($whiteBefore, $blackBefore, $whiteScore, $K) : null;
            $blackAfter = $blackStat ? $this->eloNewRating($blackBefore, $whiteBefore, $blackScore, $K) : null;

            if ($whiteStat) {
                $whiteStat->games_played++;
                $whiteStat->last_game_at = now();
                if ($whiteScore === 1) {
                    $whiteStat->wins++;
                    $whiteStat->wins_with_white++;
                    $whiteStat->streak++;
                } elseif ($whiteScore === 0.5) {
                    $whiteStat->draws++;
                    $whiteStat->streak = 0;
                } else {
                    $whiteStat->losses++;
                    $whiteStat->streak--;
                }
                $whiteStat->rating = $whiteAfter;
                $whiteStat->save();
            }

            if ($blackStat) {
                $blackStat->games_played++;
                $blackStat->last_game_at = now();
                if ($blackScore === 1) {
                    $blackStat->wins++;
                    $blackStat->wins_with_black++;
                    $blackStat->streak++;
                } elseif ($blackScore === 0.5) {
                    $blackStat->draws++;
                    $blackStat->streak = 0;
                } else {
                    $blackStat->losses++;
                    $blackStat->streak--;
                }
                $blackStat->rating = $blackAfter;
                $blackStat->save();
            }

            GameResult::create([
                'game_id' => $game->id,
                'white_player_id' => $whiteId,
                'black_player_id' => $blackId,
                'result' => $result,
                'white_rating_before' => $whiteBefore,
                'black_rating_before' => $blackBefore,
                'white_rating_after' => $whiteAfter,
                'black_rating_after' => $blackAfter,
            ]);

            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            throw new Exception("Failed to finalize stats: " . $e->getMessage());
        }
    }

    private function eloNewRating($R1, $R2, $S1, $K = 20)
    {
        $E1 = 1 / (1 + pow(10, ($R2 - $R1) / 400));
        return round($R1 + $K * ($S1 - $E1));
    }
}

