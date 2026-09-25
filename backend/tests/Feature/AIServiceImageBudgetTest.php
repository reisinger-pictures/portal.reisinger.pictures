<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Gallery;
use App\Models\Photo;
use App\Models\Role;
use App\Models\User;
use App\Services\AIService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AIServiceImageBudgetTest extends TestCase
{
    use RefreshDatabase;

    private User $photographer;

    private Gallery $gallery;

    private Photo $photo;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'scout.driver' => 'null',
            'services.ai' => [
                'enabled' => true,
                'type' => 'openai',
                'base_url' => 'https://api.openai.com/v1',
                'api_key' => 'test-key',
                'model' => 'gpt-4o',
            ],
        ]);

        $this->photographer = User::factory()->create();
        $this->photographer->roles()->attach(
            Role::firstOrCreate(['name' => UserRole::PHOTOGRAPHER->value])
        );

        $this->gallery = Gallery::factory()->create(['type' => 'delivery']);
        $this->photographer->photographerGalleries()->attach($this->gallery->id);
        $this->photo = Photo::factory()->create([
            'gallery_id' => $this->gallery->id,
            'user_id' => $this->photographer->id,
        ]);

        $this->useTemporaryStorageDisk('photos');
    }

    public function test_invalid_image_returns_documented_422_without_provider_work(): void
    {
        Storage::disk('photos')->put(
            $this->gallery->id.'/'.$this->photo->filename,
            'not-an-image'
        );
        Http::fake();

        $response = $this->actingAs($this->photographer, 'api')
            ->postJson('/api/ai/generate-metadata', [
                'photo_id' => $this->photo->id,
            ]);

        $response->assertStatus(422)
            ->assertExactJson(['error' => AIService::IMAGE_INVALID_ERROR]);
        Http::assertNothingSent();
    }
}
