<?php

namespace App\Http\Controllers;

use App\Enums\AutoJoinPolicy;
use App\Enums\Brand;
use App\Enums\UserRole;
use App\Mail\ActivateAccountMail;
use App\Models\Org;
use App\Models\OrgInvite;
use App\Models\Role;
use App\Models\User;
use App\Services\AIService;
use App\Services\AuthorizationService;
use App\Support\BrandRegistry;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $credentials['email'])->first();
        if ($user && $user->password && Hash::check($credentials['password'], $user->password)) {
            $authorization = app(AuthorizationService::class);
            if ($authorization->isReservedNullBrandActor($user)) {
                return response()->json([
                    'error' => 'Dieser Account ist für ein anderes Portal registriert.',
                ], 403);
            }

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

        return DB::transaction(function () use ($validated) {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => null, // Initial passwortlos!
                'brand' => BrandRegistry::currentOrDefault(),
            ]);

            $domain = explode('@', $validated['email'])[1] ?? null;
            $org = $domain ? Org::where('domain', $domain)->first() : null;

            if ($org) {
                // Brand check: an org without a concrete current-host brand is
                // legacy data and cannot be used as a registration authority.
                if (! BrandRegistry::resourceMatchesCurrent($org->brand)) {
                    // Throwing a response exception is intentional: returning a
                    // response from a transaction commits everything written so far.
                    // The user insert above must therefore be rolled back before the
                    // 403 reaches the client.
                    throw new HttpResponseException(response()->json([
                        'error' => 'Registrierung für diese Domain ist auf diesem Portal nicht möglich.',
                    ], 403));
                }

                // Evaluate auto_join_policy
                if ($org->auto_join_policy === AutoJoinPolicy::REQUIRES_INVITE) {
                    // No auto-join — user must be invited manually
                    // Still attach if there's a pending invite for this email
                    $pendingInvite = OrgInvite::where('email', $validated['email'])
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
            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $user->email],
                ['token' => Hash::make($token), 'created_at' => now()]
            );

            $link = BrandRegistry::frontendUrl().'/reset-password?token='.$token.'&email='.urlencode($user->email);

            Mail::to($user->email)->send(
                new ActivateAccountMail(
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
        $request->validate([
            'email' => 'required|email',
            'token' => 'required|string',
            'password' => 'required|string|min:8',
        ]);

        $record = DB::table('password_reset_tokens')->where('email', $request->email)->first();

        // Token expiry: password_reset_tokens.created_at is compared against the
        // configured broker lifetime (config('auth.passwords.users.expire')).
        $expireMinutes = (int) config('auth.passwords.users.expire', 60);
        $expired = $record && $record->created_at
            && Carbon::parse($record->created_at)->lt(now()->subMinutes($expireMinutes));

        // The system admin reset is disabled, but it must not be distinguishable
        // from any other invalid/expired token (no account-enumeration oracle).
        $isSystemAdmin = $request->email === config('admin.email');

        if ($isSystemAdmin || $expired || ! $record || ! Hash::check($request->token, $record->token)) {
            return response()->json(['error' => 'Der Setup-Link ist ungültig oder abgelaufen.'], 400);
        }

        $user = User::where('email', $request->email)->first();
        if (! $user) {
            return response()->json(['error' => 'Der Link ist ungültig oder abgelaufen.'], 400);
        }

        // A legacy null-brand account is not a valid password-reset identity.
        // Reject before changing the password or consuming the reset token.
        $authorization = app(AuthorizationService::class);
        if ($authorization->isReservedNullBrandActor($user)) {
            return response()->json([
                'error' => 'Dieser Account ist für ein anderes Portal registriert.',
            ], 403);
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
        $org = $domain ? Org::where('domain', $domain)->first() : null;
        $orgBrandMatches = $org && BrandRegistry::resourceMatchesCurrent($org->brand);
        if ($org && $orgBrandMatches && $org->auto_join_policy === AutoJoinPolicy::IMMEDIATE && ! $user->org_id) {
            // Assign role from org's default_role_id, fallback to client
            $roleId = $org->default_role_id;
            if (! $roleId) {
                $clientRole = Role::where('name', UserRole::CLIENT->value)->first();
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
        $user = Auth::guard('api')->user();

        // Zwingend formatieren, bevor die Validation (und Unique-Regel) greift!
        if ($request->has('ftp_slug') && ! empty($request->input('ftp_slug'))) {
            $request->merge([
                'ftp_slug' => Str::slug($request->input('ftp_slug')),
            ]);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'metadata_copyright' => 'nullable|string|max:255',
            'ftp_slug' => 'sometimes|required|string|max:255|unique:users,ftp_slug,'.$user->id,
        ]);

        DB::transaction(function () use ($user, $validated) {
            $user->update($validated);
            $user->photos()->searchable();
        });

        return response()->json(['success' => true]);
    }

    public function me()
    {
        $user = Auth::guard('api')->user();
        $svc = app(AuthorizationService::class);

        if ($user && $svc->isReservedNullBrandActor($user)) {
            return response()->json(['error' => 'Forbidden (Brand Isolation)'], 403);
        }

        // Immer Galerien laden, da auch Admins und Fotografen spezifische Zuweisungen haben können
        $user->load(['galleries', 'roles', 'galleryGroups', 'photographerGalleries', 'photographerGalleryGroups']);

        $missingWatermark = false;
        if ($svc->isSuperAdmin($user)) {
            $disk = Storage::disk('photos');
            if (! $disk->exists('_watermarks/master_500.png') || ! $disk->exists('_watermarks/watermark.svg')) {
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
            'billing_name' => $user->billing_name,
            'billing_company' => $user->billing_company,
            'billing_street' => $user->billing_street,
            'billing_zip' => $user->billing_zip,
            'billing_city' => $user->billing_city,
            'can_edit_metadata' => (bool) $user->can_edit_metadata,
            'can_purchase_upgrades' => (bool) $user->can_purchase_upgrades,

            'brand' => $user->brand instanceof Brand ? $user->brand->value : $user->brand,
            'is_cross_brand' => $svc->isTrustedCrossBrandActor($user),

            'is_super_admin' => $svc->isSuperAdmin($user),
            'is_admin' => $svc->isAdmin($user),
            'is_photographer' => $svc->isPhotographer($user),

            'is_org_admin' => $svc->isOrgAdmin($user),
            'is_power_user' => $svc->isPowerUser($user),
            'is_pending' => $svc->isPending($user),
            'roles' => $user->roles->pluck('name'),
            'missing_watermark' => $missingWatermark,
            'ai_is_unconfigured' => app(AIService::class)->isUnconfigured(),
            'transient_galleries' => $svc->getActiveTransientGalleryIds($user),
            'transient_meta_galleries' => $svc->getActiveTransientMetaGalleryIds($user),
            'my_galleries' => $user->galleries ?? [],
            'photographer_galleries' => $user->photographerGalleries ?? [],
            'photographer_gallery_groups' => $user->photographerGalleryGroups ?? [],
        ]);
    }

    public function refresh(Request $request)
    {
        $refreshCookieName = (string) config('jwt.refresh_cookie_key_name', 'rp_jwt_refresh');
        $refreshToken = $request->cookie($refreshCookieName);

        if (! is_string($refreshToken) || $refreshToken === '') {
            return response()->json(['error' => 'Token konnte nicht aktualisiert werden.'], 401);
        }

        try {
            // Resolve the subject before refreshing. The refresh endpoint is
            // outside auth:api, so provider-level trust checks would otherwise
            // be skipped for a legacy null-brand account.
            $guard = Auth::guard('api')->setToken($refreshToken);
            $user = $guard->user();
            if (! $user || app(AuthorizationService::class)->isReservedNullBrandActor($user)) {
                return response()->json(['error' => 'Token konnte nicht aktualisiert werden.'], 401);
            }

            // The access cookie is deliberately not used here. It is allowed to
            // expire in the browser, while this httpOnly credential remains
            // refreshable within the configured JWT refresh window.
            $token = $guard->refresh();

            return $this->respondWithToken($token);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Token konnte nicht aktualisiert werden.'], 401);
        }
    }

    public function logout()
    {
        Auth::guard('api')->logout();
        $accessCookie = cookie()->forget((string) config('jwt.cookie_key_name', 'rp_jwt'), '/');
        $refreshCookie = cookie()->forget((string) config('jwt.refresh_cookie_key_name', 'rp_jwt_refresh'), '/');

        return response()->json(['message' => 'Successfully logged out'])
            ->withCookie($accessCookie)
            ->withCookie($refreshCookie);
    }
}
