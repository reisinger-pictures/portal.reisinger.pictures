<?php

namespace Database\Factories;

use App\Enums\Brand;
use App\Models\TextSnippet;
use Illuminate\Database\Eloquent\Factories\Factory;

class TextSnippetFactory extends Factory
{
    protected $model = TextSnippet::class;

    public function definition(): array
    {
        return [
            'title' => fake()->sentence(3),
            'shortcut' => fake()->unique()->regexify('[a-z0-9_]{5,15}'),
            'content_html' => '<p>' . fake()->paragraph() . '</p>',
            'brand' => Brand::B2B,
        ];
    }
}
