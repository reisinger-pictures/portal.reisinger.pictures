<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Gallery;
use App\Models\GalleryGroup;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class MailController extends Controller
{
    // Semi-automatischer Versand (Admin)
    public function sendCustom(Request $request, $galleryId)
    {
        $request->validate(['subject' => 'required|string', 'body' => 'required|string']);
        $gallery = Gallery::with('galleryGroup')->findOrFail($galleryId);

        $userIds = DB::table('user_galleries')->where('gallery_id', $gallery->id)->pluck('user_id')->toArray();
        $groupIds = [];
        $currentGroup = $gallery->galleryGroup;
        while ($currentGroup) {
            $groupIds[] = $currentGroup->id;
            $currentGroup = GalleryGroup::find($currentGroup->parent_id);
        }
        if (!empty($groupIds)) {
            $groupUserIds = DB::table('user_gallery_groups')->whereIn('gallery_group_id', $groupIds)->pluck('user_id')->toArray();
            $userIds = array_merge($userIds, $groupUserIds);
        }
        $userIds = array_unique($userIds);

        if (empty($userIds)) return response()->json(['message' => 'Keine berechtigten User für diese Galerie gefunden.'], 404);

        $users = User::whereIn('id', $userIds)->whereNotNull('email')->get();
        $count = 0;

        foreach ($users as $user) {
            $link = rtrim(config('app.frontend_url'), '/') . '/' . $gallery->full_path;
            $subject = str_replace(['{user_name}', '{gallery_name}'], [$user->name, $gallery->name], $request->subject);
            $body = str_replace(['{user_name}', '{gallery_name}', '{link}'], [$user->name, $gallery->name, $link], $request->body);

            Mail::html($body, function ($message) use ($user, $subject) {
                $message->to($user->email)->subject($subject);
            });
            $count++;
        }

        return response()->json(['success' => true, 'notified_count' => $count]);
    }

    // Kunde meldet "Ich bin fertig"
    public function finishRating(Request $request, $galleryId)
    {
        $gallery = Gallery::findOrFail($galleryId);
        $user = auth('api')->user();

        // Wir informieren die Fotografen der Galerie, ansonsten als Fallback die Admins.
        $notifiedUsers = User::whereHas('roles', function($q) { $q->whereIn('name', ['photographer', 'admin']); })
            ->whereHas('galleries', function($q) use ($gallery) { $q->where('galleries.id', $gallery->id); })
            ->get();

        if ($notifiedUsers->isEmpty()) {
            $notifiedUsers = User::whereHas('roles', function($q) { $q->where('name', 'admin'); })->get();
        }
        
        foreach($notifiedUsers as $notifiedUser) {
            Mail::html(
                "<p>Hallo {$notifiedUser->name},</p><p>Der Kunde <b>{$user->name}</b> ({$user->email}) hat die Auswahl in der Galerie <b>{$gallery->name}</b> soeben abgeschlossen.</p>", 
                function($msg) use ($notifiedUser, $gallery) {
                    $msg->to($notifiedUser->email)->subject("Auswahl abgeschlossen: {$gallery->name}");
                }
            );
        }

        return response()->json(['success' => true]);
    }
}
