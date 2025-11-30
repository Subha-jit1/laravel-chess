<?php

use App\Http\Controllers\Chess\ChessController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Features;


Route::group(['prefix' => 'chess'], function () {
    Route::get('/', [ChessController::class, 'index'])->name('chess.index');
})->middleware(['auth', 'verified']);

