<?php


namespace App\Services;

class ChessEngine
{
    public $board; // 8x8 array of pieces, null or piece codes like 'P','p','K','k', etc.
    public $turn; // 'w' or 'b'
    public $castling; // string like 'KQkq'
    public $enPassant; // square like 'e3' or null
    public $halfmoveClock;
    public $fullmoveNumber;
    public $history; // array of FENs for repetition detection

    // Piece vectors: we'll treat white uppercase, black lowercase
    public static $dirs = [
        'N' => [[-2,-1],[-2,1],[-1,-2],[-1,2],[1,-2],[1,2],[2,-1],[2,1]],
        'B' => [[-1,-1],[-1,1],[1,-1],[1,1]],
        'R' => [[-1,0],[1,0],[0,-1],[0,1]],
        'Q' => [[-1,0],[1,0],[0,-1],[0,1],[-1,-1],[-1,1],[1,-1],[1,1]],
        'K' => [[-1,0],[1,0],[0,-1],[0,1],[-1,-1],[-1,1],[1,-1],[1,1]],
    ];

    public function __construct($fen = 'startpos', $history = [])
    {
        $this->loadFromFEN($fen);
        $this->history = $history ?: [$this->getFEN()];
    }

    // Convert algebraic square to coords [r,c] 0-indexed from top-left (8th rank to 1st)
    public static function squareToCoords($sq)
    {
        if (!$sq) return null;
        $file = ord($sq[0]) - ord('a');
        $rank = intval($sq[1]);
        return [8 - $rank, $file];
    }

    public static function coordsToSquare($r, $c)
    {
        return chr(ord('a') + $c) . (8 - $r);
    }

    public function loadFromFEN($fen)
    {
        if ($fen === 'startpos') {
            $fen = 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1';
        }
        $parts = explode(' ', $fen);
        $rows = explode('/', $parts[0]);
        $this->board = array_fill(0,8, array_fill(0,8, null));
        foreach ($rows as $r => $row) {
            $c = 0;
            $chars = str_split($row);
            foreach ($chars as $ch) {
                if (is_numeric($ch)) {
                    $n = intval($ch);
                    for ($i=0;$i<$n;$i++) {
                        $this->board[$r][$c++] = null;
                    }
                } else {
                    $this->board[$r][$c++] = $ch;
                }
            }
        }
        $this->turn = $parts[1] ?? 'w';
        $this->castling = $parts[2] ?? '';
        $this->enPassant = $parts[3] ?? null;
        if ($this->enPassant === '-') $this->enPassant = null;
        $this->halfmoveClock = isset($parts[4]) ? intval($parts[4]) : 0;
        $this->fullmoveNumber = isset($parts[5]) ? intval($parts[5]) : 1;
    }

    public function getFEN()
    {
        $rows = [];
        for ($r=0;$r<8;$r++) {
            $row = '';
            $empty = 0;
            for ($c=0;$c<8;$c++) {
                $p = $this->board[$r][$c];
                if (is_null($p)) { $empty++; }
                else { if ($empty) { $row .= $empty; $empty=0; } $row .= $p; }
            }
            if ($empty) $row .= $empty;
            $rows[] = $row;
        }
        $pos = implode('/', $rows);
        $ep = $this->enPassant ?? '-';
        return trim("{$pos} {$this->turn} {$this->castling} {$ep} {$this->halfmoveClock} {$this->fullmoveNumber}");
    }

    // Returns piece at square like 'e4' or null
    public function pieceAt($sq)
    {
        $coords = static::squareToCoords($sq);
        if (!$coords) return null;
        [$r,$c] = $coords;
        return $this->board[$r][$c];
    }

