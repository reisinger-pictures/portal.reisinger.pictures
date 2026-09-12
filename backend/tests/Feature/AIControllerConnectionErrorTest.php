<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Gallery;
use App\Models\Photo;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Regression: connection/timeout failures are not RuntimeExceptions, so the
 * controller used to leak a 500 instead of a clean 503.
 */
class AIControllerConnectionErrorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.ai' => [
            'enabled' => true,
            'type' => 'openai',
            'base_url' => 'https://api.openai.com/v1',
            'api_key' => 'test-key',
            'model' => 'gpt-4o',
        ]]);
    }

    protected function tearDown(): void
    {
        auth('api')->logout();
        parent::tearDown();
    }

    private function photographer(): User
    {
        $photographer = User::factory()->create();
        $photographer->roles()->attach(Role::firstOrCreate(['name' => UserRole::PHOTOGRAPHER->value]));

        return $photographer;
    }

    private function fakeConnectionTimeout(): void
    {
        Http::fake(function () {
            throw new ConnectionException('cURL error 28: Connection timed out');
        });
    }

    public function test_generate_metadata_returns_503_on_connection_error(): void
    {
        $photographer = $this->photographer();
        $gallery = Gallery::factory()->create(['type' => 'delivery']);
        $photographer->photographerGalleries()->attach($gallery->id);

        $photo = Photo::factory()->create([
            'gallery_id' => $gallery->id,
            'user_id' => $photographer->id,
        ]);

        Storage::fake('photos');
        Storage::disk('photos')->put(
            $gallery->id.'/'.$photo->filename,
            file_get_contents(__DIR__.'/../Fixtures/sample.jpg')
        );

        $this->fakeConnectionTimeout();

        $response = $this->actingAs($photographer, 'api')
            ->postJson('/api/ai/generate-metadata', ['photo_id' => $photo->id]);

        $response->assertStatus(503);
    }

    public function test_generate_metadata_text_returns_503_on_connection_error(): void
    {
        $photographer = $this->photographer();

        $this->fakeConnectionTimeout();

        $response = $this->actingAs($photographer, 'api')
            ->postJson('/api/ai/generate-metadata-text', ['text_input' => 'A test prompt']);

        $response->assertStatus(503);
    }
}
