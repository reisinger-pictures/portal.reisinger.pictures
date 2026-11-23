<?php

namespace App\Http\Controllers;

use App\Services\Auth\AuthTokenService;

abstract class Controller
{
    //

    /**
     * Attach the two httpOnly credential cookies and the standard token body.
     *
     * The names stay `rp_jwt` / `rp_jwt_refresh` (D-75) so the frontend does not
     * change. The cookies hold distinct access/refresh Sanctum tokens whose
     * lifetimes come from the migration config (D-76).
     */
    protected function respondWithTokens(string $accessToken, string $refreshToken, array $additionalData = [])
    {
        $tokens = app(AuthTokenService::class);
        $ttl = $tokens->accessTtlMinutes();
        $refreshTtl = $tokens->refreshTtlMinutes();
        $secure = ! app()->environment('local');
        $accessCookieName = (string) config('auth.migration.cookie_access', 'rp_jwt');
        $refreshCookieName = (string) config('auth.migration.cookie_refresh', 'rp_jwt_refresh');

        $accessCookie = cookie(
            $accessCookieName,
            $accessToken,
            $ttl,
            '/',
            null,
            $secure,
            true,
            false,
            'Lax'
        );

        $refreshCookie = cookie(
            $refreshCookieName,
            $refreshToken,
            $refreshTtl,
            '/',
            null,
            $secure,
            true,
            false,
            'Lax'
        );

        $responseBody = array_merge([
            'success' => true,
            'expires_in' => $ttl * 60,
        ], $additionalData);

        return response()->json($responseBody)
            ->withCookie($accessCookie)
            ->withCookie($refreshCookie);
    }
}
