<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Contract extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'type', 'template_id', 'expires_at',
        'status', 'billing_details', 'items', 'discounts', 'terms_html',
        'available_roles', 'allow_multiple_roles_per_signer',
        'join_token', 'closes_at', 'content_version', 'brand',
    ];

    protected $casts = [
        'billing_details' => 'array',
        'items' => 'array',
        'discounts' => 'array',
        'available_roles' => 'array',
        'allow_multiple_roles_per_signer' => 'boolean',
        'closes_at' => 'datetime',
        'expires_at' => 'datetime',
        'content_version' => 'integer',
        'brand' => \App\Enums\Brand::class,
    ];

    public function signers()
    {
        return $this->hasMany(ContractSigner::class);
    }

    public function auditLogs()
    {
        return $this->hasMany(ContractAuditLog::class);
    }

    public function template()
    {
        return $this->belongsTo(Contract::class, 'template_id');
    }

    public function instances()
    {
        return $this->hasMany(Contract::class, 'template_id');
    }

    public function scopeTemplates($query)
    {
        return $query->where('type', 'template');
    }

    public function scopeInstances($query)
    {
        return $query->where('type', 'contract')->whereNotNull('template_id');
    }

    public function isTemplate(): bool
    {
        return $this->type === 'template';
    }

    public function isInstance(): bool
    {
        return $this->type === 'contract' && $this->template_id !== null;
    }
}
