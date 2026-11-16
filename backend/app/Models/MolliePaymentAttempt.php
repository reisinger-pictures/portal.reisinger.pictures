<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One Mollie payment attempt for one immutable order generation.
 *
 * The immutable columns (order, generation, idempotency key, mode, credential
 * fingerprint, amount, currency, snapshot) identify the payment. Only the
 * provider linkage and observation fields are mutable.
 *
 * @property string $id
 * @property string $order_id
 * @property int $generation
 * @property string $idempotency_key
 * @property string|null $mollie_payment_id
 * @property string $mode
 * @property string $credential_fingerprint
 * @property int $amount_cents
 * @property string $currency
 * @property string $status
 * @property string|null $checkout_url
 * @property array<string, mixed> $snapshot
 * @property Carbon|null $last_reconciled_at
 * @property Carbon|null $paid_at
 */
class MolliePaymentAttempt extends Model
{
    use HasUuids;

    public const STATUS_CREATING = 'creating';

    public const STATUS_OPEN = 'open';

    public const STATUS_PENDING = 'pending';

    public const STATUS_AUTHORIZED = 'authorized';

    public const STATUS_PAID = 'paid';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELED = 'canceled';

    public const STATUS_EXPIRED = 'expired';

    /**
     * A create whose outcome is unknown (timeout, network failure, lost
     * response, credential change, or an expired idempotency window). Never
     * replaced automatically; an operator reconciles it first.
     */
    public const STATUS_AMBIGUOUS = 'ambiguous';

    /**
     * Statuses in which the attempt can still become paid and therefore must
     * not be replaced by a new attempt.
     *
     * @var array<int, string>
     */
    public const ACTIVE_STATUSES = [
        self::STATUS_CREATING,
        self::STATUS_OPEN,
        self::STATUS_PENDING,
        self::STATUS_AUTHORIZED,
        self::STATUS_AMBIGUOUS,
    ];

    /**
     * Statuses that a retry may replace with a new generation.
     *
     * @var array<int, string>
     */
    public const RETRYABLE_STATUSES = [
        self::STATUS_FAILED,
        self::STATUS_CANCELED,
        self::STATUS_EXPIRED,
    ];

    protected $fillable = [
        'order_id',
        'generation',
        'idempotency_key',
        'mollie_payment_id',
        'mode',
        'credential_fingerprint',
        'amount_cents',
        'currency',
        'status',
        'checkout_url',
        'snapshot',
        'last_reconciled_at',
        'paid_at',
    ];

    protected $casts = [
        'generation' => 'integer',
        'amount_cents' => 'integer',
        'snapshot' => 'array',
        'last_reconciled_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function scopeForOrder(Builder $query, Order|string $order): Builder
    {
        return $query->where('order_id', $order instanceof Order ? $order->getKey() : $order);
    }

    /**
     * Attempts whose provider state can still change and which therefore need
     * webhook/reconciliation attention.
     */
    public function scopeNeedsReconciliation(Builder $query): Builder
    {
        return $query->whereIn('status', [
            self::STATUS_CREATING,
            self::STATUS_OPEN,
            self::STATUS_PENDING,
            self::STATUS_AUTHORIZED,
        ]);
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function isRetryable(): bool
    {
        return in_array($this->status, self::RETRYABLE_STATUSES, true);
    }
}
