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
        } catch (\Exception $e) { 
            return null; 
        }

        if (is_string($identifier) && str_starts_with($identifier, 'guest_')) {
            if (\Illuminate\Support\Facades\Cache::has('blacklisted_' . $identifier)) {
                return null;
            }
            if ($payload) {
                $inviteId = $payload->get('guest_invite_id');
                if ($inviteId && \Illuminate\Support\Facades\Cache::has('blacklisted_invite_' . $inviteId)) {
                    \Illuminate\Support\Facades\Cache::put('blacklisted_' . $identifier, true, now()->addMinutes(config('jwt.ttl', 240)));
                    return null;
                }
                $user = new User();
                $user->id = null; // Explicitly null for DB-less guest
                $user->name = $payload->get('guest_name') ?? 'Gast';
                $user->guest_id = $payload->get('guest_id');
                $tg = $payload->get('transient_galleries');
                $user->transient_galleries = is_array($tg) ? $tg : [];
                $tmg = $payload->get('transient_meta_galleries');
                $user->transient_meta_galleries = is_array($tmg) ? $tmg : [];
                return $user;
            }
            return null;
        }

        $user = parent::retrieveById($identifier);
        
        if ($user && $payload) {
            $tg = $payload->get('transient_galleries');
            $user->transient_galleries = is_array($tg) ? $tg : [];
            $tmg = $payload->get('transient_meta_galleries');
            $user->transient_meta_galleries = is_array($tmg) ? $tmg : [];
        }

        return $user;
    }
}
