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
        'restricted_photographers',
        'deleted_at'
    ];

    protected $casts = [
        'is_public' => 'boolean',
        'deleted_at' => 'datetime',
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
        if ($this->is_editorial_only !== null) return (bool) $this->is_editorial_only;
        if ($this->parent) return $this->parent->effective_is_editorial_only;
        return false;
    }

    

    

    public function getEffectiveIsFreeDownloadAttribute(): bool
    {
        if ($this->is_free_download !== null) return (bool) $this->is_free_download;
        if ($this->parent) return $this->parent->effective_is_free_download;
        return false;
    }

    public function getEffectiveRestrictedPhotographersAttribute(): bool
    {
        if ($this->restricted_photographers !== null) return (bool) $this->restricted_photographers;
        if ($this->parent) return $this->parent->effective_restricted_photographers;
        return false;
    }

    public function getEffectiveIsHiddenAttribute(): bool
    {
        if ($this->is_hidden !== null) return (bool) $this->is_hidden;
        if ($this->parent) return $this->parent->effective_is_hidden;
        return false;
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
