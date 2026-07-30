<?php

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

class EnsureIdempotency
{
    private const int LOCK_SECONDS = 10;

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     *
     * @throws LockTimeoutException
     */
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');

        Validator::make(['key' => $key], ['key' => 'required|uuid'])->validate();

        $lock = Cache::lock("idempotency:$key", self::LOCK_SECONDS);

        if (!$lock->block(self::LOCK_SECONDS)) {
            return response()->json(
                ['message' => 'A request with this key is still processing.'],
                Response::HTTP_CONFLICT
            );
        }

        try {
            $existing = IdempotencyKey::query()
                ->where('key', '=', $key)
                ->first();

            $requestHash = hash('sha256', $request->getContent());

            if($existing) {
                return $this->getExistingResponse($existing, $requestHash);
            }

            $newIdempotencyKey = $this->createNewIdempotencyKey($key, $requestHash);

            $response = $next($request);

            if ($response->getStatusCode() < 500) {
                $newIdempotencyKey->update([
                    'response_status' => $response->getStatusCode(),
                    'response_body' => json_decode($response->getContent(), true),
                ]);
            }

            return $response;
        } finally {
            $lock->release();
        }
    }

    private function getExistingResponse(IdempotencyKey $existing, string $requestHash): JsonResponse
    {
        if ($existing->request_hash !== $requestHash) {
            return response()->json([
                'message' => 'Idempotency-Key was already used with a different request body.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()
            ->json($existing->response_body, $existing->response_status)
            ->header('Idempotent-Replayed', 'true');
    }

    private function createNewIdempotencyKey(string $key, string $requestHash): IdempotencyKey
    {
         return retry(3, fn() => IdempotencyKey::query()
            ->create(['key' => $key, 'request_hash' => $requestHash]), 100);
    }
}
