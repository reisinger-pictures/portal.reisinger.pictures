<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Auth;

abstract class Controller
{
    //

    protected function respondWithToken($token, $additionalData = [])
    {
        $ttl = Auth::guard('api')->factory()->getTTL();
        
        $cookie = cookie(
            'rp_jwt', 
            $token, 
            $ttl, 
            '/', 
            null, 
            env('APP_ENV') !== 'local',
            true, 
            false, 
            'Lax' 
        );

        $responseBody = array_merge([
            'success' => true,
            'expires_in' => $ttl * 60
        ], $additionalData);

        return response()->json($responseBody)->withCookie($cookie);
    }
}
