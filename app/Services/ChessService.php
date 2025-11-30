<?php 

namespace App\Services;

use App\Models\Game;
use Illuminate\Support\Facades\DB;
use Exception;

class ChessService
{
    public function createGame($whiteId = null, $blackId = null)
    {
        $game = Game::create([
            'white_player_id' => $whiteId,
            'black_player_id' => $blackId,
            'fen' => 'startpos',
            'turn' => 'w',
            'status' => 'playing',
            'move_history' => [],
            'halfmove_clock' => 0,
            'fullmove_number' => 1,
            'castling_rights' => 'KQkq',
            'en_passant' => null,
        ]);
        return $game;
    }

    // Load engine from DB row
    public function engineFromGame(Game $game)
    {
        $engine = new ChessEngine($game->fen, $game->move_history ?? []);
        $engine->turn = $game->turn;
        $engine->castling = $game->castling_rights;
        $engine->enPassant = $game->en_passant;
        $engine->halfmoveClock = $game->halfmove_clock;
        $engine->fullmoveNumber = $game->fullmove_number;
        $engine->history = $game->move_history ?: [$engine->getFEN()];
        return $engine;
    }

    // Make move safely with DB transaction and optimistic lock
    public function makeMove(Game $game, array $move, $playerId)
    {
        // Basic player turn validation
        $isWhite = $game->turn === 'w';
        if ($isWhite && $playerId !== $game->white_player_id) throw new Exception('Not your turn');
        if (!$isWhite && $playerId !== $game->black_player_id) throw new Exception('Not your turn');

        return DB::transaction(function() use ($game,$move) {
            // reload fresh
            $game->refresh();
            $engine = $this->engineFromGame($game);

            // check legality
            if (!$engine->isLegalMove($move)) throw new Exception('Illegal move');

            // apply move
            $success = $engine->makeMove($move);
            if (!$success) throw new Exception('Failed to make move');

            // update game model fields
            $game->fen = $engine->getFEN();
            $game->turn = $engine->turn;
            $game->castling_rights = $engine->castling;
            $game->en_passant = $engine->enPassant;
            $game->halfmove_clock = $engine->halfmoveClock;
            $game->fullmove_number = $engine->fullmoveNumber;

            // append move to history - store SAN or UCI; here we store simple uci: e2e4
            $uci = $move['from'] . $move['to'] . (isset($move['promotion']) ? strtolower($move['promotion']) : '');
            $mh = $game->move_history ?: [];
            $mh[] = $uci;
            $game->move_history = $engine->history; // persist engine history for repetition detection

            // check terminal
            $result = $engine->gameResult();
            if ($result) {
                $game->status = 'finished';
                $game->result = $result;
            }

            $game->save();

            return ['game'=>$game, 'engine'=>$engine, 'uci'=>$uci];
        });
    }
}