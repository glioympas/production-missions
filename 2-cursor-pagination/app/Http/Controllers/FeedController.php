<?php

namespace App\Http\Controllers;

use App\Models\Submission;
use Illuminate\Http\Request;

class FeedController extends Controller
{
    public function index()
    {
        // cursorPaginate = cursor pagination. Orders by id,
        // uses WHERE id < ... under the hood, no OFFSET.
        return Submission::query()
            ->orderBy('points', 'desc')
            ->orderBy('id', 'desc') // ← the tie-breaker
            ->cursorPaginate(20);
    }
}
