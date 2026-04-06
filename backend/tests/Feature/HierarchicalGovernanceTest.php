<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\GalleryGroup;
use App\Models\Gallery;
use App\Models\Photo;
use Illuminate\Foundation\Testing\RefreshDatabase;

class HierarchicalGovernanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_accessors_inherit_values_correctly_down_the_tree()
    {
        // Top-Level Group (Force True)
        $parentGroup = GalleryGroup::factory()->create(['is_editorial_only' => true]);
        
        // Child Group (Inherit)
        $childGroup = GalleryGroup::factory()->create([
            'parent_id' => $parentGroup->id,
            'is_editorial_only' => null
        ]);

        // Gallery (Inherit)
        $gallery = Gallery::factory()->create([
            'gallery_group_id' => $childGroup->id,
            'is_editorial_only' => null
        ]);

        // Photo (Inherit)
        $photo = Photo::factory()->create([
            'gallery_id' => $gallery->id,
            'is_editorial_only' => null
        ]);

        // Assert Inheritance
        $this->assertTrue($photo->effective_is_editorial_only);
        $this->assertTrue($gallery->effective_is_editorial_only);
        $this->assertTrue($childGroup->effective_is_editorial_only);
    }

    public function test_accessors_can_be_overridden_at_lower_levels()
    {
        // Top-Level Group (Force True)
        $parentGroup = GalleryGroup::factory()->create(['is_editorial_only' => true]);
        
        // Gallery (Inherit)
        $gallery = Gallery::factory()->create([
            'gallery_group_id' => $parentGroup->id,
            'is_editorial_only' => null
        ]);

        // Photo overrides to False
        $photo = Photo::factory()->create([
            'gallery_id' => $gallery->id,
            'is_editorial_only' => false
        ]);

        // Assert Override
        $this->assertFalse($photo->effective_is_editorial_only);
        // Parent remains true
        $this->assertTrue($gallery->effective_is_editorial_only);
    }
}
