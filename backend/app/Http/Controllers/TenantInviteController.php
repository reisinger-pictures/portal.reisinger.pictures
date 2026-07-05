<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Tenant;
use App\Models\TenantInvite;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Mail;
use App\Mail\TenantInviteMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Support\BrandRegistry;

class TenantInviteController extends Controller
{
    public function invite(Request $request, $tenantId)
    {
        $user = auth('api')->user();
        $tenant = Tenant::findOrFail($tenantId);

        // Scoped Policy: Nur Admins oder Org-Admin DES Mandanten dürfen einladen
        if (!$user->is_admin && !($user->is_org_admin && $user->tenant_id === $tenantId)) {
            return response()->json(['error' => 'Keine Berechtigung, Nutzer in diesen Mandanten einzuladen.'], 403);
        }

        $request->validate([
            'email' => 'required|email'
        ]);

        $token = Str::random(64);
        
        $invite = TenantInvite::create([
            'email' => $request->email,
            'tenant_id' => $tenant->id,
            'token' => $token,
            'expires_at' => now()->addDays(7)
        ]);

        $link = BrandRegistry::frontendUrl() . '/tenant-invite/' . $token;
        Mail::to($request->email)->queue(new TenantInviteMail($tenant->name, $link));

        return response()->json(['success' => true]);
    }

    public function check($token)
    {
        $invite = TenantInvite::where('token', $token)
            ->where('expires_at', '>', now())
            ->with('tenant')
            ->firstOrFail();
            
        return response()->json([
            'tenant_name' => $invite->tenant->name,
            'email' => $invite->email
        ]);
    }

    public function redeem(Request $request)
    {
        $user = auth('api')->user();

        if ($user) {
            $request->validate([
                'token' => 'required|string',
                'accept_privacy' => 'required|accepted'
            ]);
        } else {
            $request->validate([
                'token' => 'required|string',
                'name' => 'required|string|max:255',
                'password' => 'required|string|min:8',
                'accept_privacy' => 'required|accepted'
            ]);
        }

        $invite = TenantInvite::where('token', $request->token)
            ->where('expires_at', '>', now())
            ->firstOrFail();

        return DB::transaction(function () use ($request, $invite, $user) {
            if (!$user) {
                // Neuen User erstellen
                $user = \App\Models\User::firstOrCreate(
                    ['email' => $invite->email],
                    ['name' => $request->name, 'password' => Hash::make($request->password)]
                );

                if (empty($user->password)) {
                    $user->password = Hash::make($request->password);
                    $user->save();
                }
            }

            // Tenant-Zuweisung sicherstellen
            $user->tenant_id = $invite->tenant_id;
            $user->save();

            // Client-Rolle vergeben falls noch keine
            $clientRole = \App\Models\Role::where('name', \App\Enums\UserRole::CLIENT->value)->first();
            if ($clientRole && !$user->roles->contains($clientRole->id)) {
                $user->roles()->syncWithoutDetaching([$clientRole->id]);
            }

            // Token entwerten
            $invite->delete();

            if ($user === auth('api')->user()) {
                // Bereits eingeloggt — Session bleibt bestehen
                return response()->json(['success' => true]);
            }

            // Neuen User einloggen
            \Illuminate\Support\Facades\Auth::guard('api')->logout();
            $token = \Illuminate\Support\Facades\Auth::guard('api')->login($user);
            $ttl = \Illuminate\Support\Facades\Auth::guard('api')->factory()->getTTL();
            $cookie = cookie('rp_jwt', $token, $ttl, '/', null, env('APP_ENV') !== 'local', true, false, 'Lax');
            return response()->json(['success' => true])->withCookie($cookie);
        });
    }
}
