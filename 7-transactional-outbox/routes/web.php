<?php

use App\Actions\SubmitFlag;
use App\Models\Challenge;
use App\Models\Team;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/solve', function (SubmitFlag $submitFlag) {
    $team      = Team::firstOrFail();
    $challenge = Challenge::firstOrFail();

    // Note: this demo re-solves the same team+challenge each time, which the
    // unique index blocks after the first hit. To trigger repeatedly during
    // testing, either remove the unique index for now, or create fresh teams.
    $submitFlag->handle($team, $challenge);

    return "Solved! Points now: " . $team->fresh()->points;
});
