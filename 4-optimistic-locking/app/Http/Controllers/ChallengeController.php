<?php

namespace App\Http\Controllers;

use App\Exceptions\StaleModelException;
use App\Models\Challenge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChallengeController extends Controller
{
    public function update(Request $request, Challenge $challenge): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string'],
            'description' => ['required', 'string'],
            'points' => ['required', 'integer', 'min:0'],
            'version' => ['required', 'integer'],
        ]);

        try {
            $challenge->updateWithVersion(
                [
                    'title' => $validated['title'],
                    'description' => $validated['description'],
                    'points' => $validated['points'],
                ],
                expectedVersion: $validated['version'],
            );
        } catch (StaleModelException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'current' => $challenge->fresh(),
            ], 409);
        }

        return response()->json([
            'message' => 'Challenge updated.',
            'challenge' => $challenge->fresh(),
        ]);
    }
}
