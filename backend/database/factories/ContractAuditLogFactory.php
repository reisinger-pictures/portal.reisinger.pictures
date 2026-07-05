<?php

namespace Database\Factories;

use App\Models\Contract;
use App\Models\ContractAuditLog;
use App\Models\ContractSigner;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContractAuditLog>
 */
class ContractAuditLogFactory extends Factory
{
    public function definition(): array
    {
        return [
            'contract_id' => Contract::factory(),
            'contract_signer_id' => ContractSigner::factory(),
            'action' => $this->faker->randomElement(['opened', 'heartbeat', 'signed']),
            'ip_address' => $this->faker->ipv4(),
            'user_agent' => $this->faker->userAgent(),
        ];
    }
}
