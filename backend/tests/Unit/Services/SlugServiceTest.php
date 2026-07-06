<?php

namespace Tests\Unit\Services;

use App\Models\Gallery;
use App\Services\SlugService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SlugServiceTest extends TestCase
{
    use RefreshDatabase;

    private SlugService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(SlugService::class);
    }

    public function test_generates_slug_from_basic_value()
    {
        $slug = $this->service->makeUnique('Hello World', 'galleries', 'slug');

        $this->assertSame('hello-world', $slug);
    }

    public function test_returns_plain_slug_when_no_collision_exists()
    {
        $slug = $this->service->makeUnique('Unique Gallery', 'galleries', 'slug');

        $this->assertSame('unique-gallery', $slug);
        $this->assertDatabaseMissing('galleries', ['slug' => $slug]);
    }

    public function test_appends_suffix_when_slug_collision_exists()
    {
        Gallery::factory()->create(['slug' => 'my-gallery']);

        $slug = $this->service->makeUnique('My Gallery', 'galleries', 'slug');

        $this->assertSame('my-gallery-1', $slug);
    }

    public function test_increments_suffix_with_multiple_collisions()
    {
        Gallery::factory()->create(['slug' => 'test']);
        Gallery::factory()->create(['slug' => 'test-1']);

        $slug = $this->service->makeUnique('test', 'galleries', 'slug');

        $this->assertSame('test-2', $slug);
    }

    public function test_ignores_specified_id_when_checking_uniqueness()
    {
        $gallery = Gallery::factory()->create(['slug' => 'existing-slug']);

        $slug = $this->service->makeUnique(
            'Existing',
            'galleries',
            'slug',
            (string) $gallery->id
        );

        $this->assertSame('existing', $slug);
    }

    public function test_still_detects_collision_from_other_rows_when_ignoring_id()
    {
        $gallery = Gallery::factory()->create(['slug' => 'collision']);
        Gallery::factory()->create(['slug' => 'collision-1']);

        $slug = $this->service->makeUnique(
            'Collision',
            'galleries',
            'slug',
            (string) $gallery->id
        );

        $this->assertSame('collision-1', $slug);
    }

    public function test_handles_german_umlauts()
    {
        $slug = $this->service->makeUnique('München Straße', 'galleries', 'slug');

        $this->assertSame('munchen-strasse', $slug);
    }

    public function test_handles_special_characters()
    {
        $slug = $this->service->makeUnique('Hello! @World #2024', 'galleries', 'slug');

        $this->assertSame('hello-at-world-2024', $slug);
    }

    public function test_handles_whitespace_and_multiple_spaces()
    {
        $slug = $this->service->makeUnique('My   Beautiful   Gallery', 'galleries', 'slug');

        $this->assertSame('my-beautiful-gallery', $slug);
    }

    public function test_uses_custom_column_name()
    {
        $slug = $this->service->makeUnique('Custom Column', 'galleries', 'name');

        $this->assertSame('custom-column', $slug);
    }

    public function test_handles_empty_string()
    {
        $slug = $this->service->makeUnique('', 'galleries', 'slug');

        $this->assertSame('', $slug);
    }

    public function test_handles_string_with_only_special_characters()
    {
        $slug = $this->service->makeUnique('!!! $$$ @@@', 'galleries', 'slug');

        $this->assertSame('at-at-at', $slug);
    }
}
