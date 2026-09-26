<?php

namespace App\Services;

use App\Exceptions\ContractIdentityException;
use App\Exceptions\ContractUnavailableException;
use App\Models\Contract;
use App\Models\ContractSigner;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ContractTemplateService
{
    private ContractPricingService $contractPricingService;

    public function __construct(?ContractPricingService $contractPricingService = null)
    {
        $this->contractPricingService = $contractPricingService ?? new ContractPricingService;
    }

    /**
     * Create a new contract instance from a template, including a signer.
     *
     * The template row is locked and the identity is also protected by a
     * cache lock. This serializes the read-before-create sequence
     * without returning an existing personal token to an
     * unauthenticated caller. Contract and signer are written in one
     * transaction so a failing signer insert or a deadline crossing during
     * the write never leaves an orphaned contract behind.
     *
     * @param  Contract  $template  The template to replicate
     * @param  array  $signerData  Keys: name, email, roles, personal_token
     * @return array{created: bool, instance: ?Contract, signer: ?ContractSigner, error: ?string, status: int}
     */
    public function createInstance(Contract $template, array $signerData): array
    {
        $email = Contract::normalizeSignerEmail($signerData['email']);
        $lockKey = Contract::joinLockKey($template->getKey(), $email);

        try {
            return Cache::lock($lockKey, 30)->block(10, function () use ($template, $signerData, $email): array {
                return DB::transaction(function () use ($template, $signerData, $email): array {
                    $lockedTemplate = Contract::query()
                        ->whereKey($template->getKey())
                        ->lockForUpdate()
                        ->first();

                    if ($lockedTemplate === null || $lockedTemplate->type !== 'template') {
                        return [
                            'created' => false,
                            'instance' => null,
                            'signer' => null,
                            'error' => 'Vertrag nicht verfügbar',
                            'status' => 410,
                        ];
                    }

                    $availabilityError = $lockedTemplate->publicAvailabilityError(Contract::databaseNow());
                    if ($availabilityError !== null || ! $lockedTemplate->isPubliclyAvailableAtDatabaseTime()) {
                        $availabilityError ??= $lockedTemplate->fresh()?->publicAvailabilityError(Contract::databaseNow())
                            ?? 'Vertrag nicht verfügbar';

                        return [
                            'created' => false,
                            'instance' => null,
                            'signer' => null,
                            'error' => $availabilityError,
                            'status' => 410,
                        ];
                    }

                    $roles = array_values($signerData['roles']);
                    $availableRoles = $lockedTemplate->available_roles ?? [];
                    if (array_diff($roles, $availableRoles) !== []) {
                        throw ValidationException::withMessages([
                            'roles' => 'Die ausgewählte Rolle ist für diesen Vertrag nicht verfügbar.',
                        ]);
                    }

                    if (count($roles) > 1 && ! $lockedTemplate->allow_multiple_roles_per_signer) {
                        throw ValidationException::withMessages([
                            'roles' => 'Mehrere Rollen pro Unterzeichner sind nicht erlaubt.',
                        ]);
                    }

                    // The cache lock, locked template row, canonical duplicate
                    // SELECT and instance/signer INSERT are one transaction.
                    // The V039 unique index is the final authority if another
                    // connection wins the race after this check.
                    $scopeKey = Contract::templateJoinScopeKey($lockedTemplate);
                    $existingSigner = ContractSigner::query()
                        ->where('join_scope_key', $scopeKey)
                        ->where('normalized_email', $email)
                        ->orderBy('created_at')
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->first();

                    // The email is not a credential. The caller must receive a
                    // conflict without any part of the existing signer/token.
                    if ($existingSigner !== null) {
                        return [
                            'created' => false,
                            'instance' => null,
                            'signer' => null,
                            'error' => ContractSigner::DUPLICATE_JOIN_ERROR,
                            'status' => 409,
                        ];
                    }

                    // Recheck immediately before the write. The template row is
                    // locked for this transaction, while the SQL predicate uses
                    // the database clock so an expiry cannot be hidden by a PHP
                    // clock crossing between validation and insert.
                    if (! $lockedTemplate->isPubliclyAvailableAtDatabaseTime()) {
                        $availabilityError = $lockedTemplate->fresh()?->publicAvailabilityError(Contract::databaseNow())
                            ?? 'Vertrag nicht verfügbar';

                        return [
                            'created' => false,
                            'instance' => null,
                            'signer' => null,
                            'error' => $availabilityError,
                            'status' => 410,
                        ];
                    }

                    $templateSnapshot = $this->contractPricingService->normalizeSnapshot(
                        $lockedTemplate->items,
                        $lockedTemplate->discounts,
                    );

                    if (! $this->contractPricingService->canCopySnapshotWithoutReordering(
                        $lockedTemplate->items,
                        $lockedTemplate->discounts,
                    )) {
                        throw ValidationException::withMessages([
                            'items' => 'Die Reihenfolge der Legacy-Preispositionen kann beim Kopieren der Vorlage nicht verlustfrei erhalten werden.',
                        ]);
                    }

                    // Validate both arithmetic and the persisted-money ceiling
                    // before inserting either the instance or its signer.
                    $this->contractPricingService->preflightSnapshot($templateSnapshot);

                    $instance = Contract::create([
                        'type' => 'contract',
                        'template_id' => $lockedTemplate->getKey(),
                        'status' => 'active',
                        'billing_details' => $lockedTemplate->billing_details,
                        'items' => $templateSnapshot['items'],
                        'discounts' => $templateSnapshot['discounts'],
                        'terms_html' => $lockedTemplate->terms_html,
                        'available_roles' => $lockedTemplate->available_roles,
                        'allow_multiple_roles_per_signer' => $lockedTemplate->allow_multiple_roles_per_signer,
                        'brand' => $lockedTemplate->brand,
                        'join_token' => null,
                        'expires_at' => $lockedTemplate->expires_at,
                        'closes_at' => $lockedTemplate->closes_at,
                        'content_version' => 0,
                    ]);

                    $signer = ContractSigner::create([
                        'contract_id' => $instance->getKey(),
                        'name' => $signerData['name'],
                        'email' => $email,
                        'normalized_email' => $email,
                        'join_scope_key' => $scopeKey,
                        'roles' => $roles,
                        'personal_token' => $signerData['personal_token'],
                        'status' => 'joined',
                    ]);

                    // Roll back both new rows if the deadline crosses while the
                    // instance/signer pair is being written.
                    if (! $lockedTemplate->isPubliclyAvailableAtDatabaseTime()
                        || ! $instance->isPubliclyAvailableAtDatabaseTime()) {
                        $availabilityError = $instance->fresh()?->publicAvailabilityError(Contract::databaseNow())
                            ?? $lockedTemplate->fresh()?->publicAvailabilityError(Contract::databaseNow())
                            ?? 'Vertrag nicht verfügbar';

                        throw new ContractUnavailableException($availabilityError);
                    }

                    return [
                        'created' => true,
                        'instance' => $instance,
                        'signer' => $signer,
                        'error' => null,
                        'status' => 201,
                    ];
                }, 3);
            });
        } catch (ContractUnavailableException $exception) {
            return [
                'created' => false,
                'instance' => null,
                'signer' => null,
                'error' => $exception->getMessage(),
                'status' => 410,
            ];
        } catch (ContractIdentityException $exception) {
            return [
                'created' => false,
                'instance' => null,
                'signer' => null,
                'error' => $exception->getMessage(),
                'status' => 422,
            ];
        } catch (UniqueConstraintViolationException $exception) {
            if (! ContractSigner::isIdentityUniqueViolation($exception)) {
                throw $exception;
            }

            return [
                'created' => false,
                'instance' => null,
                'signer' => null,
                'error' => ContractSigner::DUPLICATE_JOIN_ERROR,
                'status' => 409,
            ];
        }
    }
}
