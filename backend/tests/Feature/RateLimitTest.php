<?php
namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Support\Facades\Route;

class RateLimitTest extends TestCase {
    public function test_api_throttling_returns_429_status() {
        // Temporäre Route mit striktem Limit definieren (2 Anfragen pro Minute)
        Route::get('/api/test-throttle', function() {
            return response()->json(['success' => true]);
        })->middleware('throttle:2,1');

        // 1. Request (Erfolg)
        $this->getJson('/api/test-throttle')->assertStatus(200);
        
        // 2. Request (Erfolg)
        $this->getJson('/api/test-throttle')->assertStatus(200);
        
        // 3. Request (Blockiert durch Rate-Limiter)
        $this->getJson('/api/test-throttle')->assertStatus(429);
    }
}
