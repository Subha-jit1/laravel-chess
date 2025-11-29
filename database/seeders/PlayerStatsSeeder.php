<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class PlayerStatsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run()
    {
        \App\Models\User::chunk(100, function ($users) {
            foreach ($users as $u) {
                \App\Models\PlayerStat::firstOrCreate(
                    ['user_id' => $u->id],
                    ['rating' => 1200, 'games_played' => 0]
                );
            }
        });
    }
}
