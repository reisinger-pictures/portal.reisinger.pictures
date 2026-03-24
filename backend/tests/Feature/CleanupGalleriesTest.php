<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Gallery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class CleanupGalleriesTest extends TestCase
{
    use RefreshDatabase;

    public function test_cleanup_removes_expired_galleries_and_files()
    {
        Storage::fake('photos');

        // Setze ein festes Datum für deterministische Tests
        Carbon::setTestNow(Carbon::create(2026, 3, 24, 12, 0, 0));

        // 1. Abgelaufene Galerie (älter als 3 Monate Grace-Periode)
        $expiredGallery = Gallery::factory()->create([
            'expires_at' => Carbon::now()->subMonths(4),
        ]);

        // 2. Gültige Galerie (läuft in der Zukunft ab)
        $validGallery = Gallery::factory()->create([
            'expires_at' => Carbon::now()->addMonths(1),
        ]);

        // Fake Storage befüllen
        Storage::disk('photos')->makeDirectory((string)$expiredGallery->id);
        Storage::disk('photos')->put($expiredGallery->id . '/test.jpg', 'dummy content');

        Storage::disk('photos')->makeDirectory((string)$validGallery->id);
        Storage::disk('photos')->put($validGallery->id . '/test.jpg', 'dummy content');

        // Command ausführen
        $this->artisan('app:cleanup-galleries')
             ->assertExitCode(0);

        // Assertions: Datenbank
        $this->assertDatabaseMissing('galleries', ['id' => $expiredGallery->id]);
        $this->assertDatabaseHas('galleries', ['id' => $validGallery->id]);

        // Assertions: Dateisystem
        $this->assertFalse(Storage::disk('photos')->exists((string)$expiredGallery->id));
        $this->assertTrue(Storage::disk('photos')->exists((string)$validGallery->id));
        
        // Carbon Mock wieder aufheben
        Carbon::setTestNow();
    }
}
