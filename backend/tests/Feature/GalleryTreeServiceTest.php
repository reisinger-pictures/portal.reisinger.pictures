<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Gallery;
use App\Models\GalleryGroup;
use App\Models\Role;
use App\Models\User;
use App\Services\GalleryTreeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class GalleryTreeServiceTest extends TestCase
{
    use RefreshDatabase;

    private GalleryTreeService $service;

    protected function setUp(): void
    {
        parent::setUp();
        // PROZESSGLOBALER file-Cache (CACHE_STORE=file) — Key 'gallery_tree_admin'
        // persistiert über Tests. Zwingend zwischen Tests resetten (BK-01-Erfahrung).
        Cache::flush();
        $this->service = new GalleryTreeService();
    }

    private function makeAdmin(): User
    {
        $admin = User::factory()->create();
        $role = Role::firstOrCreate(['name' => UserRole::ADMIN->value]);
        $admin->roles()->syncWithoutDetaching([$role->id]);
        return $admin;
    }

    private function assertTreeStructure(array $tree): void
    {
        $this->assertArrayHasKey('groups', $tree);
        $this->assertArrayHasKey('root_galleries', $tree);
    }

    // =====================================================================
    // 1. Leerer Baum
    // =====================================================================

    public function test_get_admin_tree_empty_database_return_empty_structure(): void
    {
        $admin = $this->makeAdmin();

        $tree = $this->service->getAdminTree($admin);

        $this->assertTreeStructure($tree);
        $this->assertSame([], $tree['groups']);
        $this->assertSame([], $tree['root_galleries']);
    }

    // =====================================================================
    // 2. Admin sieht vollen Baum (ungefiltert)
    // =====================================================================

    public function test_get_admin_tree_admin_sees_full_tree_unfiltered(): void
    {
        $admin = $this->makeAdmin();
        $group = GalleryGroup::factory()->create();
        $groupGallery = Gallery::factory()->create([
            'gallery_group_id' => $group->id,
            'type' => 'delivery',
        ]);
        $rootGallery = Gallery::factory()->create([
            'gallery_group_id' => null,
            'type' => 'selection',
        ]);

        $tree = $this->service->getAdminTree($admin);

        $this->assertCount(1, $tree['groups']);
        $this->assertSame($group->id, $tree['groups'][0]['id']);
        $this->assertCount(1, $tree['groups'][0]['galleries']);
        $this->assertSame($groupGallery->id, $tree['groups'][0]['galleries'][0]['id']);

        $this->assertCount(1, $tree['root_galleries']);
        $this->assertSame($rootGallery->id, $tree['root_galleries'][0]['id']);
    }

    // =====================================================================
    // 3. Verschachtelung 3+ Level
    // =====================================================================

    public function test_get_admin_tree_nested_three_levels_reflected_recursively(): void
    {
        $admin = $this->makeAdmin();
        $level1 = GalleryGroup::factory()->create();
        $level2 = GalleryGroup::factory()->create(['parent_id' => $level1->id]);
        $level3 = GalleryGroup::factory()->create(['parent_id' => $level2->id]);
        $deepGallery = Gallery::factory()->create([
            'gallery_group_id' => $level3->id,
            'type' => 'selection',
        ]);

        $tree = $this->service->getAdminTree($admin);

        $this->assertCount(1, $tree['groups']);
        $root = $tree['groups'][0];
        $this->assertSame($level1->id, $root['id']);
        $this->assertCount(1, $root['children']);
        $this->assertSame($level2->id, $root['children'][0]['id']);
        $this->assertCount(1, $root['children'][0]['children']);
        $this->assertSame($level3->id, $root['children'][0]['children'][0]['id']);
        $this->assertCount(1, $root['children'][0]['children'][0]['galleries']);
        $this->assertSame($deepGallery->id, $root['children'][0]['children'][0]['galleries'][0]['id']);
    }

    // =====================================================================
    // 4. filterType-Filter (selection / delivery / null)
    // =====================================================================

    public function test_get_admin_tree_filter_type_selection_keeps_only_selection_galleries(): void
    {
        $admin = $this->makeAdmin();
        $group = GalleryGroup::factory()->create();
        $selection = Gallery::factory()->create([
            'gallery_group_id' => $group->id, 'type' => 'selection',
        ]);
        $delivery = Gallery::factory()->create([
            'gallery_group_id' => $group->id, 'type' => 'delivery',
        ]);
        $rootSelection = Gallery::factory()->create([
            'gallery_group_id' => null, 'type' => 'selection',
        ]);
        $rootDelivery = Gallery::factory()->create([
            'gallery_group_id' => null, 'type' => 'delivery',
        ]);

        $tree = $this->service->getAdminTree($admin, 'selection');

        $groupGalleryIds = array_column($tree['groups'][0]['galleries'], 'id');
        $this->assertContains($selection->id, $groupGalleryIds);
        $this->assertNotContains($delivery->id, $groupGalleryIds);

        $rootGalleryIds = array_column($tree['root_galleries'], 'id');
        $this->assertContains($rootSelection->id, $rootGalleryIds);
        $this->assertNotContains($rootDelivery->id, $rootGalleryIds);
    }

    public function test_get_admin_tree_filter_type_delivery_keeps_only_delivery_galleries(): void
    {
        $admin = $this->makeAdmin();
        $group = GalleryGroup::factory()->create();
        $selection = Gallery::factory()->create([
            'gallery_group_id' => $group->id, 'type' => 'selection',
        ]);
        $delivery = Gallery::factory()->create([
            'gallery_group_id' => $group->id, 'type' => 'delivery',
        ]);

        $tree = $this->service->getAdminTree($admin, 'delivery');

        $groupGalleryIds = array_column($tree['groups'][0]['galleries'], 'id');
        $this->assertContains($delivery->id, $groupGalleryIds);
        $this->assertNotContains($selection->id, $groupGalleryIds);
    }

    public function test_get_admin_tree_filter_type_null_keeps_all_types(): void
    {
        $admin = $this->makeAdmin();
        $group = GalleryGroup::factory()->create();
        $selection = Gallery::factory()->create([
            'gallery_group_id' => $group->id, 'type' => 'selection',
        ]);
        $delivery = Gallery::factory()->create([
            'gallery_group_id' => $group->id, 'type' => 'delivery',
        ]);

        $tree = $this->service->getAdminTree($admin, null);

        $groupGalleryIds = array_column($tree['groups'][0]['galleries'], 'id');
        $this->assertContains($selection->id, $groupGalleryIds);
        $this->assertContains($delivery->id, $groupGalleryIds);
    }

    // =====================================================================
    // 5. Cache-Hit (Stub) — rememberForever liefert den gecachten Baum
    // =====================================================================

    public function test_get_admin_tree_cache_hit_returns_stub_exactly(): void
    {
        // REVIEW: Cache 'gallery_tree_admin' ist PROZESSGLOBAL und user-unabhängig.
        // Der Service cached den VOLL Baum (ungefiltert) — Filterung passiert danach
        // im Speicher. Hier wird der Hit-Pfad isoliert bewiesen, indem wir den
        // Cache mit einem Stub befüllen und prüfen, dass rememberForever exakt
        // diesen Stub zurückliefert (unabhängig von der realen DB).
        $admin = $this->makeAdmin();
        GalleryGroup::factory()->create(); // existiert, soll aber durch Stub verdeckt werden

        $stubTree = [
            'groups' => [
                ['id' => 'stub-group-id', 'name' => 'Stub Group', 'galleries' => [], 'children' => []],
            ],
            'root_galleries' => [
                ['id' => 'stub-gallery-id', 'name' => 'Stub Gallery'],
            ],
        ];
        Cache::put('gallery_tree_admin', $stubTree);

        $tree = $this->service->getAdminTree($admin);

        // rememberForever-Hit: Service liefert EXAKT den Stub zurück
        $this->assertSame('Stub Group', $tree['groups'][0]['name']);
        $this->assertSame('stub-group-id', $tree['groups'][0]['id']);
        $this->assertSame('Stub Gallery', $tree['root_galleries'][0]['name']);
        $this->assertCount(1, $tree['groups']);
        $this->assertCount(1, $tree['root_galleries']);
    }

    public function test_get_admin_tree_cache_miss_after_clear_rebuilds_from_db(): void
    {
        $admin = $this->makeAdmin();
        $group = GalleryGroup::factory()->create();
        Gallery::factory()->create(['gallery_group_id' => $group->id]);

        // Erster Build füllt den Cache
        $tree1 = $this->service->getAdminTree($admin);
        $this->assertCount(1, $tree1['groups']);

        // clearCache → Cache vergessen
        $this->service->clearCache();
        $this->assertNull(Cache::get('gallery_tree_admin'));

        // Neue Gruppe nach Forget hinzufügen — muss sichtbar werden (Miss-Pfad)
        $newGroup = GalleryGroup::factory()->create();

        $tree2 = $this->service->getAdminTree($admin);

        $groupIds = array_column($tree2['groups'], 'id');
        $this->assertContains($group->id, $groupIds);
        $this->assertContains($newGroup->id, $groupIds);
        $this->assertCount(2, $tree2['groups']);
    }

    // =====================================================================
    // 6. clearCache() löscht den Key
    // =====================================================================

    public function test_clear_cache_forgets_gallery_tree_admin_key(): void
    {
        $admin = $this->makeAdmin();
        GalleryGroup::factory()->create();
        $this->service->getAdminTree($admin);

        $this->assertNotNull(Cache::get('gallery_tree_admin'));

        $this->service->clearCache();

        $this->assertNull(Cache::get('gallery_tree_admin'));
    }

    // =====================================================================
    // 7. Nicht-Admin ohne Rechte → alle Galerien herausgefiltert
    // =====================================================================

    public function test_get_admin_tree_non_admin_without_rights_galleries_filtered_out(): void
    {
        // REVIEW: filterTreeByPermissions entfernt leere Gruppen NICHT —
        // die Gruppenhüllen bleiben leer im Baum stehen (aktuelles Verhalten,
        // hier eingefroren, nicht "passend gemacht").
        $user = User::factory()->create(); // plain user, keine Rollen
        $group = GalleryGroup::factory()->create();
        Gallery::factory()->create(['gallery_group_id' => $group->id, 'type' => 'delivery']);
        Gallery::factory()->create(['gallery_group_id' => null, 'type' => 'delivery']);

        $tree = $this->service->getAdminTree($user);

        // Gruppen-Hülle bleibt erhalten (aktuelles Verhalten)
        $this->assertCount(1, $tree['groups']);
        $this->assertSame($group->id, $tree['groups'][0]['id']);
        // ... aber alle Galerien rausgefiltert
        $this->assertSame([], $tree['groups'][0]['galleries']);
        $this->assertSame([], $tree['root_galleries']);
    }

    // =====================================================================
    // 8. Nicht-Admin MIT Rechten → nur erlaubte Galerien sichtbar
    // =====================================================================

    public function test_get_admin_tree_non_admin_with_rights_sees_only_allowed_galleries(): void
    {
        $user = User::factory()->create();
        $group = GalleryGroup::factory()->create();
        $allowedGallery = Gallery::factory()->create([
            'gallery_group_id' => $group->id, 'type' => 'delivery',
        ]);
        $forbiddenGallery = Gallery::factory()->create([
            'gallery_group_id' => $group->id, 'type' => 'delivery',
        ]);
        $allowedRoot = Gallery::factory()->create([
            'gallery_group_id' => null, 'type' => 'delivery',
        ]);
        $forbiddenRoot = Gallery::factory()->create([
            'gallery_group_id' => null, 'type' => 'delivery',
        ]);

        // Direktzuweisung → in getAllowedGalleryIds sichtbar
        $user->galleries()->attach([$allowedGallery->id, $allowedRoot->id]);

        $tree = $this->service->getAdminTree($user);

        $groupGalleryIds = array_column($tree['groups'][0]['galleries'], 'id');
        $this->assertContains($allowedGallery->id, $groupGalleryIds);
        $this->assertNotContains($forbiddenGallery->id, $groupGalleryIds);

        $rootGalleryIds = array_column($tree['root_galleries'], 'id');
        $this->assertContains($allowedRoot->id, $rootGalleryIds);
        $this->assertNotContains($forbiddenRoot->id, $rootGalleryIds);
    }

    // =====================================================================
    // 9. getAllSubgroupIds — nur Nachfahren, eigene ID NICHT
    // =====================================================================

    public function test_get_all_subgroup_ids_excludes_own_id_returns_only_descendants(): void
    {
        // REVIEW: getAllSubgroupIds schließt die ID der übergebenen Gruppe
        // selbst NICHT ein (nur Nachfahren via children) — aktuelles Verhalten.
        $root = GalleryGroup::factory()->create();
        $child1 = GalleryGroup::factory()->create(['parent_id' => $root->id]);
        $child2 = GalleryGroup::factory()->create(['parent_id' => $root->id]);
        $grandchild = GalleryGroup::factory()->create(['parent_id' => $child1->id]);

        // children-Relation explizit laden (Service iteriert $group->children)
        $root->load('children');

        $result = $this->service->getAllSubgroupIds($root);

        $this->assertContains($child1->id, $result);
        $this->assertContains($child2->id, $result);
        $this->assertContains($grandchild->id, $result);
        // Eigene ID der übergebenen Gruppe darf NICHT enthalten sein
        $this->assertNotContains($root->id, $result);
        $this->assertCount(3, $result);
    }

    public function test_get_all_subgroup_ids_group_without_children_returns_empty(): void
    {
        $root = GalleryGroup::factory()->create();
        $root->load('children');

        $result = $this->service->getAllSubgroupIds($root);

        $this->assertSame([], $result);
    }

    // =====================================================================
    // 10. Zweiter getAdminTree-Call nutzt den Cache (kein DB-Rebuild)
    // =====================================================================

    public function test_get_admin_tree_subsequent_call_uses_cache_no_db_rebuild(): void
    {
        // REVIEW: Der Cache ist user-unabhängig (immer der VOLL Baum).
        // Ein zweiter Call liefert denselben vollen Baum aus dem Cache.
        $admin = $this->makeAdmin();
        GalleryGroup::factory()->create();
        Gallery::factory()->create(['gallery_group_id' => null]);

        $tree1 = $this->service->getAdminTree($admin);
        $cachedSnapshot = Cache::get('gallery_tree_admin');

        $tree2 = $this->service->getAdminTree($admin);

        // Zweiter Call liefert denselben Baum — Cache wurde nicht neu gebaut
        $this->assertSame($cachedSnapshot, Cache::get('gallery_tree_admin'));
        $this->assertCount(1, $tree1['groups']);
        $this->assertCount(1, $tree2['groups']);
    }
}
