<?php

namespace Database\Factories;

use App\Models\InvoiceSnapshot;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvoiceSnapshot>
 */
class InvoiceSnapshotFactory extends Factory
{
    public function definition(): array
    {
        $items = [
            [
                'photoId' => $this->faker->uuid(),
                'price' => $this->faker->numberBetween(1000, 20000),
                'tier' => $this->faker->randomElement(['web', 'print', 'original']),
            ],
        ];

        $totalNet = array_sum(array_column($items, 'price'));

        return [
            'order_id' => Order::factory(),
            // invoice_number wird im Model::booted() auto-generiert (leer lassen)
            'customer_details' => [
                'name' => $this->faker->name(),
                'email' => $this->faker->safeEmail(),
                'items' => $items,
            ],
            'total_net' => $totalNet,
            'total_gross' => $totalNet,
            'tax_rate' => null,
        ];
    }
}
