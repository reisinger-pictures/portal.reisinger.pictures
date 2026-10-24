<?php

namespace App\Http\Controllers;

use App\Enums\Brand;
use App\Http\Controllers\Concerns\EnforcesBrandIsolation;
use App\Mail\OrgInviteMail;
use App\Models\Org;
use App\Models\OrgInvite;
use App\Models\User;
use App\Services\AuthorizationService;
use App\Services\OrgDefaultRolePolicy;
use App\Support\BrandRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class OrgInviteController extends Controller
{
    use EnforcesBrandIsolation;

    public function invite(Request $request, $orgId)
    {
        $user = auth('api')->user();
        $org = Org::findOrFail($orgId);

        if ($this->brandValue($org) === null) {
            return response()->json(['error' => 'Keine Berechtigung, Nutzer in diese Organisation einzuladen.'], 403);
        }

        // Scoped Policy: Nur Admins oder Org-Admin DES Org dürfen einladen
        $svc = app(AuthorizationService::class);
        if (! $svc->isAdmin($user) && ! ($svc->isOrgAdmin($user) && $user->org_id === $orgId)) {
            return response()->json(['error' => 'Keine Berechtigung, Nutzer in diese Organisation einzuladen.'], 403);
        }

        // Brand isolation: a brand-bound actor may only invite into orgs of their own brand.
        if ($this->isBrandMismatch($user, $org)) {
            return response()->json(['error' => 'Keine Berechtigung, Nutzer in diese Organisation einzuladen.'], 403);
        }

        $request->validate([
            'email' => 'required|email',
        ]);

        $token = Str::random(64);

        $invite = OrgInvite::create([
            'email' => $request->email,
            'org_id' => $org->id,
            'token' => $token,
            'expires_at' => now()->addDays(7),
        ]);

        $orgBrand = $org->brand instanceof Brand ? $org->brand : BrandRegistry::currentOrDefault();
        $link = BrandRegistry::frontendUrl($orgBrand).'/org-invite/'.$token;
        Mail::to($request->email)->queue(new OrgInviteMail($org->name, $link));

        return response()->json(['success' => true]);
    }

    public function check($token)
    {
        $invite = OrgInvite::where('token', $token)
            ->where('expires_at', '>', now())
            ->with('org')
            ->firstOrFail();

        // Org invite links are host-bound. A legacy null/foreign org must not
        // be redeemed merely because its token is still present in the table.
        if (! $invite->org || ! BrandRegistry::resourceMatchesCurrent($invite->org->brand)) {
            return response()->json(['error' => 'Einladung nicht gefunden.'], 404);
        }

        return response()->json([
            'org_name' => $invite->org->name,
            'email' => $invite->email,
        ]);
    }

    public function redeem(Request $request)
    {
        $user = auth('api')->user();

        if ($user) {
            $request->validate([
                'token' => 'required|string',
                'accept_privacy' => 'required|accepted',
            ]);
        } else {
            $request->validate([
                'token' => 'required|string',
                'name' => 'required|string|max:255',
                'password' => 'required|string|min:8',
                'accept_privacy' => 'required|accepted',
            ]);
        }

        $invite = OrgInvite::where('token', $request->token)
            ->where('expires_at', '>', now())
            ->with('org')
            ->firstOrFail();

        // A legacy org without a concrete brand is not a valid invite target.
        // Resolve the brand before any user row is created; never copy NULL
        // into a new non-Super-Admin account.
        $inviteBrand = $this->brandValue($invite->org);
        if (! $invite->org || $inviteBrand === null || ! BrandRegistry::resourceMatchesCurrent($inviteBrand)) {
            return response()->json(['error' => 'Keine Berechtigung für diese Einladung.'], 403);
        }

        // Brand isolation: the invite belongs to the org of a specific brand. A
        // brand-bound actor may only redeem an invite of their own brand — this
        // also blocks a brand-bound actor from being flipped to cross-brand via a
        // brand-less org invite. A trusted null-brand Super-Admin may act across
        // brands (magic-link trust: holding the token is the credential).
        if ($user && $this->isBrandMismatch($user, $invite->org)) {
            return response()->json(['error' => 'Keine Berechtigung für diese Einladung.'], 403);
        }

        $isAuthenticatedUser = $user !== null;
        $authenticatedUserId = $user?->getKey();

        return DB::transaction(function () use ($request, $invite, $isAuthenticatedUser, $authenticatedUserId) {
            // Keep the same org -> user lock order as membership sync. Accepting
            // this invite is explicit consent to switch organizations.
            $org = Org::query()->whereKey($invite->org_id)->lockForUpdate()->first();
            if (! $org) {
                return response()->json(['error' => 'Einladung nicht gefunden.'], 404);
            }

            $invite = OrgInvite::query()
                ->whereKey($invite->getKey())
                ->where('token', $request->token)
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->first();
            if (! $invite) {
                return response()->json(['error' => 'Einladung nicht gefunden.'], 404);
            }
            $invite->setRelation('org', $org);

            $inviteBrand = $this->brandValue($org);
            if ($inviteBrand === null || ! BrandRegistry::resourceMatchesCurrent($inviteBrand)) {
                return response()->json(['error' => 'Keine Berechtigung für diese Einladung.'], 403);
            }

            if ($isAuthenticatedUser) {
                $user = User::query()
                    ->whereKey($authenticatedUserId)
                    ->lockForUpdate()
                    ->first();
                if (! $user) {
                    return response()->json(['error' => 'Unauthenticated'], 401);
                }

                if ($this->isBrandMismatch($user, $org)) {
                    return response()->json(['error' => 'Keine Berechtigung für diese Einladung.'], 403);
                }
            } else {
                // Existing accounts may explicitly switch organizations by
                // accepting this invite, but never across the existing brand
                // isolation boundary.
                $user = User::query()
                    ->where('email', $invite->email)
                    ->lockForUpdate()
                    ->first();

                if ($user && $this->isBrandMismatch($user, $org)) {
                    return response()->json(['error' => 'Keine Berechtigung für diese Einladung.'], 403);
                }

                $user ??= User::create([
                    'name' => $request->name,
                    'email' => $invite->email,
                    'password' => Hash::make($request->password),
                    'brand' => $inviteBrand,
                ]);

                if (empty($user->password)) {
                    $user->password = Hash::make($request->password);
                    $user->save();
                }
            }

            $sameOrg = (string) $user->org_id === (string) $org->id;
            if (! $sameOrg) {
                $defaultRolePolicy = app(OrgDefaultRolePolicy::class);

                // Remove the previous organization's delegated authority before
                // changing org_id, then grant only the destination default role.
                $defaultRolePolicy->assignRoleForNewAssignment($user, $org);
                $user->org_id = $org->id;
                $user->brand = $inviteBrand;
                $user->save();
            }

            // Token consumption follows every validation and membership write,
            // so a rejected brand-bound redemption leaves it available.
            $invite->delete();

            if ($isAuthenticatedUser) {
                return response()->json(['success' => true]);
            }

            Auth::guard('api')->logout();
            $token = Auth::guard('api')->login($user);

            return $this->respondWithToken($token);
        });
    }
}
