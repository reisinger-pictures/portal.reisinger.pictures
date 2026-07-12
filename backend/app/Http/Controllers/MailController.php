<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Gallery;
use App\Models\GalleryGroup;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use App\Mail\CustomMail;
use App\Mail\RatingFinishedMail;
use App\Support\BrandRegistry;

class MailController extends Controller
{
    // Semi-automatischer Versand (Admin)
    public function sendCustom(Request $request, $galleryId)
    {
        $request->validate(['subject' => 'required|string', 'body' => 'required|string']);
        $gallery = Gallery::with('galleryGroup')->findOrFail($galleryId);

        $userIds = DB::table('user_galleries')->where('gallery_id', $gallery->id)->where('wants_notifications', true)->pluck('user_id')->toArray();
        $groupIds = [];
        $currentGroup = $gallery->galleryGroup;
        while ($currentGroup) {
            $groupIds[] = $currentGroup->id;
            $currentGroup = GalleryGroup::find($currentGroup->parent_id);
        }
        if (!empty($groupIds)) {
            $groupUserIds = DB::table('user_gallery_groups')->whereIn('gallery_group_id', $groupIds)->where('wants_notifications', true)->pluck('user_id')->toArray();
            $userIds = array_merge($userIds, $groupUserIds);
        }
        $userIds = array_unique($userIds);

        if (empty($userIds)) return response()->json(['message' => 'Keine berechtigten User für diese Galerie gefunden.'], 404);

        $users = User::whereIn('id', $userIds)->whereNotNull('email')->get();
        // Strikte Prüfung: Hat der abonnierte User auch wirklich noch das Recht, diese Galerie zu sehen?
        $validUsers = $users->filter(fn($u) => $u->canAccessGallery($gallery->id));
        $count = 0;

        foreach ($validUsers as $user) {
            $link = BrandRegistry::frontendUrl() . '/' . $gallery->full_path;
            $subject = str_replace(['{user_name}', '{gallery_name}'], [$user->name, $gallery->name], $request->subject);
            $body = str_replace(['{user_name}', '{gallery_name}', '{link}'], [$user->name, $gallery->name, $link], $request->body);

            Mail::to($user->email)->queue(new CustomMail($subject, $body));
            $count++;
        }

        return response()->json(['success' => true, 'notified_count' => $count]);
    }

    // Kunde meldet "Ich bin fertig"
    public function finishRating(Request $request, $galleryId)
    {
        $gallery = Gallery::findOrFail($galleryId);
        $user = auth('api')->user();
        if (!$user) return response()->json(['error' => 'Unauthenticated'], 401);

        // IDOR guard: only users who can access the gallery may trigger the rating-finished notification.
        if (!$user->canAccessGallery($gallery->id)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        // Strikte Logik: Wir informieren NUR Fotografen/Admins, die explizit dieser Galerie
        // zugewiesen sind UND Benachrichtigungen (wants_notifications = true) aktiviert haben.
        $notifiedUsers = User::whereHas('roles', function($q) { 
            $q->whereIn('name', [\App\Enums\UserRole::PHOTOGRAPHER->value, \App\Enums\UserRole::ADMIN->value]); 
        })
        ->whereHas('galleries', function($q) use ($gallery) { 
            $q->where('galleries.id', $gallery->id)
              ->where('user_galleries.wants_notifications', true); 
        })
        ->get();
        
        foreach($notifiedUsers as $notifiedUser) {
            Mail::to($notifiedUser->email)->queue(new RatingFinishedMail($notifiedUser->name, $user->name, $user->email, $gallery->name));
        }

        return response()->json(['success' => true]);
    }
}
