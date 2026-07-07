<?php

namespace Tests\Unit\Services;

use App\Models\Gallery;
use App\Models\GalleryGroup;
use App\Models\Role;
use App\Models\User;
use App\Services\GalleryService;
use App\Services\SlugService;
use App\Enums\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class GalleryServiceTest extends TestCase
{
    use RefreshDatabase;

    private GalleryService $service;
    private SlugService|\PHPUnit\Framework\MockObject\Stub $slugService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->slugService = $this->createStub(SlugService::class);
        $this->service = new GalleryService($this->slugService);
    }

    // ─── storeGroup() ───────────────────────────────────────────────

    public function test_storeGroup_creates_group_with_slug_from_service(): void
    {
        $this->slugService = $this->createMock(SlugService::class);
        $this->service = new GalleryService($this->slugService);
        $this->slugService->expects($this->once())
            ->method('makeUnique')
            ->with('meine-gruppe', 'gallery_groups', 'slug', null)
            ->willReturn('meine-gruppe');

        $group = $this->service->storeGroup([
            'name' => 'Meine Gruppe',
            'slug' => 'meine-gruppe',
        ]);

        $this->assertInstanceOf(GalleryGroup::class, $group);
        $this->assertSame('Meine Gruppe', $group->name);
        $this->assertSame('meine-gruppe', $group->slug);
        $this->assertNull($group->is_public);
        $this->assertFalse($group->is_free_download);
        $this->assertFalse($group->is_editorial_only);
        $this->assertFalse($group->is_hidden);
        $this->assertNull($group->parent_id);
        $this->assertNull($group->org_id);
        $this->assertDatabaseHas('gallery_groups', ['slug' => 'meine-gruppe']);
    }

    public function test_storeGroup_uses_name_for_slug_when_slug_not_provided(): void
    {
        $this->slugService = $this->createMock(SlugService::class);
        $this->service = new GalleryService($this->slugService);
        $this->slugService->expects($this->once())
            ->method('makeUnique')
            ->with('Meine Gruppe', 'gallery_groups', 'slug', null)
            ->willReturn('meine-gruppe');

        $group = $this->service->storeGroup([
            'name' => 'Meine Gruppe',
        ]);

        $this->assertSame('meine-gruppe', $group->slug);
    }

    public function test_storeGroup_accepts_parent_id_and_org_id(): void
    {
        $parent = GalleryGroup::factory()->create();

        $this->slugService->method('makeUnique')->willReturn('kind');

        $group = $this->service->storeGroup([
            'name' => 'Kind Gruppe',
            'parent_id' => $parent->id,
            'is_public' => true,
            'is_editorial_only' => true,
        ]);

        $this->assertSame($parent->id, $group->parent_id);
        $this->assertNull($group->org_id);
        $this->assertTrue($group->is_public);
        $this->assertTrue($group->is_editorial_only);
    }

    // ─── updateGroup() ──────────────────────────────────────────────

    public function test_updateGroup_updates_existing_group(): void
    {
        $group = GalleryGroup::factory()->create([
            'name' => 'Original',
            'slug' => 'original',
        ]);

        $this->slugService = $this->createMock(SlugService::class);
        $this->service = new GalleryService($this->slugService);
        $this->slugService->expects($this->once())
            ->method('makeUnique')
            ->with('aktualisiert', 'gallery_groups', 'slug', null)
            ->willReturn('aktualisiert');

        $updated = $this->service->updateGroup($group, [
            'name' => 'Aktualisiert',
        ]);

        $this->assertSame('Aktualisiert', $updated->name);
        $this->assertSame('aktualisiert', $updated->slug);
        $this->assertDatabaseHas('gallery_groups', [
            'id' => $group->id,
            'name' => 'Aktualisiert',
        ]);
    }

    public function test_updateGroup_renews_slug_when_changed(): void
    {
        $group = GalleryGroup::factory()->create([
            'name' => 'Original',
            'slug' => 'original',
        ]);

        $this->slugService = $this->createMock(SlugService::class);
        $this->service = new GalleryService($this->slugService);
        $this->slugService->expects($this->once())
            ->method('makeUnique')
            ->with('neuer-slug', 'gallery_groups', 'slug', null)
            ->willReturn('neuer-slug-1');

        $updated = $this->service->updateGroup($group, [
            'name' => 'Original',
            'slug' => 'neuer slug',
        ]);

        $this->assertSame('neuer-slug-1', $updated->slug);
    }

    // ─── storeGallery() ─────────────────────────────────────────────

    public function test_storeGallery_creates_gallery_with_basic_data(): void
    {
        $this->slugService = $this->createMock(SlugService::class);
        $this->service = new GalleryService($this->slugService);
        $this->slugService->expects($this->once())
            ->method('makeUnique')
            ->with('Meine Galerie', 'galleries', 'slug', null)
            ->willReturn('meine-galerie');

        $gallery = $this->service->storeGallery([
            'name' => 'Meine Galerie',
            'type' => 'delivery',
        ], null);

        $this->assertInstanceOf(Gallery::class, $gallery);
        $this->assertSame('Meine Galerie', $gallery->name);
        $this->assertSame('meine-galerie', $gallery->slug);
        $this->assertSame('delivery', $gallery->type);
        $this->assertFalse($gallery->is_public);
        $this->assertFalse($gallery->is_live);
        $this->assertDatabaseHas('galleries', ['slug' => 'meine-galerie']);
    }

    public function test_storeGallery_inherits_public_from_group(): void
    {
        $group = GalleryGroup::factory()->create(['is_public' => true]);

        $this->slugService->method('makeUnique')->willReturn('slug');

        $gallery = $this->service->storeGallery([
            'name' => 'Gallery in Public Group',
            'type' => 'delivery',
            'gallery_group_id' => $group->id,
        ], null);

        $this->assertTrue($gallery->is_public);
    }

    public function test_storeGallery_selection_type_always_not_public_and_not_live(): void
    {
        $this->slugService->method('makeUnique')->willReturn('selection-slug');

        $gallery = $this->service->storeGallery([
            'name' => 'Selection Gallery',
            'type' => 'selection',
            'is_live' => true,
            'is_public' => true,
        ], null);

        $this->assertFalse($gallery->is_public);
        $this->assertFalse($gallery->is_live);
        $this->assertSame('selection', $gallery->type);
    }

    public function test_storeGallery_assigns_photographer_via_sync(): void
    {
        $photographer = User::factory()->create();
        $photographer->roles()->attach(Role::firstOrCreate(['name' => UserRole::PHOTOGRAPHER->value]));

        $this->slugService->method('makeUnique')->willReturn('photog-slug');

        $gallery = $this->service->storeGallery([
            'name' => 'Photographer Gallery',
            'type' => 'delivery',
        ], $photographer);

        $this->assertDatabaseHas('photographer_galleries', [
            'user_id' => $photographer->id,
            'gallery_id' => $gallery->id,
        ]);
    }

    public function test_storeGallery_does_not_sync_non_photographer(): void
    {
        $client = User::factory()->create();

        $this->slugService->method('makeUnique')->willReturn('client-slug');

        $gallery = $this->service->storeGallery([
            'name' => 'Client Gallery',
            'type' => 'delivery',
        ], $client);

        $this->assertDatabaseMissing('photographer_galleries', [
            'user_id' => $client->id,
            'gallery_id' => $gallery->id,
        ]);
    }

    public function test_storeGallery_applies_password_hash(): void
    {
        $this->slugService->method('makeUnique')->willReturn('pw-slug');

        $gallery = $this->service->storeGallery([
            'name' => 'Password Protected',
            'type' => 'delivery',
            'password' => 'secret123',
        ], null);

        $this->assertNotNull($gallery->password_hash);
        $this->assertNotSame('secret123', $gallery->password_hash);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('secret123', $gallery->password_hash));
    }

    public function test_storeGallery_parses_expires_at_to_end_of_day(): void
    {
        $this->slugService->method('makeUnique')->willReturn('expires-slug');

        $gallery = $this->service->storeGallery([
            'name' => 'Expiring Gallery',
            'type' => 'delivery',
            'expires_at' => '2026-12-31',
        ], null);

        $this->assertNotNull($gallery->expires_at);
        $this->assertSame('2026-12-31 23:59:59', $gallery->expires_at->format('Y-m-d H:i:s'));
    }

    public function test_storeGallery_throws_on_invalid_expires_at(): void
    {
        $this->slugService->method('makeUnique')->willReturn('bad-date');

        $this->expectException(ValidationException::class);

        $this->service->storeGallery([
            'name' => 'Bad Date',
            'type' => 'delivery',
            'expires_at' => 'kein-datum',
        ], null);
    }

    // ─── updateGallery() ────────────────────────────────────────────

    public function test_updateGallery_updates_basic_fields(): void
    {
        $gallery = Gallery::factory()->create(['name' => 'Original Name']);

        $updated = $this->service->updateGallery($gallery, [
            'name' => 'Updated Name',
        ]);

        $this->assertSame('Updated Name', $updated->name);
        $this->assertDatabaseHas('galleries', [
            'id' => $gallery->id,
            'name' => 'Updated Name',
        ]);
    }

    public function test_updateGallery_enforces_slug_uniqueness(): void
    {
        $gallery = Gallery::factory()->create(['slug' => 'existing-slug']);

        $this->slugService = $this->createMock(SlugService::class);
        $this->service = new GalleryService($this->slugService);
        $this->slugService->expects($this->once())
            ->method('makeUnique')
            ->with('new-slug', 'galleries', 'slug', null)
            ->willReturn('new-slug-1');

        $updated = $this->service->updateGallery($gallery, [
            'slug' => 'new-slug',
        ]);

        $this->assertSame('new-slug-1', $updated->slug);
    }

    public function test_updateGallery_does_not_check_uniqueness_when_slug_unchanged(): void
    {
        $gallery = Gallery::factory()->create(['slug' => 'my-slug']);

        $this->slugService = $this->createMock(SlugService::class);
        $this->service = new GalleryService($this->slugService);
        $this->slugService->expects($this->never())
            ->method('makeUnique');

        $this->service->updateGallery($gallery, [
            'name' => 'New Name',
        ]);
    }

    public function test_updateGallery_selection_type_forces_is_live_and_is_public_false(): void
    {
        $gallery = Gallery::factory()->create([
            'type' => 'delivery',
            'is_live' => true,
            'is_public' => true,
        ]);

        $updated = $this->service->updateGallery($gallery, [
            'type' => 'selection',
            'name' => 'Now Selection',
        ]);

        $this->assertFalse($updated->is_live);
        $this->assertFalse($updated->is_public);
        $this->assertSame('selection', $updated->type);
    }

    public function test_updateGallery_converts_null_booleans_to_false(): void
    {
        $gallery = Gallery::factory()->create();

        $updated = $this->service->updateGallery($gallery, [
            'is_free_download' => null,
            'is_editorial_only' => null,
            'is_hidden' => null,
        ]);

        $this->assertFalse($updated->is_free_download);
        $this->assertFalse($updated->is_editorial_only);
        $this->assertFalse($updated->is_hidden);
    }

    public function test_updateGallery_updates_password_hash(): void
    {
        $gallery = Gallery::factory()->create(['password_hash' => null]);

        $updated = $this->service->updateGallery($gallery, [
            'password' => 'new-password',
        ]);

        $this->assertNotNull($updated->password_hash);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('new-password', $updated->password_hash));
    }

    public function test_updateGallery_parses_expires_at(): void
    {
        $gallery = Gallery::factory()->create(['expires_at' => null]);

        $updated = $this->service->updateGallery($gallery, [
            'expires_at' => '2027-06-15',
        ]);

        $this->assertNotNull($updated->expires_at);
        $this->assertSame('2027-06-15 23:59:59', $updated->expires_at->format('Y-m-d H:i:s'));
    }

    public function test_updateGallery_does_not_change_slug_when_only_name_changes(): void
    {
        $gallery = Gallery::factory()->create([
            'name' => 'Original',
            'slug' => 'original-slug',
        ]);

        $this->slugService = $this->createMock(SlugService::class);
        $this->service = new GalleryService($this->slugService);
        $this->slugService->expects($this->never())->method('makeUnique');

        $updated = $this->service->updateGallery($gallery, [
            'name' => 'Updated Name',
        ]);

        $this->assertSame('original-slug', $updated->slug);
    }

    // ─── applyMetadataToPhotos() ────────────────────────────────────

    public function test_applyMetadataToPhotos_does_nothing_when_disabled(): void
    {
        $gallery = Gallery::factory()->create([
            'apply_metadata_to_photos' => false,
            'default_title' => 'Default Title',
        ]);

        $photo = \App\Models\Photo::factory()->create([
            'gallery_id' => $gallery->id,
            'title' => null,
        ]);

        $this->service->applyMetadataToPhotos($gallery);

        $this->assertNull($photo->fresh()->title);
    }

    public function test_applyMetadataToPhotos_fills_empty_fields(): void
    {
        $gallery = Gallery::factory()->create([
            'apply_metadata_to_photos' => true,
            'default_title' => 'Default Title',
            'default_description' => 'Default Description',
            'default_keywords' => 'key1, key2',
            'default_location' => 'Vienna',
            'default_city' => 'Vienna',
            'default_country' => 'Austria',
            'default_iso_country' => 'AT',
        ]);

        $photo = \App\Models\Photo::factory()->create([
            'gallery_id' => $gallery->id,
            'title' => null,
            'description' => null,
            'keywords' => null,
            'location' => null,
            'city' => null,
            'country' => null,
            'iso_country' => null,
        ]);

        $this->service->applyMetadataToPhotos($gallery);

        $photo->refresh();

        $this->assertSame('Default Title', $photo->title);
        $this->assertSame('Default Description', $photo->description);
        $this->assertSame('key1, key2', $photo->keywords);
        $this->assertSame('Vienna', $photo->location);
        $this->assertSame('Vienna', $photo->city);
        $this->assertSame('Austria', $photo->country);
        $this->assertSame('AT', $photo->iso_country);
    }

    public function test_applyMetadataToPhotos_does_not_overwrite_existing_values(): void
    {
        $gallery = Gallery::factory()->create([
            'apply_metadata_to_photos' => true,
            'default_title' => 'Default Title',
            'default_description' => 'Default Description',
        ]);

        $photo = \App\Models\Photo::factory()->create([
            'gallery_id' => $gallery->id,
            'title' => 'Existing Title',
            'description' => null,
        ]);

        $this->service->applyMetadataToPhotos($gallery);

        $photo->refresh();

        $this->assertSame('Existing Title', $photo->title);
        $this->assertSame('Default Description', $photo->description);
    }
}
