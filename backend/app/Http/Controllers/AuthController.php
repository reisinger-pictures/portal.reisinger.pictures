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
use App\Services\AIService;
use App\Enums\Brand;
use App\Support\BrandRegistry;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $credentials = $request->only('email', 'password');

        $user = User::where('email', $credentials['email'])->first();
        if ($user && $user->password && Hash::check($credentials['password'], $user->password)) {
            // U-01: Brand-Mismatch check — cross-brand only for Super-Admin (brand=null).
            $userBrandValue = $user->brand instanceof Brand ? $user->brand->value : $user->brand;
            if ($user->brand !== null && $userBrandValue !== BrandRegistry::currentId()) {
                return response()->json([
                    'error' => 'Dieser Account ist für ein anderes Portal registriert.',
                ], 403);
            }

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
                'brand' => BrandRegistry::currentOrDefault(),
            ]);

            $domain = explode('@', $validated['email'])[1] ?? null;
            $org = $domain ? \App\Models\Org::where('domain', $domain)->first() : null;

            if ($org) {
                // Brand check: org brand must match the current request brand
                $orgBrandValue = $org->brand instanceof Brand ? $org->brand->value : $org->brand;
                if ($org->brand !== null && $orgBrandValue !== BrandRegistry::currentId()) {
                    return response()->json(['error' => 'Registrierung für diese Domain ist auf diesem Portal nicht möglich.'], 403);
                }

                // Evaluate auto_join_policy
                if ($org->auto_join_policy === \App\Enums\AutoJoinPolicy::REQUIRES_INVITE) {
                    // No auto-join — user must be invited manually
                    // Still attach if there's a pending invite for this email
                    $pendingInvite = \App\Models\OrgInvite::where('email', $validated['email'])
                        ->where('org_id', $org->id)
                        ->where('expires_at', '>', now())
                        ->first();
                    if ($pendingInvite) {
                        $user->org_id = $org->id;
                        $user->save();
                        $pendingInvite->delete();
                    }
                }
                // DISABLED and IMMEDIATE: no auto-join at registration — deferred to password reset
            }

            $token = Str::random(64);
            \Illuminate\Support\Facades\DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $user->email],
                ['token' => Hash::make($token), 'created_at' => now()]
            );

            $link = BrandRegistry::frontendUrl() . '/reset-password?token=' . $token . '&email=' . urlencode($user->email);
            
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
        if ($request->email === config('admin.email')) {
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
            return response()->json(['error' => 'Der Link ist ungültig oder abgelaufen.'], 400);
        }

        // U-01: Brand-Mismatch check — same as in login().
        $userBrandValue = $user->brand instanceof Brand ? $user->brand->value : $user->brand;
        if ($user->brand !== null && $userBrandValue !== BrandRegistry::currentId()) {
            return response()->json([
                'error' => 'Dieser Account ist für ein anderes Portal registriert.',
            ], 403);
        }

        $user->password = Hash::make($request->password);
        $user->save();
        
        DB::table('password_reset_tokens')->where('email', $request->email)->delete();

        // Deferred auto-join: after successful password reset (proves email ownership),
        // re-lookup org by domain and apply immediate auto-join if configured.
        $emailParts = explode('@', $user->email);
        $domain = $emailParts[1] ?? null;
        $org = $domain ? \App\Models\Org::where('domain', $domain)->first() : null;
        if ($org && $org->auto_join_policy === \App\Enums\AutoJoinPolicy::IMMEDIATE && !$user->org_id && ($org->brand === null || ($org->brand instanceof Brand ? $org->brand->value : $org->brand) === BrandRegistry::currentId())) {
            // Assign role from org's default_role_id, fallback to client
            $roleId = $org->default_role_id;
            if (!$roleId) {
                $clientRole = \App\Models\Role::where('name', \App\Enums\UserRole::CLIENT->value)->first();
                throw_unless($clientRole, \RuntimeException::class, 'Critical: Default CLIENT role missing in database.');
                $roleId = $clientRole->id;
            }
            if ($roleId) {
                $user->roles()->attach($roleId);
            }

            // Inherit flatrate settings from org
            if ($org->default_flatrate_level) {
                $user->flatrate_level = $org->default_flatrate_level;
            }
            if ($org->can_purchase_upgrades) {
                $user->can_purchase_upgrades = true;
            }

            $user->org_id = $org->id;
            $user->save();
        }

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

        $missingWatermark = false;
        if ($user->is_super_admin) {
            $disk = \Illuminate\Support\Facades\Storage::disk('photos');
            if (!$disk->exists('_watermarks/master_500.png') || !$disk->exists('_watermarks/watermark.svg')) {
                $missingWatermark = true;
            }
        }

        return response()->json([
            'id' => $user->guest_id ?: $user->id,
            'guest_id' => $user->guest_id,
            'name' => $user->name,
            'email' => $user->email,
            'metadata_copyright' => $user->metadata_copyright,
            'ftp_slug' => $user->ftp_slug,
            'flatrate_level' => $user->flatrate_level,

            'brand' => $user->brand instanceof Brand ? $user->brand->value : $user->brand,
            'is_cross_brand' => $user->brand === null,

            'is_super_admin' => $user->is_super_admin,
            'is_admin' => $user->is_admin,
            'is_photographer' => $user->is_photographer,

            'is_org_admin' => $user->is_org_admin,
            'is_power_user' => $user->is_power_user,
            'is_pending' => $user->is_pending,
            'roles' => $user->roles->pluck('name'),
            'missing_watermark' => $missingWatermark,
            'ai_is_unconfigured' => app(AIService::class)->isUnconfigured(),
            'transient_galleries' => $user->transient_galleries ?? [],
            'transient_meta_galleries' => $user->transient_meta_galleries ?? [],
            'my_galleries' => $user->galleries ?? [],
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
