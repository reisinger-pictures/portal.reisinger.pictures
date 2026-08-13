<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VolumePresetTier extends Model
{
    protected $fillable = ['volume_preset_id', 'position', 'min_quantity', 'price_cents'];

    protected $casts = [
        'position' => 'integer',
        'min_quantity' => 'integer',
        'price_cents' => 'integer',
    ];

    public function preset(): BelongsTo
    {
        return $this->belongsTo(VolumePreset::class);
    }
}
