<?php

namespace App\Models;

use App\Enums\Brand;
use App\Support\BrandRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Laravel\Scout\Searchable;

class Gallery extends Model
{
    use HasFactory, HasUuids;

    use Searchable;

    protected $visible = [
        'id', 'gallery_group_id', 'name', 'slug', 'type', 'is_live',
        'is_public', 'allow_client_metadata_edit', 'apply_metadata_to_photos',
        'default_title', 'default_description', 'default_keywords',
        'default_location', 'default_city', 'default_state', 'default_country', 'default_iso_country',
        'org_ids', 'brand', 'licensing_mode', 'effective_licensing_mode',
        'expires_at', 'created_at', 'full_path', 'effective_is_editorial_only', 'effective_is_hidden', 'effective_is_free_download', 'photos', 'galleryGroup', 'is_editorial_only', 'is_hidden', 'is_free_download', 'restricted_photographers'
    ];

    protected $fillable = [
        'gallery_group_id',
        'name',
        'slug',
        'type',
        'is_live',
        'is_public',
        'is_free_download',
        'is_editorial_only',
        'is_hidden',
        'restricted_photographers',
        'cached_full_path',
        'password_hash',
        'allow_client_metadata_edit',
        'apply_metadata_to_photos',
        'default_title',
        'default_description',
        'default_keywords',
        'default_location',
        'default_city',
        'default_state',
        'default_country',
        'default_iso_country',
        'brand',
        'licensing_mode',
        'expires_at'
    ];

    protected $casts = [
        'is_public' => 'boolean',
        'is_live' => 'boolean',
        'allow_client_metadata_edit' => 'boolean',
        'brand' => \App\Casts\AsBrand::class,
        'apply_metadata_to_photos' => 'boolean',
        'expires_at' => 'datetime',
        'is_free_download' => 'boolean',
        'is_editorial_only' => 'boolean',
        'is_hidden' => 'boolean',
        'restricted_photographers' => 'boolean',
    ];

    protected $appends = ['full_path', 'effective_is_editorial_only', 'effective_is_hidden', 'effective_is_free_download', 'org_ids', 'effective_licensing_mode'];

    public function getEffectiveLicensingModeAttribute(): string
    {
        if ($this->licensing_mode !== null) {
            return $this->licensing_mode;
        }

        return Setting::where('key', 'pricing_strategy')
            ->where('brand', BrandRegistry::currentOrDefault())
            ->value('value') ?? 'scope_licensing';
    }

    public function getEffectiveIsEditorialOnlyAttribute(): bool
    {
        return $this->is_editorial_only || ($this->galleryGroup ? $this->galleryGroup->effective_is_editorial_only : false);
    }

    public function getEffectiveIsFreeDownloadAttribute(): bool
    {
        return $this->is_free_download || ($this->galleryGroup ? $this->galleryGroup->effective_is_free_download : false);
    }

    public function getEffectiveRestrictedPhotographersAttribute(): bool
    {
        if ($this->restricted_photographers !== null) return (bool) $this->restricted_photographers;
        if ($this->galleryGroup) return $this->galleryGroup->effective_restricted_photographers;
        return false;
    }

    public function getEffectiveIsHiddenAttribute(): bool
    {
        return $this->is_hidden || ($this->galleryGroup ? $this->galleryGroup->effective_is_hidden : false);
    }

    public function getFullPathAttribute()
    {
        $path = $this->slug;
        $group = $this->galleryGroup;

        $visited = [];

        while ($group) {
            if (isset($visited[$group->id])) {
                break;
            }
            $visited[$group->id] = true;

            $path = $group->slug . '/' . $path;
            $group = $group->parent;
        }

        return 'galleries/' . $path;
    }

    protected static function booted()
    {
        static::saved(function (self $gallery) {
            \Illuminate\Support\Facades\DB::afterCommit(function() {
                app(\App\Services\GalleryTreeService::class)->clearCache();
            });
            if ($gallery->wasRecentlyCreated || $gallery->wasChanged('restricted_photographers')) {
                \Illuminate\Support\Facades\Cache::forget('unrestricted_photographer_gallery_ids');
            }
        });
        static::deleted(function () {
            \Illuminate\Support\Facades\DB::afterCommit(function() {
                app(\App\Services\GalleryTreeService::class)->clearCache();
            });
            \Illuminate\Support\Facades\Cache::forget('unrestricted_photographer_gallery_ids');
        });
    }

    public function photos()
    {
        return $this->hasMany(Photo::class)->orderBy('id', 'desc');
    }

    public function latestPhoto()
    {
        return $this->hasOne(Photo::class)->latestOfMany();
    }

    public function galleryGroup()
    {
        return $this->belongsTo(GalleryGroup::class);
    }

    public function orgs()
    {
        return $this->belongsToMany(Org::class, 'gallery_org');
    }

    public function getOrgIdsAttribute(): array
    {
        if (!$this->relationLoaded('orgs')) {
            return [];
        }
        return $this->orgs->pluck('id')->toArray();
    }

    public function toSearchableArray()
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'is_hidden' => $this->effective_is_hidden,
        ];
    }
}
