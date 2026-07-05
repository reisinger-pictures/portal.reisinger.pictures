<?php

namespace App\Services;

use App\Models\ContractAuditLog;
use Illuminate\Http\Request;

class ContractAuditService
{
    public function log(string $contractId, ?string $signerId, string $action, Request $request): ContractAuditLog
    {
        return ContractAuditLog::create([
            'contract_id' => $contractId,
            'contract_signer_id' => $signerId,
            'action' => $action,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}
