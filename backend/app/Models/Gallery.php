<?php

namespace App\Models;

use App\Casts\AsBrand;
use App\Services\GalleryTreeService;
use App\Support\BrandRegistry;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
        'volume_preset_id',
        'expires_at', 'created_at', 'full_path', 'effective_is_public', 'effective_is_editorial_only', 'effective_is_hidden', 'effective_is_free_download', 'photos', 'galleryGroup', 'is_editorial_only', 'is_hidden', 'is_free_download', 'restricted_photographers',
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
        'volume_preset_id',
        'expires_at',
    ];

    protected $casts = [
        'is_public' => 'boolean',
        'is_live' => 'boolean',
        'allow_client_metadata_edit' => 'boolean',
        'brand' => AsBrand::class,
        'apply_metadata_to_photos' => 'boolean',
        'expires_at' => 'datetime',
        'is_free_download' => 'boolean',
        'is_editorial_only' => 'boolean',
        'is_hidden' => 'boolean',
        'restricted_photographers' => 'boolean',
    ];

    protected $appends = ['full_path', 'effective_is_public', 'effective_is_editorial_only', 'effective_is_hidden', 'effective_is_free_download', 'org_ids', 'effective_licensing_mode'];

    public function getEffectiveLicensingModeAttribute(): string
    {
        if ($this->licensing_mode !== null) {
            return $this->licensing_mode;
        }

        // Prefer the gallery's own brand so the mode is correct even when the
        // gallery is rendered while another brand is the active context.
        $brand = $this->brand ?? BrandRegistry::currentOrDefault();

        return Setting::where('key', 'pricing_strategy')
            ->where('brand', $brand)
            ->value('value') ?? 'scope_licensing';
    }

    /**
     * Selection galleries are private rating surfaces by definition.  Keep the
     * effective value safe even if a legacy row or a direct query-builder
     * update managed to set the persisted flag to true.
     */
    public function getEffectiveIsPublicAttribute(): bool
    {
        if ($this->isSelection()) {
            return false;
        }

        return (bool) $this->is_public;
    }

    public function getEffectiveIsEditorialOnlyAttribute(): bool
    {
        return $this->is_editorial_only || ($this->galleryGroup ? $this->galleryGroup->effective_is_editorial_only : false);
    }

    public function getEffectiveIsFreeDownloadAttribute(): bool
    {
        if ($this->isSelection()) {
            return false;
        }

        return $this->is_free_download || ($this->galleryGroup ? $this->galleryGroup->effective_is_free_download : false);
    }

    public function getEffectiveRestrictedPhotographersAttribute(): bool
    {
        if ($this->restricted_photographers !== null) {
            return (bool) $this->restricted_photographers;
        }
        if ($this->galleryGroup) {
            return $this->galleryGroup->effective_restricted_photographers;
        }

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

            $path = $group->slug.'/'.$path;
            $group = $group->parent;
        }

        return 'galleries/'.$path;
    }

    protected static function booted()
    {
        static::saving(function (self $gallery) {
            if ($gallery->isSelection()) {
                // Keep the invariant at the model boundary as well as in the
                // service layer.  This also covers direct model updates made by
                // maintenance commands and future controllers.
                $gallery->is_live = false;
                $gallery->is_public = false;
                $gallery->is_free_download = false;
            }
        });

        static::saved(function (self $gallery) {
            DB::afterCommit(function () {
                app(GalleryTreeService::class)->clearCache();
            });
            if ($gallery->wasRecentlyCreated || $gallery->wasChanged('restricted_photographers')) {
                Cache::forget('unrestricted_photographer_gallery_ids');
            }
        });
        static::deleted(function () {
            DB::afterCommit(function () {
                app(GalleryTreeService::class)->clearCache();
            });
            Cache::forget('unrestricted_photographer_gallery_ids');
        });
    }

    public function isSelection(): bool
    {
        return $this->type === 'selection';
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

    public function volumePreset()
    {
        return $this->belongsTo(VolumePreset::class, 'volume_preset_id');
    }

    public function orgs()
    {
        return $this->belongsToMany(Org::class, 'gallery_org');
    }

    public function getOrgIdsAttribute(): array
    {
        if (! $this->relationLoaded('orgs')) {
            $this->load('orgs');
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
