<?php

namespace App\Services;

use App\Models\Gallery;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RatingService
{
    /**
     * Get the rating status (users and guests) for a gallery.
     */
    public function ratingStatus(Gallery $gallery): array
    {
        $totalPhotos = $gallery->photos()->count();

        $users = User::whereHas('galleries', function ($q) use ($gallery) {
            $q->where('galleries.id', $gallery->id);
        })->get();

        $status = [];
        foreach ($users as $u) {
            $ratedCount = DB::table('ratings')
                ->join('photos', 'ratings.photo_id', '=', 'photos.id')
                ->where('photos.gallery_id', $gallery->id)
                ->where('ratings.user_id', $u->id)
                ->where('ratings.rating', '>', 0)
                ->count();

            $status[] = [
                'user_id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'rated_count' => $ratedCount,
                'total_photos' => $totalPhotos,
            ];
        }

        $guestRatings = DB::table('ratings')
            ->join('photos', 'ratings.photo_id', '=', 'photos.id')
            ->where('photos.gallery_id', $gallery->id)
            ->whereNull('ratings.user_id')
            ->select('ratings.guest_id', 'ratings.guest_name', DB::raw('COUNT(CASE WHEN ratings.rating > 0 THEN 1 END) as rated_count'))
            ->groupBy('ratings.guest_id', 'ratings.guest_name')
            ->get();

        foreach ($guestRatings as $gr) {
            $status[] = [
                'user_id' => 'guest_' . $gr->guest_id,
                'name' => $gr->guest_name ?? 'Gast',
                'email' => '@invite.local',
                'rated_count' => $gr->rated_count,
                'total_photos' => $totalPhotos,
            ];
        }

        return ['users' => $status, 'total_photos' => $totalPhotos];
    }

    /**
     * Export ratings for a gallery (Lightroom-style).
     */
    public function exportRatings(Gallery $gallery): array
    {
        $photos = Photo::where('gallery_id', $gallery->id)->get();
        $export = [];

        foreach ($photos as $photo) {
            $ratings = DB::table('ratings')
                ->leftJoin('users', 'ratings.user_id', '=', 'users.id')
                ->where('photo_id', $photo->id)
                ->select('ratings.rating', 'ratings.comment', 'ratings.guest_name', 'ratings.guest_id', 'users.name')
                ->get();

            if ($ratings->isEmpty()) {
                continue;
            }

            $comments = [];
            foreach ($ratings as $r) {
                $ratingStr = $r->rating > 0 ? $r->rating . ' Sterne' : 'Ignoriert';
                $displayName = $r->name ?? ($r->guest_name ?? 'Gast');
                $line = "{$displayName} ({$ratingStr})";
                if (!empty($r->comment)) {
                    $line .= ": {$r->comment}";
                }
                $comments[] = $line;
            }

            $export[] = [
                'id' => $photo->id,
                'filename' => $photo->title ?: 'Bild ' . substr($photo->id, 0, 8),
                'thumb_url' => $photo->thumb_url,
                'lr_uuid' => $photo->lr_uuid,
                'avg_rating' => ceil($ratings->where('rating', '>', 0)->avg('rating') ?? 0),
                'all_comments' => implode("\n", $comments),
            ];
        }

        return $export;
    }
}
