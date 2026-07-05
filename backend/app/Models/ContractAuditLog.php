<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContractAuditLog extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'contract_id', 'contract_signer_id', 'action',
        'ip_address', 'user_agent',
    ];

    public function contract()
    {
        return $this->belongsTo(Contract::class);
    }

    public function signer()
    {
        return $this->belongsTo(ContractSigner::class, 'contract_signer_id');
    }
}
