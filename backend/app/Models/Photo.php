<?php

namespace App\Models;

use App\Constants\TierRanks;
use App\Exceptions\InvalidPhotoIdentifierException;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Scout\Searchable;

class Photo extends Model
{
    use HasFactory, HasUuids, Searchable;

    public const DERIVATIVE_SIZES = [250, 400, 800, 1024, 1200, 2000];

    /**
     * Columns derived from the file's own EXIF data by the import writers.
     *
     * They are server-side facts about the stored file, never client input, so
     * they stay out of `$fillable` and are assigned deliberately by
     * {@see self::createWithId()} instead. Listing them explicitly is what keeps
     * a future import writer from silently dropping them.
     */
    private const DERIVED_IMPORT_ATTRIBUTES = ['captured_at'];

    protected $visible = [
        'id', 'gallery_id', 'lr_uuid', 'width', 'height',
        'title', 'headline', 'description', 'artist', 'keywords', 'location',
        'city', 'state', 'country', 'iso_country', 'created_at', 'url', 'thumb_url',
        'rating', 'comment', 'gallery', 'artist',
        'is_editorial_only', 'is_hidden',
        'effective_is_editorial_only', 'effective_is_hidden', 'last_accessed_at', 'is_downscaled', 'captured_at',
    ];

    /**
     * `id` is intentionally absent: the primary key is the photo's filesystem
     * identity and must only ever be set through {@see self::createWithId()}.
     *
     * @see static::createWithId()
     */
    protected $fillable = [
        'gallery_id', 'mime_type', 'lr_uuid', 'width', 'height',
        'title', 'headline', 'description', 'user_id', 'keywords', 'location',
        'city', 'state', 'country', 'iso_country', 'is_editorial_only',
        'is_hidden', 'last_accessed_at', 'is_downscaled',
    ];

    protected $appends = [
        'artist', 'url', 'thumb_url', 'srcset',
        'effective_is_editorial_only', 'effective_is_hidden',
    ];

    protected $casts = [
        'is_editorial_only' => 'boolean',
        'captured_at' => 'datetime',
        'is_hidden' => 'boolean',
        'is_downscaled' => 'boolean',
    ];

    protected $with = ['gallery'];

    /**
     * Create a photo with an explicitly supplied, pre-generated identifier.
     *
     * Import and upload writers must know `photos.id` before the row exists: the
     * identifier is the stored original's file name (`{id}.{ext}`) and the key
     * of every derivative URL. `id` is therefore not fillable — which is what
     * stops a request payload from choosing the identity of the row it writes —
     * and this factory is the only sanctioned way to set it.
     *
     * Mass assignment stays in force for `$attributes`; the identifier and the
     * EXIF-derived columns are written deliberately, one attribute at a time.
     * No `unguarded()` window is opened.
     *
     * @param  array<string, mixed>  $attributes  Must not contain `id`. Every key
     *                                            must be fillable or a derived import column.
     *
     * @throws InvalidPhotoIdentifierException
     */
    public static function createWithId(array $attributes, string $id): self
    {
        if (! Str::isUuid($id)) {
            throw InvalidPhotoIdentifierException::notACanonicalUuid($id);
        }

        $photo = new self;
        $keyName = $photo->getKeyName();

        if (array_key_exists($keyName, $attributes)) {
            throw InvalidPhotoIdentifierException::suppliedInsideAttributes();
        }

        $managed = array_merge($photo->getFillable(), self::DERIVED_IMPORT_ATTRIBUTES);
        $unmanaged = array_diff(array_keys($attributes), $managed);
        if ($unmanaged !== []) {
            throw InvalidPhotoIdentifierException::unmanagedAttribute((string) reset($unmanaged));
        }

        $derived = array_intersect_key($attributes, array_flip(self::DERIVED_IMPORT_ATTRIBUTES));

        $photo->fill($attributes);
        foreach ($derived as $column => $value) {
            $photo->setAttribute($column, $value);
        }
        $photo->setAttribute($keyName, $id);
        $photo->save();

        return $photo;
    }

