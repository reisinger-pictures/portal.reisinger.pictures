<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContractSigner extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'contract_id', 'name', 'email', 'roles',
        'personal_token', 'status', 'signed_at',
    ];

    protected $casts = [
        'roles' => 'array',
        'signed_at' => 'datetime',
    ];

    public function contract()
    {
        return $this->belongsTo(Contract::class);
    }

    public function auditLogs()
    {
        return $this->hasMany(ContractAuditLog::class);
    }
}
