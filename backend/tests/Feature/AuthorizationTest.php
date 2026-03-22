<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Role;
use App\Models\Gallery;
use App\Models\GalleryGroup;
use App\Models\DomainMapping;
use Illuminate\Foundation\Testing\RefreshDatabase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_has_access_to_all_galleries()
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::firstOrCreate(['name' => 'admin']));
        
        Gallery::factory()->count(3)->create();
        
        $this->assertCount(3, $admin->getAllowedGalleryIds());
        $this->assertTrue($admin->canAccessGallery(Gallery::first()->id));
    }

    public function test_user_inherits_access_via_gallery_group()
    {
        $user = User::factory()->create();
        
        $parentGroup = GalleryGroup::factory()->create();
        $childGroup = GalleryGroup::factory()->create(['parent_id' => $parentGroup->id]);
        
        $gallery = Gallery::factory()->create(['gallery_group_id' => $childGroup->id]);
        
        // Zuweisung auf die oberste Gruppe
        $user->galleryGroups()->attach($parentGroup);

        $this->assertContains($gallery->id, $user->getAllowedGalleryIds());
    }

    public function test_domain_mapping_grants_access_to_delivery_galleries_only()
    {
        $user = User::factory()->create(['email' => 'employee@firma.com']);
        $group = GalleryGroup::factory()->create();
        
        DomainMapping::create([
            'domain' => 'firma.com',
            'gallery_group_id' => $group->id
        ]);

        $deliveryGallery = Gallery::factory()->create([
            'gallery_group_id' => $group->id, 
            'type' => 'delivery'
        ]);
        
        $selectionGallery = Gallery::factory()->create([
            'gallery_group_id' => $group->id, 
            'type' => 'selection'
        ]);

        $allowedIds = $user->getAllowedGalleryIds();
        
        $this->assertContains($deliveryGallery->id, $allowedIds);
        $this->assertNotContains($selectionGallery->id, $allowedIds);
    }
}
