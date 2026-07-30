<?php

use App\Models\IdempotencyKey;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function () {
    IdempotencyKey::query()
        ->where('created_at', '<', now()->subDay())
        ->delete();
})->daily();
