<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use App\Models\ContractSigner;
use App\Models\ContractAuditLog;
use App\Services\ContractAuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ContractJoinController extends Controller
{
    public function check($token)
    {
        $contract = Contract::where('join_token', $token)->first();
        if (!$contract) {
            return response()->json(['error' => 'Ungültiger Vertragslink'], 404);
        }
        if ($contract->status !== 'active') {
            return response()->json(['error' => 'Dieser Vertrag nimmt keine Unterschriften mehr an'], 410);
        }
        return response()->json([
            'contract_id' => $contract->id,
            'status' => $contract->status,
            'available_roles' => $contract->available_roles,
            'allow_multiple_roles' => $contract->allow_multiple_roles_per_signer,
            'terms_html' => $contract->terms_html,
        ]);
    }

    public function join(Request $request, $token)
    {
        $contract = Contract::where('join_token', $token)->first();
        if (!$contract || $contract->status !== 'active') {
            return response()->json(['error' => 'Vertrag nicht verfügbar'], 410);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'roles' => 'required|array|min:1',
            'roles.*' => 'required|string|in:' . implode(',', $contract->available_roles),
        ]);

        if (count($validated['roles']) > 1 && !$contract->allow_multiple_roles_per_signer) {
            return response()->json(['error' => 'Mehrere Rollen pro Unterzeichner sind nicht erlaubt'], 422);
        }

        $existing = ContractSigner::where('contract_id', $contract->id)
            ->where('email', $validated['email'])
            ->where('status', 'signed')
            ->first();
        if ($existing) {
            return response()->json(['error' => 'Mit dieser E-Mail wurde bereits unterschrieben'], 409);
        }

        $existingJoined = ContractSigner::where('contract_id', $contract->id)
            ->where('email', $validated['email'])
            ->where('status', 'joined')
            ->first();
        if ($existingJoined) {
            app(ContractAuditService::class)->log($contract->id, $existingJoined->id, 'opened', $request);
            return response()->json([
                'personal_token' => $existingJoined->personal_token,
                'name' => $existingJoined->name,
                'roles' => $existingJoined->roles,
            ]);
        }

        $personalToken = Str::random(64);

        $signer = ContractSigner::create([
            'contract_id' => $contract->id,
            'name' => $validated['name'],
            'email' => $validated['email'],
            'roles' => $validated['roles'],
            'personal_token' => $personalToken,
            'status' => 'joined',
        ]);

        app(ContractAuditService::class)->log($contract->id, $signer->id, 'opened', $request);

        return response()->json([
            'personal_token' => $personalToken,
            'name' => $signer->name,
            'roles' => $signer->roles,
        ], 201);
    }

    public function contractContent($personalToken)
    {
        $signer = ContractSigner::where('personal_token', $personalToken)->with('contract')->first();
        if (!$signer) {
            return response()->json(['error' => 'Ungültiger persönlicher Link'], 404);
        }
        if ($signer->status === 'signed') {
            return response()->json(['error' => 'Bereits unterschrieben'], 409);
        }
        if ($signer->contract->status !== 'active') {
            return response()->json(['error' => 'Der Vertrag nimmt keine Unterschriften mehr an'], 410);
        }

        app(ContractAuditService::class)->log($signer->contract_id, $signer->id, 'heartbeat', request());

        return response()->json([
            'contract' => [
                'id' => $signer->contract->id,
                'terms_html' => $signer->contract->terms_html,
                'items' => $signer->contract->items,
                'discounts' => $signer->contract->discounts,
                'billing_details' => $signer->contract->billing_details,
                'available_roles' => $signer->contract->available_roles,
                'content_version' => $signer->contract->content_version,
            ],
            'signer' => [
                'id' => $signer->id,
                'name' => $signer->name,
                'email' => $signer->email,
                'roles' => $signer->roles,
                'status' => $signer->status,
            ],
        ]);
    }

    public function pageExit($personalToken)
    {
        $signer = ContractSigner::where('personal_token', $personalToken)->first();
        if (!$signer) {
            return response()->json(null, 404);
        }

        app(ContractAuditService::class)->log($signer->contract_id, $signer->id, 'page_exit', request());

        return response()->json(null, 204);
    }

    public function sign(Request $request, $personalToken)
    {
        $signer = ContractSigner::where('personal_token', $personalToken)->first();
        if (!$signer) {
            return response()->json(['error' => 'Ungültiger persönlicher Link'], 404);
        }
        if ($signer->status === 'signed') {
            return response()->json(['error' => 'Bereits unterschrieben'], 409);
        }

        $validated = $request->validate([
            'accept_contract' => 'required|accepted',
            'content_version' => 'required|integer',
        ]);

        $affected = DB::table('contract_signers')
            ->join('contracts', 'contract_signers.contract_id', '=', 'contracts.id')
            ->where('contract_signers.personal_token', $personalToken)
            ->where('contract_signers.status', 'joined')
            ->where('contracts.status', 'active')
            ->where('contracts.content_version', (int) $validated['content_version'])
            ->update([
                'contract_signers.status' => 'signed',
                'contract_signers.signed_at' => now(),
            ]);

        if ($affected === 0) {
            $reloaded = ContractSigner::where('personal_token', $personalToken)->with('contract')->first();
            if ($reloaded && $reloaded->contract->status !== 'active') {
                return response()->json(['error' => 'Der Vertrag nimmt keine Unterschriften mehr an'], 410);
            }
            return response()->json(['error' => 'Der Vertrag wurde geändert. Bitte laden Sie die Seite neu und lesen Sie die aktuelle Version.'], 409);
        }

        app(ContractAuditService::class)->log($signer->contract_id, $signer->id, 'signed', $request);

        return response()->json(['success' => true, 'message' => 'Vertrag erfolgreich unterschrieben']);
    }
}
