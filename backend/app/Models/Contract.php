<?php

namespace App\Models;

use App\Casts\AsBrand;
use App\Exceptions\ContractIdentityException;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Contract extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'type', 'template_id', 'expires_at',
        'status', 'billing_details', 'items', 'discounts', 'terms_html',
        'available_roles', 'allow_multiple_roles_per_signer',
        'join_token', 'closes_at', 'content_version', 'brand',
    ];

    protected $casts = [
        'billing_details' => 'array',
        'items' => 'array',
        'discounts' => 'array',
        'available_roles' => 'array',
        'allow_multiple_roles_per_signer' => 'boolean',
        'closes_at' => 'datetime',
        'expires_at' => 'datetime',
        'content_version' => 'integer',
        'brand' => AsBrand::class,
    ];

    public function signers()
    {
        return $this->hasMany(ContractSigner::class);
    }

    public function auditLogs()
    {
        return $this->hasMany(ContractAuditLog::class);
    }

    public function template()
    {
        return $this->belongsTo(Contract::class, 'template_id');
    }

    public function instances()
    {
        return $this->hasMany(Contract::class, 'template_id');
    }

    public function scopeTemplates($query)
    {
        return $query->where('type', 'template');
    }

    public function scopeInstances($query)
    {
        return $query->where('type', 'contract')->whereNotNull('template_id');
    }

    public const JOIN_SCOPE_DIRECT_PREFIX = 'contract:';

    public const JOIN_SCOPE_TEMPLATE_PREFIX = 'template:';

    /**
     * Normalize and validate the identity used by the unauthenticated join
     * paths. The original email remains untouched; this value is the durable
     * database identity and must never be inferred from a mutable relation.
     */
    public static function normalizeSignerEmail(string $email): string
    {
        $normalized = Str::lower(trim($email));

        if ($normalized === '' || strlen($normalized) > 255 || filter_var($normalized, FILTER_VALIDATE_EMAIL) === false) {
            throw new ContractIdentityException('Die E-Mail-Identität ist ungültig.');
        }

        return $normalized;
    }

    /**
     * Derive the immutable identity scope for a signer attached to a contract.
     *
     * Direct contracts use their own UUID. Every instance of a template uses
     * the template UUID, not the instance UUID. The template relation is
     * checked now so a malformed parent cannot be guessed into a scope.
     */
    public static function signerJoinScopeKey(self $contract): string
    {
        $contractId = self::normalizeUuid($contract->getKey(), 'Vertrag');

        if ($contract->type !== 'contract') {
            throw new ContractIdentityException('Ungültiger Vertragsscope.');
        }

        if ($contract->template_id === null) {
            return self::JOIN_SCOPE_DIRECT_PREFIX.$contractId;
        }

        $templateId = self::normalizeUuid($contract->template_id, 'Vorlage');
        $template = self::query()->find($templateId);

        if (! $template instanceof self
            || $template->type !== 'template'
            || strtolower((string) $template->getKey()) !== $templateId) {
            throw new ContractIdentityException('Ungültiger Template-Scope.');
        }

        return self::JOIN_SCOPE_TEMPLATE_PREFIX.$templateId;
    }

    /**
     * Derive a template scope from a validated template model.
     */
    public static function templateJoinScopeKey(self $template): string
    {
        if ($template->type !== 'template') {
            throw new ContractIdentityException('Ungültiger Template-Scope.');
        }

        return self::JOIN_SCOPE_TEMPLATE_PREFIX.self::normalizeUuid($template->getKey(), 'Vorlage');
    }

    public static function joinScopeKeyFor(self $contract): string
    {
        return self::signerJoinScopeKey($contract);
    }

    /**
     * Validate a persisted scope snapshot without trying to repair it.
     */
    public static function validateJoinScopeKey(string $scopeKey): string
    {
        if (preg_match('/^(contract|template):[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $scopeKey) !== 1) {
            throw new ContractIdentityException('Ungültiger Vertragsscope.');
        }

        return $scopeKey;
    }

    /**
     * Return the stable cache-lock identity for a join scope.
     *
     * The scope ID argument is deliberately kept separate from the durable
     * scope key for compatibility with the existing lock callers. The
     * database duplicate query always uses signerJoinScopeKey().
     */
    public static function joinLockKey(string $scopeId, string $email): string
    {
        return 'contract-join:'.$scopeId.':'.hash('sha256', self::normalizeSignerEmail($email));
    }

    private static function normalizeUuid(mixed $value, string $label): string
    {
        if (! Str::isUuid($value)) {
            throw new ContractIdentityException("Ungültige {$label}-UUID.");
        }

        return strtolower($value);
    }

    /**
     * Return the deadline that actually applies to a public contract path.
     *
     * New template instances snapshot both deadlines. Older instances may
     * still have null columns, so the template deadline is used as a
     * compatibility fallback. When both rows contain a deadline, the earlier
     * one wins: an instance can never outlive its template. This is computed
     * on read and never mass-updates legacy rows.
     */
    public function effectiveDeadline(string $attribute): ?CarbonInterface
    {
        $ownDeadline = $this->getAttribute($attribute);

        if ($this->template_id === null) {
            return $ownDeadline;
        }

        $template = $this->template;
        if ($template === null || $template->type !== 'template') {
            return $ownDeadline;
        }

        $templateDeadline = $template->getAttribute($attribute);
        if ($ownDeadline === null) {
            return $templateDeadline;
        }

        if ($templateDeadline === null) {
            return $ownDeadline;
        }

        return $ownDeadline->lessThan($templateDeadline) ? $ownDeadline : $templateDeadline;
    }

    public function effectiveExpiresAt(): ?CarbonInterface
    {
        return $this->effectiveDeadline('expires_at');
    }

    public function effectiveClosesAt(): ?CarbonInterface
    {
        return $this->effectiveDeadline('closes_at');
    }

    /**
     * Apply the same effective-deadline contract in SQL using the database
     * clock. The scope is intentionally conservative: a template-linked row
     * is valid only while both its own and its parent's non-null deadlines
     * are still in the future.
     */
    public function scopePubliclyAvailableAtDatabaseTime($query)
    {
        $databaseNow = DB::raw('CURRENT_TIMESTAMP');

        $query->where('status', 'active')
            ->where(function (Builder $query) use ($databaseNow): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', $databaseNow);
            })
            ->where(function (Builder $query) use ($databaseNow): void {
                $query->whereNull('closes_at')
                    ->orWhere('closes_at', '>', $databaseNow);
            })
            ->where(function (Builder $query) use ($databaseNow): void {
                $query->whereNull('template_id')
                    ->orWhereHas('template', function (Builder $template) use ($databaseNow): void {
                        $template->where('type', 'template')
                            ->where(function (Builder $template) use ($databaseNow): void {
                                $template->whereNull('expires_at')
                                    ->orWhere('expires_at', '>', $databaseNow);
                            })
                            ->where(function (Builder $template) use ($databaseNow): void {
                                $template->whereNull('closes_at')
                                    ->orWhere('closes_at', '>', $databaseNow);
                            });
                    });
            });

        return $query;
    }

    /**
     * Read the database clock once for a locked operation. PHP's application
     * clock remains appropriate for ordinary UI checks, but create/sign
     * transactions use this value at the point where the row is locked.
     */
    public static function databaseNow(): Carbon
    {
        // MariaDB 11.4 treats CURRENT_TIME as a reserved word, so it cannot
        // be used as an unquoted result alias. Keep this read portable across
        // MariaDB and SQLite; otherwise every public join/check path fails
        // with a 500 before validation or persistence runs.
        $row = DB::selectOne('SELECT CURRENT_TIMESTAMP AS contract_database_now');

        return Carbon::parse($row->contract_database_now, 'UTC');
    }

    public function isPubliclyAvailableAtDatabaseTime(): bool
    {
        return static::query()
            ->whereKey($this->getKey())
            ->publiclyAvailableAtDatabaseTime()
            ->exists();
    }

    /**
     * Return the reason why a public contract path is unavailable, if any.
     *
     * Both deadline columns are deliberately evaluated here so the join,
     * content, and signature endpoints cannot drift apart. A deadline is
     * inclusive: at the configured timestamp the public path is already
     * closed. Parent status is intentionally not inherited: closing a template
     * stops new instances, while already-created instances remain governed by
     * their own effective deadlines.
     */
    public function publicAvailabilityError(?CarbonInterface $at = null): ?string
    {
        if ($this->status !== 'active') {
            return 'Dieser Vertrag nimmt keine Unterschriften mehr an';
        }

        if ($this->template_id !== null) {
            $template = $this->template;
            if ($template === null || $template->type !== 'template') {
                return 'Dieser Vertrag nimmt keine Unterschriften mehr an';
            }
        }

        $at ??= now();

        if (($expiresAt = $this->effectiveExpiresAt()) !== null && ! $expiresAt->isAfter($at)) {
            return 'Der Vertragslink ist abgelaufen';
        }

        if (($closesAt = $this->effectiveClosesAt()) !== null && ! $closesAt->isAfter($at)) {
            return 'Die Signaturphase ist beendet';
        }

        return null;
    }

    public function isPubliclyAvailable(): bool
    {
        return $this->publicAvailabilityError() === null;
    }

    public function isTemplate(): bool
    {
        return $this->type === 'template';
    }

    public function isInstance(): bool
    {
        return $this->type === 'contract' && $this->template_id !== null;
    }
}
