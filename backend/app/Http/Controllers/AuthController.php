<?php

namespace App\Http\Controllers;

use App\Enums\AutoJoinPolicy;
use App\Enums\Brand;
use App\Exceptions\FtpCredentialException;
use App\Mail\ActivateAccountMail;
use App\Models\Org;
use App\Models\OrgInvite;
use App\Models\User;
use App\Services\ActivationTokenService;
use App\Services\AIService;
use App\Services\AuthorizationService;
use App\Services\FtpCredentialService;
use App\Services\OrgDefaultRolePolicy;
use App\Support\BrandRegistry;
use App\Support\FtpInboxDirectory;
use App\Support\FtpSlug;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

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

            // The token row is claimed, never overwritten (D-17): a still valid
            // token stays the one the person already holds in their inbox, and
            // no second mail goes out. `resetPassword()` resolves the account by
            // email at click time, so the earlier link activates *this* account —
            // which is why the response stays the same success in both cases.
            $token = app(ActivationTokenService::class)->issue($user->email);

            if ($token !== null) {
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
            }

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
            $defaultRole = app(OrgDefaultRolePolicy::class)->roleForNewAssignment($org);
            $user->roles()->attach($defaultRole->id);

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

        // P1-M21: `ftp_slug` ist der FTP-/SFTP-Accountname, kein Anzeigename.
        // Formatiert wird deshalb nur kosmetisch (Groß-/Kleinschreibung, Umlaute,
        // Leerzeichen) — `Str::slug()` wäre hier falsch, weil es genau die Zeichen
        // verschluckt, die die Formatregel verbietet (`a/b` -> `ab`, `a@b` ->
        // `a-at-b`, `j.doe` -> `jdoe` und damit eine stille Namenskollision).
        // Punkt, `@`, Slash und ein führendes Trennzeichen überstehen die
        // Normalisierung und werden unten abgelehnt: aus einem getippten Login
        // darf nicht stillschweigend ein anderer werden. Das alte `max:255` ist
        // durch die Formatregel (max. 32 Zeichen) überflüssig.
        $requestedSlug = $request->input('ftp_slug');
        $normalizedSlug = is_string($requestedSlug) && $requestedSlug !== ''
            ? FtpSlug::normalize($requestedSlug)
            : $requestedSlug;

        // SEC-20261018-04: only a photographer has a camera account (§7.16 —
        // losing the role revokes it), so only a photographer may change the
        // account name. Checked before validation and before the transaction:
        // a rejected request must not create a directory or reach SFTPGo.
        // Submitting the already-assigned slug is not a change — that path
        // provisions nothing anyway and stays open to everyone.
        if (is_string($normalizedSlug)
            && $normalizedSlug !== ''
            && $normalizedSlug !== $user->ftp_slug
            && ! app(AuthorizationService::class)->isPhotographer($user)
        ) {
            return response()->json([
                'error' => 'Der FTP-Login kann nur von Fotografen geändert werden.',
            ], 403);
        }

        if (is_string($normalizedSlug) && $normalizedSlug !== '') {
            $request->merge(['ftp_slug' => $normalizedSlug]);
        }

        $validated = $request->validate(
            [
                'name' => 'required|string|max:255',
                'metadata_copyright' => 'nullable|string|max:255',
                'ftp_slug' => [
                    'sometimes',
                    'required',
                    'string',
                    'regex:'.FtpSlug::PATTERN,
                    'unique:users,ftp_slug,'.$user->id,
                ],
            ],
            [
                'ftp_slug.regex' => FtpSlug::message(),
            ]
        );

        // Set after the old account is deleted and read after the profile write:
        // the old folder is retained because it may still contain uploads.
        $orphanedInboxPath = null;
        $newSlug = $validated['ftp_slug'] ?? null;
        $credentialResponse = null;

        if (is_string($newSlug)) {
            $credentialSvc = app(FtpCredentialService::class);

            try {
                // Slug-bearing profile writes use the same per-account lock as
                // reset and self-change. The service calls this callback only
                // after a successful old-account delete; only the profile write
                // itself is transactional, never an SFTPGo HTTP call.
                $password = $credentialSvc->changeSlugAndShow(
                    $user,
                    $newSlug,
                    function (User $lockedUser): void {
                        if (! app(AuthorizationService::class)->isPhotographer($lockedUser)) {
                            throw new HttpResponseException(response()->json([
                                'error' => 'Der FTP-Login kann nur von Fotografen geändert werden.',
                            ], 403));
                        }
                    },
                    function (User $lockedUser) use ($validated, $newSlug, &$orphanedInboxPath): void {
                        $oldSlug = $lockedUser->ftp_slug;

                        if (is_string($oldSlug) && $oldSlug !== '' && $oldSlug !== $newSlug) {
                            $oldInboxPath = FtpInboxDirectory::pathFor($oldSlug);
                            if (is_dir($oldInboxPath)) {
                                $orphanedInboxPath = $oldInboxPath;
                            }
                        }

                        DB::transaction(function () use ($lockedUser, $validated): void {
                            if (! $lockedUser->update($validated)) {
                                throw new \RuntimeException('Could not persist the profile update.');
                            }

                            $lockedUser->photos()->searchable();
                        });
                    },
                );

                if ($password !== null) {
                    $credentialResponse = response()->json([
                        'success' => true,
                        'ftp_password' => $password,
                        'ftp_password_note' => 'Dieses Passwort wird nur einmal angezeigt. Speichere es sofort.',
                    ]);
                }
            } catch (FtpCredentialException $exception) {
                if (in_array($exception->reason, [
                    FtpCredentialException::REASON_ROTATION_IN_PROGRESS,
                    FtpCredentialException::REASON_NOT_RESETTABLE,
                ], true)) {
                    return response()->json(['error' => $exception->getMessage()], 409);
                }

                if (in_array($exception->reason, [
                    FtpCredentialException::REASON_UNCREATABLE_INBOX_DIRECTORY,
                    FtpCredentialException::REASON_UNUSABLE_INBOX_PATH,
                ], true)) {
                    return response()->json(['error' => $exception->getMessage()], 503);
                }

                throw $exception;
            } finally {
                if ($orphanedInboxPath !== null) {
                    // The folder can still hold unimported uploads, and removing
                    // it would destroy them. The operator decides what to do.
                    Log::warning('Der FTP-Ordner des vorherigen Logins bleibt erhalten.', ['path' => $orphanedInboxPath]);
                }
            }
        } else {
            DB::transaction(function () use ($user, $validated): void {
                if (! $user->update($validated)) {
                    throw new \RuntimeException('Could not persist the profile update.');
                }

                $user->photos()->searchable();
            });
        }

        return $credentialResponse ?? response()->json(['success' => true]);
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
            // The org admin's delegation boundary, resolved exactly like the
            // reader (getAllowedGalleryIds) so the UI can only offer
            // assignments the server accepts. Null for every other actor.
            'org_scope' => $svc->isOrgAdmin($user) ? [
                'org_id' => (string) $user->org_id,
                'gallery_ids' => $svc->getOrgGalleryScope((string) $user->org_id),
                // Broad group scope: what the organisation may manage
                // (`OrgController::syncGroups`) and the set the reader derives
                // member access from.
                'gallery_group_ids' => $svc->getOrgGroupScope((string) $user->org_id),
                // Narrower set of groups an org admin may newly grant to a
                // user. `UserController::update` enforces exactly this set, so
                // the user editor must offer no more than this — otherwise it
                // presents groups the server answers with 403.
                'assignable_gallery_group_ids' => $svc->getOrgAssignableGalleryGroupIds((string) $user->org_id),
            ] : null,
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
