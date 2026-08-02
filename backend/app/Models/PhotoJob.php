<?php

namespace App\Models;

use App\Casts\AsBrand;
use App\Enums\PhotoJobStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PhotoJob extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'brand',
        'owner_id',
        'assignee_id',
        'title',
        'lightroom_catalog',
        'total_count',
        'selected_count',
        'target_gallery_id',
        'is_private',
        'status',
        'position',
    ];

    protected $casts = [
        'brand' => AsBrand::class,
        'total_count' => 'integer',
        'selected_count' => 'integer',
        'is_private' => 'boolean',
        'status' => 'string',
        'position' => 'integer',
    ];

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function targetGallery()
    {
        return $this->belongsTo(Gallery::class, 'target_gallery_id');
    }

    protected static function booted()
    {
        static::saving(function ($photoJob) {
            $allowedStatuses = ['shooting', 'culling', 'bearbeitung', 'export', 'veroeffentlicht'];
            if (!in_array($photoJob->status, $allowedStatuses)) {
                throw new \InvalidArgumentException("Ungültiger Bildbearbeitungsstatus: {$photoJob->status}");
            }
        });
    }
}