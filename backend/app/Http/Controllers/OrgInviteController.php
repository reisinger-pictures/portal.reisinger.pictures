<?php

namespace App\Http\Controllers;

use App\Enums\Brand;
use App\Enums\UserRole;
use App\Mail\OrgInviteMail;
use App\Models\Org;
use App\Models\OrgInvite;
use App\Models\Role;
use App\Models\User;
use App\Services\AuthorizationService;
use App\Support\BrandRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class OrgInviteController extends Controller
{
    public function invite(Request $request, $orgId)
    {
        $user = auth('api')->user();
        $org = Org::findOrFail($orgId);

        // Scoped Policy: Nur Admins oder Org-Admin DES Org dürfen einladen
        $svc = app(AuthorizationService::class);
        if (! $svc->isAdmin($user) && ! ($svc->isOrgAdmin($user) && $user->org_id === $orgId)) {
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

        return DB::transaction(function () use ($request, $invite, $user) {
            if (! $user) {
                // Neuen User erstellen
                $user = User::firstOrCreate(
                    ['email' => $invite->email],
                    ['name' => $request->name, 'password' => Hash::make($request->password)]
                );

                if (empty($user->password)) {
                    $user->password = Hash::make($request->password);
                    $user->save();
                }
            }

            // Org-Zuweisung sicherstellen
            $user->org_id = $invite->org_id;
            $user->brand = $invite->org->brand;
            $user->save();

            // Client-Rolle vergeben falls noch keine
            $clientRole = Role::where('name', UserRole::CLIENT->value)->first();
            if ($clientRole && ! $user->roles->contains($clientRole->id)) {
                $user->roles()->syncWithoutDetaching([$clientRole->id]);
            }

            // Token entwerten
            $invite->delete();

            if ($user === auth('api')->user()) {
                // Bereits eingeloggt — Session bleibt bestehen
                return response()->json(['success' => true]);
            }

            // Neuen User einloggen
            Auth::guard('api')->logout();
            $token = Auth::guard('api')->login($user);
            $ttl = Auth::guard('api')->factory()->getTTL();
            $cookie = cookie('rp_jwt', $token, $ttl, '/', null, ! app()->environment('local'), true, false, 'Lax');

            return response()->json(['success' => true])->withCookie($cookie);
        });
    }
}
