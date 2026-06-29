<?php

namespace App\Models;

use App\Enums\Brand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Tenant extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'name',
        'domain',
        'invoice_frequency',
        'brand'
    ];

    protected $casts = [
        'brand' => Brand::class,
    ];

    public function users()
    {
        return $this->belongsToMany(User::class);
    }

    public function galleryGroups()
    {
        return $this->belongsToMany(GalleryGroup::class);
    }
}
