<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Cache;

class GalleryGroup extends Model
{
    use HasFactory, HasUuids;

    public const UPDATED_AT = null;
    
    protected $fillable = [
        'parent_id',
        'name',
        'slug',
        'is_public',
        'is_free_download',
        'is_editorial_only',
        'is_hidden',
        'restricted_photographers'
    ];

    protected $casts = [
        'is_public' => 'boolean',
        'is_free_download' => 'boolean',
        'is_editorial_only' => 'boolean',
        'is_hidden' => 'boolean',
        'restricted_photographers' => 'boolean',
    ];

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

    protected $appends = ['effective_is_editorial_only', 'effective_is_hidden', 'effective_is_free_download', 'effective_restricted_photographers'];

    public function getEffectiveIsEditorialOnlyAttribute(): bool
    {
        return $this->is_editorial_only || ($this->parent ? $this->parent->effective_is_editorial_only : false);
    }

    public function getEffectiveIsFreeDownloadAttribute(): bool
    {
        return $this->is_free_download || ($this->parent ? $this->parent->effective_is_free_download : false);
    }

    public function getEffectiveRestrictedPhotographersAttribute(): bool
    {
        if ($this->restricted_photographers !== null) return (bool) $this->restricted_photographers;
        if ($this->parent) return $this->parent->effective_restricted_photographers;
        return false;
    }

    public function getEffectiveIsHiddenAttribute(): bool
    {
        return $this->is_hidden || ($this->parent ? $this->parent->effective_is_hidden : false);
    }

    public function parent()
    {
        return $this->belongsTo(GalleryGroup::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(GalleryGroup::class, 'parent_id')->with(['children', 'galleries']);
    }

    public function galleries()
    {
        return $this->hasMany(Gallery::class);
    }

    public function tenants()
    {
        return $this->belongsToMany(Tenant::class);
    }
}
