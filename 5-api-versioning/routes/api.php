<?php

use App\Http\Middleware\AddDeprecationHeaders;
use App\Http\Controllers\Api\V1\ChallengeController as V1ChallengeController;
use App\Http\Controllers\Api\V2\ChallengeController as V2ChallengeController;

Route::prefix('v1')
    ->middleware(AddDeprecationHeaders::class.':Wed, 31 Dec 2027 23:59:59 GMT')
    ->group(function () {
        Route::get('/challenges', [V1ChallengeController::class, 'index']);
        Route::get('/challenges/{challenge}', [V1ChallengeController::class, 'show']);
    });

// ---- Version 2 (current) ----
Route::prefix('v2')->group(function () {
    Route::get('/challenges', [V2ChallengeController::class, 'index']);
    Route::get('/challenges/{challenge}', [V2ChallengeController::class, 'show']);
});
