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
        static::saved(function () { Cache::forget('gallery_tree_admin'); });
        static::deleted(function () { Cache::forget('gallery_tree_admin'); });
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
