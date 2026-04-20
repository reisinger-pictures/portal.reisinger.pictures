<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class DownloadLog extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'user_name_snapshot',
        'gallery_id',
        'gallery_name_snapshot',
        'order_id',
        'item_type', // 'single_image', 'full_zip'
        'resolution_tier',
        'user_agent',
        'payload',
        'photo_count'
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    public function gallery()
    {
        return $this->belongsTo(Gallery::class);
    }
}
