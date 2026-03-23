<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class UserController extends Controller
{
    public function index()
    {
        return \App\Http\Resources\UserResource::collection(User::with(['roles', 'galleryGroups', 'galleries'])->get());
    }

    public function roles()
    {
        return Role::all();
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email'
        ]);

        return \Illuminate\Support\Facades\DB::transaction(function () use ($request) {
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => null
            ]);

            $token = Str::random(64);
            \Illuminate\Support\Facades\DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $user->email],
                ['token' => Hash::make($token), 'created_at' => now()]
            );

            $frontendUrl = rtrim(config('app.frontend_url'), '/');
            $link = $frontendUrl . '/reset-password?token=' . $token . '&email=' . urlencode($user->email);
            $logoUrl = $frontendUrl . '/android-chrome-192x192.png';
            
            $html = "
            <table width='100%' cellpadding='0' cellspacing='0' border='0' style='background-color: #f4f4f4; padding: 20px; font-family: Arial, sans-serif;'>
                <tr><td align='center'>
                    <table width='100%' cellpadding='0' cellspacing='0' border='0' style='max-width: 600px; background-color: #ffffff; border: 1px solid #e0e0e0;'>
                        <tr><td align='center' style='padding: 30px 20px 10px 20px;'>
                            <img src='{$logoUrl}' alt='Logo' width='64' height='64' style='display: block; border-radius: 8px;' />
                        </td></tr>
                        <tr><td style='padding: 20px 30px 30px 30px;'>
                            <h2 style='color: #2A9D8F; margin-top: 0;'>Hallo {$user->name},</h2>
                            <p style='color: #333333; line-height: 1.6; margin-bottom: 20px;'>Es wurde ein Account für dich angelegt. Klicke hier, um ein Passwort zu vergeben:</p>
                            <table width='100%' cellpadding='0' cellspacing='0' border='0'>
                                <tr><td align='center'>
                                    <a href='{$link}' style='background-color: #2A9D8F; color: #ffffff; padding: 12px 24px; text-decoration: none; font-weight: bold; display: inline-block;'>Account aktivieren</a>
                                </td></tr>
                            </table>
                        </td></tr>
                    </table>
                </td></tr>
            </table>";

            // Schlägt der Mail-Versand fehl (Exception), rollt die DB-Transaktion den User wieder zurück.
            Mail::html($html, function($msg) use ($user) {
                $msg->to($user->email)->subject('Dein neuer Account');
            });

            return response()->json(['success' => true, 'user' => new \App\Http\Resources\UserResource($user)]);
        });
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'role_ids' => 'array',
            'gallery_group_ids' => 'array',
            'gallery_ids' => 'array',
            'can_edit_metadata' => 'boolean'
        ]);

        $user = User::findOrFail($id);
        $user->roles()->sync($request->role_ids ?? []);
        $user->galleryGroups()->sync($request->gallery_group_ids ?? []);
        $user->galleries()->sync($request->gallery_ids ?? []);

        if ($request->has('can_edit_metadata')) {
            $user->update(['can_edit_metadata' => $request->can_edit_metadata]);
        }

        return response()->json(['success' => true]);
    }
}
