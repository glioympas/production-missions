<?php

use App\Http\Controllers\LeaderboardController;
use Illuminate\Support\Facades\Route;

Route::get('/leaderboard', [LeaderboardController::class, 'index']);
