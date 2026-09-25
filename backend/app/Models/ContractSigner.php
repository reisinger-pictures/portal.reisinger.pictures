<?php

namespace App\Models;

use App\Exceptions\ContractIdentityException;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Throwable;

class ContractSigner extends Model
{
    use HasFactory, HasUuids;

    public const IDENTITY_UNIQUE_INDEX = 'contract_signers_scope_normalized_email_unique';

    public const DUPLICATE_JOIN_ERROR = 'Für diese E-Mail besteht bereits ein Vertrag.';

    protected $fillable = [
        'contract_id', 'name', 'email', 'normalized_email', 'join_scope_key', 'roles',
        'personal_token', 'status', 'signed_at',
    ];

    protected $casts = [
        'roles' => 'array',
        'signed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $signer): void {
            $signer->prepareIdentityForWrite();
        });

        static::updating(function (self $signer): void {
            $signer->assertIdentityIsImmutable();
        });
    }

    public function contract()
    {
        return $this->belongsTo(Contract::class);
    }

    public function auditLogs()
    {
        return $this->hasMany(ContractAuditLog::class);
    }

    /**
     * Return true only for the identity index owned by V039. Other unique
     * constraints (for example a random personal-token collision) must not be
     * hidden behind a duplicate-email response.
     */
    public static function isIdentityUniqueViolation(Throwable $exception): bool
    {
        $message = strtolower($exception->getMessage());
        $index = strtolower(self::IDENTITY_UNIQUE_INDEX);

        if (str_contains($message, $index)) {
            return true;
        }

        return str_contains($message, 'unique constraint failed')
            && str_contains($message, 'contract_signers.join_scope_key')
            && str_contains($message, 'normalized_email');
    }

    private function prepareIdentityForWrite(): void
    {
        $contractId = $this->getAttribute('contract_id');
        if (! is_string($contractId) || $contractId === '') {
            throw new ContractIdentityException('Der Vertragsscope fehlt.');
        }

        $contract = Contract::query()->find($contractId);
        if (! $contract instanceof Contract) {
            throw new ContractIdentityException('Der Vertragsscope ist ungültig.');
        }

        $email = $this->getAttribute('email');
        if (! is_string($email)) {
            throw new ContractIdentityException('Die E-Mail-Identität fehlt.');
        }

        $normalizedEmail = Contract::normalizeSignerEmail($email);
        $scopeKey = Contract::signerJoinScopeKey($contract);

        $providedEmail = $this->getAttribute('normalized_email');
        if ($providedEmail !== null
            && (! is_string($providedEmail)
                || Contract::normalizeSignerEmail($providedEmail) !== $providedEmail
                || $providedEmail !== $normalizedEmail)) {
            throw new ContractIdentityException('Die normalisierte E-Mail ist ungültig.');
        }

        $providedScope = $this->getAttribute('join_scope_key');
        if ($providedScope !== null
            && (! is_string($providedScope)
                || Contract::validateJoinScopeKey($providedScope) !== $providedScope
                || $providedScope !== $scopeKey)) {
            throw new ContractIdentityException('Der Vertragsscope ist ungültig.');
        }

        $this->setAttribute('normalized_email', $normalizedEmail);
        $this->setAttribute('join_scope_key', $scopeKey);
    }

    private function assertIdentityIsImmutable(): void
    {
        $originalEmail = $this->getRawOriginal('normalized_email');

        if ($this->isDirty('contract_id')) {
            throw new ContractIdentityException('Der Vertragsscope ist unveränderlich.');
        }

        if ($this->isDirty('join_scope_key')) {
            throw new ContractIdentityException('Der Vertragsscope ist unveränderlich.');
        }

        if ($this->isDirty('normalized_email')) {
            throw new ContractIdentityException('Die E-Mail-Identität ist unveränderlich.');
        }

        if ($this->isDirty('email')) {
            $newEmail = $this->getAttribute('email');
            if (! is_string($newEmail)
                || $originalEmail === null
                || Contract::normalizeSignerEmail($newEmail) !== $originalEmail) {
                throw new ContractIdentityException('Die E-Mail-Identität ist unveränderlich.');
            }
        }
    }
}
