<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Gallery;
use App\Models\GalleryGroup;
use App\Models\InvoiceSnapshot;
use App\Models\Order;
use App\Models\Role;
use App\Models\Org;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class UserPermissionLogicTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    // Hilfsfunktion: Rolle zuweisen
    private function assignRole(User $user, UserRole $role): Role
    {
        $roleModel = Role::firstOrCreate(['name' => $role->value]);
        $user->roles()->syncWithoutDetaching([$roleModel->id]);
        return $roleModel;
    }

    private function makeGuest(array $transientGalleries = []): User
    {
        $user = User::factory()->create();
        $user->guest_id = 'guest-' . $user->id;
        $user->transient_galleries = $transientGalleries;
        return $user;
    }

    // =====================================================================
    // 1. getAllowedGalleryIds() — Gast-Frühreturn
    // =====================================================================

    public function test_get_allowed_gallery_ids_guest_returns_transient_galleries(): void
    {
        $galleryA = Gallery::factory()->create();
        $galleryB = Gallery::factory()->create();
        $user = $this->makeGuest([$galleryA->id, $galleryB->id]);

        $result = $user->getAllowedGalleryIds();

        $this->assertEqualsCanonicalizing([$galleryA->id, $galleryB->id], $result);
    }

    public function test_get_allowed_gallery_ids_guest_with_empty_transient_returns_empty_array(): void
    {
        $user = $this->makeGuest([]);

        $result = $user->getAllowedGalleryIds();

        $this->assertSame([], $result);
    }

    public function test_get_allowed_gallery_ids_guest_with_null_transient_returns_empty_array(): void
    {
        $user = $this->makeGuest();
        $user->transient_galleries = null;

        $result = $user->getAllowedGalleryIds();

        $this->assertSame([], $result);
    }

    public function test_get_allowed_gallery_ids_guest_ignores_direct_assignments(): void
    {
        // Gast-Frühreturn darf KEINE direkten Zuweisungen einbeziehen
        $gallery = Gallery::factory()->create();
        $user = $this->makeGuest([]);
        $user->galleries()->attach($gallery->id);

        $result = $user->getAllowedGalleryIds();

        $this->assertSame([], $result);
    }

    // =====================================================================
    // 1b. getAllowedGalleryIds() — direkte Galerien
    // =====================================================================

    public function test_get_allowed_gallery_ids_user_without_any_rights_returns_empty(): void
    {
        $user = User::factory()->create();

        $result = $user->getAllowedGalleryIds();

        $this->assertSame([], $result);
    }

    public function test_get_allowed_gallery_ids_includes_direct_galleries(): void
    {
        $user = User::factory()->create();
        $gallery = Gallery::factory()->create();
        $user->galleries()->attach($gallery->id);

        $result = $user->getAllowedGalleryIds();

        $this->assertContains($gallery->id, $result);
        $this->assertCount(1, $result);
    }

    // =====================================================================
    // 1c. getAllowedGalleryIds() — Gruppen (rekursiv via getSubGroupIds)
    // =====================================================================

    public function test_get_allowed_gallery_ids_includes_galleries_from_direct_group(): void
    {
        $user = User::factory()->create();
        $group = GalleryGroup::factory()->create();
        $gallery = Gallery::factory()->create(['gallery_group_id' => $group->id]);
        $user->galleryGroups()->attach($group->id);

        $result = $user->getAllowedGalleryIds();

        $this->assertContains($gallery->id, $result);
    }

    public function test_get_allowed_gallery_ids_includes_galleries_from_nested_subgroup(): void
    {
        $user = User::factory()->create();
        $parent = GalleryGroup::factory()->create();
        $child = GalleryGroup::factory()->create(['parent_id' => $parent->id]);
        $gallery = Gallery::factory()->create(['gallery_group_id' => $child->id]);
        // User ist nur auf Parent zugewiesen — Child muss rekursiv aufgelöst werden
        $user->galleryGroups()->attach($parent->id);

        $result = $user->getAllowedGalleryIds();

        $this->assertContains($gallery->id, $result);
    }

    public function test_get_allowed_gallery_ids_includes_galleries_from_deeply_nested_subgroup(): void
    {
        $user = User::factory()->create();
        $level1 = GalleryGroup::factory()->create();
        $level2 = GalleryGroup::factory()->create(['parent_id' => $level1->id]);
        $level3 = GalleryGroup::factory()->create(['parent_id' => $level2->id]);
        $gallery = Gallery::factory()->create(['gallery_group_id' => $level3->id]);
        $user->galleryGroups()->attach($level1->id);

        $result = $user->getAllowedGalleryIds();

        $this->assertContains($gallery->id, $result);
    }

    // =====================================================================
    // 1d. getAllowedGalleryIds() — Org-Zuweisungen (nur type=delivery)
    // =====================================================================

    public function test_get_allowed_gallery_ids_org_includes_only_delivery_galleries(): void
    {
        $user = User::factory()->create();
        $org = Org::factory()->create();
        $group = GalleryGroup::factory()->create();
        $org->galleryGroups()->attach($group->id);
        $user->org_id = $org->id;
        $user->save();

        $deliveryGallery = Gallery::factory()->create([
            'gallery_group_id' => $group->id,
            'type' => 'delivery',
        ]);
        $selectionGallery = Gallery::factory()->create([
            'gallery_group_id' => $group->id,
            'type' => 'selection',
        ]);

        $result = $user->getAllowedGalleryIds();

        $this->assertContains($deliveryGallery->id, $result);
        $this->assertNotContains($selectionGallery->id, $result);
    }

    public function test_get_allowed_gallery_ids_org_excludes_non_delivery_galleries_completely(): void
    {
        $user = User::factory()->create();
        $org = Org::factory()->create();
        $group = GalleryGroup::factory()->create();
        $org->galleryGroups()->attach($group->id);
        $user->org_id = $org->id;
        $user->save();

        // Nur selection-Galerie — darf NICHT auftauchen
        $selectionGallery = Gallery::factory()->create([
            'gallery_group_id' => $group->id,
            'type' => 'selection',
        ]);

        $result = $user->getAllowedGalleryIds();

        $this->assertNotContains($selectionGallery->id, $result);
    }

    // =====================================================================
    // 1e. getAllowedGalleryIds() — transient_galleries-Merge + Dedup
    // =====================================================================

    public function test_get_allowed_gallery_ids_merges_transient_galleries(): void
    {
        $user = User::factory()->create();
        $direct = Gallery::factory()->create();
        $user->galleries()->attach($direct->id);
        $transient = Gallery::factory()->create();
        $user->transient_galleries = [$transient->id];

        $result = $user->getAllowedGalleryIds();

        $this->assertContains($direct->id, $result);
        $this->assertContains($transient->id, $result);
        $this->assertCount(2, $result);
    }

    public function test_get_allowed_gallery_ids_deduplicates_overlapping_sources(): void
    {
        $user = User::factory()->create();
        $gallery = Gallery::factory()->create();
        // Dieselbe Galerie über zwei Quellen
        $user->galleries()->attach($gallery->id);
        $user->transient_galleries = [$gallery->id];

        $result = $user->getAllowedGalleryIds();

        $this->assertSame([$gallery->id], $result);
    }

    // =====================================================================
    // 1f. getAllowedGalleryIds() — Fotograf-Pfad
    // =====================================================================

    public function test_get_allowed_gallery_ids_photographer_includes_unrestricted_galleries(): void
    {
        $user = User::factory()->create();
        $this->assignRole($user, UserRole::PHOTOGRAPHER);

        // unrestricted = effective_restricted_photographers === false (default)
        $unrestrictedGallery = Gallery::factory()->create();

        $result = $user->getAllowedGalleryIds();

        $this->assertContains($unrestrictedGallery->id, $result);
    }

    public function test_get_allowed_gallery_ids_photographer_includes_direct_photographer_galleries(): void
    {
        $user = User::factory()->create();
        $this->assignRole($user, UserRole::PHOTOGRAPHER);
        // restricted Gallery — nur über photographerGalleries erreichbar
        $restrictedGallery = Gallery::factory()->create(['restricted_photographers' => true]);
        $user->photographerGalleries()->attach($restrictedGallery->id);

        $result = $user->getAllowedGalleryIds();

        $this->assertContains($restrictedGallery->id, $result);
    }

    public function test_get_allowed_gallery_ids_photographer_includes_photographer_group_galleries_recursively(): void
    {
        $user = User::factory()->create();
        $this->assignRole($user, UserRole::PHOTOGRAPHER);
        $parent = GalleryGroup::factory()->create(['restricted_photographers' => true]);
        $child = GalleryGroup::factory()->create(['parent_id' => $parent->id]);
        $gallery = Gallery::factory()->create([
            'gallery_group_id' => $child->id,
            'restricted_photographers' => true,
        ]);
        $user->photographerGalleryGroups()->attach($parent->id);

        $result = $user->getAllowedGalleryIds();

        $this->assertContains($gallery->id, $result);
    }

    public function test_get_allowed_gallery_ids_photographer_populates_global_unrestricted_cache(): void
    {
        // REVIEW: Der Cache 'unrestricted_photographer_gallery_ids' ist PROZESSGLOBAL
        // (rememberForever ohne User-Bezug) — einmal bevöllt gilt er für alle
        // Photographer-Calls bis zum Flush. Hier testen wir robust die Bevöllung.
        $user = User::factory()->create();
        $this->assignRole($user, UserRole::PHOTOGRAPHER);
        $gallery = Gallery::factory()->create();

        $this->assertNull(Cache::get('unrestricted_photographer_gallery_ids'));

        $result = $user->getAllowedGalleryIds();

        $this->assertContains($gallery->id, $result);
        $cached = Cache::get('unrestricted_photographer_gallery_ids');
        $this->assertNotNull($cached);
        $this->assertContains($gallery->id, $cached);
    }

    public function test_get_allowed_gallery_ids_photographer_cache_miss_after_flush(): void
    {
        $user = User::factory()->create();
        $this->assignRole($user, UserRole::PHOTOGRAPHER);
        $firstGallery = Gallery::factory()->create();
        $user->getAllowedGalleryIds();

        $newGallery = Gallery::factory()->create();
        Cache::forget('unrestricted_photographer_gallery_ids');

        $result = $user->getAllowedGalleryIds();

        $this->assertContains($newGallery->id, $result);
    }

    // =====================================================================
    // 2. getSubGroupIds() — direkt (via Reflection, private Methode)
    //    Die Methode lebt jetzt im AuthorizationService.
    // =====================================================================

    public function test_get_sub_group_ids_empty_input_returns_empty_array(): void
    {
        $service = new \App\Services\AuthorizationService();
        $method = new \ReflectionMethod($service, 'getSubGroupIds');
        $method->setAccessible(true);

        $this->assertSame([], $method->invoke($service, []));
    }

    public function test_get_sub_group_ids_single_id_returns_self(): void
    {
        $service = new \App\Services\AuthorizationService();
        $group = GalleryGroup::factory()->create();
        $method = new \ReflectionMethod($service, 'getSubGroupIds');
        $method->setAccessible(true);

        $result = $method->invoke($service, [$group->id]);

        $this->assertSame([$group->id], $result);
    }

    public function test_get_sub_group_ids_with_nonexistent_id_returns_empty(): void
    {
        $service = new \App\Services\AuthorizationService();
        $method = new \ReflectionMethod($service, 'getSubGroupIds');
        $method->setAccessible(true);

        $result = $method->invoke($service, ['nonexistent-uuid']);

        $this->assertSame([], $result);
    }

    public function test_get_sub_group_ids_recursive_deep_chain(): void
    {
        $service = new \App\Services\AuthorizationService();
        $g1 = GalleryGroup::factory()->create();
        $g2 = GalleryGroup::factory()->create(['parent_id' => $g1->id]);
        $g3 = GalleryGroup::factory()->create(['parent_id' => $g2->id]);
        $g4 = GalleryGroup::factory()->create(['parent_id' => $g3->id]);
        $method = new \ReflectionMethod($service, 'getSubGroupIds');
        $method->setAccessible(true);

        $result = $method->invoke($service, [$g1->id]);

        $this->assertEqualsCanonicalizing([$g1->id, $g2->id, $g3->id, $g4->id], $result);
    }

    /**
     * R-02 resolved: getSubGroupIds nutzt jetzt gebundene Parameter (?-Platzhalter) statt
     * String-Concatenation. Dieser Test friert das Verhalten für viele Parent-IDs ein
     * (Base-Case + Dedup bei Überschneidungen) und deckt gleichzeitig den Bound-Parameter-Pfad.
     */
    public function test_get_sub_group_ids_handles_large_uuid_list(): void
    {
        $service = new \App\Services\AuthorizationService();
        // 50 separate Gruppen testen.
        $root1 = GalleryGroup::factory()->create();
        $root2 = GalleryGroup::factory()->create();
        $allIds = [$root1->id, $root2->id];
        for ($i = 0; $i < 48; $i++) {
            $allIds[] = GalleryGroup::factory()->create()->id;
        }

        $method = new \ReflectionMethod($service, 'getSubGroupIds');
        $method->setAccessible(true);

        $result = $method->invoke($service, $allIds);

        // Alle eingegebenen IDs müssen enthalten sein, Dedup sichergestellt
        foreach ($allIds as $id) {
            $this->assertContains($id, $result);
        }
        $this->assertSame(count($allIds), count($result));
    }

    /**
     * R-02 regression: Parent-IDs mit SQL-Sonderzeichen (einfaches Quote) müssen als reine
     * Werte behandelt werden (gebundene Parameter), nicht in den SQL-String interpoliert werden.
     * Mit der alten String-Concatenation hätte ein Wert wie `x'--` die IN-Liste kaputtgemacht;
     * mit ?-Platzhaltern wird er sauber gesucht (kein Match → leere Menge, kein SQL-Fehler).
     */
    public function test_get_sub_group_ids_with_quote_in_id_uses_bound_parameters(): void
    {
        $service = new \App\Services\AuthorizationService();
        $method = new \ReflectionMethod($service, 'getSubGroupIds');
        $method->setAccessible(true);

        $result = $method->invoke($service, ["x'-- OR 1=1 --"]);

        $this->assertSame([], $result);
    }

    /**
     * R-02 regression: Der Empty-Early-Return greift auch nach array_filter (falsy aber non-null),
     * sodass kein `WHERE id IN ()` erzeugt wird.
     */
    public function test_get_sub_group_ids_empty_after_filter_returns_empty_array(): void
    {
        $service = new \App\Services\AuthorizationService();
        $method = new \ReflectionMethod($service, 'getSubGroupIds');
        $method->setAccessible(true);

        $result = $method->invoke($service, array_values(array_filter([], fn($id) => $id !== null)));

        $this->assertSame([], $result);
    }

    // =====================================================================
    // 3. canAccessGallery()
    // =====================================================================

    public function test_can_access_gallery_super_admin_returns_true_for_any_gallery(): void
    {
        $user = User::factory()->create();
        $this->assignRole($user, UserRole::SUPER_ADMIN);
        $gallery = Gallery::factory()->create();

        $this->assertTrue($user->canAccessGallery($gallery->id));
    }

    public function test_can_access_gallery_super_admin_returns_true_for_nonexistent_gallery(): void
    {
        $user = User::factory()->create();
        $this->assignRole($user, UserRole::SUPER_ADMIN);

        $this->assertTrue($user->canAccessGallery('nonexistent-gallery-id'));
    }

    public function test_can_access_gallery_normal_admin_without_rights_returns_false(): void
    {
        // WICHTIG: Normale Admins haben keinen GOD MODE mehr
        $user = User::factory()->create();
        $this->assignRole($user, UserRole::ADMIN);
        $gallery = Gallery::factory()->create();

        $this->assertFalse($user->canAccessGallery($gallery->id));
    }

    public function test_can_access_gallery_normal_admin_with_direct_right_returns_true(): void
    {
        $user = User::factory()->create();
        $this->assignRole($user, UserRole::ADMIN);
        $gallery = Gallery::factory()->create();
        $user->galleries()->attach($gallery->id);

        $this->assertTrue($user->canAccessGallery($gallery->id));
    }

    public function test_can_access_gallery_user_without_rights_returns_false(): void
    {
        $user = User::factory()->create();
        $gallery = Gallery::factory()->create();

        $this->assertFalse($user->canAccessGallery($gallery->id));
    }

    public function test_can_access_gallery_user_with_direct_right_returns_true(): void
    {
        $user = User::factory()->create();
        $gallery = Gallery::factory()->create();
        $user->galleries()->attach($gallery->id);

        $this->assertTrue($user->canAccessGallery($gallery->id));
    }

    public function test_can_access_gallery_nonexistent_id_for_normal_user_returns_false(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($user->canAccessGallery('does-not-exist'));
    }

    public function test_can_access_gallery_photographer_with_unrestricted_gallery_returns_true(): void
    {
        $user = User::factory()->create();
        $this->assignRole($user, UserRole::PHOTOGRAPHER);
        $gallery = Gallery::factory()->create(); // unrestricted per default

        $this->assertTrue($user->canAccessGallery($gallery->id));
    }

    public function test_can_access_gallery_photographer_restricted_without_right_returns_false(): void
    {
        $user = User::factory()->create();
        $this->assignRole($user, UserRole::PHOTOGRAPHER);
        $gallery = Gallery::factory()->create(['restricted_photographers' => true]);

        $this->assertFalse($user->canAccessGallery($gallery->id));
    }

    // =====================================================================
    // 4. canPhotographerAccessGallery()
    // =====================================================================

    public function test_can_photographer_access_gallery_super_admin_returns_true(): void
    {
        $user = User::factory()->create();
        $this->assignRole($user, UserRole::SUPER_ADMIN);
        $gallery = Gallery::factory()->create(['restricted_photographers' => true]);

        $this->assertTrue($user->canPhotographerAccessGallery($gallery->id));
    }

    public function test_can_photographer_access_gallery_non_photographer_returns_false(): void
    {
        $user = User::factory()->create();
        $gallery = Gallery::factory()->create();

        $this->assertFalse($user->canPhotographerAccessGallery($gallery->id));
    }

    public function test_can_photographer_access_gallery_nonexistent_returns_false(): void
    {
        $user = User::factory()->create();
        $this->assignRole($user, UserRole::PHOTOGRAPHER);

        $this->assertFalse($user->canPhotographerAccessGallery('nonexistent-id'));
    }

    public function test_can_photographer_access_gallery_unrestricted_returns_true(): void
    {
        $user = User::factory()->create();
        $this->assignRole($user, UserRole::PHOTOGRAPHER);
        $gallery = Gallery::factory()->create(['restricted_photographers' => false]);

        $this->assertTrue($user->canPhotographerAccessGallery($gallery->id));
    }

    public function test_can_photographer_access_gallery_restricted_with_direct_assignment_returns_true(): void
    {
        $user = User::factory()->create();
        $this->assignRole($user, UserRole::PHOTOGRAPHER);
        $gallery = Gallery::factory()->create(['restricted_photographers' => true]);
        $user->photographerGalleries()->attach($gallery->id);

        $this->assertTrue($user->canPhotographerAccessGallery($gallery->id));
    }

    public function test_can_photographer_access_gallery_restricted_without_assignment_returns_false(): void
    {
        $user = User::factory()->create();
        $this->assignRole($user, UserRole::PHOTOGRAPHER);
        $gallery = Gallery::factory()->create(['restricted_photographers' => true]);

        $this->assertFalse($user->canPhotographerAccessGallery($gallery->id));
    }

    public function test_can_photographer_access_gallery_restricted_via_group_returns_true(): void
    {
        $user = User::factory()->create();
        $this->assignRole($user, UserRole::PHOTOGRAPHER);
        $group = GalleryGroup::factory()->create(['restricted_photographers' => true]);
        $gallery = Gallery::factory()->create([
            'gallery_group_id' => $group->id,
            'restricted_photographers' => true,
        ]);
        $user->photographerGalleryGroups()->attach($group->id);

        $this->assertTrue($user->canPhotographerAccessGallery($gallery->id));
    }

    public function test_can_photographer_access_gallery_restricted_via_nested_group_returns_true(): void
    {
        $user = User::factory()->create();
        $this->assignRole($user, UserRole::PHOTOGRAPHER);
        $parent = GalleryGroup::factory()->create(['restricted_photographers' => true]);
        $child = GalleryGroup::factory()->create(['parent_id' => $parent->id]);
        $gallery = Gallery::factory()->create([
            'gallery_group_id' => $child->id,
            'restricted_photographers' => true,
        ]);
        $user->photographerGalleryGroups()->attach($parent->id);

        $this->assertTrue($user->canPhotographerAccessGallery($gallery->id));
    }

    public function test_can_photographer_access_gallery_inherits_restricted_from_group(): void
    {
        // Gallery selbst: restricted_photographers=null → erbt vom Group (true)
        $user = User::factory()->create();
        $this->assignRole($user, UserRole::PHOTOGRAPHER);
        $group = GalleryGroup::factory()->create(['restricted_photographers' => true]);
        $gallery = Gallery::factory()->create([
            'gallery_group_id' => $group->id,
            'restricted_photographers' => null,
        ]);
        // kein Recht zugewiesen → muss false sein, weil effective restricted

        $this->assertFalse($user->canPhotographerAccessGallery($gallery->id));
    }

    // =====================================================================
    // 5. hasPurchasedPhoto()
    // =====================================================================

    private function createOrderWithItem(User $user, string $photoId, string $tier, string $status = 'paid', bool $isQuote = false): Order
    {
        $order = Order::create([
            'user_id' => $user->id,
            'status' => $status,
            'is_quote_request' => $isQuote,
            'total_amount' => 1000,
        ]);
        InvoiceSnapshot::create([
            'order_id' => $order->id,
            'customer_details' => [
                'items' => [
                    ['photoId' => $photoId, 'tier' => $tier, 'price' => 1000],
                ],
            ],
            'total_net' => 1000,
            'total_gross' => 1200,
            'tax_rate' => 20.00,
        ]);
        return $order;
    }

    public function test_has_purchased_photo_paid_original_returns_true_for_web_request(): void
    {
        $user = User::factory()->create();
        $photoId = 'photo-uuid-1';
        $this->createOrderWithItem($user, $photoId, 'original', 'paid');

        $this->assertTrue($user->hasPurchasedPhoto($photoId, 'web'));
    }

    public function test_has_purchased_photo_web_tier_satisfies_web_request(): void
    {
        $user = User::factory()->create();
        $photoId = 'photo-uuid-2';
        $this->createOrderWithItem($user, $photoId, 'web', 'paid');

        $this->assertTrue($user->hasPurchasedPhoto($photoId, 'web'));
    }

    public function test_has_purchased_photo_web_tier_does_not_satisfy_original_request(): void
    {
        $user = User::factory()->create();
        $photoId = 'photo-uuid-3';
        $this->createOrderWithItem($user, $photoId, 'web', 'paid');

        $this->assertFalse($user->hasPurchasedPhoto($photoId, 'original'));
    }

    public function test_has_purchased_photo_print_tier_does_not_satisfy_original_request(): void
    {
        $user = User::factory()->create();
        $photoId = 'photo-uuid-4';
        $this->createOrderWithItem($user, $photoId, 'print', 'paid');

        $this->assertFalse($user->hasPurchasedPhoto($photoId, 'original'));
    }

    public function test_has_purchased_photo_print_tier_satisfies_print_request(): void
    {
        $user = User::factory()->create();
        $photoId = 'photo-uuid-5';
        $this->createOrderWithItem($user, $photoId, 'print', 'paid');

        $this->assertTrue($user->hasPurchasedPhoto($photoId, 'print'));
    }

    public function test_has_purchased_photo_original_tier_satisfies_original_request(): void
    {
        $user = User::factory()->create();
        $photoId = 'photo-uuid-6';
        $this->createOrderWithItem($user, $photoId, 'original', 'paid');

        $this->assertTrue($user->hasPurchasedPhoto($photoId, 'original'));
    }

    public function test_has_purchased_photo_disputed_order_is_excluded(): void
    {
        $user = User::factory()->create();
        $photoId = 'photo-uuid-disputed';
        $this->createOrderWithItem($user, $photoId, 'original', 'disputed');

        $this->assertFalse($user->hasPurchasedPhoto($photoId, 'web'));
    }

    public function test_has_purchased_photo_refunded_order_is_excluded(): void
    {
        $user = User::factory()->create();
        $photoId = 'photo-uuid-refunded';
        $this->createOrderWithItem($user, $photoId, 'original', 'refunded');

        $this->assertFalse($user->hasPurchasedPhoto($photoId, 'web'));
    }

    public function test_has_purchased_photo_cancelled_order_is_excluded(): void
    {
        $user = User::factory()->create();
        $photoId = 'photo-uuid-cancelled';
        $this->createOrderWithItem($user, $photoId, 'original', 'cancelled');

        $this->assertFalse($user->hasPurchasedPhoto($photoId, 'web'));
    }

    public function test_has_purchased_photo_pending_quote_request_is_excluded(): void
    {
        // is_quote_request=true UND status=pending → muss ausgeschlossen werden
        $user = User::factory()->create();
        $photoId = 'photo-uuid-pending-quote';
        $this->createOrderWithItem($user, $photoId, 'original', 'pending', true);

        $this->assertFalse($user->hasPurchasedPhoto($photoId, 'web'));
    }

    public function test_has_purchased_photo_approved_quote_request_is_included(): void
    {
        // is_quote_request=true ABER status!=pending (z.B. paid) → inkludiert
        $user = User::factory()->create();
        $photoId = 'photo-uuid-approved-quote';
        $this->createOrderWithItem($user, $photoId, 'original', 'paid', true);

        $this->assertTrue($user->hasPurchasedPhoto($photoId, 'web'));
    }

    public function test_has_purchased_photo_unknown_tier_defaults_to_rank_zero(): void
    {
        $user = User::factory()->create();
        $photoId = 'photo-uuid-unknown-tier';
        $this->createOrderWithItem($user, $photoId, 'weird-tier', 'paid');

        // itemRank = 0 → kein Match für requestedTier web (rank 1)
        $this->assertFalse($user->hasPurchasedPhoto($photoId, 'web'));
    }

    public function test_has_purchased_photo_unknown_requested_tier_defaults_to_original(): void
    {
        $user = User::factory()->create();
        $photoId = 'photo-uuid-unknown-req';
        $this->createOrderWithItem($user, $photoId, 'web', 'paid');

        // reqRank defaultet zu 3 (original) → web (rank 1) reicht nicht
        $this->assertFalse($user->hasPurchasedPhoto($photoId, 'unknown-tier'));
    }

    public function test_has_purchased_photo_without_snapshot_is_skipped(): void
    {
        $user = User::factory()->create();
        $photoId = 'photo-no-snapshot';
        $order = Order::create([
            'user_id' => $user->id,
            'status' => 'paid',
            'is_quote_request' => false,
            'total_amount' => 1000,
        ]);
        // absichtlich KEIN InvoiceSnapshot anlegen

        $this->assertFalse($user->hasPurchasedPhoto($photoId, 'web'));
    }

    public function test_has_purchased_photo_missing_items_key_is_skipped(): void
    {
        $user = User::factory()->create();
        $photoId = 'photo-missing-items';
        $order = Order::create([
            'user_id' => $user->id,
            'status' => 'paid',
            'is_quote_request' => false,
            'total_amount' => 1000,
        ]);
        InvoiceSnapshot::create([
            'order_id' => $order->id,
            'customer_details' => [
                // 'items' fehlt komplett
                'name' => 'Test',
            ],
            'total_net' => 1000,
            'total_gross' => 1200,
            'tax_rate' => 20.00,
        ]);

        $this->assertFalse($user->hasPurchasedPhoto($photoId, 'web'));
    }

    public function test_has_purchased_photo_item_missing_tier_key_is_rank_zero(): void
    {
        $user = User::factory()->create();
        $photoId = 'photo-missing-tier';
        $order = Order::create([
            'user_id' => $user->id,
            'status' => 'paid',
            'is_quote_request' => false,
            'total_amount' => 1000,
        ]);
        InvoiceSnapshot::create([
            'order_id' => $order->id,
            'customer_details' => [
                'items' => [
                    ['photoId' => $photoId, 'price' => 1000], // tier fehlt
                ],
            ],
            'total_net' => 1000,
            'total_gross' => 1200,
            'tax_rate' => 20.00,
        ]);

        $this->assertFalse($user->hasPurchasedPhoto($photoId, 'web'));
    }

    public function test_has_purchased_photo_different_photo_id_does_not_match(): void
    {
        $user = User::factory()->create();
        $this->createOrderWithItem($user, 'other-photo-id', 'original', 'paid');

        $this->assertFalse($user->hasPurchasedPhoto('my-photo-id', 'web'));
    }

    public function test_has_purchased_photo_no_orders_returns_false(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($user->hasPurchasedPhoto('any-photo', 'web'));
    }

    public function test_has_purchased_photo_item_missing_photo_id_key_is_skipped(): void
    {
        $user = User::factory()->create();
        $order = Order::create([
            'user_id' => $user->id,
            'status' => 'paid',
            'is_quote_request' => false,
            'total_amount' => 1000,
        ]);
        InvoiceSnapshot::create([
            'order_id' => $order->id,
            'customer_details' => [
                'items' => [
                    ['tier' => 'original', 'price' => 1000], // photoId fehlt
                ],
            ],
            'total_net' => 1000,
            'total_gross' => 1200,
            'tax_rate' => 20.00,
        ]);

        $this->assertFalse($user->hasPurchasedPhoto('any-photo', 'web'));
    }

    // =====================================================================
    // 6. Rollen-Accessors & is_pending
    // =====================================================================

    public function test_is_super_admin_attribute_returns_true_when_role_assigned(): void
    {
        $user = User::factory()->create();
        $this->assignRole($user, UserRole::SUPER_ADMIN);

        $this->assertTrue($user->is_super_admin);
    }

    public function test_is_super_admin_attribute_returns_false_without_role(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($user->is_super_admin);
    }

    public function test_is_admin_attribute_returns_true_for_admin_role(): void
    {
        $user = User::factory()->create();
        $this->assignRole($user, UserRole::ADMIN);

        $this->assertTrue($user->is_admin);
    }

    public function test_is_admin_attribute_returns_true_for_super_admin_role(): void
    {
        // is_admin ist true für super_admin ODER admin
        $user = User::factory()->create();
        $this->assignRole($user, UserRole::SUPER_ADMIN);

        $this->assertTrue($user->is_admin);
    }

    public function test_is_photographer_attribute_returns_true_for_photographer_role(): void
    {
        $user = User::factory()->create();
        $this->assignRole($user, UserRole::PHOTOGRAPHER);

        $this->assertTrue($user->is_photographer);
    }

    public function test_is_org_admin_attribute_returns_true_for_role(): void
    {
        $org = \App\Models\Org::factory()->create();
        $user = User::factory()->create(['org_id' => $org->id]);
        $this->assignRole($user, UserRole::ORG_ADMIN);

        $this->assertTrue($user->is_org_admin);
    }

    public function test_is_power_user_attribute_returns_true_for_role(): void
    {
        $user = User::factory()->create();
        $this->assignRole($user, UserRole::POWER_USER);

        $this->assertTrue($user->is_power_user);
    }

    public function test_is_pending_returns_true_for_user_without_any_assignments(): void
    {
        $user = User::factory()->create();

        $this->assertTrue($user->is_pending);
    }

    public function test_is_pending_returns_false_when_role_assigned(): void
    {
        $user = User::factory()->create();
        $this->assignRole($user, UserRole::CLIENT);

        $this->assertFalse($user->is_pending);
    }

    public function test_is_pending_returns_false_when_gallery_assigned(): void
    {
        $user = User::factory()->create();
        $gallery = Gallery::factory()->create();
        $user->galleries()->attach($gallery->id);

        $this->assertFalse($user->is_pending);
    }

    public function test_is_pending_returns_false_when_group_assigned(): void
    {
        $user = User::factory()->create();
        $group = GalleryGroup::factory()->create();
        $user->galleryGroups()->attach($group->id);

        $this->assertFalse($user->is_pending);
    }

    public function test_is_pending_returns_false_for_guest(): void
    {
        $user = $this->makeGuest();

        $this->assertFalse($user->is_pending);
    }
}
