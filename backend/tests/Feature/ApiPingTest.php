<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApiPingTest extends TestCase
{
    public function test_api_ping(): void
    {
        $this->get('/api/ping')->assertStatus(200)->assertJson(['message' => 'API OK']);
    }
}
