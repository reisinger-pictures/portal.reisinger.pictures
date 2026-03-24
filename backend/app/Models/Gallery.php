<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Cache;
use Laravel\Scout\Searchable;

class Gallery extends Model
{
    use HasFactory;

    use Searchable;

    public const UPDATED_AT = null;
    
    protected $visible = [
        'id', 'gallery_group_id', 'name', 'slug', 'type', 'is_live', 
        'is_public', 'allow_client_metadata_edit', 'apply_metadata_to_photos', 
        'default_title', 'default_description', 'default_keywords', 
        'default_location', 'default_city', 'default_state', 'default_country', 'default_iso_country',
        'expires_at', 'created_at', 'full_path', 'photos', 'galleryGroup'
    ];

    protected $fillable = [
        'gallery_group_id',
        'name',
        'slug',
        'type',
        'is_live',
        'is_public',
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
        'expires_at',
        'deleted_at'
    ];

    protected $casts = [
        'is_public' => 'boolean',
        'is_live' => 'boolean',
        'allow_client_metadata_edit' => 'boolean',
        'apply_metadata_to_photos' => 'boolean',
        'expires_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    // Dieses Attribut wird bei JSON-Responses automatisch angehängt
    protected $appends = ['full_path'];

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
        static::saved(function () { Cache::forget('gallery_tree_admin'); });
        static::deleted(function () { Cache::forget('gallery_tree_admin'); });
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
        ];
    }
}
