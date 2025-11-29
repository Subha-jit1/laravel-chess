<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlayerStat extends Model
{
    protected $table = 'player_stats';
    protected $fillable = [
        'user_id',
        'rating',
        'games_played',
        'wins',
        'losses',
        'draws',
        'wins_with_white',
        'wins_with_black',
        'streak',
        'last_game_at',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
