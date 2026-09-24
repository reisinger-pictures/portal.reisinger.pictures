<?php

namespace App\Services;

use App\Models\Gallery;
use App\Models\Photo;
use App\Models\Rating;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class RatingService
{
    /**
     * Insert or update one rating for an explicit actor identity.
     *
     * The actor lock serializes the normal request path. The V038 unique index
     * remains the durable guard when two application nodes do not share a
     * cache backend: a losing insert is retried and then updates the row that
     * won the database race.
     */
    public function upsertForActor(
        Photo $photo,
        ?string $userId,
        ?string $guestId,
        int $rating,
        ?string $comment = null,
        ?string $guestName = null,
    ): Rating {
        $actorKey = Rating::actorKeyFor($userId, $guestId);
        if ($actorKey === null) {
            throw new \InvalidArgumentException('Eine Bewertung benötigt genau eine gültige Actor-Identität.');
        }

        $lockKey = 'rating:'.$photo->getKey().':'.hash('sha256', $actorKey);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                /** @var Rating $saved */
                $saved = Cache::lock($lockKey, 10)->block(5, function () use (
                    $photo,
                    $userId,
                    $guestId,
                    $rating,
                    $comment,
                    $guestName,
                    $actorKey,
                ): Rating {
                    return DB::transaction(function () use (
                        $photo,
                        $userId,
                        $guestId,
                        $rating,
                        $comment,
                        $guestName,
                        $actorKey,
                    ): Rating {
                        $existing = Rating::query()
                            ->where('photo_id', $photo->getKey())
                            ->where('actor_key', $actorKey)
                            ->orderBy('id')
                            ->lockForUpdate()
                            ->first();

                        // A V038 migration backfills identifiable legacy rows,
                        // but this fallback keeps the mutation safe if a row
                        // predates the backfill or was inserted directly.
                        if ($existing === null) {
                            $legacyQuery = Rating::query()
                                ->where('photo_id', $photo->getKey());

                            if ($userId !== null) {
                                $legacyQuery
                                    ->where('user_id', $userId)
                                    ->whereNull('guest_id');
                            } else {
                                $legacyQuery
                                    ->whereNull('user_id')
                                    ->where('guest_id', $guestId);
                            }

                            $existing = $legacyQuery
                                ->orderBy('id')
                                ->lockForUpdate()
                                ->first();
                        }

                        $saved = $existing ?? new Rating;
                        $saved->photo_id = $photo->getKey();
                        $saved->user_id = $userId;
                        $saved->guest_id = $guestId;
                        $saved->guest_name = $guestId === null ? null : $guestName;
                        $saved->rating = $rating;
                        $saved->comment = $comment ?? '';
                        $saved->save();

                        return $saved;
                    }, 3);
                });

                return $saved;
            } catch (UniqueConstraintViolationException $exception) {
                if ($attempt === 2) {
                    throw $exception;
                }
            }
        }

        throw new \LogicException('Die Bewertung konnte nicht atomar gespeichert werden.');
    }

    /**
     * Get the rating status (users and guests) for a gallery.
     */
    public function ratingStatus(Gallery $gallery): array
    {
        $totalPhotos = $gallery->photos()->count();

        $users = User::whereHas('galleries', function ($q) use ($gallery) {
            $q->where('galleries.id', $gallery->id);
        })->get();

        // Aggregate all users' rated counts in a single query instead of one
        // count query per user (N+1).
        $ratedCounts = $users->isEmpty()
            ? collect()
            : DB::table('ratings')
                ->join('photos', 'ratings.photo_id', '=', 'photos.id')
                ->where('photos.gallery_id', $gallery->id)
                ->whereIn('ratings.user_id', $users->pluck('id'))
                ->where('ratings.rating', '>', 0)
                ->select('ratings.user_id', DB::raw('COUNT(*) as rated_count'))
                ->groupBy('ratings.user_id')
                ->pluck('rated_count', 'ratings.user_id');

        $status = [];
        foreach ($users as $u) {
            $status[] = [
                'user_id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'rated_count' => (int) ($ratedCounts[$u->id] ?? 0),
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
                'user_id' => 'guest_'.$gr->guest_id,
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

        // Fetch every rating for the gallery once and group in memory instead
        // of issuing one query per photo (N+1).
        $ratingsByPhoto = DB::table('ratings')
            ->leftJoin('users', 'ratings.user_id', '=', 'users.id')
            ->whereIn('ratings.photo_id', $photos->pluck('id'))
            ->select('ratings.photo_id', 'ratings.rating', 'ratings.comment', 'ratings.guest_name', 'ratings.guest_id', 'users.name')
            ->get()
            ->groupBy('photo_id');

        $export = [];

        foreach ($photos as $photo) {
            $ratings = $ratingsByPhoto->get($photo->id);

            if ($ratings === null || $ratings->isEmpty()) {
                continue;
            }

            $comments = [];
            foreach ($ratings as $r) {
                $ratingStr = $r->rating > 0 ? $r->rating.' Sterne' : 'Ignoriert';
                $displayName = $r->name ?? ($r->guest_name ?? 'Gast');
                $line = "{$displayName} ({$ratingStr})";
                if (! empty($r->comment)) {
                    $line .= ": {$r->comment}";
                }
                $comments[] = $line;
            }

            $export[] = [
                'id' => $photo->id,
                'filename' => $photo->title ?: 'Bild '.substr($photo->id, 0, 8),
                'thumb_url' => $photo->thumb_url,
                'lr_uuid' => $photo->lr_uuid,
                'avg_rating' => ceil($ratings->where('rating', '>', 0)->avg('rating') ?? 0),
                'all_comments' => implode("\n", $comments),
            ];
        }

        return $export;
    }
}
