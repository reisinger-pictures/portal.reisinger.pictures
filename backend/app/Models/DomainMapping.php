<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class DomainMapping extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'domain',
        'role_id',
        'gallery_group_id'
    ];

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function galleryGroup()
    {
        return $this->belongsTo(GalleryGroup::class);
    }
}
