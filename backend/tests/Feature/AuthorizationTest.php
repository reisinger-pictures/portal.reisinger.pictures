<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Enums\UserRole;
use App\Models\Gallery;
use App\Models\GalleryGroup;
use App\Models\Org;
use App\Models\Role;
// use App\Models\DomainMapping; (Removed in favor of Org)
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_does_not_have_implicit_access_to_galleries()
    {
        $admin = User::factory()->create(['brand' => Brand::B2B]);
        $admin->roles()->attach(Role::firstOrCreate(['name' => UserRole::ADMIN->value]));

        $gallery = Gallery::factory()->create();

        // Admins haben keinen globalen, impliziten Zugriff mehr
        $this->assertCount(0, $admin->getAllowedGalleryIds());
        $this->assertFalse($admin->canAccessGallery($gallery->id));

        // Zugriff funktioniert erst nach expliziter Zuweisung
        $admin->galleries()->attach($gallery);
        $this->assertCount(1, $admin->fresh()->getAllowedGalleryIds());
        $this->assertTrue($admin->fresh()->canAccessGallery($gallery->id));
    }

    public function test_user_inherits_access_via_gallery_group()
    {
        $user = User::factory()->create(['brand' => Brand::B2B]);

        $parentGroup = GalleryGroup::factory()->create();
        $childGroup = GalleryGroup::factory()->create(['parent_id' => $parentGroup->id]);

        $gallery = Gallery::factory()->create(['gallery_group_id' => $childGroup->id]);

        // Zuweisung auf die oberste Gruppe
        $user->galleryGroups()->attach($parentGroup);

        $this->assertContains($gallery->id, $user->getAllowedGalleryIds());
    }

    public function test_domain_mapping_grants_access_to_delivery_galleries_only()
    {
        $user = User::factory()->create(['brand' => Brand::B2B, 'email' => 'employee@firma.com']);
        $group = GalleryGroup::factory()->create();

        $org = Org::create([
            'name' => 'Firma',
            'domain' => 'firma.com',
            'invoice_frequency' => 'immediate',
        ]);
        $user->org_id = $org->id;
        $user->save();
        $org->galleryGroups()->attach($group->id);

        $deliveryGallery = Gallery::factory()->create([
            'gallery_group_id' => $group->id,
            'type' => 'delivery',
        ]);

        $selectionGallery = Gallery::factory()->create([
            'gallery_group_id' => $group->id,
            'type' => 'selection',
        ]);

        $allowedIds = $user->getAllowedGalleryIds();

        $this->assertContains($deliveryGallery->id, $allowedIds);
        $this->assertNotContains($selectionGallery->id, $allowedIds);
    }

    public function test_photographer_team_access_logic()
    {
        $photogA = User::factory()->create(['brand' => Brand::B2B]);
        $photogA->roles()->attach(Role::firstOrCreate(['name' => UserRole::PHOTOGRAPHER->value]));
        $photogB = User::factory()->create(['brand' => Brand::B2B]);
        $photogB->roles()->attach(Role::firstOrCreate(['name' => UserRole::PHOTOGRAPHER->value]));

        $group = GalleryGroup::factory()->create(['restricted_photographers' => true]);
        $gallery = Gallery::factory()->create(['gallery_group_id' => $group->id, 'restricted_photographers' => null]);

        $this->assertFalse($photogB->canPhotographerAccessGallery($gallery->id));

        $photogB->photographerGalleryGroups()->attach($group->id);
        $this->assertTrue($photogB->fresh()->canPhotographerAccessGallery($gallery->id));
    }
}
