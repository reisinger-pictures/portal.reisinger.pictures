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
     * Keep export queries and the temporary IN lists bounded for large
     * galleries. The public export remains a complete, unmodified array.
     */
    private const EXPORT_CHUNK_SIZE = 100;

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

        // Fetch assigned users and their positive rating count in one
        // streaming query. The correlated aggregate avoids both the previous
        // N+1 count loop and an unbounded user-id IN list.
        $users = User::query()
            ->select(['users.id', 'users.name', 'users.email'])
            ->selectSub(
                DB::table('ratings')
                    ->join('photos', 'ratings.photo_id', '=', 'photos.id')
                    ->whereColumn('ratings.user_id', 'users.id')
                    ->where('photos.gallery_id', $gallery->id)
                    ->where('ratings.rating', '>', 0)
                    ->selectRaw('COUNT(*)'),
                'rated_count',
            )
            ->whereHas('galleries', function ($query) use ($gallery) {
                $query->where('galleries.id', $gallery->id);
            })
            ->cursor();

        $status = [];
        foreach ($users as $user) {
            $status[] = [
                'user_id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'rated_count' => (int) $user->rated_count,
                'total_photos' => $totalPhotos,
            ];
        }

        // Guest identities are already grouped by the database. Stream the
        // aggregate instead of materializing every rating row in PHP.
        $guestRatings = DB::table('ratings')
            ->join('photos', 'ratings.photo_id', '=', 'photos.id')
            ->where('photos.gallery_id', $gallery->id)
            ->whereNull('ratings.user_id')
            ->select('ratings.guest_id', 'ratings.guest_name', DB::raw('COUNT(CASE WHEN ratings.rating > 0 THEN 1 END) as rated_count'))
            ->groupBy('ratings.guest_id', 'ratings.guest_name')
            ->cursor();

        foreach ($guestRatings as $guestRating) {
            $status[] = [
                'user_id' => 'guest_'.$guestRating->guest_id,
                'name' => $guestRating->guest_name ?? 'Gast',
                'email' => '@invite.local',
                'rated_count' => (int) $guestRating->rated_count,
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
        $export = [];

        // Process photos in bounded chunks. Each chunk gets one ratings query
        // (rather than one query per photo), while the returned payload keeps
        // the existing complete-array contract.
        Photo::query()
            ->where('gallery_id', $gallery->id)
            ->chunkById(self::EXPORT_CHUNK_SIZE, function ($photos) use (&$export): void {
                $ratingsByPhoto = DB::table('ratings')
                    ->leftJoin('users', 'ratings.user_id', '=', 'users.id')
                    ->whereIn('ratings.photo_id', $photos->pluck('id'))
                    ->select('ratings.photo_id', 'ratings.rating', 'ratings.comment', 'ratings.guest_name', 'ratings.guest_id', 'users.name')
                    ->get()
                    ->groupBy('photo_id');

                foreach ($photos as $photo) {
                    $ratings = $ratingsByPhoto->get($photo->id);

                    if ($ratings === null || $ratings->isEmpty()) {
                        continue;
                    }

                    $comments = [];
                    foreach ($ratings as $rating) {
                        $ratingStr = $rating->rating > 0 ? $rating->rating.' Sterne' : 'Ignoriert';
                        $displayName = $rating->name ?? ($rating->guest_name ?? 'Gast');
                        $line = "{$displayName} ({$ratingStr})";
                        if (! empty($rating->comment)) {
                            $line .= ": {$rating->comment}";
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
            });

        return $export;
    }
}
