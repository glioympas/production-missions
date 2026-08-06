<?php

namespace App\Http\Controllers;

use App\Actions\BulkCreateChallenges;
use App\Http\Requests\BulkStoreChallengeRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class BulkChallengeController extends Controller
{
    public function store(BulkStoreChallengeRequest $request, BulkCreateChallenges $action): JsonResponse
    {
        $result = $action->handle($request->validated()['items']);

        // 207 Multi-Status: the batch was processed, but individual items have
        // their own outcomes. The correct status code for partial success.
        $status = empty($result['failed']) ? Response::HTTP_CREATED : Response::HTTP_MULTI_STATUS;

        return response()->json([
            'summary' => [
                'total' => count($result['created']) + count($result['failed']),
                'created' => count($result['created']),
                'failed' => count($result['failed']),
            ],
            'created' => $result['created'],
            'failed' => $result['failed'],
        ], $status);
    }
}
