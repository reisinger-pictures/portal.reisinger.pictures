<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class PhotoMetadataVersion extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'photo_id',
        'user_id',
        'mime_type',
        'title',
        'headline',
        'description',
        'keywords',
        'location',
        'city',
        'state',
        'country',
        'iso_country'
    ];

    public function photo()
    {
        return $this->belongsTo(Photo::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
