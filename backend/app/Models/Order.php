<?php

namespace App\Models;

use App\Casts\AsBrand;
use App\Support\ActorIdentity;
use App\Support\ModelStatusGuard;
use App\Support\PersistedMoney;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use HasFactory, HasUuids;

    public const UPDATED_AT = null;

    /**
     * The current order workflow states.
     *
     * Persisted rows may still carry a legacy value that is no longer part of
     * this list; such a row must not fail unrelated saves (see
     * {@see ModelStatusGuard::assertTransitionAllowed()}).
     *
     * @var array<int, string>
     */
    public const ALLOWED_STATUSES = [
        'pending',
        'invoice_created',
        'pending_payment',
        'paid',
        'overdue',
        'cancelled',
        'disputed',
        'refunded',
        'delivery_note',
        'archived_in_collective',
    ];

    protected $attributes = [
        'payment_intent_generation' => 1,
        'payment_failure_count' => 0,
    ];

    protected $fillable = [
        'user_id',
        'guest_id',
        'status',
        'brand',
        'total_amount',
        'stripe_fee_cents',
        'coupon_id',
        'coupon_discount_cents',
        'is_quote_request',
        'ip_address',
        'stripe_payment_intent_id',
        'checkout_idempotency_key',
        'checkout_fingerprint',
        'payment_intent_generation',
        'payment_failure_count',
        'last_payment_failure_at',
        'last_payment_decline_code',
        'quote_status',
        'withdrawal_waived',
        'withdrawal_consent_at',
    ];

    protected $casts = [
        'guest_id' => 'string',
        'total_amount' => 'integer',
        'stripe_fee_cents' => 'integer',
        'coupon_discount_cents' => 'integer',
        'payment_intent_generation' => 'integer',
        'payment_failure_count' => 'integer',
        'last_payment_failure_at' => 'datetime',
        'is_quote_request' => 'boolean',
        'withdrawal_waived' => 'boolean',
        'withdrawal_consent_at' => 'datetime',
        'brand' => AsBrand::class,
    ];

    public function coupon()
    {
        return $this->belongsTo(Coupon::class);
    }

    /**
     * The single owner predicate for customer-facing order queries.
     *
     * A null users.id is never used as a wildcard: registered actors match
     * user_id only, transient guests match guest_id only, and legacy
     * both-null rows match neither.
     */
    public function scopeOwnedBy(Builder $query, ?User $actor): Builder
    {
        return ActorIdentity::scopeOrders($query, $actor);
    }

    protected static function booted()
    {
        static::saving(function ($order) {
            ActorIdentity::assertOrderOwnerInvariant($order->user_id, $order->guest_id);
            PersistedMoney::assertFitsCents($order->total_amount, 'orders.total_amount');

            // Transition guard: a persisted legacy status must not break saves
            // that do not touch the status column (e.g. payment-failure
            // bookkeeping or the withdrawal consent snapshot).
            ModelStatusGuard::assertTransitionAllowed(
                $order,
                'status',
                self::ALLOWED_STATUSES,
                'Bestellstatus',
            );
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function invoiceSnapshot()
    {
        return $this->hasOne(InvoiceSnapshot::class);
    }

    public function downloadLogs(): HasMany
    {
        return $this->hasMany(DownloadLog::class);
    }
}