    // Apply a move object: ['from'=>'e2','to'=>'e4','promotion'=>null]
    // Returns true on success, false on illegal
    public function makeMove(array $move)
    {
        if (!$this->isLegalMove($move)) return false;

        $from = $move['from'];
        $to = $move['to'];
        $prom = $move['promotion'] ?? null;

        [$fr,$fc] = static::squareToCoords($from);
        [$tr,$tc] = static::squareToCoords($to);
        $piece = $this->board[$fr][$fc];
        $target = $this->board[$tr][$tc];

        $isCapture = !is_null($target);

        // handle pawn double step for en-passant setup and en-passant capture
        // store previous enPassant
        $prevEP = $this->enPassant;

        // reset enPassant
        $this->enPassant = null;

        // Castling
        if (strtolower($piece) === 'k' && abs($fc - $tc) === 2) {
            // king side or queen side
            if ($tc === 6) { // king-side
                $rookFrom = [ $fr, 7 ];
                $rookTo = [ $fr, 5 ];
            } else { // queen-side
                $rookFrom = [ $fr, 0 ];
                $rookTo = [ $fr, 3 ];
            }
            // move king
            $this->board[$tr][$tc] = $piece;
            $this->board[$fr][$fc] = null;
            // move rook
            $this->board[$rookTo[0]][$rookTo[1]] = $this->board[$rookFrom[0]][$rookFrom[1]];
            $this->board[$rookFrom[0]][$rookFrom[1]] = null;

            // update castling rights
            if (ctype_upper($piece)) {
                $this->castling = str_replace(['K','Q'], '', $this->castling);
            } else {
                $this->castling = str_replace(['k','q'], '', $this->castling);
            }
        } else {
            // en-passant capture
            if (strtolower($piece) === 'p') {
                if ($to === $prevEP) {
                    // capture the pawn behind
                    [$epR,$epC] = static::squareToCoords($prevEP);
                    // The captured pawn is on the rank the pawn moved from
                    if ($this->turn === 'w') {
                        // white capturing black pawn that moved two squares to ep target
                        $this->board[$epR+1][$epC] = null;
                    } else {
                        $this->board[$epR-1][$epC] = null;
                    }
                    $isCapture = true;
                }

                // if pawn moved two squares, set en-passant target
                if (abs($fr - $tr) === 2) {
                    $epR = ($fr + $tr) / 2;
                    $this->enPassant = static::coordsToSquare($epR, $fc);
                }
            }

            // regular move and capture
            $this->board[$tr][$tc] = $piece;
            $this->board[$fr][$fc] = null;

            // handle promotion
            if ($prom && strtolower($piece) === 'p') {
                // ensure promotion is valid
                $promPiece = strtoupper($prom);
                $isWhite = ctype_upper($piece);
                $this->board[$tr][$tc] = $isWhite ? $promPiece : strtolower($promPiece);
            }

            // update castling rights if rook or king moved or rook captured
            if (strtolower($piece) === 'k') {
                if (ctype_upper($piece)) {
                    $this->castling = str_replace(['K','Q'], '', $this->castling);
                } else {
                    $this->castling = str_replace(['k','q'], '', $this->castling);
                }
            }
            if (strtolower($piece) === 'r') {
                // which rook moved?
                if ($fr === 7 && $fc === 0) $this->castling = str_replace('Q','',$this->castling);
                if ($fr === 7 && $fc === 7) $this->castling = str_replace('K','',$this->castling);
                if ($fr === 0 && $fc === 0) $this->castling = str_replace('q','',$this->castling);
                if ($fr === 0 && $fc === 7) $this->castling = str_replace('k','',$this->castling);
            }
            if ($isCapture && strtolower($target) === 'r') {
                if ($tr === 7 && $tc === 0) $this->castling = str_replace('Q','',$this->castling);
                if ($tr === 7 && $tc === 7) $this->castling = str_replace('K','',$this->castling);
                if ($tr === 0 && $tc === 0) $this->castling = str_replace('q','',$this->castling);
                if ($tr === 0 && $tc === 7) $this->castling = str_replace('k','',$this->castling);
            }
        }

        // update clocks
        if (strtolower($piece) === 'p' || $isCapture) {
            $this->halfmoveClock = 0;
        } else {
            $this->halfmoveClock++;
        }
        if ($this->turn === 'b') $this->fullmoveNumber++;

        // switch turn
        $this->turn = ($this->turn === 'w') ? 'b' : 'w';

        // add to history
        $this->history[] = $this->getFEN();

        return true;
    }

