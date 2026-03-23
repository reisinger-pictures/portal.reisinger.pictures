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

class InviteController extends Controller
{
    public function generate(Request $request, $galleryId)
    {
        $request->validate([
            'name' => 'nullable|string|max:255'
        ]);

        $gallery = Gallery::findOrFail($galleryId);
        $token = Str::random(64);
        
        GalleryInvite::create([
            'gallery_id' => $gallery->id,
            'token' => $token,
            'name' => $request->name
        ]);

        return response()->json([
            'success' => true,
            'link' => rtrim(config('app.frontend_url'), '/') . '/invite/' . $token
        ]);
    }

    public function sendEmail(Request $request, $galleryId)
    {
        $request->validate([
            'email' => 'required|email',
            'name' => 'nullable|string|max:255'
        ]);

        $gallery = Gallery::findOrFail($galleryId);
        
        \Illuminate\Support\Facades\DB::transaction(function () use ($gallery, $request) {
            $token = Str::random(64);
            
            GalleryInvite::create([
                'gallery_id' => $gallery->id,
                'token' => $token,
                'name' => $request->name
            ]);

            $link = rtrim(config('app.frontend_url'), '/') . '/invite/' . $token;
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
            'password' => 'nullable|string'
        ]);

        $invite = GalleryInvite::where('token', $request->token)->with('gallery')->firstOrFail();
        $gallery = $invite->gallery;

        if ($gallery->password_hash && !Hash::check($request->password, $gallery->password_hash)) {
            return response()->json(['error' => 'Das Galerie-Passwort ist nicht korrekt.'], 403);
        }

        if ($invite->name) {
            // Benannter Invite: Erzeuge Dummy-Account on-the-fly
            $email = Str::slug($invite->name) . '-' . substr($invite->token, 0, 8) . '@invite.local';
            $user = User::firstOrCreate(
                ['email' => $email],
                ['name' => $invite->name]
            );
        } else {
            // Klassischer anonymer Invite: E-Mail & Name sind zwingend
            if (!$request->email || !$request->name) {
                return response()->json(['error' => 'Name und E-Mail sind erforderlich.'], 400);
            }

            $user = User::where('email', $request->email)->first();
            
            if ($user) {
                // Prevent Account Takeover
                if ($user->password || $user->is_admin) {
                    return response()->json(['error' => 'Diese E-Mail ist bereits mit einem Passwort registriert. Bitte logge dich regulär ein.'], 403);
                }
            } else {
                $user = User::create([
                    'email' => $request->email,
                    'name' => $request->name
                ]);
            }
        }

        $user->galleries()->syncWithoutDetaching([$gallery->id]);

        $jwt = Auth::guard('api')->login($user);

        return $this->respondWithToken($jwt, [
            'full_path' => $gallery->full_path
        ]);
    }

    public function index($galleryId)
    {
        return response()->json(\App\Models\GalleryInvite::where('gallery_id', $galleryId)->orderBy('id', 'desc')->get());
    }

    public function update(Request $request, $id)
    {
        $request->validate(['name' => 'nullable|string|max:255']);
        $invite = \App\Models\GalleryInvite::findOrFail($id);
        $invite->update(['name' => $request->name]);
        return response()->json(['success' => true]);
    }

    public function destroy($id)
    {
        \App\Models\GalleryInvite::destroy($id);
        return response()->json(['success' => true]);
    }
}