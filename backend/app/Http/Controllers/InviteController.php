<?php

namespace App\Http\Controllers;

use App\Http\Requests\GenerateInviteRequest;
use App\Http\Requests\RedeemInviteRequest;
use App\Http\Requests\SendInviteEmailRequest;
use App\Mail\GalleryInviteMail;
use App\Models\Gallery;
use App\Models\GalleryInvite;
use App\Models\User;
use App\Services\AuthorizationService;
use App\Support\BrandRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Factory;
use PHPOpenSourceSaver\JWTAuth\JWTAuth;

class InviteController extends Controller
{
    public function generate(GenerateInviteRequest $request, $galleryId)
    {
        $gallery = Gallery::findOrFail($galleryId);
        if (! BrandRegistry::galleryTreeMatchesCurrent($gallery)) {
            return response()->json(['error' => 'Galerie nicht gefunden.'], 404);
        }
        if (Gate::denies('manage', $gallery)) {
            return response()->json(['error' => 'Keine Berechtigung'], 403);
        }

        $validated = $request->validated();
        $token = Str::random(64);

        GalleryInvite::create([
            'gallery_id' => $gallery->id,
            'token' => $token,
            'name' => $validated['name'] ?? null,
            'can_edit_metadata' => $validated['can_edit_metadata'] ?? false,
        ]);

        return response()->json([
            'success' => true,
            'link' => BrandRegistry::frontendUrl().'/invite/'.$token,
        ]);
    }

    public function sendEmail(SendInviteEmailRequest $request, $galleryId)
    {
        $gallery = Gallery::findOrFail($galleryId);
        if (! BrandRegistry::galleryTreeMatchesCurrent($gallery)) {
            return response()->json(['error' => 'Galerie nicht gefunden.'], 404);
        }
        if (Gate::denies('manage', $gallery)) {
            return response()->json(['error' => 'Keine Berechtigung'], 403);
        }

        $validated = $request->validated();

        DB::transaction(function () use ($gallery, $validated) {
            $token = Str::random(64);

            GalleryInvite::create([
                'gallery_id' => $gallery->id,
                'token' => $token,
                'name' => $validated['name'] ?? null,
            ]);

            $link = BrandRegistry::frontendUrl().'/invite/'.$token;
            Mail::to($validated['email'])->send(new GalleryInviteMail($gallery->name, $link));
        });

        return response()->json(['success' => true]);
    }

    public function check($token)
    {
        $invite = GalleryInvite::where('token', $token)->with('gallery')->firstOrFail();
        if (! $invite->gallery || ! BrandRegistry::galleryTreeMatchesCurrent($invite->gallery)) {
            return response()->json(['error' => 'Einladung nicht gefunden.'], 404);
        }

        return response()->json([
            'gallery_name' => $invite->gallery->name,
            'requires_password' => ! empty($invite->gallery->password_hash),
            'invite_name' => $invite->name,
        ]);
    }