    // Validate that a move is legal (basic gating + check avoidance)
    public function isLegalMove(array $move)
    {
        $from = $move['from'];
        $to = $move['to'];
        $prom = $move['promotion'] ?? null;

        // basic squares
        $p = $this->pieceAt($from);
        if (!$p) return false;
        $isWhitePiece = ctype_upper($p);
        if (($this->turn === 'w') !== $isWhitePiece) return false;

        // generate legal moves from 'from' and see if 'to' matches
        $legal = $this->generateLegalMovesForSquare($from);
        foreach ($legal as $m) {
            if ($m['to'] === $to) {
                // if promotion required, ensure provided or default to queen
                if (($m['promotion_required'] ?? false) && !$prom) return false;
                // simulate and ensure not leaving king in check
                $clone = $this->clone();
                $clone->makeMove(['from'=>$from,'to'=>$to,'promotion'=>$prom ?? $m['promotion'] ?? null]);
                if ($clone->isInCheck($this->turn)) return false; // note $this->turn hasn't switched yet here because clone.makeMove switches
                return true;
            }
        }
        return false;
    }

    // Generate legal moves for a square ignoring check (we'll filter later)
    public function generateLegalMovesForSquare($from)
    {
        $p = $this->pieceAt($from);
        if (!$p) return [];
        $isWhite = ctype_upper($p);
        $color = $isWhite ? 'w' : 'b';
        $moves = [];
        [$fr,$fc] = static::squareToCoords($from);
        $lc = strtolower($p);

        if ($lc === 'p') {
            $dir = $isWhite ? -1 : 1;
            // single
            $nr = $fr + $dir; $nc = $fc;
            if ($this->onBoard($nr,$nc) && is_null($this->board[$nr][$nc])) {
                $to = static::coordsToSquare($nr,$nc);
                $m = ['from'=>$from,'to'=>$to];
                // promotion check
                if (($isWhite && $nr === 0) || (!$isWhite && $nr === 7)) {
                    $m['promotion_required'] = true;
                    $m['promotion'] = 'q';
                }
                $moves[] = $m;
                // double
                if (($isWhite && $fr === 6) || (!$isWhite && $fr === 1)) {
                    $nr2 = $fr + 2*$dir;
                    if ($this->onBoard($nr2,$nc) && is_null($this->board[$nr2][$nc])) {
                        $moves[] = ['from'=>$from,'to'=>static::coordsToSquare($nr2,$nc)];
                    }
                }
            }
            // captures
            foreach ([ -1, 1 ] as $dc) {
                $nr = $fr + $dir; $nc = $fc + $dc;
                if ($this->onBoard($nr,$nc) && !is_null($this->board[$nr][$nc])) {
                    if (ctype_upper($this->board[$nr][$nc]) !== $isWhite) {
                        $to = static::coordsToSquare($nr,$nc);
                        $m = ['from'=>$from,'to'=>$to];
                        if (($isWhite && $nr === 0) || (!$isWhite && $nr === 7)) {
                            $m['promotion_required']=true; $m['promotion']='q';
                        }
                        $moves[] = $m;
                    }
                }
            }
            // en-passant capture
            if ($this->enPassant) {
                foreach ([-1,1] as $dc) {
                    $nc = $fc + $dc; $nr = $fr + $dir;
                    if ($this->onBoard($nr,$nc) && static::coordsToSquare($nr,$nc) === $this->enPassant) {
                        $moves[] = ['from'=>$from,'to'=>$this->enPassant,'en_passant'=>true];
                    }
                }
            }
        } elseif ($lc === 'n') {
            foreach (self::$dirs['N'] as $d) {
                $nr = $fr + $d[0]; $nc = $fc + $d[1];
                if (!$this->onBoard($nr,$nc)) continue;
                if (is_null($this->board[$nr][$nc]) || ctype_upper($this->board[$nr][$nc]) !== $isWhite) {
                    $moves[] = ['from'=>$from,'to'=>static::coordsToSquare($nr,$nc)];
                }
            }
        } elseif ($lc === 'b' || $lc === 'r' || $lc === 'q') {
            $key = strtoupper($lc);
            $dirs = self::$dirs[$key];
            foreach ($dirs as $d) {
                $nr = $fr + $d[0]; $nc = $fc + $d[1];
                while ($this->onBoard($nr,$nc)) {
                    if (is_null($this->board[$nr][$nc])) {
                        $moves[] = ['from'=>$from,'to'=>static::coordsToSquare($nr,$nc)];
                    } else {
                        if (ctype_upper($this->board[$nr][$nc]) !== $isWhite) {
                            $moves[] = ['from'=>$from,'to'=>static::coordsToSquare($nr,$nc)];
                        }
                        break;
                    }
                    $nr += $d[0]; $nc += $d[1];
                }
            }
        } elseif ($lc === 'k') {
            foreach (self::$dirs['K'] as $d) {
                $nr = $fr + $d[0]; $nc = $fc + $d[1];
                if (!$this->onBoard($nr,$nc)) continue;
                if (is_null($this->board[$nr][$nc]) || ctype_upper($this->board[$nr][$nc]) !== $isWhite) {
                    $moves[] = ['from'=>$from,'to'=>static::coordsToSquare($nr,$nc)];
                }
            }
            // castling
            if ($isWhite && $fr===7 && $fc===4 && $this->turn==='w') {
                if (strpos($this->castling,'K') !== false) {
                    // squares f1,g1 empty and not attacked
                    if (is_null($this->board[7][5]) && is_null($this->board[7][6])) {
                        $moves[] = ['from'=>$from,'to'=>static::coordsToSquare(7,6),'castle'=>'K'];
                    }
                }
                if (strpos($this->castling,'Q') !== false) {
                    if (is_null($this->board[7][3]) && is_null($this->board[7][2]) && is_null($this->board[7][1])) {
                        $moves[] = ['from'=>$from,'to'=>static::coordsToSquare(7,2),'castle'=>'Q'];
                    }
                }
            }
            if (!$isWhite && $fr===0 && $fc===4 && $this->turn==='b') {
                if (strpos($this->castling,'k') !== false) {
                    if (is_null($this->board[0][5]) && is_null($this->board[0][6])) {
                        $moves[] = ['from'=>$from,'to'=>static::coordsToSquare(0,6),'castle'=>'k'];
                    }
                }
                if (strpos($this->castling,'q') !== false) {
                    if (is_null($this->board[0][3]) && is_null($this->board[0][2]) && is_null($this->board[0][1])) {
                        $moves[] = ['from'=>$from,'to'=>static::coordsToSquare(0,2),'castle'=>'q'];
                    }
                }
            }
        }

        return $moves;
    }

