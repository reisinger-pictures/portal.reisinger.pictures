<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreContractRequest;
use App\Http\Requests\UpdateContractRequest;
use App\Enums\Brand;
use App\Models\Contract;
use App\Services\ContractCloseService;
use App\Support\BrandRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ContractController extends Controller
{
    public function index(Request $request)
    {
        $contracts = Contract::with('signers')
            ->where('brand', BrandRegistry::currentOrDefault())
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($contracts);
    }

    public function store(StoreContractRequest $request)
    {
        $contract = Contract::create(array_merge(
            $request->validated(),
            ['status' => 'draft', 'brand' => BrandRegistry::currentOrDefault()]
        ));

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

        $contract->update($request->validated());

        if ($contract->wasChanged() && $contract->status === 'active') {
            $contract->increment('content_version');
            app(\App\Services\ContractAuditService::class)->log(
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

    public function close($id)
    {
        $contract = Contract::with('signers')->findOrFail($id);

        if ($contract->status !== 'active') {
            return response()->json(['error' => 'Nur aktive Verträge können geschlossen werden'], 400);
        }

        $contract->status = 'closed';
        $contract->save();

        app(ContractCloseService::class)->close($contract);

        return response()->json([
            'success' => true,
            'contract' => $contract->load('signers'),
        ]);
    }
}
