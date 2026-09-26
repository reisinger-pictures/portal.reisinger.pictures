<?php

namespace App\Http\Controllers;

use App\Exceptions\ContractCloseConflictException;
use App\Http\Controllers\Concerns\EnforcesBrandIsolation;
use App\Http\Requests\StoreContractRequest;
use App\Http\Requests\UpdateContractRequest;
use App\Models\Contract;
use App\Services\ContractAuditService;
use App\Services\ContractCloseService;
use App\Services\ContractPricingService;
use App\Support\BrandRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class ContractController extends Controller
{
    use EnforcesBrandIsolation;

    private ContractAuditService $contractAuditService;

    private ContractCloseService $contractCloseService;

    private ContractPricingService $contractPricingService;

    public function __construct(
        ContractAuditService $contractAuditService,
        ContractCloseService $contractCloseService,
        ContractPricingService $contractPricingService,
    ) {
        $this->contractAuditService = $contractAuditService;
        $this->contractCloseService = $contractCloseService;
        $this->contractPricingService = $contractPricingService;
    }

    public function index(Request $request)
    {
        $query = Contract::with('signers')
            ->where('brand', BrandRegistry::currentOrDefault());

        if ($request->has('type') && in_array($request->query('type'), ['contract', 'template'])) {
            $query->where('type', $request->query('type'));
        }

        $contracts = $query->orderBy('created_at', 'desc')->get();

        return response()->json($contracts->map(
            fn (Contract $contract): array => $this->serializeContract($contract),
        )->values());
    }

    public function store(StoreContractRequest $request)
    {
        $data = array_merge(
            $this->normalizeWriteSnapshot($request->validated()),
            ['status' => 'draft', 'brand' => BrandRegistry::currentOrDefault()]
        );

        if (empty($data['type'])) {
            $data['type'] = 'contract';
        }

        $contract = Contract::create($data);

        return response()->json([
            'success' => true,
            'contract' => $this->serializeContract($contract->load('signers')),
        ], 201);
    }

    public function show($id)
    {
        $contract = Contract::with(['signers.auditLogs'])->findOrFail($id);

        if ($this->isBrandMismatch(auth('api')->user(), $contract)) {
            return response()->json(['error' => 'Forbidden (Brand Isolation)'], 403);
        }

        return response()->json(['contract' => $this->serializeContract($contract)]);
    }

    public function update(UpdateContractRequest $request, $id)
    {
        $contract = Contract::findOrFail($id);

        if ($this->isBrandMismatch($request->user(), $contract)) {
            return response()->json(['error' => 'Forbidden (Brand Isolation)'], 403);
        }

        if ($contract->status !== 'draft' && $contract->status !== 'active') {
            return response()->json(['error' => 'Nur Entwürfe oder aktive Verträge ohne Unterschriften können bearbeitet werden'], 403);
        }

        if ($contract->status === 'active' && $contract->signers()->where('status', 'signed')->exists()) {
            return response()->json(['error' => 'Vertrag kann nicht mehr bearbeitet werden, da bereits Unterschriften vorliegen'], 403);
        }

        if ($request->has('type') && $request->input('type') !== $contract->type) {
            return response()->json(['error' => 'Der Vertragstyp kann nach der Erstellung nicht mehr geändert werden'], 422);
        }

        $data = $this->normalizeWriteSnapshot($request->validated(), $contract);

        // Content and content_version must move together. In autocommit mode a
        // concurrent sign() could otherwise take the row lock between the
        // content UPDATE and the version increment, observe new content with
        // the old version, pass the staleness check, and bind to terms the
        // signer never saw.
        DB::transaction(function () use ($contract, $data, $request): void {
            $contract->update($data);

            if ($contract->wasChanged() && $contract->status === 'active') {
                $contract->increment('content_version');
                $this->contractAuditService->log(
                    $contract->id,
                    null,
                    'modified',
                    $request
                );
            }
        });

        return response()->json([
            'success' => true,
            'contract' => $this->serializeContract($contract->load('signers')),
        ]);
    }

    public function open($id)
    {
        $contract = Contract::findOrFail($id);

        if ($this->isBrandMismatch(auth('api')->user(), $contract)) {
            return response()->json(['error' => 'Forbidden (Brand Isolation)'], 403);
        }

        if ($contract->status !== 'draft') {
            return response()->json(['error' => 'Nur Entwürfe können geöffnet werden'], 400);
        }

        if ($contract->type === 'template' && (! $contract->expires_at || $contract->expires_at->isPast())) {
            return response()->json(['error' => 'Für Vorlagen muss ein gültiges Ablaufdatum in der Zukunft gesetzt sein'], 422);
        }

        try {
            // Validate the authoritative amount before changing lifecycle state
            // or generating a join token. This also enforces the persisted
            // order/invoice money ceiling for contracts that may be closed.
            $this->contractCloseService->calculateTotal($contract);
            if ($contract->type === 'template'
                && ! $this->contractPricingService->canCopySnapshotWithoutReordering($contract->items, $contract->discounts)
            ) {
                return response()->json([
                    'error' => 'Die Reihenfolge der Legacy-Preiszpositionen dieser Vorlage kann nicht verlustfrei kopiert werden.',
                ], 422);
            }
        } catch (InvalidArgumentException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }

        $contract->status = 'active';
        $contract->join_token = Str::random(64);
        $contract->save();

        $frontendUrl = BrandRegistry::frontendUrl();

        return response()->json([
            'success' => true,
            'join_link' => rtrim($frontendUrl, '/').'/contracts/join/'.$contract->join_token,
            'contract' => $this->serializeContract($contract),
        ]);
    }

    public function instances($id)
    {
        $template = Contract::findOrFail($id);

        if ($this->isBrandMismatch(auth('api')->user(), $template)) {
            return response()->json(['error' => 'Forbidden (Brand Isolation)'], 403);
        }

        if ($template->type !== 'template') {
            return response()->json(['error' => 'Nicht gefunden'], 404);
        }

        $instances = Contract::with('signers')
            ->where('template_id', $id)
            ->orderBy('created_at', 'desc')
            ->get();

        // Defend against an anomalous instance row whose brand drifted from
        // its template: never serialize an instance the actor may not see.
        $instances = $instances->reject(
            fn (Contract $contract): bool => $this->isBrandMismatch(auth('api')->user(), $contract),
        );

        return response()->json($instances->map(
            fn (Contract $contract): array => $this->serializeContract($contract),
        )->values());
    }

    public function close($id)
    {
        $contract = Contract::with('signers')->findOrFail($id);

        if ($this->isBrandMismatch(auth('api')->user(), $contract)) {
            return response()->json(['error' => 'Forbidden (Brand Isolation)'], 403);
        }

        try {
            $result = $this->contractCloseService->close($contract);
        } catch (ContractCloseConflictException) {
            return $this->closeConflictResponse();
        } catch (InvalidArgumentException) {
            return response()->json(['error' => 'Der Vertragsbetrag ist ungültig.'], 422);
        }

        if ($result['status'] === ContractCloseService::RESULT_NOT_ACTIVE) {
            return response()->json(['error' => 'Nur aktive Verträge können geschlossen werden'], 400);
        }

        if ($result['status'] === ContractCloseService::RESULT_CONFLICT) {
            return $this->closeConflictResponse();
        }

        return response()->json([
            'success' => true,
            'contract' => $this->serializeContract($result['contract']->load('signers')),
        ]);
    }

    private function closeConflictResponse()
    {
        return response()->json([
            'error' => ContractCloseConflictException::RETRY_MESSAGE,
        ], 409);
    }

    /**
     * Canonicalize a write before it reaches the model. A partial update keeps
     * omitted arrays from the existing row, while a legacy row is normalized
     * through the read path before the changed fields are validated.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeWriteSnapshot(array $data, ?Contract $contract = null): array
    {
        $hasItems = array_key_exists('items', $data);
        $hasDiscounts = array_key_exists('discounts', $data);

        // A new contract always receives canonical empty arrays when the
        // client omits both fields. An unrelated partial update, however,
        // must not rewrite a legacy snapshot just because it did not mention
        // either pricing field. It still preflights the existing amount so an
        // invalid legacy row cannot turn a harmless update into a later 500.
        if ($contract !== null && ! $hasItems && ! $hasDiscounts) {
            try {
                $this->contractCloseService->calculateTotal($contract);
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages([
                    'items' => $exception->getMessage(),
                ]);
            }

            return $data;
        }

        try {
            // A rewrite of an existing snapshot must never silently change the
            // authoritative total. A legacy mixed placement that cannot be
            // copied into the canonical items/discounts partitions without
            // reordering fails closed for every contract type, not only for
            // templates.
            if ($contract !== null
                && ! $this->contractPricingService->canCopySnapshotWithoutReordering($contract->items, $contract->discounts)
            ) {
                throw ValidationException::withMessages([
                    'items' => 'Die Reihenfolge der Legacy-Preispositionen kann nicht verlustfrei erhalten werden.',
                ]);
            }

            $existing = $contract === null
                ? ['items' => [], 'discounts' => []]
                : $this->contractPricingService->normalizeSnapshot(
                    $contract->items,
                    $contract->discounts,
                );

            $items = $hasItems ? $data['items'] : $existing['items'];
            $discounts = $hasDiscounts ? $data['discounts'] : $existing['discounts'];
            $snapshot = $this->contractPricingService->preflightForWrite($items, $discounts);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'items' => $exception->getMessage(),
            ]);
        }

        // Persist both canonical partitions whenever either one is edited. This
        // also migrates a legacy mixed row without clearing an omitted field.
        $data['items'] = $snapshot['items'];
        $data['discounts'] = $snapshot['discounts'];

        return $data;
    }

    /**
     * Serialize the same normalized snapshot used by the signer and invoice
     * paths, including the authoritative cents total for management clients.
     *
     * @return array<string, mixed>
     */
    private function serializeContract(Contract $contract): array
    {
        $snapshot = $this->contractPricingService->normalizeSnapshot(
            $contract->items,
            $contract->discounts,
        );

        $data = $contract->toArray();
        $data['items'] = $snapshot['items'];
        $data['discounts'] = $snapshot['discounts'];
        $data['total'] = $this->contractPricingService->processLines($snapshot['lines'])['total'];

        return $data;
    }
}
