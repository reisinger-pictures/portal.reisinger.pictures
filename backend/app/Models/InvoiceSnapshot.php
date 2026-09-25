<?php

namespace App\Models;

use App\Casts\AsBrand;
use App\Support\PersistedMoney;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class InvoiceSnapshot extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $primaryKey = 'invoice_number';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'order_id',
        'invoice_number',
        'brand',
        'customer_details',
        'total_net',
        'total_gross',
        'tax_rate',
    ];

    protected $casts = [
        'customer_details' => 'array',
        'total_net' => 'integer',
        'total_gross' => 'integer',
        'tax_rate' => 'decimal:2',
        'brand' => AsBrand::class,
    ];

    /**
     * Immutable organization attribution captured when the purchase snapshot
     * is written. The existing JSON customer_details column is the storage
     * contract; no order or snapshot schema change is required.
     *
     * A present key is authoritative, including a null/invalid value. Missing
     * keys identify legacy snapshots and are handled by the caller's explicit
     * current-membership fallback.
     */
    public const PURCHASE_ORG_ID_KEY = 'org_id';

    /**
     * Reserved operational metadata in the existing JSON snapshot column.
     *
     * The customer/line-item evidence remains frozen, while this marker gives
     * the mail dispatcher a durable, non-expiring enqueue claim that survives
     * cache eviction or a process restart. It is not a delivery receipt or an
     * exactly-once SMTP guarantee, and it is intentionally kept out of the
     * public snapshot representation.
     */
    public const MAIL_DISPATCH_KEY = '_invoice_mail_dispatch';

    /**
     * Source identity for an invoice created while closing a contract. The
     * existing JSON snapshot column is sufficient; no order or invoice schema
     * extension is required for contract-close idempotency.
     */
    public const CONTRACT_ID_KEY = 'contract_id';

    public function invoiceMailDispatchClaimed(): bool
    {
        $details = $this->customer_details;

        return is_array($details) && array_key_exists(self::MAIL_DISPATCH_KEY, $details);
    }

    public function claimInvoiceMailDispatch(): void
    {
        $details = is_array($this->customer_details) ? $this->customer_details : [];
        $details[self::MAIL_DISPATCH_KEY] = [
            'state' => 'claimed',
            'claimed_at' => now()->toISOString(),
        ];

        $this->forceFill(['customer_details' => $details])->save();
    }

    /**
     * Return the customer-facing/evidence payload without internal dispatch
     * metadata.
     *
     * @return array<string, mixed>
     */
    public function customerDetailsForPresentation(): array
    {
        $details = is_array($this->customer_details) ? $this->customer_details : [];
        unset($details[self::MAIL_DISPATCH_KEY]);

        return $details;
    }

    public function hasPurchaseOrgSnapshot(): bool
    {
        $details = $this->purchaseOrgDetails();

        return $details !== null && array_key_exists(self::PURCHASE_ORG_ID_KEY, $details);
    }

    public function purchaseOrgId(): ?string
    {
        $details = $this->purchaseOrgDetails();
        if ($details === null || ! array_key_exists(self::PURCHASE_ORG_ID_KEY, $details)) {
            return null;
        }

        $orgId = $details[self::PURCHASE_ORG_ID_KEY];
        if (! is_string($orgId)
            || trim($orgId) === ''
            || trim($orgId) !== $orgId
            || ! Str::isUuid($orgId)) {
            return null;
        }

        return $orgId;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function purchaseOrgDetails(): ?array
    {
        try {
            $details = $this->customer_details;
        } catch (\Throwable) {
            return null;
        }

        return is_array($details) ? $details : null;
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    protected static function booted()
    {
        static::saving(function (self $snapshot): void {
            PersistedMoney::assertFitsCents($snapshot->total_net, 'invoice_snapshots.total_net');
            PersistedMoney::assertFitsCents($snapshot->total_gross, 'invoice_snapshots.total_gross');
        });

        static::creating(function ($snapshot) {
            if (empty($snapshot->invoice_number)) {
                $order = $snapshot->order;
                if ($order && $order->is_quote_request) {
                    $snapshot->invoice_number = 'A-'.strtoupper(Str::random(8));
                } else {
                    $prefix = ($order && $order->status === 'delivery_note') ? 'L-' : 'P-';
                    $snapshot->invoice_number = InvoiceSequence::getNextInvoiceNumber($prefix);
                }
            }
        });

        static::updating(function (self $snapshot): void {
            $snapshot->assertPurchaseOrgIdImmutable();
        });
    }

    /**
     * Enforce the application-level attribution invariant at the Eloquent
     * model boundary. A legacy snapshot without the key may be backfilled
     * once, but an existing key (including null or an invalid value) may
     * neither be replaced nor removed.
     *
     * This is deliberately not described as database-level immutability:
     * Query Builder/DB-facade and bulk Eloquent updates bypass model events.
     * Standard business logic that changes customer_details must use the
     * per-model Eloquent save/update path, as required by the backend
     * architecture; those low-level paths are trusted maintenance escape
     * hatches and must not be used to rewrite snapshot attribution.
     */
    public function assertPurchaseOrgIdImmutable(): void
    {
        if (! $this->exists || ! $this->isDirty('customer_details')) {
            return;
        }

        $originalDetails = $this->decodeCustomerDetails(
            $this->getRawOriginal('customer_details')
        );
        if ($originalDetails === null) {
            throw new \LogicException(
                'Malformed invoice snapshot customer_details cannot be changed.'
            );
        }

        if (! array_key_exists(self::PURCHASE_ORG_ID_KEY, $originalDetails)) {
            // Missing-key snapshots are the explicit legacy compatibility
            // case. They may receive a one-time attribution backfill.
            return;
        }

        $currentDetails = $this->decodeCustomerDetails(
            $this->getAttributes()['customer_details'] ?? null
        );
        if ($currentDetails === null) {
            throw new \LogicException(
                'Malformed invoice snapshot customer_details cannot be changed.'
            );
        }

        $originalOrgId = $originalDetails[self::PURCHASE_ORG_ID_KEY];
        $currentOrgId = $currentDetails[self::PURCHASE_ORG_ID_KEY] ?? null;

        if (! array_key_exists(self::PURCHASE_ORG_ID_KEY, $currentDetails)
            || $currentOrgId !== $originalOrgId) {
            throw new \LogicException(
                'Purchase-time organization attribution in an invoice snapshot is immutable.'
            );
        }
    }

    /**
     * Decode only valid JSON object/array roots for the immutability guard.
     * Invalid roots must fail closed instead of being mistaken for legacy
     * snapshots.
     *
     * @return array<mixed>|null
     */
    private function decodeCustomerDetails(mixed $raw): ?array
    {
        if (is_array($raw)) {
            return $raw;
        }

        if (! is_string($raw)) {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
