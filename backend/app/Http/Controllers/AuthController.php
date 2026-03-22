<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use App\Models\User;
use App\Models\Role;
use App\Models\DomainMapping;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $credentials = $request->only('email', 'password');
        
        if (app()->environment('local')) {
            $adminEmail = env('ADMIN_EMAIL', 'florian@reisinger.pictures');
            $adminPass = env('ADMIN_PASSWORD', 'admin');

            if ($credentials['email'] === $adminEmail && $credentials['password'] === $adminPass) {
                $user = User::firstOrCreate(
                    ['email' => $adminEmail],
                    ['name' => 'Florian Reisinger']
                );
                $adminRole = Role::firstOrCreate(['name' => 'admin']);
                $photoRole = Role::firstOrCreate(['name' => 'photographer']);
                $clientRole = Role::firstOrCreate(['name' => 'client']);
                $user->roles()->syncWithoutDetaching([$adminRole->id, $photoRole->id, $clientRole->id]);

                $token = Auth::guard('api')->login($user);
                return $this->respondWithToken($token);
            }
        }

        $user = User::where('email', $credentials['email'])->first();
        if ($user && $user->password && Hash::check($credentials['password'], $user->password)) {
            $token = Auth::guard('api')->login($user);
            return $this->respondWithToken($token);
        }

        return response()->json(['error' => 'Ungültige Zugangsdaten.'], 401);
    }

    public function register(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => null, // Initial passwortlos!
        ]);

        $domain = substr(strrchr($validated['email'], "@"), 1);
        $mapping = DomainMapping::where('domain', $domain)->first();

        if ($mapping) {
            if ($mapping->role_id) $user->roles()->syncWithoutDetaching([$mapping->role_id]);
            if ($mapping->gallery_group_id) $user->galleryGroups()->syncWithoutDetaching([$mapping->gallery_group_id]);
        }

        $token = Str::random(64);
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $user->email],
            ['token' => Hash::make($token), 'created_at' => now()]
        );

        $frontendUrl = rtrim(config('app.url'), '/');
        $link = $frontendUrl . '/reset-password?token=' . $token . '&email=' . urlencode($user->email);
        
        $html = "<div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;'>
                    <h2 style='color: #2A9D8F;'>Hallo {$user->name},</h2>
                    <p>Willkommen! Um deinen Account zu aktivieren und ein sicheres Passwort zu vergeben, klicke bitte auf den folgenden Button:</p>
                    <p style='margin: 30px 0;'>
                        <a href='{$link}' style='background-color: #2A9D8F; color: white; padding: 12px 24px; text-decoration: none; border-radius: 4px; font-weight: bold;'>Account aktivieren</a>
                    </p>
                 </div>";

        Mail::html($html, function($msg) use ($user) {
            $msg->to($user->email)->subject('Account aktivieren');
        });

        return response()->json(['success' => true, 'message' => 'Registrierung erfolgreich. Bitte prüfe deine E-Mails.']);
    }

    public function resetPassword(Request $request) 
    {
        $request->validate([
            'email' => 'required|email',
            'token' => 'required|string',
            'password' => 'required|string|min:8'
        ]);

        $record = DB::table('password_reset_tokens')->where('email', $request->email)->first();
        
        if (!$record || !Hash::check($request->token, $record->token)) {
            return response()->json(['error' => 'Der Setup-Link ist ungültig oder abgelaufen.'], 400);
        }

        $user = User::where('email', $request->email)->first();
        if (!$user) {
            return response()->json(['error' => 'User nicht gefunden.'], 404);
        }

        $user->password = Hash::make($request->password);
        $user->save();
        
        DB::table('password_reset_tokens')->where('email', $request->email)->delete();
        
        return response()->json(['success' => true]);
    }

    public function me()
    {
        $user = Auth::guard('api')->user();
        
        if (!$user->is_admin) {
            $user->load('galleries');
        }
        $user->load('roles');

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'is_admin' => $user->is_admin,
            'is_photographer' => $user->is_photographer,
            'is_pending' => $user->is_pending,
            'roles' => $user->roles->pluck('name'),
            'my_galleries' => $user->galleries ?? []
        ]);
    }

    public function refresh()
    {
        try {
            $token = Auth::guard('api')->refresh();
            return $this->respondWithToken($token);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Token konnte nicht aktualisiert werden.'], 401);
        }
    }

    public function logout()
    {
        Auth::guard('api')->logout();
        $cookie = cookie()->forget('rp_jwt');
        return response()->json(['message' => 'Successfully logged out'])->withCookie($cookie);
    }

    protected function respondWithToken($token)
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

        return response()->json([
            'success' => true,
            'expires_in' => $ttl * 60
        ])->withCookie($cookie);
    }
}
