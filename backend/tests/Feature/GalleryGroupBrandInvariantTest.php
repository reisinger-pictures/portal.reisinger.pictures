<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Enums\UserRole;
use App\Http\Middleware\ManagementMiddleware;
use App\Models\Gallery;
use App\Models\GalleryGroup;
use App\Models\Role;
use App\Models\User;
use App\Models\VolumePreset;
use App\Services\AuthorizationService;
use App\Services\GalleryService;
use App\Services\GalleryTreeService;
use App\Support\BrandRegistry;
use App\Values\BrandConfig;
use ArgumentCountError;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use TypeError;

/**
 * P1-M15 — a gallery and its group must always carry the same brand.
 *
 * Before this invariant, `GalleryService::storeGallery()` wrote the *request
 * host* brand on the gallery while a trusted cross-brand Super-Admin could
 * reference a group of any brand. The persisted pair was therefore incoherent
 * (`galleries.brand = rp`, `gallery_groups.brand = srp`), which made the
 * gallery invisible in every brand-scoped listing
 * (`BrandRegistry::galleryTreeMatchesBrand()`) while still surfacing it in the
 * cross-brand management tree.
 *
 * Chosen variant: the group is authoritative. A cross-brand Super-Admin keeps
 * its all-brand reach (no 422, unchanged UX) and the new gallery adopts the
 * group's brand; a brand-bound actor referencing a foreign group is rejected.
 *
 * These tests drive the service directly, so the guards are proven to live in
 * the authoritative boundary and not only in the FormRequest layer. The
 * HTTP/middleware layers are asserted separately, because "brand-bound" means
 * something different in each of the three:
 *
 * | actor                        | ManagementMiddleware | brandScopedExists | service |
 * |------------------------------|----------------------|-------------------|---------|
 * | brand-bound, own host        | pass                 | 422               | —       |
 * | brand-bound, foreign host    | 403                  | —                 | —       |
 * | cross-brand (brand `null`)   | pass                 | pass              | adopt   |
 */
class GalleryGroupBrandInvariantTest extends TestCase
{
    use RefreshDatabase;

