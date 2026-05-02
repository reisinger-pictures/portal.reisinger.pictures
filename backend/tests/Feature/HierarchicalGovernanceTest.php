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

    public function test_accessors_propagate_true_down_the_tree()
    {
        // Top-Level Group (Force True)
        $parentGroup = GalleryGroup::factory()->create(['is_editorial_only' => true]);
        
        // Child Group (Inherit / default false)
        $childGroup = GalleryGroup::factory()->create([
            'parent_id' => $parentGroup->id
        ]);

        // Gallery (Inherit / default false)
        $gallery = Gallery::factory()->create([
            'gallery_group_id' => $childGroup->id
        ]);

        // Photo (Inherit / default false)
        $photo = Photo::factory()->create([
            'gallery_id' => $gallery->id
        ]);

        // Assert Inheritance
        $this->assertTrue($photo->effective_is_editorial_only);
        $this->assertTrue($gallery->effective_is_editorial_only);
        $this->assertTrue($childGroup->effective_is_editorial_only);
    }
}
