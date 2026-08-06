<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ChallengeResource;
use App\Models\Challenge;
use Illuminate\Http\Request;

class ChallengeController extends Controller
{
    public function index()
    {
        return ChallengeResource::collection(Challenge::query()->get());
    }

    public function show(Challenge $challenge)
    {
        return new ChallengeResource($challenge);
    }
}
