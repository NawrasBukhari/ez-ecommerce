<?php

namespace EzEcommerce\Api\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class CommerceApiToken
{
    public function handle(Request $request, Closure $next, string ...$requiredScopes): Response
    {
        if ($this->tokenMap() === [] && ! config('ez-ecommerce.api.allow_unauthenticated', false)) {
            abort(503, 'Commerce API token is not configured.');
        }

        if ($this->tokenMap() === [] && config('ez-ecommerce.api.allow_unauthenticated', false)) {
            $request->attributes->set('commerce_api_scopes', ['*']);

            return $next($request);
        }

        $provided = $request->bearerToken() ?? $request->header('X-Commerce-Api-Token');
        if (! is_string($provided) || $provided === '') {
            abort(401, 'Invalid commerce API token.');
        }

        $scopes = $this->resolveScopes($provided);
        if ($scopes === null) {
            abort(401, 'Invalid commerce API token.');
        }

        $request->attributes->set('commerce_api_scopes', $scopes);

        if ($requiredScopes !== [] && ! $this->scopesAllow($scopes, $requiredScopes)) {
            abort(403, 'Insufficient API scope.');
        }

        return $next($request);
    }

    /** @return array<string, list<string>> */
    private function tokenMap(): array
    {
        $scoped = config('ez-ecommerce.api.scoped_tokens', []);
        if (is_array($scoped) && $scoped !== []) {
            return array_filter(
                $scoped,
                static fn (mixed $scopes, mixed $token): bool => is_string($token) && $token !== '' && is_array($scopes),
                ARRAY_FILTER_USE_BOTH,
            );
        }

        $legacy = config('ez-ecommerce.api.token');
        if (is_string($legacy) && $legacy !== '') {
            return [$legacy => ['*']];
        }

        return [];
    }

    /** @return list<string>|null */
    private function resolveScopes(string $provided): ?array
    {
        foreach ($this->tokenMap() as $token => $scopes) {
            if (hash_equals($token, $provided)) {
                return $scopes;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $tokenScopes
     * @param  list<string>  $requiredScopes
     */
    private function scopesAllow(array $tokenScopes, array $requiredScopes): bool
    {
        if (in_array('*', $tokenScopes, true)) {
            return true;
        }

        foreach ($requiredScopes as $required) {
            if (in_array($required, $tokenScopes, true)) {
                continue;
            }

            if (str_ends_with($required, '.read')) {
                $writeScope = substr($required, 0, -strlen('.read')).'.write';
                if (in_array($writeScope, $tokenScopes, true)) {
                    continue;
                }
            }

            return false;
        }

        return true;
    }
}
