<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Tenant;
use App\Models\TenantInvite;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Mail;
use App\Mail\CustomMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class TenantInviteController extends Controller
{
    public function invite(Request $request, $tenantId)
    {
        $user = auth('api')->user();
        $tenant = Tenant::findOrFail($tenantId);

        // Scoped Policy: Nur Admins oder Customer Manager DES Mandanten dürfen einladen
        if (!$user->is_admin && !($user->is_customer_manager && $user->tenants->contains($tenantId))) {
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

        $link = rtrim(config('app.frontend_url'), '/') . '/tenant-invite/' . $token;
        $body = "<p>Hallo!</p><p>Du wurdest eingeladen, dem Unternehmens-Account <b>{$tenant->name}</b> auf dem Reisinger Foto Portal beizutreten.</p><p><a href='{$link}'>Hier klicken, um den Account zu aktivieren</a></p>";

        Mail::to($request->email)->send(new CustomMail("Einladung zum Portal: {$tenant->name}", $body));

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
        $request->validate([
            'token' => 'required|string',
            'name' => 'required|string|max:255',
            'password' => 'required|string|min:8'
        ]);

        $invite = TenantInvite::where('token', $request->token)
            ->where('expires_at', '>', now())
            ->firstOrFail();

        return DB::transaction(function () use ($request, $invite) {
            // User erstellen oder holen
            $user = \App\Models\User::firstOrCreate(
                ['email' => $invite->email],
                ['name' => $request->name, 'password' => Hash::make($request->password)]
            );

            // Falls User existierte, aber noch kein PW hatte
            if (empty($user->password)) {
                $user->password = Hash::make($request->password);
                $user->save();
            }

            // Mandanten-Zuweisung sicherstellen
            $user->tenants()->syncWithoutDetaching([$invite->tenant_id]);

            // Automatisch die Client-Rolle vergeben
            $clientRole = \App\Models\Role::where('name', 'client')->first();
            if ($clientRole) {
                $user->roles()->syncWithoutDetaching([$clientRole->id]);
            }

            // Token entwerten
            $invite->delete();

            // Automatisch einloggen
            $token = \Illuminate\Support\Facades\Auth::guard('api')->login($user);
            // Wir binden Controller.php Funktionalität ein
            $ttl = \Illuminate\Support\Facades\Auth::guard('api')->factory()->getTTL();
            $cookie = cookie('rp_jwt', $token, $ttl, '/', null, env('APP_ENV') !== 'local', true, false, 'Lax');
            return response()->json(['success' => true])->withCookie($cookie);
        });
    }
}
