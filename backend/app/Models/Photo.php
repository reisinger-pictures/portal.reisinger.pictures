<?php

namespace App\Models;

use App\Constants\TierRanks;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Laravel\Scout\Searchable;

class Photo extends Model
{
    use HasFactory, HasUuids, Searchable;

    public const DERIVATIVE_SIZES = [250, 400, 800, 1024, 1200, 2000];

    protected $visible = [
        'id', 'gallery_id', 'lr_uuid', 'width', 'height', 
        'title', 'headline', 'description', 'artist', 'keywords', 'location', 
        'city', 'state', 'country', 'iso_country', 'created_at', 'url', 'thumb_url', 
        'rating', 'comment', 'gallery', 'artist',
        'is_editorial_only', 'is_hidden',
        'effective_is_editorial_only', 'effective_is_hidden', 'last_accessed_at', 'is_downscaled', 'captured_at'
    ];

    protected $fillable = [
        'id', 'gallery_id', 'mime_type', 'lr_uuid', 'width', 'height',
        'title', 'headline', 'description', 'user_id', 'keywords', 'location',
        'city', 'state', 'country', 'iso_country', 'is_editorial_only',
        'is_hidden', 'last_accessed_at', 'is_downscaled'
    ];

    protected $appends = [
        'artist', 'url', 'thumb_url', 'srcset', 
        'effective_is_editorial_only', 'effective_is_hidden'
    ];
    
    protected $casts = [
        'is_editorial_only' => 'boolean',
        'captured_at' => 'datetime',
        'is_hidden' => 'boolean',
        'is_downscaled' => 'boolean',
    ];

    protected $with = ['gallery'];

    public function gallery() { return $this->belongsTo(Gallery::class); }
    public function user() { return $this->belongsTo(User::class); }
    public function versions() { return $this->hasMany(PhotoMetadataVersion::class)->orderBy('id', 'desc'); }

    public function getArtistAttribute() {
        if (!$this->user) return null;
        return $this->user->metadata_copyright ?? $this->user->name;
    }

    public function getEffectiveIsEditorialOnlyAttribute(): bool {
        return $this->is_editorial_only || ($this->gallery ? $this->gallery->effective_is_editorial_only : false);
    }

    public function getEffectiveIsHiddenAttribute(): bool {
        return $this->is_hidden || ($this->gallery ? $this->gallery->effective_is_hidden : false);
    }

    public function requiresWatermark(?User $user = null): bool {
        if (!$this->gallery) return true;
        if ($this->gallery->effective_is_free_download) return false;

        $user ??= auth('api')->user();
        if ($user && ($user->is_admin || $user->is_photographer)) return false;
        if ($user && $user->canAccessGallery($this->gallery_id)) {
            if ((TierRanks::RANKS[$user->flatrate_level ?? 'none'] ?? 0) >= 1) return false;
        }
        return true;
    }

    public function getUrlAttribute() {
        $v = $this->created_at ? $this->created_at->timestamp : '1';
        $reqWm = $this->requiresWatermark();
        if ($reqWm) $v .= '_' . \Illuminate\Support\Facades\Cache::get('watermark_version', '1');
        $prefix = $reqWm ? 'watermarked/' : '';
        return '/api/media/' . $this->gallery_id . '/' . $prefix . '_thumbs/2000/' . $this->id . '.webp?v=' . $v;
    }

    public function getThumbUrlAttribute() {
        $v = $this->created_at ? $this->created_at->timestamp : '1';
        $reqWm = $this->requiresWatermark();
        if ($reqWm) $v .= '_' . \Illuminate\Support\Facades\Cache::get('watermark_version', '1');
        $prefix = $reqWm ? 'watermarked/' : '';
        return '/api/media/' . $this->gallery_id . '/' . $prefix . '_thumbs/800/' . $this->id . '.webp?v=' . $v;
    }

    public function getSrcsetAttribute() {
        $v = $this->created_at ? $this->created_at->timestamp : '1';
        $reqWm = $this->requiresWatermark();
        if ($reqWm) $v .= '_' . \Illuminate\Support\Facades\Cache::get('watermark_version', '1');
        $prefix = $reqWm ? 'watermarked/' : '';
        $baseUrl = '/api/media/' . $this->gallery_id . '/' . $prefix;
        return $baseUrl . '_thumbs/250/' . $this->id . '.webp?v=' . $v . ' 250w, ' . 
               $baseUrl . '_thumbs/400/' . $this->id . '.webp?v=' . $v . ' 400w, ' . 
               $baseUrl . '_thumbs/800/' . $this->id . '.webp?v=' . $v . ' 800w, ' . 
               $baseUrl . '_thumbs/1200/' . $this->id . '.webp?v=' . $v . ' 1200w';
    }

    public function shouldBeSearchable() { return $this->gallery && $this->gallery->type !== 'selection'; }

    public function toSearchableArray() {
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

    public function getFilenameAttribute(): string {
        if (isset($this->attributes['filename']) && $this->attributes['filename'] !== null) {
            return $this->attributes['filename'];
        }
        $ext = 'jpg';
        if ($this->mime_type === 'image/png') $ext = 'png';
        if ($this->mime_type === 'image/webp') $ext = 'webp';
        return "{$this->id}.{$ext}";
    }
}