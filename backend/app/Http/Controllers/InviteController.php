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
                // Token ignorieren, falls abgelaufen/ungültig
            }
        }
        if ($currentUser) {
            $currentUser->galleries()->syncWithoutDetaching([$gallery->id]);
            return response()->json([
                'success' => true,
                'full_path' => $gallery->full_path
            ]);
        }

        if ($invite->name) {
            // Benannter Invite: Erzeuge Dummy-Account on-the-fly und logge direkt ein
            $email = \Illuminate\Support\Str::slug($invite->name) . '-' . substr($invite->token, 0, 8) . '@invite.local';
            $user = \App\Models\User::firstOrCreate(['email' => $email], ['name' => $invite->name]);
            $user->galleries()->syncWithoutDetaching([$gallery->id]);
            $jwt = \Illuminate\Support\Facades\Auth::guard('api')->login($user);
            return $this->respondWithToken($jwt, ['full_path' => $gallery->full_path]);
        } else {
            // Klassischer anonymer Invite: E-Mail & Name erfassen, dann Magic-Link schicken
            if (!$request->email || !$request->name) {
                return response()->json(['error' => 'Name und E-Mail sind erforderlich.'], 400);
            }
            $user = \App\Models\User::where('email', $request->email)->first();
            if ($user) {
                if ($user->password || $user->is_admin) {
                    return response()->json(['error' => 'Diese E-Mail ist bereits mit einem Passwort registriert. Bitte logge dich regulär ein.'], 403);
                }
            } else {
                $user = \App\Models\User::create(['email' => $request->email, 'name' => $request->name]);
            }
            $user->galleries()->syncWithoutDetaching([$gallery->id]);

            // Generiere Token und sende E-Mail
            $resetToken = \Illuminate\Support\Str::random(64);
            \Illuminate\Support\Facades\DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $user->email], ['token' => \Illuminate\Support\Facades\Hash::make($resetToken), 'created_at' => now()]
            );

            $link = rtrim(config('app.frontend_url'), '/') . '/reset-password?token=' . $resetToken . '&email=' . urlencode($user->email);
            $html = "<h2>Hallo {$user->name},</h2><p>Bitte klicke hier, um deine E-Mail zu bestätigen und die Galerie zu öffnen:</p><a href='{$link}'>Zur Galerie</a>";

            \Illuminate\Support\Facades\Mail::html($html, function($msg) use ($user, $gallery) {
                $msg->to($user->email)->subject("Einladung zur Galerie {$gallery->name}");
            });

            return response()->json([
                'success' => true,
                'requires_mail_verification' => true,
                'message' => 'Bitte prüfe deine E-Mails. Wir haben dir einen Link gesendet, um die Anmeldung abzuschließen.'
            ]);
        }
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