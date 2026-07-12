<?php

namespace EzEcommerce\Api\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class CommerceApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = config('ez-ecommerce.api.token');

        if ($token === null || $token === '') {
            if (config('ez-ecommerce.api.allow_unauthenticated', false)) {
                return $next($request);
            }

            abort(503, 'Commerce API token is not configured.');
        }

        $provided = $request->bearerToken() ?? $request->header('X-Commerce-Api-Token');

        if (! is_string($provided) || ! hash_equals($token, $provided)) {
            abort(401, 'Invalid commerce API token.');
        }

        return $next($request);
    }
}
