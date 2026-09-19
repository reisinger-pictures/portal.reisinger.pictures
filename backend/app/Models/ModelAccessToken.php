<?php

namespace App\Models;

use App\Enums\Brand;
use App\Support\BrandRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Wiederverwendbarer Profil-Zugangs-Token (Profil-Magic-Link).
 *
 * TTL 24 h. Ein Token ist „aktiv", solange er nicht widerrufen ist und noch
 * nicht abgelaufen ist. „unique-aktiv": beim Ausstellen wird ein bestehender
 * aktiver Token desselben Customers widerrufen, sodass höchstens einer aktiv ist.
 */
class ModelAccessToken extends Model
{
    use HasFactory, HasUuids;

    public const TTL_HOURS = 24;

    protected $fillable = [
        'customer_id',
        'token',
        'expires_at',
        'last_used_at',
        'revoked_at',
        'created_by',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'last_used_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')->where('expires_at', '>', now());
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null
            && $this->expires_at !== null
            && $this->expires_at->isFuture();
    }

    /**
     * Revoke any currently active token for the customer and issue a fresh one.
     */
    public static function issueFor(Customer $customer, ?string $createdBy = null, int $ttlHours = self::TTL_HOURS): self
    {
        static::where('customer_id', $customer->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'updated_at' => now()]);

        return static::create([
            'customer_id' => $customer->id,
            'token' => Str::random(64),
            'expires_at' => now()->addHours($ttlHours),
            'created_by' => $createdBy,
        ]);
    }

    public function url(): string
    {
        $brand = $this->customer?->brand;

        return BrandRegistry::frontendUrl($brand instanceof Brand ? $brand : null)
            .'/model-profil/'.$this->token;
    }
}
