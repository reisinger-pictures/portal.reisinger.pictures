<?php

namespace Database\Factories;

use App\Enums\Brand;
use App\Models\Contract;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contract>
 */
class ContractFactory extends Factory
{
    public function definition(): array
    {
        $types = ['Lizenz', 'Freigabe', 'Kooperation'];
        $itemCount = $this->faker->numberBetween(1, 3);

        $items = [];
        for ($i = 0; $i < $itemCount; $i++) {
            $items[] = [
                'type' => $this->faker->randomElement($types),
                'description' => $this->faker->sentence(),
                'notes' => $this->faker->optional(0.5)->sentence(),
                'qty' => $this->faker->numberBetween(1, 10),
                'price' => $this->faker->numberBetween(1000, 500000),
            ];
        }

        $roles = $this->faker->randomElements(
            ['Model', 'Visagist', 'Kunde', 'Fotograf', 'Stylist'],
            $this->faker->numberBetween(2, 3)
        );

        $billingDetails = null;
        if ($this->faker->boolean(70)) {
            $billingDetails = [
                'name' => $this->faker->name(),
                'company' => $this->faker->boolean(50) ? $this->faker->company() : null,
                'street' => $this->faker->streetAddress(),
                'zip' => $this->faker->postcode(),
                'city' => $this->faker->city(),
                'country' => $this->faker->country(),
                'email' => $this->faker->safeEmail(),
                'uid' => $this->faker->boolean(30) ? $this->faker->bothify('ATU########') : null,
            ];
        }

        $discounts = [];
        if ($this->faker->boolean(40)) {
            $discounts[] = [
                'type' => $this->faker->randomElement(['Prozent', 'Fixbetrag', 'Rabattcode']),
                'description' => $this->faker->sentence(),
                'notes' => $this->faker->optional(0.5)->sentence(),
                'price' => $this->faker->numberBetween(500, 50000),
            ];
        }

        $termsHtml = null;
        if ($this->faker->boolean(80)) {
            $termsHtml = '<p>' . implode('</p><p>', $this->faker->paragraphs(3)) . '</p>';
        }

        return [
            'brand' => $this->faker->randomElement([Brand::B2B, Brand::SRP]),
            'status' => $this->faker->randomElement(['draft', 'active', 'closed', 'cancelled']),
            'billing_details' => $billingDetails,
            'items' => $items,
            'discounts' => $discounts,
            'terms_html' => $termsHtml,
            'available_roles' => $roles,
            'allow_multiple_roles_per_signer' => $this->faker->boolean(20),
            'join_token' => $this->faker->optional(0.5)->regexify('[A-Za-z0-9]{64}'),
            'content_version' => 0,
            'type' => 'contract',
            'template_id' => null,
            'expires_at' => null,
            'closes_at' => $this->faker->optional(0.6)->dateTimeBetween('+1 day', '+30 days'),
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'draft',
            'join_token' => null,
            'closes_at' => null,
        ]);
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'active',
            'join_token' => $this->faker->regexify('[A-Za-z0-9]{64}'),
            'closes_at' => $this->faker->dateTimeBetween('+7 days', '+30 days'),
        ]);
    }

    public function template(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'template',
            'join_token' => null,
            'closes_at' => null,
            'expires_at' => now()->addDays(30),
        ]);
    }
}
