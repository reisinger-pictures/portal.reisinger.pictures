<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\ContractSigner;
use Illuminate\Support\Facades\DB;

class ContractTemplateService
{
    /**
     * Create a new contract instance from a template, including a signer.
     *
     * Contract and signer are written in one transaction so a failing signer
     * insert never leaves an orphaned contract behind.
     *
     * @param  Contract  $template  The template to replicate
     * @param  array     $signerData  Keys: name, email, roles, personal_token
     * @return array{instance: Contract, signer: ContractSigner}
     */
    public function createInstance(Contract $template, array $signerData): array
    {
        return DB::transaction(function () use ($template, $signerData) {
            $instance = Contract::create([
                'type' => 'contract',
                'template_id' => $template->id,
                'status' => 'active',
                'billing_details' => $template->billing_details,
                'items' => $template->items,
                'discounts' => $template->discounts,
                'terms_html' => $template->terms_html,
                'available_roles' => $template->available_roles,
                'allow_multiple_roles_per_signer' => $template->allow_multiple_roles_per_signer,
                'brand' => $template->brand,
                'join_token' => null,
                'content_version' => 0,
            ]);

            $signer = ContractSigner::create([
                'contract_id' => $instance->id,
                'name' => $signerData['name'],
                'email' => $signerData['email'],
                'roles' => $signerData['roles'],
                'personal_token' => $signerData['personal_token'],
                'status' => 'joined',
            ]);

            return [
                'instance' => $instance,
                'signer' => $signer,
            ];
        });
    }
}
