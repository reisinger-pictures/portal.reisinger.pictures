<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Auth;

abstract class Controller
{
    //

    protected function respondWithToken($token, $additionalData = [])
    {
        $ttl = Auth::guard('api')->factory()->getTTL();
        $secure = !app()->environment('local');

        $cookie = cookie(
            'rp_jwt',
            $token,
            $ttl,
            '/',
            null,
            $secure,
            true,
            false,
            $secure ? 'None' : 'Lax'
        );

        $responseBody = array_merge([
            'success' => true,
            'expires_in' => $ttl * 60
        ], $additionalData);

        return response()->json($responseBody)->withCookie($cookie);
    }
}