    public function onBoard($r,$c)
    {
        return $r>=0 && $r<8 && $c>=0 && $c<8;
    }

    // Check if the side-to-move (color) is currently in check
    // color param optional; if omitted uses current turn
    public function isInCheck($color = null)
    {
        if (!$color) $color = $this->turn;
        // find king
        $king = $color === 'w' ? 'K' : 'k';
        $kr = $kc = null;
        for ($r=0;$r<8;$r++) for ($c=0;$c<8;$c++) if ($this->board[$r][$c] === $king) { $kr=$r;$kc=$c; }
        if (is_null($kr)) return true; // no king - treat as checked

        // for each enemy piece, see if it attacks king
        $enemyIsWhite = ($color === 'b');
        for ($r=0;$r<8;$r++) for ($c=0;$c<8;$c++) {
            $p = $this->board[$r][$c]; if (is_null($p)) continue;
            if (ctype_upper($p) === $enemyIsWhite) {
                $fromSq = static::coordsToSquare($r,$c);
                $moves = $this->generateLegalMovesForSquare($fromSq);
                foreach ($moves as $m) {
                    if ($m['to'] === static::coordsToSquare($kr,$kc)) return true;
                }
            }
        }
        return false;
    }

    // Generate all legal moves for the current side (filter moves that leave king in check)
    public function generateLegalMoves()
    {
        $moves = [];
        for ($r=0;$r<8;$r++) for ($c=0;$c<8;$c++) {
            $p = $this->board[$r][$c]; if (is_null($p)) continue;
            $isWhite = ctype_upper($p);
            if (($this->turn === 'w') !== $isWhite) continue;
            $from = static::coordsToSquare($r,$c);
            foreach ($this->generateLegalMovesForSquare($from) as $m) {
                // simulate
                $clone = $this->clone();
                $ok = $clone->makeMove(['from'=>$m['from'],'to'=>$m['to'],'promotion'=>$m['promotion'] ?? null]);
                if (!$ok) continue;
                if ($clone->isInCheck($this->turn)) continue;
                $moves[] = $m;
            }
        }
        return $moves;
    }

