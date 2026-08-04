<?php

use App\Http\Controllers\ChallengeController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::put('/challenges/{challenge}', [ChallengeController::class, 'update']);
