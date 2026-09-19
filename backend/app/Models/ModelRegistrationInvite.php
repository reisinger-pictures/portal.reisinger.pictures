<?php

namespace App\Models;

use App\Casts\AsBrand;
use App\Enums\Brand;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Einmal-Einladungstoken für die öffentliche Model-Registrierung.
 *
 * Einladung = ein Act. Der Token wird beim Submit atomar über
 * `UPDATE ... WHERE used_at IS NULL` verbraucht. Ablauf: 7 Tage.
 */
class ModelRegistrationInvite extends Model
{
    use HasFactory, HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'token',
        'email',
        'label',
        'brand',
        'invited_by',
        'expires_at',
        'used_at',
        'act_id',
        'customer_id',
        'created_at',
    ];

    protected $casts = [
        'brand' => AsBrand::class,
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function act(): BelongsTo
    {
        return $this->belongsTo(Act::class);
    }

    public function isUsable(): bool
    {
        return $this->used_at === null
            && $this->expires_at !== null
            && $this->expires_at->isFuture();
    }

    public function status(): string
    {
        if ($this->used_at !== null) {
            return 'redeemed';
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return 'expired';
        }

        return 'open';
    }

    public function brandValue(): ?string
    {
        $brand = $this->brand;

        if ($brand instanceof Brand) {
            return $brand->value;
        }

        return $brand !== null ? (string) $brand : null;
    }
}
