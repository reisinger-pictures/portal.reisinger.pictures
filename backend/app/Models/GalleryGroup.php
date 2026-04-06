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
        'deleted_at'
    ];

    protected $casts = [
        'is_public' => 'boolean',
        'deleted_at' => 'datetime',
    ];

    protected static function booted()
    {
        static::saved(function () { 
            \Illuminate\Support\Facades\DB::afterCommit(fn() => Cache::forget('gallery_tree_admin')); 
        });
        static::deleted(function () { 
            \Illuminate\Support\Facades\DB::afterCommit(fn() => Cache::forget('gallery_tree_admin')); 
        });
    }

    protected $appends = ['effective_is_editorial_only', 'effective_is_hidden'];

    public function getEffectiveIsEditorialOnlyAttribute(): bool
    {
        if ($this->is_editorial_only !== null) return (bool) $this->is_editorial_only;
        if ($this->parent) return $this->parent->effective_is_editorial_only;
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
}
