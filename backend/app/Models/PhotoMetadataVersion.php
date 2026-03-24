<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PhotoMetadataVersion extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'photo_id',
        'user_id',
        'title',
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