    public function gallery()
    {
        return $this->belongsTo(Gallery::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function versions()
    {
        return $this->hasMany(PhotoMetadataVersion::class)->orderBy('id', 'desc');
    }

    public function getArtistAttribute()
    {
        if (! $this->user) {
            return null;
        }

        return $this->user->metadata_copyright ?? $this->user->name;
    }

    public function getEffectiveIsEditorialOnlyAttribute(): bool
    {
        return $this->is_editorial_only || ($this->gallery ? $this->gallery->effective_is_editorial_only : false);
    }

    public function getEffectiveIsHiddenAttribute(): bool
    {
        return $this->is_hidden || ($this->gallery ? $this->gallery->effective_is_hidden : false);
    }

    public function requiresWatermark(?User $user = null): bool
    {
        if (! $this->gallery) {
            return true;
        }
        // Selection galleries are rating-only surfaces.  Their persisted free,
        // management, and flat-rate flags must never turn their media URLs into
        // an original-download URL.
        if ($this->gallery->isSelection()) {
            return true;
        }
        if ($this->gallery->effective_is_free_download) {
            return false;
        }

        $user ??= auth('api')->user();
        if ($user && ($user->is_admin || $user->is_photographer)) {
            return false;
        }
        if ($user && $user->canAccessGallery($this->gallery_id)) {
            if ((TierRanks::RANKS[$user->flatrate_level ?? 'none'] ?? 0) >= 1) {
                return false;
            }
        }

        return true;
    }

    public function getUrlAttribute()
    {
        $v = $this->created_at ? $this->created_at->timestamp : '1';
        $reqWm = $this->requiresWatermark();
        if ($reqWm) {
            $v .= '_'.Cache::get('watermark_version', '1');
        }
        $prefix = $reqWm ? 'watermarked/' : '';

        return '/api/media/'.$this->gallery_id.'/'.$prefix.'_thumbs/2000/'.$this->id.'.webp?v='.$v;
    }

    public function getThumbUrlAttribute()
    {
        $v = $this->created_at ? $this->created_at->timestamp : '1';
        $reqWm = $this->requiresWatermark();
        if ($reqWm) {
            $v .= '_'.Cache::get('watermark_version', '1');
        }
        $prefix = $reqWm ? 'watermarked/' : '';

        return '/api/media/'.$this->gallery_id.'/'.$prefix.'_thumbs/800/'.$this->id.'.webp?v='.$v;
    }

    public function getSrcsetAttribute()
    {
        $v = $this->created_at ? $this->created_at->timestamp : '1';
        $reqWm = $this->requiresWatermark();
        if ($reqWm) {
            $v .= '_'.Cache::get('watermark_version', '1');
        }
        $prefix = $reqWm ? 'watermarked/' : '';
        $baseUrl = '/api/media/'.$this->gallery_id.'/'.$prefix;

        return $baseUrl.'_thumbs/250/'.$this->id.'.webp?v='.$v.' 250w, '.
               $baseUrl.'_thumbs/400/'.$this->id.'.webp?v='.$v.' 400w, '.
               $baseUrl.'_thumbs/800/'.$this->id.'.webp?v='.$v.' 800w, '.
               $baseUrl.'_thumbs/1200/'.$this->id.'.webp?v='.$v.' 1200w';
    }

    public function shouldBeSearchable()
    {
        return $this->gallery && $this->gallery->type !== 'selection';
    }

    public function toSearchableArray()
    {
        return [
            'id' => $this->id,
            'title' => $this->title ?? '',
            'headline' => $this->headline ?? '',
            'description' => $this->description ?? '',
            'artist' => $this->artist ?? '',
            'keywords' => $this->keywords ?? '',
            'location' => $this->location ?? '',
            'city' => $this->city ?? '',
            'state' => $this->state ?? '',
            'country' => $this->country ?? '',
            'iso_country' => $this->iso_country ?? '',
            'gallery_id' => $this->gallery_id,
            'is_hidden' => $this->effective_is_hidden,
        ];
    }

    public function getFilenameAttribute(): string
    {
        if (isset($this->attributes['filename']) && $this->attributes['filename'] !== null) {
            return $this->attributes['filename'];
        }
        $ext = 'jpg';
        if ($this->mime_type === 'image/png') {
            $ext = 'png';
        }
        if ($this->mime_type === 'image/webp') {
            $ext = 'webp';
        }

        return "{$this->id}.{$ext}";
    }
}
