<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class GalleryInvite extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;
    
    protected $fillable = [
        'gallery_id',
        'token',
        'name',
        'created_at'
    ];

    public function gallery()
    {
        return $this->belongsTo(Gallery::class);
    }
}