    public function redeem(RedeemInviteRequest $request)
    {
        $validated = $request->validated();

        $invite = GalleryInvite::where('token', $validated['token'])->with('gallery')->firstOrFail();
        $gallery = $invite->gallery;
        if (! $gallery || ! BrandRegistry::galleryTreeMatchesCurrent($gallery)) {
            return response()->json(['error' => 'Einladung nicht gefunden.'], 404);
        }

        if ($gallery->password_hash && ! Hash::check($validated['password'] ?? null, $gallery->password_hash)) {
            return response()->json(['error' => 'Das Galerie-Passwort ist nicht korrekt.'], 403);
        }

        $guard = Auth::guard('api');
        $currentUser = $guard->user();
        if (! $currentUser && $request->hasCookie('rp_jwt')) {
            try {
                $currentUser = $guard->setToken($request->cookie('rp_jwt'))->user();
            } catch (\Exception $e) {
                // Ignore invalid/expired token gracefully
            }
        }

        if ($currentUser) {
            $authorization = app(AuthorizationService::class);
            if ($authorization->isReservedNullBrandActor($currentUser)) {
                return response()->json(['error' => 'Keine Berechtigung für diese Einladung.'], 403);
            }

            if (! $authorization->isTransientGuest($currentUser)
                && ! $authorization->sharesBrand($currentUser, $gallery->brand)) {
                return response()->json(['error' => 'Keine Berechtigung für diese Einladung.'], 403);
            }
        }

        $transientGalleries = [(string) $gallery->id];
        $transientMetaGalleries = $invite->can_edit_metadata ? [(string) $gallery->id] : [];

        if ($currentUser) {
            $payload = $guard->payload();
            $transientClaims = [
                'transient_galleries' => $payload->get('transient_galleries'),
                'transient_meta_galleries' => $payload->get('transient_meta_galleries'),
            ];

            foreach ([
                'transient_invites',
                'transient_invite_ids',
            ] as $claim) {
                if ($payload->hasKey($claim)) {
                    $transientClaims[$claim] = $payload->get($claim);
                }
            }

            $existingGalleries = is_array($transientClaims['transient_galleries'])
                ? $transientClaims['transient_galleries']
                : [];
            $existingMetaGalleries = is_array($transientClaims['transient_meta_galleries'])
                ? $transientClaims['transient_meta_galleries']
                : [];
            $transientClaims['transient_galleries'] = array_values(array_unique(array_merge(
                $existingGalleries,
                $transientGalleries
            )));
            $transientClaims['transient_meta_galleries'] = array_values(array_unique(array_merge(
                $existingMetaGalleries,
                $transientMetaGalleries
            )));

            $inviteId = (string) $invite->getKey();
            $existingInvites = is_array($transientClaims['transient_invites'] ?? null)
                ? $transientClaims['transient_invites']
                : [];
            $existingInvites[$inviteId] = [
                'gallery_id' => (string) $gallery->getKey(),
                'can_edit_metadata' => (bool) $invite->can_edit_metadata,
            ];
            $transientClaims['transient_invites'] = $existingInvites;
            $transientClaims['transient_invite_ids'] = array_values(array_unique(array_merge(
                is_array($transientClaims['transient_invite_ids'] ?? null)
                    ? $transientClaims['transient_invite_ids']
                    : [],
                [$inviteId]
            )));

            // Sanitize before issuing a replacement token as well. This drops
            // grants whose invite was revoked while the current JWT was alive.
            //
            // FINAL-6: a grant that is only invisible from this host is carried
            // through rather than burned, because the replacement token outlives
            // this request and a host mismatch is a viewing-context problem, not
            // a revocation. The server still has to be on the invite's host for
            // the grant to become usable.
            $transientClaims = app(AuthorizationService::class)->sanitizeTransientClaims(
                $transientClaims,
                true,
            );
            $token = $guard->claims($transientClaims)->login($currentUser);

            return $this->respondWithToken($token, ['full_path' => $gallery->full_path]);
        }

        // Anonymous Guest
        $guestName = $invite->name ?? $validated['name'] ?? 'Gast';
        $guestEmail = $validated['email'] ?? null;
        $guestId = (string) Str::uuid();

        if ($guestEmail) {
            $realUser = User::where('email', $guestEmail)->first();
            if ($realUser) {
                return response()->json(['error' => 'Diese E-Mail ist bereits mit einem Passwort registriert. Bitte logge dich regulär ein.'], 403);
            }
        }

        $factory = app(Factory::class);
        $payload = $factory->customClaims([
            'sub' => 'guest_'.$guestId,
            'guest_id' => $guestId,
            'guest_name' => $guestName,
            'guest_invite_id' => $invite->id,
            'transient_galleries' => $transientGalleries,
            'transient_meta_galleries' => $transientMetaGalleries,
        ])->make();
        $token = app(JWTAuth::class)->encode($payload)->get();

        return $this->respondWithToken($token, ['full_path' => $gallery->full_path]);
    }

    public function index($galleryId)
    {
        $gallery = Gallery::findOrFail($galleryId);
        if (! BrandRegistry::galleryTreeMatchesCurrent($gallery)) {
            return response()->json(['error' => 'Galerie nicht gefunden.'], 404);
        }
        if (Gate::denies('manage', $gallery)) {
            return response()->json(['error' => 'Keine Berechtigung'], 403);
        }

        return response()->json(GalleryInvite::where('gallery_id', $galleryId)->orderBy('id', 'desc')->get());
    }

    public function update(Request $request, $id)
    {
        $invite = GalleryInvite::with('gallery')->findOrFail($id);
        if (! $invite->gallery || ! BrandRegistry::galleryTreeMatchesCurrent($invite->gallery)) {
            return response()->json(['error' => 'Einladung nicht gefunden.'], 404);
        }
        if (Gate::denies('manage', $invite->gallery)) {
            return response()->json(['error' => 'Keine Berechtigung'], 403);
        }
        $request->validate(['name' => 'nullable|string|max:255']);
        $invite->update(['name' => $request->name]);

        return response()->json(['success' => true]);
    }

    public function destroy($id)
    {
        $invite = GalleryInvite::with('gallery')->findOrFail($id);
        if (! $invite->gallery || ! BrandRegistry::galleryTreeMatchesCurrent($invite->gallery)) {
            return response()->json(['error' => 'Einladung nicht gefunden.'], 404);
        }
        if (Gate::denies('manage', $invite->gallery)) {
            return response()->json(['error' => 'Keine Berechtigung'], 403);
        }

        GalleryInvite::destroy($id);

        $ttl = Auth::guard('api')->factory()->getTTL();
        $ttl = is_numeric($ttl) ? max(1, (int) $ttl) : 240;
        // The same marker is checked for guest_invite_id and registered
        // transient_invite_ids. It is intentionally independent of the token
        // subject so one invite can be revoked for every issued session.
        Cache::put('blacklisted_invite_'.$id, true, now()->addMinutes($ttl));

        return response()->json(['success' => true]);
    }
}
