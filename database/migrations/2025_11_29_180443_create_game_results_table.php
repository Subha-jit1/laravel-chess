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
        Schema::create('game_results', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('game_id')->unique();
            $table->unsignedBigInteger('white_player_id')->nullable()->index();
            $table->unsignedBigInteger('black_player_id')->nullable()->index();
            $table->enum('result', ['1-0', '0-1', '1/2-1/2'])->nullable();
            $table->integer('white_rating_before')->nullable();
            $table->integer('black_rating_before')->nullable();
            $table->integer('white_rating_after')->nullable();
            $table->integer('black_rating_after')->nullable();
            $table->timestamps();

            $table->foreign('game_id')->references('id')->on('games')->onDelete('cascade');
        });
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('game_results');
    }
};
