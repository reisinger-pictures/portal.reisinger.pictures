<?php

namespace App\Http\Controllers;

use App\Exceptions\ContractUnavailableException;
use App\Models\Contract;
use App\Models\ContractSigner;
use App\Services\ContractAuditService;
use App\Services\ContractCloseService;
use App\Services\ContractTemplateService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ContractJoinController extends Controller
{
    private ContractTemplateService $contractTemplateService;

    private ContractAuditService $contractAuditService;

    private ContractCloseService $contractCloseService;

    public function __construct(ContractTemplateService $contractTemplateService, ContractAuditService $contractAuditService, ContractCloseService $contractCloseService)
    {
        $this->contractTemplateService = $contractTemplateService;
        $this->contractAuditService = $contractAuditService;
        $this->contractCloseService = $contractCloseService;
    }

    public function check($token)
    {
        $contract = Contract::where('join_token', $token)->first();
        if (! $contract) {
            return response()->json(['error' => 'Ungültiger Vertragslink'], 404);
        }

        $availabilityResponse = $this->availabilityResponse($contract);
        if ($availabilityResponse !== null) {
            return $availabilityResponse;
        }

        return response()->json([
            'contract_id' => $contract->id,
            'status' => $contract->status,
            'type' => $contract->type,
            'available_roles' => $contract->available_roles,
            'allow_multiple_roles' => $contract->allow_multiple_roles_per_signer,
            'terms_html' => $contract->terms_html,
        ]);
    }

    public function join(Request $request, $token)
    {
        $contract = Contract::where('join_token', $token)->first();
        if (! $contract) {
            return response()->json(['error' => 'Vertrag nicht verfügbar'], 410);
        }

        // Reject an expired/closed link before exposing its role metadata.
        $availabilityResponse = $this->availabilityResponse($contract);
        if ($availabilityResponse !== null) {
            return $availabilityResponse;
        }

        $validated = $this->validateJoinRequest($request, $contract);
        $email = Contract::normalizeSignerEmail($validated['email']);

        try {
            $result = $contract->type === 'template'
                ? $this->contractTemplateService->createInstance($contract, [
                    'name' => $validated['name'],
                    'email' => $email,
                    'roles' => $validated['roles'],
                    'personal_token' => Str::random(64),
                ])
                : $this->createStandardJoin($contract, $request, $email);
        } catch (ContractUnavailableException $exception) {
            return response()->json([
                'error' => $exception->getMessage(),
            ], 410);
        } catch (LockTimeoutException) {
            return response()->json([
                'error' => 'Der Vertrag wird gerade bearbeitet. Bitte versuche es erneut.',
            ], 409);
        }

        if (($result['created'] ?? false) !== true) {
            return response()->json([
                'error' => $result['error'] ?? 'Der Beitritt konnte nicht verarbeitet werden.',
            ], (int) ($result['status'] ?? 409));
        }

        /** @var ContractSigner $signer */
        $signer = $result['signer'];
        /** @var Contract $instance */
        $instance = $result['instance'];

        $this->contractAuditService->log($instance->getKey(), $signer->getKey(), 'opened', $request);

        return response()->json([
            'personal_token' => $signer->personal_token,
            'name' => $signer->name,
            'roles' => $signer->roles,
        ], 201);
    }

    public function contractContent($personalToken)
    {
        $signer = ContractSigner::where('personal_token', $personalToken)->with('contract')->first();
        if (! $signer || ! $signer->contract) {
            return response()->json(['error' => 'Ungültiger persönlicher Link'], 404);
        }

        $result = DB::transaction(function () use ($personalToken, $signer): array {
            // Use the same lock order as sign(): contract first, then its
            // optional template, then the signer. A read therefore observes
            // either the state before a concurrent close or the state after
            // it, never a stale parent/deadline mix.
            $lockedContract = $this->lockContractAndTemplate($signer->contract_id);
            if ($lockedContract === null) {
                return [
                    'status' => 404,
                    'error' => 'Ungültiger persönlicher Link',
                    'payload' => null,
                ];
            }

            $lockedSigner = ContractSigner::query()
                ->whereKey($signer->getKey())
                ->where('contract_id', $lockedContract->getKey())
                ->where('personal_token', $personalToken)
                ->lockForUpdate()
                ->first();

            if ($lockedSigner === null) {
                return [
                    'status' => 404,
                    'error' => 'Ungültiger persönlicher Link',
                    'payload' => null,
                ];
            }

            $availabilityError = $lockedContract->publicAvailabilityError(Contract::databaseNow());
            if ($availabilityError !== null || ! $lockedContract->isPubliclyAvailableAtDatabaseTime()) {
                $availabilityError ??= $lockedContract->fresh()?->publicAvailabilityError(Contract::databaseNow())
                    ?? 'Dieser Vertrag nimmt keine Unterschriften mehr an';

                return [
                    'status' => 410,
                    'error' => $availabilityError,
                    'payload' => null,
                ];
            }

            if ($lockedSigner->status === 'signed') {
                return [
                    'status' => 409,
                    'error' => 'Bereits unterschrieben',
                    'payload' => null,
                ];
            }

            $this->contractAuditService->log($lockedContract->getKey(), $lockedSigner->getKey(), 'heartbeat', request());

            return [
                'status' => 200,
                'error' => null,
                'payload' => [
                    'contract' => [
                        'id' => $lockedContract->id,
                        'terms_html' => $lockedContract->terms_html,
                        'items' => $lockedContract->items,
                        'discounts' => $lockedContract->discounts,
                        'billing_details' => $lockedContract->billing_details,
                        'available_roles' => $lockedContract->available_roles,
                        'content_version' => $lockedContract->content_version,
                    ],
                    'signer' => [
                        'id' => $lockedSigner->id,
                        'name' => $lockedSigner->name,
                        'email' => $lockedSigner->email,
                        'roles' => $lockedSigner->roles,
                        'status' => $lockedSigner->status,
                    ],
                ],
            ];
        }, 3);

        if (($result['status'] ?? 500) !== 200) {
            return response()->json([
                'error' => $result['error'] ?? 'Der Vertrag konnte nicht gelesen werden.',
            ], (int) ($result['status'] ?? 500));
        }

        return response()->json($result['payload']);
    }

    public function pageExit($personalToken)
    {
        // Page exit is telemetry, not a signing or authorization operation.
        // A beacon can legitimately arrive after the contract was closed (or
        // after a successful signature), so signing deadlines/status are not
        // re-evaluated here. It is still valid only for a real personal token
        // with an associated contract; it never returns contract data or
        // mutates signer state.
        $signer = ContractSigner::query()
            ->where('personal_token', $personalToken)
            ->whereHas('contract')
            ->first();
        if (! $signer) {
            return response()->json(null, 404);
        }

        $this->contractAuditService->log($signer->contract_id, $signer->id, 'page_exit', request());

        return response()->json(null, 204);
    }

    public function sign(Request $request, $personalToken)
    {
        $signer = ContractSigner::where('personal_token', $personalToken)->with('contract')->first();
        if (! $signer || ! $signer->contract) {
            return response()->json(['error' => 'Ungültiger persönlicher Link'], 404);
        }

        $availabilityResponse = $this->availabilityResponse($signer->contract);
        if ($availabilityResponse !== null) {
            return $availabilityResponse;
        }

        if ($signer->status === 'signed') {
            return response()->json(['error' => 'Bereits unterschrieben'], 409);
        }

        $validated = $request->validate([
            'accept_contract' => 'required|accepted',
            'content_version' => 'required|integer',
        ]);

        try {
            $result = Cache::lock($this->signLockKey($personalToken), 30)->block(10, function () use ($request, $personalToken, $signer, $validated): array {
                return DB::transaction(function () use ($request, $personalToken, $signer, $validated): array {
                    $lockedContract = $this->lockContractAndTemplate($signer->contract_id);

                    if ($lockedContract === null) {
                        return [
                            'status' => 404,
                            'error' => 'Ungültiger persönlicher Link',
                        ];
                    }

                    $lockedSigner = ContractSigner::query()
                        ->whereKey($signer->getKey())
                        ->where('personal_token', $personalToken)
                        ->lockForUpdate()
                        ->first();

                    if ($lockedSigner === null) {
                        return [
                            'status' => 404,
                            'error' => 'Ungültiger persönlicher Link',
                        ];
                    }

                    $availabilityError = $lockedContract->publicAvailabilityError(Contract::databaseNow());
                    if ($availabilityError !== null || ! $lockedContract->isPubliclyAvailableAtDatabaseTime()) {
                        $availabilityError ??= $lockedContract->fresh()?->publicAvailabilityError(Contract::databaseNow())
                            ?? 'Dieser Vertrag nimmt keine Unterschriften mehr an';

                        return [
                            'status' => 410,
                            'error' => $availabilityError,
                        ];
                    }

                    if ($lockedSigner->status === 'signed') {
                        return [
                            'status' => 409,
                            'error' => 'Bereits unterschrieben',
                        ];
                    }

                    if ((int) $lockedContract->content_version !== (int) $validated['content_version']) {
                        return [
                            'status' => 409,
                            'error' => 'Der Vertrag wurde geändert. Bitte laden Sie die Seite neu und lesen Sie die aktuelle Version.',
                        ];
                    }

                    $affected = ContractSigner::query()
                        ->whereKey($lockedSigner->getKey())
                        ->where('contract_id', $lockedContract->getKey())
                        ->where('personal_token', $personalToken)
                        ->where('status', 'joined')
                        ->whereHas('contract', function (Builder $query) use ($validated): void {
                            $query->where('content_version', (int) $validated['content_version'])
                                ->publiclyAvailableAtDatabaseTime();
                        })
                        ->update([
                            'status' => 'signed',
                            'signed_at' => DB::raw('CURRENT_TIMESTAMP'),
                            'updated_at' => DB::raw('CURRENT_TIMESTAMP'),
                        ]);

                    if ($affected !== 1) {
                        $freshSigner = ContractSigner::with('contract.template')->find($lockedSigner->getKey());
                        if ($freshSigner?->status === 'signed') {
                            return [
                                'status' => 409,
                                'error' => 'Bereits unterschrieben',
                            ];
                        }

                        $availabilityError = $freshSigner?->contract?->publicAvailabilityError(Contract::databaseNow());
                        if ($availabilityError !== null) {
                            return [
                                'status' => 410,
                                'error' => $availabilityError,
                            ];
                        }

                        return [
                            'status' => 409,
                            'error' => 'Der Vertrag wurde geändert. Bitte laden Sie die Seite neu und lesen Sie die aktuelle Version.',
                        ];
                    }

                    $this->contractAuditService->log($lockedContract->getKey(), $lockedSigner->getKey(), 'signed', $request);

                    $lockedContract->load('signers');
                    if ($lockedContract->template_id !== null) {
                        $lockedContract->status = 'closed';
                        $lockedContract->save();
                    }

                    return [
                        'status' => 200,
                        'error' => null,
                        'contract' => $lockedContract,
                    ];
                }, 3);
            });
        } catch (LockTimeoutException) {
            return response()->json([
                'error' => 'Die Unterschrift wird gerade verarbeitet. Bitte versuche es erneut.',
            ], 409);
        }

        if (($result['status'] ?? 500) !== 200) {
            return response()->json([
                'error' => $result['error'] ?? 'Die Unterschrift konnte nicht verarbeitet werden.',
            ], (int) ($result['status'] ?? 500));
        }

        /** @var Contract $contract */
        $contract = $result['contract'];
        if ($contract->template_id !== null) {
            $this->contractCloseService->close($contract);
        }

        return response()->json(['success' => true, 'message' => 'Vertrag erfolgreich unterschrieben']);
    }

    /**
     * @return array{created: bool, instance: ?Contract, signer: ?ContractSigner, error: ?string, status: int}
     */
    private function createStandardJoin(Contract $contract, Request $request, string $email): array
    {
        return Cache::lock(Contract::joinLockKey($contract->getKey(), $email), 30)->block(10, function () use ($contract, $request, $email): array {
            return DB::transaction(function () use ($contract, $request, $email): array {
                $lockedContract = Contract::query()
                    ->whereKey($contract->getKey())
                    ->lockForUpdate()
                    ->first();

                if ($lockedContract === null || $lockedContract->type !== 'contract') {
                    return [
                        'created' => false,
                        'instance' => null,
                        'signer' => null,
                        'error' => 'Vertrag nicht verfügbar',
                        'status' => 410,
                    ];
                }

                $availabilityError = $lockedContract->publicAvailabilityError(Contract::databaseNow());
                if ($availabilityError !== null || ! $lockedContract->isPubliclyAvailableAtDatabaseTime()) {
                    $availabilityError ??= $lockedContract->fresh()?->publicAvailabilityError(Contract::databaseNow())
                        ?? 'Vertrag nicht verfügbar';

                    return [
                        'created' => false,
                        'instance' => null,
                        'signer' => null,
                        'error' => $availabilityError,
                        'status' => 410,
                    ];
                }

                $validated = $this->validateJoinRequest($request, $lockedContract);
                $email = Contract::normalizeSignerEmail($validated['email']);
                $roles = array_values($validated['roles']);

                if (count($roles) > 1 && ! $lockedContract->allow_multiple_roles_per_signer) {
                    return [
                        'created' => false,
                        'instance' => null,
                        'signer' => null,
                        'error' => 'Mehrere Rollen pro Unterzeichner sind nicht erlaubt',
                        'status' => 422,
                    ];
                }

                // The cache lock, locked contract row, duplicate SELECT and
                // signer INSERT are one transaction. The current schema has
                // no normalized-email unique index, so this is the strongest
                // portable protocol available here for the supported writers.
                $existingSigner = ContractSigner::query()
                    ->where('contract_id', $lockedContract->getKey())
                    ->whereRaw('LOWER(TRIM(email)) = ?', [$email])
                    ->orderBy('created_at')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first();

                // Email knowledge must never reveal a previously issued
                // personal token. A retry fails closed until the original
                // personal link is used.
                if ($existingSigner !== null) {
                    return [
                        'created' => false,
                        'instance' => null,
                        'signer' => null,
                        'error' => 'Für diese E-Mail besteht bereits ein Vertrag.',
                        'status' => 409,
                    ];
                }

                // The final database-time predicate is deliberately adjacent
                // to the insert. The contract row remains locked, so a
                // concurrent close cannot commit between this check and the
                // signer write.
                if (! $lockedContract->isPubliclyAvailableAtDatabaseTime()) {
                    $availabilityError = $lockedContract->fresh()?->publicAvailabilityError(Contract::databaseNow())
                        ?? 'Vertrag nicht verfügbar';

                    return [
                        'created' => false,
                        'instance' => null,
                        'signer' => null,
                        'error' => $availabilityError,
                        'status' => 410,
                    ];
                }

                $personalToken = Str::random(64);
                $signer = ContractSigner::create([
                    'contract_id' => $lockedContract->getKey(),
                    'name' => $validated['name'],
                    'email' => $email,
                    'roles' => $roles,
                    'personal_token' => $personalToken,
                    'status' => 'joined',
                ]);

                // A model event or a real clock tick may cross the deadline
                // during the insert. Rechecking after the write makes the
                // surrounding transaction roll the signer back instead of
                // committing a post-deadline identity.
                if (! $lockedContract->isPubliclyAvailableAtDatabaseTime()) {
                    $availabilityError = $lockedContract->fresh()?->publicAvailabilityError(Contract::databaseNow())
                        ?? 'Vertrag nicht verfügbar';

                    throw new ContractUnavailableException($availabilityError);
                }

                return [
                    'created' => true,
                    'instance' => $lockedContract,
                    'signer' => $signer,
                    'error' => null,
                    'status' => 201,
                ];
            }, 3);
        });
    }

    /**
     * Lock a contract and its optional template in the canonical order used
     * by both public read and sign operations. A stale eager-loaded parent
     * must never be used for a deadline decision.
     */
    private function lockContractAndTemplate(string $contractId): ?Contract
    {
        $lockedContract = Contract::query()
            ->whereKey($contractId)
            ->lockForUpdate()
            ->first();

        if ($lockedContract === null) {
            return null;
        }

        if ($lockedContract->template_id !== null) {
            $lockedTemplate = Contract::query()
                ->whereKey($lockedContract->template_id)
                ->lockForUpdate()
                ->first();
            $lockedContract->setRelation('template', $lockedTemplate);
        }

        return $lockedContract;
    }

    /**
     * @return array<string, mixed>
     */
    private function validateJoinRequest(Request $request, Contract $contract): array
    {
        return $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'roles' => 'required|array|min:1',
            'roles.*' => ['required', 'string', Rule::in($contract->available_roles ?? [])],
        ]);
    }

    private function signLockKey(string $personalToken): string
    {
        return 'contract-sign:'.hash('sha256', $personalToken);
    }

    private function availabilityResponse(Contract $contract): ?JsonResponse
    {
        $freshContract = $contract->fresh(['template']);
        $checkedContract = $freshContract ?? $contract;
        $error = $checkedContract->publicAvailabilityError(Contract::databaseNow());

        if ($error === null && ! $checkedContract->isPubliclyAvailableAtDatabaseTime()) {
            $error = $checkedContract->publicAvailabilityError(Contract::databaseNow())
                ?? 'Dieser Vertrag nimmt keine Unterschriften mehr an';
        }

        if ($error === null) {
            return null;
        }

        return response()->json(['error' => $error], 410);
    }
}
