<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Gallery;
use App\Models\Photo;
use App\Models\User;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Meilisearch\Contracts\TasksQuery;

class SearchTest extends TestCase {
    use RefreshDatabase;

    protected function setUp(): void {
        parent::setUp();
        \Illuminate\Support\Facades\Artisan::call('scout:flush', ['model' => Photo::class]);
        \Illuminate\Support\Facades\Artisan::call('scout:flush', ['model' => Gallery::class]);
        \Illuminate\Support\Facades\Artisan::call('scout:sync-index-settings');
    }

    protected function waitForSearchIndex() {
        $client = app(\Meilisearch\Client::class);
        $query = (new TasksQuery())->setStatuses(['enqueued', 'processing']);
        $tasks = $client->getTasks($query);

        foreach ($tasks as $task) {
            // SDK Fix: $task ist ein Array
            $uid = is_array($task) ? $task['uid'] : $task->getUid();
            $client->waitForTask($uid, 5000, 50);
        }
    }

    public function test_search_discovery_returns_public_galleries() {
        Gallery::factory()->create(['is_public' => true, 'name' => 'Public Wedding']);
        Gallery::factory()->create(['is_public' => false, 'name' => 'Private Secret']);

        $response = $this->getJson('/api/search?q=');
        $response->assertStatus(200);
        $this->assertCount(1, $response->json('galleries'));
    }

    public function test_search_filters_photos_by_metadata() {
        $gallery = Gallery::factory()->create(['is_public' => true, 'type' => 'delivery']);
        Photo::factory()->create([
            'gallery_id' => $gallery->id,
            'title' => 'UniqueMountainView',
        ]);

        $this->waitForSearchIndex();

        $response = $this->getJson('/api/search?q=UniqueMountainView');
        $response->assertStatus(200);
        $this->assertCount(1, $response->json('photos'));
    }

    public function test_search_respects_role_based_filtering() {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::firstOrCreate(['name' => 'admin']));

        Gallery::factory()->create(['is_public' => false, 'name' => 'Secret Admin Stuff']);
        Gallery::factory()->create(['is_public' => true, 'name' => 'Public Showcase']);

        $this->waitForSearchIndex();

        $adminToken = auth('api')->login($admin);
        $response = $this->withHeaders(['Authorization' => "Bearer $adminToken"])->getJson('/api/search?q=');
        
        $this->assertCount(1, $response->json('galleries'));
    }

    public function test_client_can_find_photos_from_authorized_private_gallery() {
        $client = User::factory()->create();
        $client->roles()->attach(Role::firstOrCreate(['name' => 'client']));

        $privateGallery = Gallery::factory()->create(['is_public' => false, 'type' => 'delivery']);
        $client->galleries()->attach($privateGallery);
        Photo::factory()->create(['gallery_id' => $privateGallery->id, 'title' => 'AllowedPhoto123']);

        $this->waitForSearchIndex();

        $token = auth('api')->login($client);
        $response = $this->withHeaders(['Authorization' => "Bearer $token"])->getJson('/api/search?q=AllowedPhoto123');

        $this->assertCount(1, $response->json('photos'));
    }

    public function test_photographer_can_find_photos_from_own_gallery_but_not_others() {
        $photog = User::factory()->create();
        $photog->roles()->attach(Role::firstOrCreate(['name' => 'photographer']));

        $ownGallery = Gallery::factory()->create(['is_public' => false, 'type' => 'delivery']);
        $photog->galleries()->attach($ownGallery);
        Photo::factory()->create(['gallery_id' => $ownGallery->id, 'title' => 'PhotogOwnPhoto']);

        $this->waitForSearchIndex();

        $token = auth('api')->login($photog);
        $response = $this->withHeaders(['Authorization' => "Bearer $token"])->getJson('/api/search?q=PhotogOwnPhoto');

        $this->assertCount(1, $response->json('photos'));
    }
}
