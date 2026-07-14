<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreContractRequest;
use App\Http\Requests\UpdateContractRequest;
use App\Enums\Brand;
use App\Models\Contract;
use App\Services\ContractAuditService;
use App\Services\ContractCloseService;
use App\Support\BrandRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ContractController extends Controller
{
    private ContractAuditService $contractAuditService;
    private ContractCloseService $contractCloseService;

    public function __construct(ContractAuditService $contractAuditService, ContractCloseService $contractCloseService)
    {
        $this->contractAuditService = $contractAuditService;
        $this->contractCloseService = $contractCloseService;
    }

    public function index(Request $request)
    {
        $query = Contract::with('signers')
            ->where('brand', BrandRegistry::currentOrDefault());

        if ($request->has('type') && in_array($request->query('type'), ['contract', 'template'])) {
            $query->where('type', $request->query('type'));
        }

        $contracts = $query->orderBy('created_at', 'desc')->get();

        return response()->json($contracts);
    }

    public function store(StoreContractRequest $request)
    {
        $data = array_merge(
            $request->validated(),
            ['status' => 'draft', 'brand' => BrandRegistry::currentOrDefault()]
        );

        if (empty($data['type'])) {
            $data['type'] = 'contract';
        }

        $contract = Contract::create($data);

        return response()->json([
            'success' => true,
            'contract' => $contract->load('signers'),
        ], 201);
    }

    public function show($id)
    {
        $contract = Contract::with(['signers.auditLogs'])->findOrFail($id);

        return response()->json(['contract' => $contract]);
    }

    public function update(UpdateContractRequest $request, $id)
    {
        $contract = Contract::findOrFail($id);

        if ($contract->status !== 'draft' && $contract->status !== 'active') {
            return response()->json(['error' => 'Nur Entwürfe oder aktive Verträge ohne Unterschriften können bearbeitet werden'], 403);
        }

        if ($contract->status === 'active' && $contract->signers()->where('status', 'signed')->exists()) {
            return response()->json(['error' => 'Vertrag kann nicht mehr bearbeitet werden, da bereits Unterschriften vorliegen'], 403);
        }

        if ($request->has('type') && $request->input('type') !== $contract->type) {
            return response()->json(['error' => 'Der Vertragstyp kann nach der Erstellung nicht mehr geändert werden'], 422);
        }

        $contract->update($request->validated());

        if ($contract->wasChanged() && $contract->status === 'active') {
            $contract->increment('content_version');
            $this->contractAuditService->log(
                $contract->id,
                null,
                'modified',
                $request
            );
        }

        return response()->json([
            'success' => true,
            'contract' => $contract->load('signers'),
        ]);
    }

    public function open($id)
    {
        $contract = Contract::findOrFail($id);

        if ($contract->status !== 'draft') {
            return response()->json(['error' => 'Nur Entwürfe können geöffnet werden'], 400);
        }

        if ($contract->type === 'template' && (!$contract->expires_at || $contract->expires_at->isPast())) {
            return response()->json(['error' => 'Für Vorlagen muss ein gültiges Ablaufdatum in der Zukunft gesetzt sein'], 422);
        }

        $contract->status = 'active';
        $contract->join_token = Str::random(64);
        $contract->save();

        $frontendUrl = BrandRegistry::frontendUrl();

        return response()->json([
            'success' => true,
            'join_link' => rtrim($frontendUrl, '/') . '/contracts/join/' . $contract->join_token,
            'contract' => $contract,
        ]);
    }

    public function instances($id)
    {
        $template = Contract::findOrFail($id);

        if ($template->type !== 'template') {
            return response()->json(['error' => 'Nicht gefunden'], 404);
        }

        $instances = Contract::with('signers')
            ->where('template_id', $id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($instances);
    }

    public function close($id)
    {
        $contract = Contract::with('signers')->findOrFail($id);

        if ($contract->status !== 'active') {
            return response()->json(['error' => 'Nur aktive Verträge können geschlossen werden'], 400);
        }

        $contract->status = 'closed';
        $contract->save();

        $this->contractCloseService->close($contract);

        return response()->json([
            'success' => true,
            'contract' => $contract->load('signers'),
        ]);
    }
}
