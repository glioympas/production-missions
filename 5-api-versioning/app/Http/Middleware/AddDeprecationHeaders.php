<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AddDeprecationHeaders
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $sunset): Response
    {
        $response = $next($request);

        $response->headers->set('Sunset', $sunset);

        $response->headers->set('Warning',
            '299 - "This API version is deprecated. Please migrate to v2."'
        );

        $response->headers->set(
            'Link',
            '<https://docs.sample.example/api/migration>; rel="sunset"'
        );

        return $response;
    }
}