    public function isCheckmate()
    {
        if (!$this->isInCheck($this->turn)) return false;
        return count($this->generateLegalMoves()) === 0;
    }

    public function isStalemate()
    {
        if ($this->isInCheck($this->turn)) return false;
        return count($this->generateLegalMoves()) === 0;
    }

    public function insufficientMaterial()
    {
        // Basic implementation: only king vs king, king+minor vs king, king+two knights vs king?
        $pieces = [];
        for ($r=0;$r<8;$r++) for ($c=0;$c<8;$c++) {
            $p = $this->board[$r][$c]; if (is_null($p)) continue;
            $pieces[] = strtolower($p);
            if (in_array(strtolower($p), ['p','r','q'])) return false; // pawn/rook/queen present -> not insufficient
        }
        // now pieces only kings and bishops/knights
        $minor = array_filter($pieces, fn($x)=>in_array($x,['b','n']));
        if (count($pieces) === 2) return true; // K vs K
        if (count($pieces) === 3 && count($minor) === 1) return true; // K+N vs K or K+B vs K
        return false;
    }

    public function threefoldRepetition()
    {
        $counts = array_count_values($this->history);
        foreach ($counts as $fen => $n) if ($n >= 3) return true;
        return false;
    }

    public function fiftyMoveRule()
    {
        return $this->halfmoveClock >= 100; // 50 full moves -> 100 halfmoves
    }

    public function gameResult()
    {
        if ($this->isCheckmate()) {
            return $this->turn === 'w' ? '0-1' : '1-0';
        }
        if ($this->isStalemate()) return '1/2-1/2';
        if ($this->insufficientMaterial()) return '1/2-1/2';
        if ($this->threefoldRepetition()) return '1/2-1/2';
        if ($this->fiftyMoveRule()) return '1/2-1/2';
        return null;
    }

    public function clone()
    {
        $new = new self($this->getFEN(), $this->history);
        // ensure deep copy
        $new->board = array_map(fn($r)=>array_map(fn($c)=>$c,$r), $this->board);
        $new->turn = $this->turn;
        $new->castling = $this->castling;
        $new->enPassant = $this->enPassant;
        $new->halfmoveClock = $this->halfmoveClock;
        $new->fullmoveNumber = $this->fullmoveNumber;
        $new->history = $this->history ? array_values($this->history) : [];
        return $new;
    }
}