    private GalleryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        config(['scout.driver' => 'null']);
        BrandRegistry::set(Brand::B2B);
        $this->service = app(GalleryService::class);
    }

    protected function tearDown(): void
    {
        // A test may install a second brand context; never leak it into the
        // static brand-config cache of the next test in this process.
        BrandRegistry::set(Brand::B2B);
        BrandRegistry::clearCache();
        parent::tearDown();
    }

    // ─── Same-brand create (the normal path) ───────────────────────────

    public function test_same_brand_create_keeps_gallery_and_group_brand_identical(): void
    {
        $group = GalleryGroup::factory()->create(['brand' => 'srp']);

        $gallery = $this->service->storeGallery([
            'name' => 'Same brand gallery',
            'type' => 'delivery',
            'gallery_group_id' => $group->id,
        ], $this->user(UserRole::PHOTOGRAPHER, 'srp'));

        $this->assertSame('srp', $this->brandId($gallery));
        $this->assertSame($this->brandId($group), $this->brandId($gallery));
        $this->assertDatabaseHas('galleries', [
            'id' => $gallery->id,
            'brand' => 'srp',
            'gallery_group_id' => $group->id,
        ]);
    }

    public function test_create_without_a_group_keeps_the_request_host_brand(): void
    {
        $gallery = $this->service->storeGallery([
            'name' => 'Root gallery',
            'type' => 'delivery',
        ], $this->user(UserRole::PHOTOGRAPHER, Brand::B2B->value));

        $this->assertSame(Brand::B2B->value, $this->brandId($gallery));
        $this->assertNull($gallery->gallery_group_id);
    }

    // ─── Brand-bound actor may not reach a foreign group ───────────────

    public function test_brand_bound_actor_cannot_attach_a_foreign_brand_group(): void
    {
        $foreignGroup = GalleryGroup::factory()->create(['brand' => 'rp']);

        try {
            $this->service->storeGallery([
                'name' => 'Foreign group gallery',
                'type' => 'delivery',
                'gallery_group_id' => $foreignGroup->id,
            ], $this->user(UserRole::PHOTOGRAPHER, 'srp'));

            $this->fail('Expected a ValidationException for the foreign-brand group.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('gallery_group_id', $exception->errors());
        }

        $this->assertDatabaseMissing('galleries', ['gallery_group_id' => $foreignGroup->id]);
    }

    public function test_brand_bound_admin_cannot_attach_a_foreign_brand_group(): void
    {
        $foreignGroup = GalleryGroup::factory()->create(['brand' => 'srp']);

        $this->expectException(ValidationException::class);

        try {
            $this->service->storeGallery([
                'name' => 'Foreign group gallery',
                'type' => 'delivery',
                'gallery_group_id' => $foreignGroup->id,
            ], $this->user(UserRole::ADMIN, 'rp'));
        } finally {
            $this->assertDatabaseMissing('galleries', ['gallery_group_id' => $foreignGroup->id]);
        }
    }

    // ─── Fail-closed edge cases ────────────────────────────────────────

    public function test_group_without_a_brand_is_rejected(): void
    {
        $brandlessGroup = GalleryGroup::factory()->create(['brand' => null]);

        $this->expectException(ValidationException::class);

        try {
            $this->service->storeGallery([
                'name' => 'Brandless group gallery',
                'type' => 'delivery',
                'gallery_group_id' => $brandlessGroup->id,
            ], $this->user(UserRole::SUPER_ADMIN, null));
        } finally {
            $this->assertDatabaseMissing('galleries', ['gallery_group_id' => $brandlessGroup->id]);
        }
    }

    public function test_group_with_a_mixed_brand_parent_chain_is_rejected(): void
    {
        $foreignParent = GalleryGroup::factory()->create(['brand' => 'rp']);
        $childGroup = GalleryGroup::factory()->create([
            'brand' => 'srp',
            'parent_id' => $foreignParent->id,
        ]);

        $this->expectException(ValidationException::class);

        try {
            $this->service->storeGallery([
                'name' => 'Mixed chain gallery',
                'type' => 'delivery',
                'gallery_group_id' => $childGroup->id,
            ], $this->user(UserRole::SUPER_ADMIN, null));
        } finally {
            $this->assertDatabaseMissing('galleries', ['gallery_group_id' => $childGroup->id]);
        }
    }

    public function test_unknown_group_is_rejected_instead_of_persisting_a_dangling_reference(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->storeGallery([
            'name' => 'Dangling group gallery',
            'type' => 'delivery',
            'gallery_group_id' => '00000000-0000-0000-0000-000000000000',
        ], $this->user(UserRole::SUPER_ADMIN, null));
    }

    /**
     * A guest is a transient, database-less identity, not a brand-less one: the
     * fixture deliberately carries the group's own brand, so the brand
     * comparison cannot be the rejecting check here — only the identity branch
     * can. That keeps the guest case a sharp regression test for the
     * `isTransientGuest()` half of the fail-closed identity guard.
     */
    public function test_transient_guest_cannot_attach_a_group(): void
    {
        $group = GalleryGroup::factory()->create(['brand' => Brand::B2B->value]);
        $guest = User::factory()->create(['brand' => Brand::B2B->value]);
        // `guest_id` is a runtime identity property, not a persisted column.
        $guest->guest_id = 'guest-'.Str::uuid();

        $authorization = app(AuthorizationService::class);
        $this->assertTrue($authorization->isTransientGuest($guest));
        $this->assertFalse($authorization->isReservedNullBrandActor($guest));
        $this->assertFalse($authorization->isTrustedCrossBrandActor($guest));

        try {
            $this->service->storeGallery([
                'name' => 'Guest gallery',
                'type' => 'delivery',
                'gallery_group_id' => $group->id,
            ], $guest);

            $this->fail('Expected a ValidationException for the transient guest.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('gallery_group_id', $exception->errors());
        }

        $this->assertDatabaseMissing('galleries', ['gallery_group_id' => $group->id]);
    }

    // ─── Reserved null-brand persisted actor ───────────────────────────

    /**
     * A persisted account with `brand === null` that is *not* a Super-Admin is
     * the reserved/invalid identity state, not a cross-brand one. It must fail
     * closed on the create path even when the group sits in the request host
     * brand — without this guard it would behave like a trusted cross-brand
     * actor purely because of its null brand.
     */
    public function test_reserved_null_brand_actor_cannot_attach_a_group(): void
    {
        $group = GalleryGroup::factory()->create(['brand' => Brand::B2B->value]);
        $actor = $this->user(UserRole::PHOTOGRAPHER, null);

        // Assert the classification this test relies on, so a later change to
        // `isReservedNullBrandActor()` cannot silently void the regression.
        $authorization = app(AuthorizationService::class);
        $this->assertFalse($authorization->isTransientGuest($actor));
        $this->assertTrue($authorization->isReservedNullBrandActor($actor));
        $this->assertFalse($authorization->isTrustedCrossBrandActor($actor));

        try {
            $this->service->storeGallery([
                'name' => 'Reserved actor gallery',
                'type' => 'delivery',
                'gallery_group_id' => $group->id,
            ], $actor);

            $this->fail('Expected a ValidationException for the reserved null-brand actor.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('gallery_group_id', $exception->errors());
        }

        $this->assertDatabaseMissing('galleries', ['gallery_group_id' => $group->id]);
    }

    // ─── Brand-bound Super-Admin: rejected in all three layers ────────

    /**
     * `UserRole::SUPER_ADMIN` alone does not grant cross-brand reach: only a
     * *null-brand* Super-Admin is a trusted cross-brand actor
     * (`AuthorizationService::isTrustedCrossBrandActor()`). A brand-bound
     * Super-Admin referencing a foreign group is therefore rejected by the
     * service with a 422 — no silent brand adoption, nothing persisted.
     */
    public function test_brand_bound_super_admin_cannot_reference_a_foreign_group_in_the_service(): void
    {
        $foreignGroup = GalleryGroup::factory()->create(['brand' => 'srp']);
        $actor = $this->user(UserRole::SUPER_ADMIN, Brand::B2B->value);

        $authorization = app(AuthorizationService::class);
        $this->assertTrue($authorization->isSuperAdmin($actor));
        $this->assertFalse($authorization->isTrustedCrossBrandActor($actor));

        try {
            $this->service->storeGallery([
                'name' => 'Brand-bound super admin gallery',
                'type' => 'delivery',
                'gallery_group_id' => $foreignGroup->id,
            ], $actor);

            $this->fail('Expected a ValidationException for the foreign-brand group.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('gallery_group_id', $exception->errors());
        }

        $this->assertDatabaseMissing('galleries', ['gallery_group_id' => $foreignGroup->id]);
    }

    /**
     * Layer 2 — `GalleryRequest::brandScopedExists()` rejects the foreign group
     * with a 422 before the service is reached. Nothing is persisted.
     *
     * The request host is `rp` (see setUp), i.e. the actor's own brand, so the
     * management middleware lets the request through and the brand-scoped
     * `exists` rule is the first guard that fires.
     */
    public function test_brand_bound_super_admin_foreign_group_is_rejected_by_the_brand_scoped_request_validation(): void
    {
        $foreignGroup = GalleryGroup::factory()->create(['brand' => 'srp']);
        $token = auth('api')->login($this->user(UserRole::SUPER_ADMIN, Brand::B2B->value));

        $response = $this->withHeaders($this->bearer($token))
            ->postJson('/api/management/galleries', [
                'name' => 'Brand-bound super admin gallery',
                'type' => 'delivery',
                'gallery_group_id' => $foreignGroup->id,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('gallery_group_id');
        $this->assertDatabaseMissing('galleries', ['gallery_group_id' => $foreignGroup->id]);
    }

    /**
     * Control for the test above: the rejection is brand-driven, not a blanket
     * ban on the `super_admin` role. The same brand-bound Super-Admin may
     * create a gallery in a group of its own brand.
     */
    public function test_brand_bound_super_admin_may_reference_a_group_of_its_own_brand(): void
    {
        $ownGroup = GalleryGroup::factory()->create(['brand' => Brand::B2B->value]);
        $token = auth('api')->login($this->user(UserRole::SUPER_ADMIN, Brand::B2B->value));

        $response = $this->withHeaders($this->bearer($token))
            ->postJson('/api/management/galleries', [
                'name' => 'Brand-bound super admin own group',
                'type' => 'delivery',
                'gallery_group_id' => $ownGroup->id,
            ]);

        $response->assertStatus(200);

        $gallery = Gallery::where('gallery_group_id', $ownGroup->id)->firstOrFail();
        $this->assertSame('rp', $this->brandId($gallery));
    }

    /**
     * Layer 1 — `ManagementMiddleware` answers 403 for a brand-bound Super-Admin
     * on a host that is not their brand, i.e. the literal "Super-Admin without
     * rights in the target brand" case. Nothing is persisted.
     *
     * Invoked directly instead of over HTTP: `BrandContextMiddleware` always
     * re-derives the brand from the request host, and with the single configured
     * brand (`rp`) the host can only ever be `rp`. A `brand = rp` Super-Admin
     * therefore never trips the host check through the HTTP kernel — the
     * request-layer test above covers that host instead. Driving the middleware
     * directly is what makes the 403 branch itself assertable.
     */
    public function test_brand_bound_super_admin_is_forbidden_by_the_management_middleware_on_a_foreign_host(): void
    {
        $foreignGroup = GalleryGroup::factory()->create(['brand' => 'srp']);
        $actor = $this->user(UserRole::SUPER_ADMIN, Brand::B2B->value);
        $middleware = app(ManagementMiddleware::class);
        $request = Request::create('/api/management/galleries', 'POST', [
            'name' => 'Brand-bound super admin gallery',
            'type' => 'delivery',
            'gallery_group_id' => $foreignGroup->id,
        ]);
        $next = fn () => response()->json(['reached' => true]);

        // Control: on the actor's own host the request passes the middleware
        // (it is rejected later, with a 422 — see the request-layer test).
        BrandRegistry::set(Brand::B2B);
        auth('api')->setUser($actor);
        $this->assertSame(200, $middleware->handle($request, $next)->getStatusCode());

        BrandRegistry::set(new BrandConfig(
            id: 'srp',
            name: 'SRP',
            theme: 'rp',
            portalName: 'SRP',
            impressumUrl: null,
            logoPath: null,
            hostnames: ['srp.localhost'],
        ));

        $response = $middleware->handle($request, $next);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(
            ['error' => 'Forbidden (Brand Isolation)'],
            json_decode((string) $response->getContent(), true),
        );
        $this->assertDatabaseMissing('galleries', ['gallery_group_id' => $foreignGroup->id]);
    }

    // ─── Cross-brand Super-Admin adopts the group brand ─────────────────

    public function test_cross_brand_super_admin_create_adopts_the_group_brand(): void
    {
        // Host brand is `rp` (see setUp) while the referenced group is `srp`.
        $foreignGroup = GalleryGroup::factory()->create(['brand' => 'srp']);

        $gallery = $this->service->storeGallery([
            'name' => 'Cross brand gallery',
            'type' => 'delivery',
            'gallery_group_id' => $foreignGroup->id,
        ], $this->user(UserRole::SUPER_ADMIN, null));

        $this->assertNotSame(BrandRegistry::currentOrDefault()->value, $this->brandId($gallery));
        $this->assertSame('srp', $this->brandId($gallery));
        $this->assertSame($this->brandId($foreignGroup), $this->brandId($gallery));
    }

    public function test_a_client_supplied_brand_field_is_never_trusted(): void
    {
        $foreignGroup = GalleryGroup::factory()->create(['brand' => 'srp']);

        $gallery = $this->service->storeGallery([
            'name' => 'Spoofed brand gallery',
            'type' => 'delivery',
            'gallery_group_id' => $foreignGroup->id,
            'brand' => Brand::B2B->value,
        ], $this->user(UserRole::SUPER_ADMIN, null));

        $this->assertSame('srp', $this->brandId($gallery));
        $this->assertDatabaseMissing('galleries', ['id' => $gallery->id, 'brand' => Brand::B2B->value]);
    }

    public function test_update_never_trusts_a_client_supplied_brand_field(): void
    {
        $gallery = Gallery::factory()->create(['brand' => 'srp']);

        $updated = $this->service->updateGallery(
            $gallery,
            ['name' => 'Spoofed brand update', 'brand' => Brand::B2B->value],
            $this->user(UserRole::PHOTOGRAPHER, 'srp')
        );

        $this->assertSame('Spoofed brand update', $updated->name);
        $this->assertSame('srp', $this->brandId($updated));
        $this->assertDatabaseHas('galleries', [
            'id' => $gallery->id,
            'brand' => 'srp',
            'name' => 'Spoofed brand update',
        ]);
    }

    // ─── Media/licensing references follow the resulting brand ──────────

    public function test_volume_preset_of_the_group_brand_is_accepted_for_a_cross_brand_create(): void
    {
        $foreignGroup = GalleryGroup::factory()->create(['brand' => 'srp']);
        $foreignPreset = VolumePreset::create([
            'brand' => 'srp',
            'name' => 'SRP preset',
            'is_default' => false,
        ]);

        $gallery = $this->service->storeGallery([
            'name' => 'Cross brand volume gallery',
            'type' => 'delivery',
            'gallery_group_id' => $foreignGroup->id,
            'volume_preset_id' => $foreignPreset->id,
        ], $this->user(UserRole::SUPER_ADMIN, null));

        $this->assertSame('srp', $this->brandId($gallery));
        $this->assertDatabaseHas('galleries', [
            'id' => $gallery->id,
            'brand' => 'srp',
            'volume_preset_id' => $foreignPreset->id,
        ]);
    }

    public function test_volume_preset_of_the_request_host_brand_is_rejected_for_a_cross_brand_create(): void
    {
        $foreignGroup = GalleryGroup::factory()->create(['brand' => 'srp']);
        $hostPreset = VolumePreset::create([
            'brand' => Brand::B2B->value,
            'name' => 'RP preset',
            'is_default' => false,
        ]);

        $this->expectException(ValidationException::class);

        try {
            $this->service->storeGallery([
                'name' => 'Wrong preset gallery',
                'type' => 'delivery',
                'gallery_group_id' => $foreignGroup->id,
                'volume_preset_id' => $hostPreset->id,
            ], $this->user(UserRole::SUPER_ADMIN, null));
        } finally {
            $this->assertDatabaseMissing('galleries', ['gallery_group_id' => $foreignGroup->id]);
        }
    }

    // ─── Re-parenting is the same invariant ────────────────────────────

    public function test_reparenting_into_a_foreign_group_adopts_the_group_brand(): void
    {
        $gallery = Gallery::factory()->create(['brand' => Brand::B2B->value]);
        $foreignGroup = GalleryGroup::factory()->create(['brand' => 'srp']);

        $updated = $this->service->updateGallery(
            $gallery,
            ['gallery_group_id' => $foreignGroup->id],
            $this->user(UserRole::SUPER_ADMIN, null)
        );

        $this->assertSame('srp', $this->brandId($updated));
        $this->assertSame($this->brandId($foreignGroup), $this->brandId($updated));
    }

    public function test_reparenting_into_a_foreign_group_is_rejected_for_a_brand_bound_actor(): void
    {
        $gallery = Gallery::factory()->create(['brand' => 'srp']);
        $foreignGroup = GalleryGroup::factory()->create(['brand' => Brand::B2B->value]);

        $this->expectException(ValidationException::class);

        try {
            $this->service->updateGallery(
                $gallery,
                ['gallery_group_id' => $foreignGroup->id],
                $this->user(UserRole::PHOTOGRAPHER, 'srp')
            );
        } finally {
            $this->assertDatabaseHas('galleries', [
                'id' => $gallery->id,
                'brand' => 'srp',
                'gallery_group_id' => null,
            ]);
        }
    }

    public function test_reparenting_across_brands_rejects_a_foreign_volume_preset(): void
    {
        $hostPreset = VolumePreset::create([
            'brand' => Brand::B2B->value,
            'name' => 'RP preset',
            'is_default' => false,
        ]);
        $gallery = Gallery::factory()->create([
            'brand' => Brand::B2B->value,
            'volume_preset_id' => $hostPreset->id,
        ]);
        $foreignGroup = GalleryGroup::factory()->create(['brand' => 'srp']);

        $this->expectException(ValidationException::class);

        try {
            $this->service->updateGallery(
                $gallery,
                ['gallery_group_id' => $foreignGroup->id],
                $this->user(UserRole::SUPER_ADMIN, null)
            );
        } finally {
            $this->assertDatabaseHas('galleries', [
                'id' => $gallery->id,
                'brand' => Brand::B2B->value,
                'gallery_group_id' => null,
            ]);
        }
    }

    public function test_reparenting_into_a_same_brand_group_keeps_the_gallery_brand(): void
    {
        $gallery = Gallery::factory()->create(['brand' => 'srp']);
        $ownGroup = GalleryGroup::factory()->create(['brand' => 'srp']);

        $updated = $this->service->updateGallery(
            $gallery,
            ['gallery_group_id' => $ownGroup->id],
            $this->user(UserRole::PHOTOGRAPHER, 'srp')
        );

        $this->assertSame('srp', $this->brandId($updated));
    }

    // ─── The update path enforces the same guards as the create path ──

    public function test_transient_guest_cannot_reparent_into_a_group(): void
    {
        $gallery = Gallery::factory()->create(['brand' => Brand::B2B->value]);
        $group = GalleryGroup::factory()->create(['brand' => Brand::B2B->value]);
        $guest = User::factory()->create(['brand' => Brand::B2B->value]);
        $guest->guest_id = 'guest-'.Str::uuid();

        $this->assertTrue(app(AuthorizationService::class)->isTransientGuest($guest));

        $this->expectException(ValidationException::class);

        try {
            $this->service->updateGallery(
                $gallery,
                ['gallery_group_id' => $group->id],
                $guest
            );
        } finally {
            $this->assertUnchangedRootGallery($gallery);
        }
    }

    public function test_reserved_null_brand_actor_cannot_reparent_into_a_group(): void
    {
        $gallery = Gallery::factory()->create(['brand' => Brand::B2B->value]);
        $group = GalleryGroup::factory()->create(['brand' => Brand::B2B->value]);

        $this->expectException(ValidationException::class);

        try {
            $this->service->updateGallery(
                $gallery,
                ['gallery_group_id' => $group->id],
                $this->user(UserRole::PHOTOGRAPHER, null)
            );
        } finally {
            $this->assertUnchangedRootGallery($gallery);
        }
    }

    public function test_reparenting_into_a_group_with_a_mixed_brand_parent_chain_is_rejected(): void
    {
        $gallery = Gallery::factory()->create(['brand' => 'srp']);
        $foreignParent = GalleryGroup::factory()->create(['brand' => 'rp']);
        $childGroup = GalleryGroup::factory()->create([
            'brand' => 'srp',
            'parent_id' => $foreignParent->id,
        ]);

        $this->expectException(ValidationException::class);

        try {
            $this->service->updateGallery(
                $gallery,
                ['gallery_group_id' => $childGroup->id],
                $this->user(UserRole::SUPER_ADMIN, null)
            );
        } finally {
            $this->assertUnchangedRootGallery($gallery);
        }
    }

    public function test_reparenting_into_an_unknown_group_is_rejected(): void
    {
        $gallery = Gallery::factory()->create(['brand' => 'srp']);

        $this->expectException(ValidationException::class);

        try {
            $this->service->updateGallery(
                $gallery,
                ['gallery_group_id' => '00000000-0000-0000-0000-000000000000'],
                $this->user(UserRole::SUPER_ADMIN, null)
            );
        } finally {
            $this->assertUnchangedRootGallery($gallery);
        }
    }

    /**
     * F1 regression: `updateGallery()` used to accept `?User $user = null`, so a
     * caller that forgot the actor silently skipped every identity and brand
     * check. Proven consequence: re-parenting an `srp` gallery into an `rp`
     * group *adopted* `rp`, while the very same call with an `srp` actor was
     * rejected. Omitting the actor is now a hard error, and an explicit `null`
     * no longer type-checks.
     */
    public function test_update_gallery_cannot_be_called_without_an_actor(): void
    {
        $gallery = Gallery::factory()->create(['brand' => 'srp']);
        $rpGroup = GalleryGroup::factory()->create(['brand' => Brand::B2B->value]);
        $payload = ['gallery_group_id' => $rpGroup->id];

        try {
            $this->service->updateGallery($gallery, $payload);
            $this->fail('Omitting the actor must not be possible.');
        } catch (ArgumentCountError) {
            // Expected: the actor is a required argument.
        }

        $this->assertUnchangedRootGallery($gallery);

        try {
            $this->service->updateGallery($gallery, $payload, null);
            $this->fail('A null actor must not be possible.');
        } catch (TypeError) {
            // Expected: the actor is non-nullable.
        }

        $this->assertUnchangedRootGallery($gallery);

        // The very same call with a real actor is still a 422, not an adoption.
        $this->expectException(ValidationException::class);

        try {
            $this->service->updateGallery($gallery, $payload, $this->user(UserRole::PHOTOGRAPHER, 'srp'));
        } finally {
            $this->assertUnchangedRootGallery($gallery);
        }
    }

    // ─── HTTP-level re-parenting through GalleryController ────────────

    /**
     * The controller hands the authenticated actor to the service; without it
     * the re-parent would have gone through the F1 fail-open path. A cross-brand
     * Super-Admin keeps the adopted variant: HTTP 200, the gallery carries the
     * group's brand, and it moves brand-scoped listing exactly once.
     *
     * `brand` is not part of `GalleryResource` (it is a management-internal
     * field, and the same resource is serialized on public gallery/search
     * endpoints), so the adopted brand is asserted on the persisted row and
     * through the brand-scoped management tree.
     *
     * `clearCache()` is invoked explicitly because the model hook registers its
     * invalidation via `DB::afterCommit()`, and `RefreshDatabase` rolls the
     * outer transaction back instead of committing it.
     */
    public function test_cross_brand_super_admin_reparenting_over_http_adopts_the_group_brand(): void
    {
        $gallery = Gallery::factory()->create([
            'brand' => Brand::B2B->value,
            'gallery_group_id' => null,
        ]);
        $foreignGroup = GalleryGroup::factory()->create(['brand' => 'srp']);
        $token = auth('api')->login($this->user(UserRole::SUPER_ADMIN, null));

        $response = $this->withHeaders($this->bearer($token))
            ->putJson("/api/management/galleries/{$gallery->id}", [
                'gallery_group_id' => $foreignGroup->id,
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('gallery.id', $gallery->id);
        $response->assertJsonPath('gallery.gallery_group_id', $foreignGroup->id);

        $gallery->refresh();
        $this->assertSame('srp', $this->brandId($gallery));
        $this->assertSame($this->brandId($foreignGroup), $this->brandId($gallery));
        $this->assertDatabaseHas('galleries', [
            'id' => $gallery->id,
            'brand' => 'srp',
            'gallery_group_id' => $foreignGroup->id,
        ]);

        $treeService = app(GalleryTreeService::class);
        $rpAdmin = $this->user(UserRole::ADMIN, Brand::B2B->value);
        $srpAdmin = $this->user(UserRole::ADMIN, 'srp');
        $crossBrandAdmin = $this->user(UserRole::SUPER_ADMIN, null);

        $treeService->clearCache();

        $rpTreeGalleryIds = $this->galleryIdsInTree($treeService->getAdminTree($rpAdmin));
        $srpTreeGalleryIds = $this->galleryIdsInTree($treeService->getAdminTree($srpAdmin));
        $crossTreeGalleryIds = $this->galleryIdsInTree($treeService->getAdminTree($crossBrandAdmin));

        // Moved brand: gone from the old listing, present exactly once in the new.
        $this->assertNotContains($gallery->id, $rpTreeGalleryIds);
        $this->assertSame(1, array_count_values($srpTreeGalleryIds)[$gallery->id] ?? 0);
        $this->assertSame(1, array_count_values($crossTreeGalleryIds)[$gallery->id] ?? 0);
    }

    // ─── Brand-scoped listing / cache leak ─────────────────────────────

    /**
     * The management tree is cached per brand (`gallery_tree_admin_<brand>`)
     * plus one global key for the cross-brand Super-Admin. The gallery created
     * above belongs to the `srp` group, so it must be listed under `srp` and
     * under the cross-brand tree — and must NOT appear in the `rp` tree.
     *
     * `clearCache()` is invoked explicitly because the model hook registers its
     * invalidation via `DB::afterCommit()`, and `RefreshDatabase` rolls the
     * outer transaction back instead of committing it.
     */
    public function test_cross_brand_created_gallery_is_listed_only_under_its_resulting_brand(): void
    {
        $rpGroup = GalleryGroup::factory()->create(['brand' => Brand::B2B->value]);
        $srpGroup = GalleryGroup::factory()->create(['brand' => 'srp']);

        $rpGallery = $this->service->storeGallery([
            'name' => 'RP gallery',
            'type' => 'delivery',
            'gallery_group_id' => $rpGroup->id,
        ], $this->user(UserRole::SUPER_ADMIN, null));

        $srpGallery = $this->service->storeGallery([
            'name' => 'SRP gallery',
            'type' => 'delivery',
            'gallery_group_id' => $srpGroup->id,
        ], $this->user(UserRole::SUPER_ADMIN, null));

        $this->assertSame('rp', $this->brandId($rpGallery));
        $this->assertSame('srp', $this->brandId($srpGallery));

        // Prime both brand-scoped caches *before* the invalidation so the
        // assertions below also cover a stale entry being dropped.
        $treeService = app(GalleryTreeService::class);
        $rpAdmin = $this->user(UserRole::ADMIN, Brand::B2B->value);
        $srpAdmin = $this->user(UserRole::ADMIN, 'srp');
        $crossBrandAdmin = $this->user(UserRole::SUPER_ADMIN, null);

        $treeService->getAdminTree($rpAdmin);
        $treeService->getAdminTree($srpAdmin);
        $treeService->getAdminTree($crossBrandAdmin);

        $treeService->clearCache();

        $rpTreeGalleryIds = $this->galleryIdsInTree($treeService->getAdminTree($rpAdmin));
        $srpTreeGalleryIds = $this->galleryIdsInTree($treeService->getAdminTree($srpAdmin));
        $crossTreeGalleryIds = $this->galleryIdsInTree($treeService->getAdminTree($crossBrandAdmin));

        $this->assertContains($rpGallery->id, $rpTreeGalleryIds);
        $this->assertNotContains($srpGallery->id, $rpTreeGalleryIds);

        $this->assertContains($srpGallery->id, $srpTreeGalleryIds);
        $this->assertNotContains($rpGallery->id, $srpTreeGalleryIds);

        // Exactly once in the cross-brand tree — no duplicate/second entry.
        $this->assertContains($rpGallery->id, $crossTreeGalleryIds);
        $this->assertContains($srpGallery->id, $crossTreeGalleryIds);
        $this->assertSame(1, array_count_values($crossTreeGalleryIds)[$srpGallery->id] ?? 0);
        $this->assertSame(1, array_count_values($crossTreeGalleryIds)[$rpGallery->id] ?? 0);
    }

    /**
     * Flat list of every gallery id contained in a serialized admin tree,
     * including nested groups, so duplicates stay observable.
     *
     * @return array<int, string>
     */
    private function galleryIdsInTree(array $tree): array
    {
        $ids = [];

        foreach ($tree['root_galleries'] ?? [] as $gallery) {
            $ids[] = (string) $gallery['id'];
        }

        $collect = function (array $groups) use (&$collect, &$ids): void {
            foreach ($groups as $group) {
                foreach ($group['galleries'] ?? [] as $gallery) {
                    $ids[] = (string) $gallery['id'];
                }
                $collect($group['children'] ?? []);
            }
        };

        $collect($tree['groups'] ?? []);

        return $ids;
    }

    // ─── helpers ───────────────────────────────────────────────────────

    private function user(UserRole $role, ?string $brand): User
    {
        $user = User::factory()->create(['brand' => $brand]);
        $user->roles()->attach(Role::firstOrCreate(['name' => $role->value]));

        return $user;
    }

    /**
     * @return array<string, string>
     */
    private function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }

    /**
     * Assert that a rejected re-parent left the row completely untouched: still
     * at the root level and still carrying its original brand.
     */
    private function assertUnchangedRootGallery(Gallery $gallery): void
    {
        $this->assertDatabaseHas('galleries', [
            'id' => $gallery->id,
            'brand' => BrandRegistry::normalizeId($gallery->brand),
            'gallery_group_id' => null,
        ]);
    }

    /**
     * The `AsBrand` cast returns a `Brand` enum for configured ids and the raw
     * string for a brand id outside the enum (e.g. the legacy `srp` fixture).
     */
    private function brandId(Gallery|GalleryGroup $model): ?string
    {
        return BrandRegistry::normalizeId($model->brand);
    }
}
