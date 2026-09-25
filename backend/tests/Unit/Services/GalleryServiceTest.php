<?php

namespace Tests\Unit\Services;

use App\Enums\UserRole;
use App\Models\Gallery;
use App\Models\GalleryGroup;
use App\Models\Org;
use App\Models\Photo;
use App\Models\Role;
use App\Models\User;
use App\Services\GalleryService;
use App\Services\SlugService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\MockObject\Stub;
use Tests\TestCase;

class GalleryServiceTest extends TestCase
{
    use RefreshDatabase;

    private GalleryService $service;

    private SlugService|Stub $slugService;

    private ?User $actor = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->slugService = $this->createStub(SlugService::class);
        $this->service = new GalleryService($this->slugService);
    }

    /**
     * A persisted cross-brand Super-Admin — the most permissive actor
     * `updateGallery()` accepts.
     *
     * P1-M15 made the actor a required, non-nullable argument: an earlier
     * `?User $user = null` default silently disabled every identity and brand
     * check for callers that forgot it. These unit tests therefore pass the
     * real actor instead of relying on a default; a trusted cross-brand
     * Super-Admin keeps the field-mapping assertions below free of
     * authorization concerns.
     */
    private function actor(): User
    {
        return $this->actor ??= tap(
            User::factory()->create(['brand' => null]),
            function (User $user): void {
                $user->roles()->attach(Role::firstOrCreate(['name' => UserRole::SUPER_ADMIN->value]));
            }
        );
    }

    // ─── storeGroup() ───────────────────────────────────────────────

    public function test_store_group_creates_group_with_slug_from_service(): void
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

    public function test_store_group_uses_name_for_slug_when_slug_not_provided(): void
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

    public function test_store_group_accepts_parent_id_and_org_id(): void
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

    public function test_update_group_updates_existing_group(): void
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

    public function test_update_group_renews_slug_when_changed(): void
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

    public function test_update_group_synchronizes_org_pivot_and_explicit_null_clears_it(): void
    {
        $group = GalleryGroup::factory()->create([
            'name' => 'Original',
            'slug' => 'original',
        ]);
        $orgA = Org::factory()->create();
        $orgB = Org::factory()->create();
        $group->orgs()->attach($orgA->id);

        $this->service->updateGroup($group, [
            'name' => 'Original',
            'org_id' => $orgB->id,
        ]);

        $this->assertDatabaseMissing('gallery_group_org', [
            'gallery_group_id' => $group->id,
            'org_id' => $orgA->id,
        ]);
        $this->assertDatabaseHas('gallery_group_org', [
            'gallery_group_id' => $group->id,
            'org_id' => $orgB->id,
        ]);

        $this->service->updateGroup($group, [
            'name' => 'Original',
            'org_id' => null,
        ]);

        $this->assertDatabaseMissing('gallery_group_org', [
            'gallery_group_id' => $group->id,
            'org_id' => $orgB->id,
        ]);
    }

    // ─── storeGallery() ─────────────────────────────────────────────

    public function test_store_gallery_creates_gallery_with_basic_data(): void
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

    public function test_store_gallery_inherits_public_from_group(): void
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

    public function test_store_gallery_selection_type_always_not_public_and_not_live(): void
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

    public function test_store_gallery_selection_cannot_inherit_public_or_free_download_from_group(): void
    {
        $group = GalleryGroup::factory()->create([
            'is_public' => true,
            'is_free_download' => true,
        ]);
        $this->slugService->method('makeUnique')->willReturn('selection-inherited');

        $gallery = $this->service->storeGallery([
            'name' => 'Selection in permissive group',
            'type' => 'selection',
            'gallery_group_id' => $group->id,
            'is_public' => true,
            'is_free_download' => true,
        ], null);

        $this->assertFalse($gallery->is_public);
        $this->assertFalse($gallery->is_free_download);
        $this->assertFalse($gallery->effective_is_public);
        $this->assertFalse($gallery->effective_is_free_download);
    }

    public function test_update_gallery_selection_cannot_set_public_or_free_download(): void
    {
        $gallery = Gallery::factory()->create([
            'type' => 'selection',
            'is_public' => false,
            'is_free_download' => false,
        ]);

        $updated = $this->service->updateGallery($gallery, [
            'is_public' => true,
            'is_free_download' => true,
            'is_live' => true,
        ], $this->actor());

        $this->assertFalse($updated->is_public);
        $this->assertFalse($updated->is_free_download);
        $this->assertFalse($updated->is_live);
    }

    public function test_selection_model_boundary_blocks_direct_flag_updates(): void
    {
        $gallery = Gallery::factory()->create(['type' => 'selection']);

        $gallery->update([
            'is_public' => true,
            'is_free_download' => true,
            'is_live' => true,
        ]);

        $gallery->refresh();
        $this->assertFalse($gallery->is_public);
        $this->assertFalse($gallery->is_free_download);
        $this->assertFalse($gallery->is_live);
    }

    public function test_store_gallery_assigns_photographer_via_sync(): void
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

    public function test_store_gallery_does_not_sync_non_photographer(): void
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

    public function test_store_gallery_applies_password_hash(): void
    {
        $this->slugService->method('makeUnique')->willReturn('pw-slug');

        $gallery = $this->service->storeGallery([
            'name' => 'Password Protected',
            'type' => 'delivery',
            'password' => 'secret123',
        ], null);

        $this->assertNotNull($gallery->password_hash);
        $this->assertNotSame('secret123', $gallery->password_hash);
        $this->assertTrue(Hash::check('secret123', $gallery->password_hash));
    }

    public function test_store_gallery_parses_expires_at_to_end_of_day(): void
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

    public function test_store_gallery_throws_on_invalid_expires_at(): void
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

    public function test_update_gallery_updates_basic_fields(): void
    {
        $gallery = Gallery::factory()->create(['name' => 'Original Name']);

        $updated = $this->service->updateGallery($gallery, [
            'name' => 'Updated Name',
        ], $this->actor());

        $this->assertSame('Updated Name', $updated->name);
        $this->assertDatabaseHas('galleries', [
            'id' => $gallery->id,
            'name' => 'Updated Name',
        ]);
    }

    public function test_update_gallery_enforces_slug_uniqueness(): void
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
        ], $this->actor());

        $this->assertSame('new-slug-1', $updated->slug);
    }

    public function test_update_gallery_does_not_check_uniqueness_when_slug_unchanged(): void
    {
        $gallery = Gallery::factory()->create(['slug' => 'my-slug']);

        $this->slugService = $this->createMock(SlugService::class);
        $this->service = new GalleryService($this->slugService);
        $this->slugService->expects($this->never())
            ->method('makeUnique');

        $this->service->updateGallery($gallery, [
            'name' => 'New Name',
        ], $this->actor());
    }

    public function test_update_gallery_selection_type_forces_is_live_and_is_public_false(): void
    {
        $gallery = Gallery::factory()->create([
            'type' => 'delivery',
            'is_live' => true,
            'is_public' => true,
        ]);

        $updated = $this->service->updateGallery($gallery, [
            'type' => 'selection',
            'name' => 'Now Selection',
        ], $this->actor());

        $this->assertFalse($updated->is_live);
        $this->assertFalse($updated->is_public);
        $this->assertSame('selection', $updated->type);
    }

    public function test_update_gallery_applies_parent_visibility_policy(): void
    {
        $privateGroup = GalleryGroup::factory()->create(['is_public' => false]);
        $publicGroup = GalleryGroup::factory()->create(['is_public' => true]);

        Gallery::withoutSyncingToSearch(function () use ($privateGroup, $publicGroup): void {
            $gallery = Gallery::factory()->create([
                'type' => 'delivery',
                'is_public' => false,
            ]);

            $updated = $this->service->updateGallery($gallery, [
                'gallery_group_id' => $privateGroup->id,
                'is_public' => true,
            ], $this->actor());
            $this->assertFalse($updated->is_public);

            $updated = $this->service->updateGallery($updated, [
                'gallery_group_id' => $publicGroup->id,
                'is_public' => false,
            ], $this->actor());
            $this->assertTrue($updated->is_public);
        });
    }

    public function test_update_gallery_preserves_explicit_visibility_for_non_enforcing_parent(): void
    {
        $group = GalleryGroup::factory()->create(['is_public' => null]);

        Gallery::withoutSyncingToSearch(function () use ($group): void {
            $gallery = Gallery::factory()->create([
                'type' => 'delivery',
                'is_public' => false,
                'gallery_group_id' => $group->id,
            ]);

            $updated = $this->service->updateGallery($gallery, [
                'is_public' => true,
            ], $this->actor());

            $this->assertTrue($updated->is_public);
        });
    }

    public function test_update_gallery_converts_null_booleans_to_false(): void
    {
        $gallery = Gallery::factory()->create();

        $updated = $this->service->updateGallery($gallery, [
            'is_free_download' => null,
            'is_editorial_only' => null,
            'is_hidden' => null,
        ], $this->actor());

        $this->assertFalse($updated->is_free_download);
        $this->assertFalse($updated->is_editorial_only);
        $this->assertFalse($updated->is_hidden);
    }

    public function test_update_gallery_updates_password_hash(): void
    {
        $gallery = Gallery::factory()->create(['password_hash' => null]);

        $updated = $this->service->updateGallery($gallery, [
            'password' => 'new-password',
        ], $this->actor());

        $this->assertNotNull($updated->password_hash);
        $this->assertTrue(Hash::check('new-password', $updated->password_hash));
    }

    public function test_update_gallery_parses_expires_at(): void
    {
        $gallery = Gallery::factory()->create(['expires_at' => null]);

        $updated = $this->service->updateGallery($gallery, [
            'expires_at' => '2027-06-15',
        ], $this->actor());

        $this->assertNotNull($updated->expires_at);
        $this->assertSame('2027-06-15 23:59:59', $updated->expires_at->format('Y-m-d H:i:s'));
    }

    public function test_update_gallery_does_not_change_slug_when_only_name_changes(): void
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
        ], $this->actor());

        $this->assertSame('original-slug', $updated->slug);
    }

    /**
     * Regression for P1-M1: an omitted optional org_ids field must preserve the
     * existing pivot assignments instead of being interpreted as an empty list.
     */
    public function test_update_gallery_keeps_orgs_when_org_ids_absent(): void
    {
        Gallery::withoutSyncingToSearch(function (): void {
            $gallery = Gallery::factory()->create(['is_live' => false]);
            $org = Org::factory()->create();
            $gallery->orgs()->attach($org->id);

            $updated = $this->service->updateGallery($gallery, ['is_live' => true], $this->actor());

            $this->assertTrue($updated->is_live);
            $this->assertDatabaseHas('gallery_org', [
                'gallery_id' => $gallery->id,
                'org_id' => $org->id,
            ]);
        });
    }

    public function test_update_gallery_syncs_orgs_when_org_ids_provided(): void
    {
        Gallery::withoutSyncingToSearch(function (): void {
            $gallery = Gallery::factory()->create();
            $orgA = Org::factory()->create();
            $orgB = Org::factory()->create();
            $gallery->orgs()->attach($orgA->id);

            $this->service->updateGallery($gallery, ['org_ids' => [$orgB->id]], $this->actor());

            $this->assertDatabaseMissing('gallery_org', [
                'gallery_id' => $gallery->id,
                'org_id' => $orgA->id,
            ]);
            $this->assertDatabaseHas('gallery_org', [
                'gallery_id' => $gallery->id,
                'org_id' => $orgB->id,
            ]);
        });
    }

    public function test_update_gallery_ignores_null_slug(): void
    {
        $gallery = Gallery::factory()->create(['slug' => 'keep-me']);

        $this->slugService = $this->createMock(SlugService::class);
        $this->service = new GalleryService($this->slugService);
        $this->slugService->expects($this->never())->method('makeUnique');

        $updated = $this->service->updateGallery($gallery, [
            'slug' => null,
            'name' => 'Name stays',
        ], $this->actor());

        $this->assertSame('keep-me', $updated->slug);
        $this->assertSame('Name stays', $updated->name);
        $this->assertDatabaseHas('galleries', ['id' => $gallery->id, 'slug' => 'keep-me']);
    }

    // ─── applyMetadataToPhotos() ────────────────────────────────────

    public function test_apply_metadata_to_photos_does_nothing_when_disabled(): void
    {
        $gallery = Gallery::factory()->create([
            'apply_metadata_to_photos' => false,
            'default_title' => 'Default Title',
        ]);

        $photo = Photo::factory()->create([
            'gallery_id' => $gallery->id,
            'title' => null,
        ]);

        $this->service->applyMetadataToPhotos($gallery);

        $this->assertNull($photo->fresh()->title);
    }

    public function test_apply_metadata_to_photos_fills_empty_fields(): void
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

        $photo = Photo::factory()->create([
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

    public function test_apply_metadata_to_photos_does_not_overwrite_existing_values(): void
    {
        $gallery = Gallery::factory()->create([
            'apply_metadata_to_photos' => true,
            'default_title' => 'Default Title',
            'default_description' => 'Default Description',
        ]);

        $photo = Photo::factory()->create([
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
