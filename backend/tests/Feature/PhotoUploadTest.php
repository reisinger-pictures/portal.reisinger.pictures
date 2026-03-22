<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Role;
use App\Models\Gallery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class PhotoUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('photos');
    }

    public function test_photographer_can_upload_to_delivery_and_apply_fallbacks()
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::firstOrCreate(['name' => 'photographer']));

        $gallery = Gallery::factory()->create([
            'type' => 'delivery',
            'apply_metadata_to_photos' => true,
            'default_headline' => 'Fallback Headline',
            'default_country' => 'Austria'
        ]);
        $user->galleries()->attach($gallery);

        $fixturePath = base_path('tests/Fixtures/sample.jpg');
        $content = file_exists($fixturePath) ? file_get_contents($fixturePath) : 'dummy content';
        $file = UploadedFile::fake()->createWithContent('test_upload.jpg', $content);

        $token = auth('api')->login($user);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/management/upload', [
                'gallery_id' => $gallery->id,
                'lr_uuid' => 'uuid-12345',
                'file' => $file
            ]);

        $response->assertStatus(200)->assertJson(['success' => true]);
        
        // Assert: Das Fallback-System hat die Galerie-Defaults ins Foto-Model übertragen
        $this->assertDatabaseHas('photos', [
            'gallery_id' => $gallery->id,
            'lr_uuid' => 'uuid-12345',
            'headline' => 'Fallback Headline',
            'country' => 'Austria'
        ]);
    }

    public function test_upload_to_selection_gallery_skips_metadata_extraction()
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::firstOrCreate(['name' => 'photographer']));

        $gallery = Gallery::factory()->create([
            'type' => 'selection',
            // Auch wenn Defaults gesetzt sind, darf Selection diese nicht anwenden!
            'apply_metadata_to_photos' => true, 
            'default_headline' => 'Should be ignored'
        ]);
        $user->galleries()->attach($gallery);

        $fixturePath = base_path('tests/Fixtures/sample.jpg');
        $content = file_exists($fixturePath) ? file_get_contents($fixturePath) : 'dummy content';
        $file = UploadedFile::fake()->createWithContent('selection.jpg', $content);
        $token = auth('api')->login($user);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/management/upload', [
                'gallery_id' => $gallery->id,
                'lr_uuid' => 'uuid-selection',
                'file' => $file
            ]);

        $response->assertStatus(200);
        
        $this->assertDatabaseHas('photos', [
            'gallery_id' => $gallery->id,
            'lr_uuid' => 'uuid-selection',
            'headline' => null // Wurde übersprungen!
        ]);
    }
}
