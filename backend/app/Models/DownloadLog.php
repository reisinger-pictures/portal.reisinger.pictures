<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

class DownloadLog extends Model
{
    use HasFactory, HasUuids;

    public const UPDATED_AT = null;

    /**
     * A new full-ZIP audit row must carry the number of files that were
     * actually prepared. The database default of 1 remains for historical
     * single-image rows and legacy imports; relying on it for a new ZIP would
     * silently under-count an archive and distort payouts.
     */
    protected static function booted(): void
    {
        static::creating(function (self $log): void {
            if ($log->item_type !== 'full_zip') {
                return;
            }

            if (! self::isExplicitPositivePhotoCount($log->getAttribute('photo_count'))) {
                throw new InvalidArgumentException(
                    'A new full_zip download log requires an explicit positive photo_count.',
                );
            }
        });
    }

    private static function isExplicitPositivePhotoCount(mixed $value): bool
    {
        if (is_int($value)) {
            return $value > 0;
        }

        if (! is_string($value)) {
            return false;
        }

        $normalized = trim($value);

        return preg_match('/^[0-9]+$/D', $normalized) === 1
            && (int) $normalized > 0;
    }

    protected $fillable = [
        'user_id',
        'guest_id',
        'user_name_snapshot',
        'gallery_id',
        'gallery_name_snapshot',
        'order_id',
        'item_type', // 'single_image', 'full_zip'
        'resolution_tier',
        'user_agent',
        'payload',
        'photo_count',
    ];

    protected $casts = [
        'payload' => 'array',
        'guest_id' => 'string',
        'photo_count' => 'integer',
    ];

    public function gallery(): BelongsTo
    {
        return $this->belongsTo(Gallery::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
