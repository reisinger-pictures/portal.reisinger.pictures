<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Gallery;
use App\Models\Photo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;

class SystemMiscTest extends TestCase
{
    use RefreshDatabase;

    public function test_x_accel_redirect_header_is_present_for_nginx()
    {
        Storage::fake('photos');
        $gallery = Gallery::factory()->create(['type' => 'delivery', 'is_public' => true, 'is_free_download' => true]);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        
        Storage::disk('photos')->put($gallery->id . '/' . $photo->filename, 'dummy content');
        
        // Simuliere Nginx Proxy Konfiguration
        Config::set('services.proxy_delivery_header', 'X-Accel-Redirect');
        
        $response = $this->get('/api/media/' . $gallery->slug . '/' . $photo->id . '.jpg');
        $response->assertStatus(200);
        $response->assertHeader('X-Accel-Redirect');
        $this->assertEquals('', $response->getContent()); // Body muss bei Proxy-Offloading leer sein
        
        Config::set('services.proxy_delivery_header', null); // Cleanup
    }

    public function test_x_sendfile_header_is_present_for_apache()
    {
        Storage::fake('photos');
        $gallery = Gallery::factory()->create(['type' => 'delivery', 'is_public' => true, 'is_free_download' => true]);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        
        Storage::disk('photos')->put($gallery->id . '/' . $photo->filename, 'dummy content');
        
        // Simuliere Apache mod_xsendfile Konfiguration
        Config::set('services.proxy_delivery_header', 'X-Sendfile');
        
        $response = $this->get('/api/media/' . $gallery->slug . '/' . $photo->id . '.jpg');
        $response->assertStatus(200);
        $response->assertHeader('X-Sendfile');
        
        Config::set('services.proxy_delivery_header', null); // Cleanup
    }
}
