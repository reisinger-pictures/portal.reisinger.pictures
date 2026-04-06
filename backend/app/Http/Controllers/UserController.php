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
            
            \Illuminate\Support\Facades\Mail::to($user->email)->send(
                new \App\Mail\ActivateAccountMail(
                    $user->name,
                    'Es wurde ein Account für dich angelegt. Klicke hier, um ein Passwort zu vergeben:',
                    $link,
                    'Account aktivieren',
                    'Dein neuer Account'
                )
            );

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
