<?php

namespace Tests\Unit\Models;

use App\Models\Gallery;
use App\Models\Org;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GalleryOrgIdsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Regression: the `org_ids` accessor used to return `[]` unless the `orgs`
     * relation happened to be eager loaded, so API payloads hid real
     * assignments. It must resolve the relation on demand.
     */
    public function test_org_ids_are_exposed_without_explicit_eager_load(): void
    {
        $gallery = Gallery::factory()->create();
        $org = Org::factory()->create();
        $gallery->orgs()->attach($org->id);

        $fresh = Gallery::findOrFail($gallery->id);
        $this->assertFalse($fresh->relationLoaded('orgs'));

        $array = $fresh->toArray();

        $this->assertSame([$org->id], $array['org_ids']);
    }
}
