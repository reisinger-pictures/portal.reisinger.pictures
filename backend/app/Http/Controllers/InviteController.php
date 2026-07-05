<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Gallery;
use App\Models\GalleryInvite;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use App\Mail\GalleryInviteMail;
use App\Support\BrandRegistry;

class InviteController extends Controller
{
    public function generate(Request $request, $galleryId)
    {
        $gallery = Gallery::findOrFail($galleryId);
        if (\Illuminate\Support\Facades\Gate::denies('manage', $gallery)) return response()->json(['error' => 'Keine Berechtigung'], 403);

        $request->validate([
            'name' => 'nullable|string|max:255',
            'can_edit_metadata' => 'boolean'
        ]);

        $token = Str::random(64);
        
        GalleryInvite::create([
            'gallery_id' => $gallery->id,
            'token' => $token,
            'name' => $request->name,
            'can_edit_metadata' => $request->can_edit_metadata ?? false
        ]);

        return response()->json([
            'success' => true,
            'link' => BrandRegistry::frontendUrl() . '/invite/' . $token
        ]);
    }

    public function sendEmail(Request $request, $galleryId)
    {
        $gallery = Gallery::findOrFail($galleryId);
        if (\Illuminate\Support\Facades\Gate::denies('manage', $gallery)) return response()->json(['error' => 'Keine Berechtigung'], 403);

        $request->validate([
            'email' => 'required|email',
            'name' => 'nullable|string|max:255'
        ]);
        
        \Illuminate\Support\Facades\DB::transaction(function () use ($gallery, $request) {
            $token = Str::random(64);
            
            GalleryInvite::create([
                'gallery_id' => $gallery->id,
                'token' => $token,
                'name' => $request->name
            ]);

            $link = BrandRegistry::frontendUrl() . '/invite/' . $token;
            Mail::to($request->email)->send(new GalleryInviteMail($gallery->name, $link));
        });

        return response()->json(['success' => true]);
    }

    public function check($token)
    {
        $invite = GalleryInvite::where('token', $token)->with('gallery')->firstOrFail();
        
        return response()->json([
            'gallery_name' => $invite->gallery->name,
            'requires_password' => !empty($invite->gallery->password_hash),
            'invite_name' => $invite->name
        ]);
    }

    public function redeem(Request $request)
    {
        $request->validate([
            'token' => 'required|string',
            'name' => 'nullable|string',
            'email' => 'nullable|email',
            'password' => 'nullable|string',
            'accept_privacy' => 'required|accepted'
        ]);

        $invite = \App\Models\GalleryInvite::where('token', $request->token)->with('gallery')->firstOrFail();
        $gallery = $invite->gallery;

        if ($gallery->password_hash && !\Illuminate\Support\Facades\Hash::check($request->password, $gallery->password_hash)) {
            return response()->json(['error' => 'Das Galerie-Passwort ist nicht korrekt.'], 403);
        }

        $guard = \Illuminate\Support\Facades\Auth::guard('api');
        $currentUser = $guard->user();
        if (!$currentUser && $request->hasCookie('rp_jwt')) {
            try {
                $currentUser = $guard->setToken($request->cookie('rp_jwt'))->user();
            } catch (\Exception $e) { 
                // Ignore invalid/expired token gracefully
            }
        }

        $transientGalleries = [$gallery->id];
        $transientMetaGalleries = $invite->can_edit_metadata ? [$gallery->id] : [];

        if ($currentUser) {
            $payload = $guard->payload();
            $existing = $payload->get('transient_galleries');
            if (!is_array($existing)) {
                $existing = [];
            }
            $merged = array_values(array_unique(array_merge($existing, $transientGalleries)));
            
            $existingMeta = $payload->get('transient_meta_galleries');
            if (!is_array($existingMeta)) { $existingMeta = []; }
            $mergedMeta = array_values(array_unique(array_merge($existingMeta, $transientMetaGalleries)));
            
            $token = $guard->claims(['transient_galleries' => $merged, 'transient_meta_galleries' => $mergedMeta])->login($currentUser);
            return $this->respondWithToken($token, ['full_path' => $gallery->full_path]);
        }

        // Anonymous Guest
        $guestName = $invite->name ?? $request->name ?? 'Gast';
        $guestEmail = $request->email;
        $guestId = (string) \Illuminate\Support\Str::uuid();

        if ($guestEmail) {
            $realUser = \App\Models\User::where('email', $guestEmail)->first();
            if ($realUser && ($realUser->password || $realUser->is_admin)) {
                return response()->json(['error' => 'Diese E-Mail ist bereits mit einem Passwort registriert. Bitte logge dich regulär ein.'], 403);
            }
        }

        $factory = app(\PHPOpenSourceSaver\JWTAuth\Factory::class);
        $payload = $factory->customClaims([
            'sub' => 'guest_' . $guestId,
            'guest_id' => $guestId,
            'guest_name' => $guestName,
            'transient_galleries' => $transientGalleries,
            'transient_meta_galleries' => $transientMetaGalleries
        ])->make();
        $token = app(\PHPOpenSourceSaver\JWTAuth\JWTAuth::class)->encode($payload)->get();

        return $this->respondWithToken($token, ['full_path' => $gallery->full_path]);
    }

    public function index($galleryId)
    {
        $gallery = Gallery::findOrFail($galleryId);
        if (\Illuminate\Support\Facades\Gate::denies('manage', $gallery)) return response()->json(['error' => 'Keine Berechtigung'], 403);

        return response()->json(\App\Models\GalleryInvite::where('gallery_id', $galleryId)->orderBy('id', 'desc')->get());
    }

    public function update(Request $request, $id)
    {
        $invite = \App\Models\GalleryInvite::with('gallery')->findOrFail($id);
        if (\Illuminate\Support\Facades\Gate::denies('manage', $invite->gallery)) return response()->json(['error' => 'Keine Berechtigung'], 403);
        $request->validate(['name' => 'nullable|string|max:255']);
        $invite->update(['name' => $request->name]);
        return response()->json(['success' => true]);
    }

    public function destroy($id)
    {
        $invite = \App\Models\GalleryInvite::with('gallery')->findOrFail($id);
        if (\Illuminate\Support\Facades\Gate::denies('manage', $invite->gallery)) return response()->json(['error' => 'Keine Berechtigung'], 403);

        \App\Models\GalleryInvite::destroy($id);
        return response()->json(['success' => true]);
    }
}