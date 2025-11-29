<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Game extends Model
{

    public function whitePlayer()
    {
        return $this->belongsTo(User::class, 'white_player_id');
    }
    public function blackPlayer()
    {
        return $this->belongsTo(User::class, 'black_player_id');
    }
}
