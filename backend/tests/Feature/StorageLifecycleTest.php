<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Gallery;
use App\Models\Photo;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

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
        $user->roles()->attach(Role::firstOrCreate(['name' => UserRole::CLIENT->value]));
        $gallery = Gallery::factory()->create(['type' => 'delivery', 'is_public' => true]);
        $user->galleries()->attach($gallery);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);

        // Create dummy file
        $fixturePath = base_path('tests/Fixtures/sample.jpg');
        Storage::disk('photos')->put($gallery->id.'/'.$photo->filename, file_get_contents($fixturePath));

        // Ensure initially null
        $this->assertNull($photo->last_accessed_at);

        // Wir loggen uns ein, um die ImageMagick-Wasserzeichengenerierung zu umgehen,
        // welche an der Dummy-Textdatei des Tests scheitern und 404 auslösen würde.
        $token = auth('api')->login($user);

        // First Request
        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->get('/api/media/'.$gallery->slug.'/'.$photo->id.'.jpg')
            ->assertStatus(200);

        // Verify DB was updated
        $photo->refresh();
        $this->assertNotNull($photo->last_accessed_at);
        $firstTimestamp = $photo->last_accessed_at;

        // Verify Cache is set
        $this->assertTrue(Cache::has('photo_hit_'.$photo->id));

        // Advance the clock deterministically instead of a real sleep(1): a second
        // request "one second later" must still hit the 24h cache and therefore must
        // NOT touch last_accessed_at. Advancing time (rather than not advancing) is
        // what makes a broken throttle detectable, since SQLite timestamps have
        // 1-second resolution and an erroneous re-write would otherwise be invisible.
        Carbon::setTestNow(Carbon::now()->addSecond());

        try {
            $this->withHeaders(['Authorization' => "Bearer $token"])
                ->get('/api/media/'.$gallery->slug.'/'.$photo->id.'.jpg')
                ->assertStatus(200);

            // Verify DB was NOT updated again due to cache throttling
            $photo->refresh();
            $this->assertEquals($firstTimestamp, $photo->last_accessed_at);
        } finally {
            Carbon::setTestNow();
        }
    }
}
