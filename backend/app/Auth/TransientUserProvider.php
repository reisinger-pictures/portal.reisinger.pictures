<?php

namespace App\Auth;

use Illuminate\Auth\EloquentUserProvider;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use App\Models\User;

class TransientUserProvider extends EloquentUserProvider
{
    public function retrieveById($identifier)
    {
        $payload = null;
        try {
            $payload = JWTAuth::parseToken()->getPayload();
        } catch (\Exception $e) {}

        if (is_string($identifier) && str_starts_with($identifier, 'guest_')) {
            if ($payload) {
                $user = new User();
                $user->id = null; // Explicitly null for DB-less guest
                $user->name = $payload->get('guest_name') ?? 'Gast';
                $user->guest_id = $payload->get('guest_id');
                $tg = $payload->get('transient_galleries');
                $user->transient_galleries = is_array($tg) ? $tg : [];
                return $user;
            }
            return null;
        }

        $user = parent::retrieveById($identifier);
        
        if ($user && $payload) {
            $tg = $payload->get('transient_galleries');
            $user->transient_galleries = is_array($tg) ? $tg : [];
        }

        return $user;
    }
}
