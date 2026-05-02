<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Role;
use App\Models\Gallery;
use App\Models\Photo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Cache;

class StorageLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('photos');
    }

    public function test_image_delivery_updates_last_accessed_at_and_caches_hit()
    {
        // Require flatrate_level to bypass watermark generation (which would 404 without ImageMagick)
        $user = User::factory()->create(['flatrate_level' => 'original']);
        $user->roles()->attach(Role::firstOrCreate(['name' => \App\Enums\UserRole::CLIENT->value]));
        $gallery = Gallery::factory()->create(['type' => 'delivery', 'is_public' => true]);
        $user->galleries()->attach($gallery);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);

        // Create dummy file
        $fixturePath = base_path('tests/Fixtures/sample.jpg');
        Storage::disk('photos')->put($gallery->id . '/' . $photo->filename, file_get_contents($fixturePath));

        // Ensure initially null
        $this->assertNull($photo->last_accessed_at);

        // Wir loggen uns ein, um die ImageMagick-Wasserzeichengenerierung zu umgehen, 
        // welche an der Dummy-Textdatei des Tests scheitern und 404 auslösen würde.
        $token = auth('api')->login($user);

        // First Request
        $this->withHeaders(['Authorization' => "Bearer $token"])
             ->get('/api/media/' . $gallery->slug . '/' . $photo->id . '.jpg')
             ->assertStatus(200);

        // Verify DB was updated
        $photo->refresh();
        $this->assertNotNull($photo->last_accessed_at);
        $firstTimestamp = $photo->last_accessed_at;

        // Verify Cache is set
        $this->assertTrue(Cache::has('photo_hit_' . $photo->id));

        // Sleep briefly and request again
        sleep(1);
        $this->withHeaders(['Authorization' => "Bearer $token"])
             ->get('/api/media/' . $gallery->slug . '/' . $photo->id . '.jpg')
             ->assertStatus(200);

        // Verify DB was NOT updated again due to cache throttling
        $photo->refresh();
        $this->assertEquals($firstTimestamp, $photo->last_accessed_at);
    }
}
