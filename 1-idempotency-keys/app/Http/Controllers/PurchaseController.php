<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;

class PurchaseController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'credits' => 'required|integer|min:1',
        ]);

        //        $user = $request->user(); // normally

        $user = User::query()->first();

        // >>> In real life: charge the card here (Stripe etc.) <
        // This is the dangerous operation we do NOT want to run twice.
        // $amount = $data['credits'] * 0.10; // €0.10 per credit
        // $stripe->charge($team, $amount);

        // Add credits.
        $user->increment('credits', $data['credits']);

        return response()->json([
            'message' => 'Purchase successful',
            'credits_added' => $data['credits'],
            'new_balance' => $user->fresh()->credits,
        ], 201);
    }
}
