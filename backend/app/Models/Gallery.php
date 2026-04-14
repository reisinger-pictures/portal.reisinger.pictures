<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Cache;
use Laravel\Scout\Searchable;

class Gallery extends Model
{
    use HasFactory, HasUuids;

    use Searchable;

    public const UPDATED_AT = null;
    
    protected $visible = [
        'id', 'gallery_group_id', 'name', 'slug', 'type', 'is_live', 
        'is_public', 'allow_client_metadata_edit', 'apply_metadata_to_photos', 
        'default_title', 'default_description', 'default_keywords', 
        'default_location', 'default_city', 'default_state', 'default_country', 'default_iso_country',
        'expires_at', 'created_at', 'full_path', 'effective_is_editorial_only', 'effective_is_hidden', 'effective_is_free_download', 'effective_is_free_download', 'photos', 'galleryGroup'
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
        'expires_at'
    ];

    protected $casts = [
        'is_public' => 'boolean',
        'is_live' => 'boolean',
        'allow_client_metadata_edit' => 'boolean',
        'apply_metadata_to_photos' => 'boolean',
        'expires_at' => 'datetime',
    ];

    // Dieses Attribut wird bei JSON-Responses automatisch angehängt
    protected $appends = ['full_path', 'effective_is_editorial_only', 'effective_is_hidden', 'effective_is_free_download'];

    public function getEffectiveIsEditorialOnlyAttribute(): bool
    {
        if ($this->is_editorial_only !== null) return (bool) $this->is_editorial_only;
        if ($this->galleryGroup) return $this->galleryGroup->effective_is_editorial_only;
        return false;
    }

    

    

    public function getEffectiveIsFreeDownloadAttribute(): bool
    {
        if ($this->is_free_download !== null) return (bool) $this->is_free_download;
        if ($this->galleryGroup) return $this->galleryGroup->effective_is_free_download;
        return false;
    }

    public function getEffectiveRestrictedPhotographersAttribute(): bool
    {
        if ($this->restricted_photographers !== null) return (bool) $this->restricted_photographers;
        if ($this->galleryGroup) return $this->galleryGroup->effective_restricted_photographers;
        return false;
    }

    public function getEffectiveIsHiddenAttribute(): bool
    {
        if ($this->is_hidden !== null) return (bool) $this->is_hidden;
        if ($this->galleryGroup) return $this->galleryGroup->effective_is_hidden;
        return false;
    }

    public function getFullPathAttribute()
    {
        $path = $this->slug;
        $group = $this->galleryGroup;
        
        while ($group) {
            $path = $group->slug . '/' . $path;
            $group = $group->parent; 
        }
        
        return 'galleries/' . $path;
    }

    protected static function booted()
    {
        static::saved(function () { 
            \Illuminate\Support\Facades\DB::afterCommit(function() {
            Cache::forget('gallery_tree_admin');
            Cache::forget('unrestricted_photographer_gallery_ids');
        }); 
        });
        static::deleted(function () { 
            \Illuminate\Support\Facades\DB::afterCommit(function() {
            Cache::forget('gallery_tree_admin');
            Cache::forget('unrestricted_photographer_gallery_ids');
        }); 
        });
    }

    public function photos()
    {
        return $this->hasMany(Photo::class)->orderBy('id', 'desc');
    }

    public function galleryGroup()
    {
        return $this->belongsTo(GalleryGroup::class);
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
