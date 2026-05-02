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
            $tenant = \App\Models\Tenant::where('domain', $domain)->first();

            if ($tenant) {
                // Auto-Join the user to the matching Tenant
                $user->tenants()->attach($tenant->id);
                // Assign Standard-Client Role by default to protect billing
                $clientRole = \App\Models\Role::where('name', \App\Enums\UserRole::CLIENT->value)->first();
                if ($clientRole) {
                    $user->roles()->attach($clientRole->id);
                }
            }

            $token = Str::random(64);
            \Illuminate\Support\Facades\DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $user->email],
                ['token' => Hash::make($token), 'created_at' => now()]
            );

            $frontendUrl = rtrim(config('app.frontend_url', config('app.url')), '/');
            $link = $frontendUrl . '/reset-password?token=' . $token . '&email=' . urlencode($user->email);
            
            \Illuminate\Support\Facades\Mail::to($user->email)->send(
                new \App\Mail\ActivateAccountMail(
                    $user->name,
                    'Willkommen! Um deinen Account zu aktivieren und ein sicheres Passwort zu vergeben, klicke bitte auf den folgenden Button:',
                    $link,
                    'Account aktivieren',
                    'Account aktivieren'
                )
            );

            return response()->json(['success' => true, 'message' => 'Registrierung erfolgreich. Bitte prüfe deine E-Mails.']);
        });
    }

    public function resetPassword(Request $request) 
    {
        if ($request->email === env('ADMIN_EMAIL', 'florian@reisinger.pictures')) {
            return response()->json(['error' => 'Passwort-Reset für den System-Admin ist deaktiviert.'], 403);
        }

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
        $user = \Illuminate\Support\Facades\Auth::guard('api')->user();
        
        // Zwingend formatieren, bevor die Validation (und Unique-Regel) greift!
        if ($request->has('ftp_slug') && !empty($request->input('ftp_slug'))) {
            $request->merge([
                'ftp_slug' => \Illuminate\Support\Str::slug($request->input('ftp_slug'))
            ]);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'metadata_copyright' => 'nullable|string|max:255',
            'ftp_slug' => 'sometimes|required|string|max:255|unique:users,ftp_slug,' . $user->id
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
        $user->load(['galleries', 'roles', 'galleryGroups', 'photographerGalleries', 'photographerGalleryGroups']);
        $user->load('roles');

        $missingWatermark = false;
        if ($user->is_super_admin) {
            $disk = \Illuminate\Support\Facades\Storage::disk('photos');
            if (!$disk->exists('_watermarks/master_500.png') || !$disk->exists('_watermarks/watermark.svg')) {
                $missingWatermark = true;
            }
        }

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'metadata_copyright' => $user->metadata_copyright,
            'ftp_slug' => $user->ftp_slug,
            'flatrate_level' => $user->flatrate_level,
            
            'is_super_admin' => $user->is_super_admin,
            'is_admin' => $user->is_admin,
            'is_photographer' => $user->is_photographer,
            'is_customer_manager' => $user->is_customer_manager,
            'is_power_user' => $user->is_power_user,
            'is_pending' => $user->is_pending,
            'roles' => $user->roles->pluck('name'),
            'missing_watermark' => $missingWatermark,
            'transient_meta_galleries' => $user->transient_meta_galleries ?? [],
            'my_galleries' => $user->galleries ?? [],
            'photographer_galleries' => $user->photographerGalleries ?? [],
            'photographer_gallery_groups' => $user->photographerGalleryGroups ?? [],
            'photographer_galleries' => $user->photographerGalleries ?? [],
            'photographer_gallery_groups' => $user->photographerGalleryGroups ?? []
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
