<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Resources\V2\ChallengeResource;
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
