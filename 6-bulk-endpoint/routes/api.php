<?php

use App\Http\Controllers\BulkChallengeController;
use Illuminate\Support\Facades\Route;

Route::post('/challenges/bulk', [BulkChallengeController::class, 'store']);
