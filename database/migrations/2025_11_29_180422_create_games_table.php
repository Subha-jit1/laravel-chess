<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::create('games', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('white_player_id')->nullable()->index();
            $table->unsignedBigInteger('black_player_id')->nullable()->index();
            $table->string('fen')->default('startpos');
            $table->enum('turn', ['w', 'b'])->default('w');
            $table->string('status')->default('waiting'); // waiting, playing, finished
            $table->string('result')->nullable(); // '1-0','0-1','1/2-1/2'
            $table->json('move_history')->nullable();
            $table->integer('halfmove_clock')->default(0);
            $table->integer('fullmove_number')->default(1);
            $table->string('castling_rights')->default('KQkq');
            $table->string('en_passant')->nullable();
            $table->timestamps();

            $table->foreign('white_player_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('black_player_id')->references('id')->on('users')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('games');
    }
};
