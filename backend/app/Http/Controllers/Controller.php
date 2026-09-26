<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Auth;

abstract class Controller
{
    //

    protected function respondWithToken($token, $additionalData = [])
    {
        $ttl = Auth::guard('api')->factory()->getTTL();
        $secure = ! app()->environment('local');
        $accessCookieName = (string) config('jwt.cookie_key_name', 'rp_jwt');
        $refreshCookieName = (string) config('jwt.refresh_cookie_key_name', 'rp_jwt_refresh');
        $refreshTtl = (int) config('jwt.refresh_ttl', 10080);

        $accessCookie = cookie(
            $accessCookieName,
            $token,
            $ttl,
            '/',
            null,
            $secure,
            true,
            false,
            'Lax'
        );

        // Keep the same JWT as a separate, longer-lived httpOnly credential.
        // The access cookie may expire after JWT_TTL; the JWT package can still
        // refresh this token until JWT_REFRESH_TTL, including rolling iat/TTL
        // and blacklist-based dedup semantics.
        $refreshCookie = cookie(
            $refreshCookieName,
            $token,
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
