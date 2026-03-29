<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Laravel\Scout\Searchable;

class Photo extends Model
{
    use HasFactory, HasUuids;

    use Searchable;

    public const UPDATED_AT = null;

    protected $visible = [
        'id', 'gallery_id', 'filename', 'lr_uuid', 'width', 'height', 
        'title', 'description', 'artist', 'keywords', 'location', 
        'city', 'state', 'country', 'iso_country', 'created_at', 'url', 'thumb_url', 
        'rating', 'comment', 'gallery', 'artist'
    ];

    protected $fillable = [
        'gallery_id',
        'filename',
        'lr_uuid',
        'width',
        'height',
        'title',
        'description',
        'user_id',
        'keywords',
        'location',
        'city',
        'state',
        'country',
        'iso_country'
    ];

    public function gallery()
    {
        return $this->belongsTo(Gallery::class);
    }

    protected $appends = ['artist', 'url', 'thumb_url', 'srcset'];
    protected $with = ['gallery'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function getArtistAttribute()
    {
        if (!$this->user) return null;
        return $this->user->metadata_copyright ?: $this->user->name;
    }

    
    public function getUrlAttribute() {
        if (!$this->gallery) return null;
        return '/api/media/' . $this->gallery->slug . '/_thumbs/2000/' . $this->id . '.webp';
    }

    public function getThumbUrlAttribute() {
        if (!$this->gallery) return null;
        return '/api/media/' . $this->gallery->slug . '/_thumbs/800/' . $this->id . '.webp';
    }

    public function getSrcsetAttribute() {
        if (!$this->gallery) return null;
        $baseUrl = '/api/media/' . $this->gallery->slug;
        return $baseUrl . '/_thumbs/400/' . $this->filename . '.webp 400w, ' . 
               $baseUrl . '/_thumbs/800/' . $this->filename . '.webp 800w, ' . 
               $baseUrl . '/_thumbs/1200/' . $this->filename . '.webp 1200w';
    }

    public function versions()
    {
        return $this->hasMany(PhotoMetadataVersion::class)->orderBy('id', 'desc');
    }

    public function shouldBeSearchable()
    {
        // Bilder aus Selection-Galerien komplett vom Suchindex ausschließen
        return $this->gallery && $this->gallery->type !== 'selection';
    }

    public function toSearchableArray()
    {
        return [
            'id' => $this->id,
            'filename' => $this->filename,
            'title' => $this->title,
            'description' => $this->description,
            'artist' => $this->artist,
            'keywords' => $this->keywords,
            'location' => $this->location,
            'gallery_id' => $this->gallery_id,
        ];
    }
}
