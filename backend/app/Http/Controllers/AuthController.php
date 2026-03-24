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

        return \Illuminate\Support\Facades\DB::transaction(function () use ($validated) {
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
            \Illuminate\Support\Facades\DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $user->email],
                ['token' => Hash::make($token), 'created_at' => now()]
            );

            $frontendUrl = rtrim(config('app.frontend_url', config('app.url')), '/');
            $link = $frontendUrl . '/reset-password?token=' . $token . '&email=' . urlencode($user->email);
            $logoUrl = $frontendUrl . '/android-chrome-192x192.png';
            
            $html = "
            <table width='100%' cellpadding='0' cellspacing='0' border='0' style='background-color: #f4f4f4; padding: 20px; font-family: Arial, sans-serif;'>
                <tr><td align='center'>
                    <table width='100%' cellpadding='0' cellspacing='0' border='0' style='max-width: 600px; background-color: #ffffff; border: 1px solid #e0e0e0;'>
                        <tr><td align='center' style='padding: 30px 20px 10px 20px;'>
                            <img src='{$logoUrl}' alt='Logo' width='64' height='64' style='display: block; border-radius: 8px;' />
                        </td></tr>
                        <tr><td style='padding: 20px 30px 30px 30px;'>
                            <h2 style='color: #2A9D8F; margin-top: 0;'>Hallo {$user->name},</h2>
                            <p style='color: #333333; line-height: 1.6; margin-bottom: 20px;'>Willkommen! Um deinen Account zu aktivieren und ein sicheres Passwort zu vergeben, klicke bitte auf den folgenden Button:</p>
                            <table width='100%' cellpadding='0' cellspacing='0' border='0'>
                                <tr><td align='center'>
                                    <a href='{$link}' style='background-color: #2A9D8F; color: #ffffff; padding: 12px 24px; text-decoration: none; font-weight: bold; display: inline-block;'>Account aktivieren</a>
                                </td></tr>
                            </table>
                        </td></tr>
                    </table>
                </td></tr>
            </table>";

            Mail::html($html, function($msg) use ($user) {
                $msg->to($user->email)->subject('Account aktivieren');
            });

            return response()->json(['success' => true, 'message' => 'Registrierung erfolgreich. Bitte prüfe deine E-Mails.']);
        });
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
        
        $token = Auth::guard('api')->login($user);
        return $this->respondWithToken($token);
    }

    public function updateProfile(Request $request)
    {
        $user = Auth::guard('api')->user();
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'metadata_copyright' => 'nullable|string|max:255'
        ]);
        \Illuminate\Support\Facades\DB::transaction(function () use ($user, $validated) {
            $user->update($validated);
            $user->photos()->searchable();
        });
        return response()->json(['success' => true]);
    }

    public function me()
    {
        $user = Auth::guard('api')->user();
        
        // Immer Galerien laden, da auch Admins und Fotografen spezifische Zuweisungen haben können
        $user->load(['galleries', 'roles', 'galleryGroups']);
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

    
}
