<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Laravel\Scout\Searchable;

class Photo extends Model
{
    use HasFactory;

    use Searchable;

    public const UPDATED_AT = null;

    protected $visible = [
        'id', 'gallery_id', 'filename', 'lr_uuid', 'width', 'height', 
        'title', 'description', 'artist', 'headline', 'keywords', 'location', 
        'city', 'state', 'country', 'iso_country', 'created_at', 'url', 'thumb_url', 
        'rating', 'comment', 'gallery'
    ];

    protected $fillable = [
        'gallery_id',
        'filename',
        'lr_uuid',
        'width',
        'height',
        'title',
        'description',
        'artist',
        'headline',
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

    public function versions()
    {
        return $this->hasMany(PhotoMetadataVersion::class)->orderBy('id', 'desc');
    }

    public function toSearchableArray()
    {
        return [
            'id' => $this->id,
            'filename' => $this->filename,
            'title' => $this->title,
            'description' => $this->description,
            'artist' => $this->artist,
            'headline' => $this->headline,
            'keywords' => $this->keywords,
            'location' => $this->location,
            'gallery_id' => $this->gallery_id,
        ];
    }
}
