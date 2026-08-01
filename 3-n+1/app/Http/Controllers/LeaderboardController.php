<?php

namespace App\Http\Controllers;

use App\Models\Team;

class LeaderboardController extends Controller
{
    public function index()
    {
        $teams = Team::query()
            ->withCount('solves')
            ->with('country:id,name')
            ->take(50)
            ->get();

        $result = $teams->map(function (Team $team) {
            return [
                'name' => $team->name,
                'country' => $team->country->name,
                'solves_count' => $team->solves_count,
            ];
        });

        return response()->json($result);
    }
}
