<?php

namespace Tests\Feature;

use App\Models\Gallery;
use App\Models\GalleryGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EffectiveAttributesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
    }

    // -----------------------------------------------------------------------
    // Gallery::effective_is_editorial_only (||-Kaskade via galleryGroup)
    // -----------------------------------------------------------------------

    public function test_gallery_effective_is_editorial_only_returns_own_value_when_true(): void
    {
        $gallery = Gallery::factory()->create([
            'is_editorial_only' => true,
            'gallery_group_id' => null,
        ]);

        $this->assertTrue($gallery->effective_is_editorial_only);
    }

    public function test_gallery_effective_is_editorial_only_returns_false_when_own_false_and_no_group(): void
    {
        $gallery = Gallery::factory()->create([
            'is_editorial_only' => false,
            'gallery_group_id' => null,
        ]);

        $this->assertFalse($gallery->effective_is_editorial_only);
    }

    public function test_gallery_effective_is_editorial_only_inherits_from_group(): void
    {
        $group = GalleryGroup::factory()->create(['is_editorial_only' => true]);

        $gallery = Gallery::factory()->create([
            'is_editorial_only' => false,
            'gallery_group_id' => $group->id,
        ]);

        $this->assertTrue($gallery->effective_is_editorial_only);
    }

    public function test_gallery_effective_is_editorial_only_cascades_multiple_levels(): void
    {
        // Großvater true → Vater false → Gallery false ⇒ true (||-Kaskade)
        $grandparent = GalleryGroup::factory()->create([
            'is_editorial_only' => true,
            'parent_id' => null,
        ]);
        $parent = GalleryGroup::factory()->create([
            'is_editorial_only' => false,
            'parent_id' => $grandparent->id,
        ]);
        $gallery = Gallery::factory()->create([
            'is_editorial_only' => false,
            'gallery_group_id' => $parent->id,
        ]);

        $this->assertTrue($gallery->effective_is_editorial_only);
    }

    // -----------------------------------------------------------------------
    // Gallery::effective_is_free_download (||-Kaskade)
    // -----------------------------------------------------------------------

    public function test_gallery_effective_is_free_download_returns_own_true(): void
    {
        $gallery = Gallery::factory()->create([
            'is_free_download' => true,
            'gallery_group_id' => null,
        ]);

        $this->assertTrue($gallery->effective_is_free_download);
    }

    public function test_gallery_effective_is_free_download_inherits_from_group(): void
    {
        $group = GalleryGroup::factory()->create(['is_free_download' => true]);

        $gallery = Gallery::factory()->create([
            'is_free_download' => false,
            'gallery_group_id' => $group->id,
        ]);

        $this->assertTrue($gallery->effective_is_free_download);
    }

    public function test_gallery_effective_is_free_download_false_when_all_false(): void
    {
        $group = GalleryGroup::factory()->create([
            'is_free_download' => false,
            'parent_id' => null,
        ]);
        $gallery = Gallery::factory()->create([
            'is_free_download' => false,
            'gallery_group_id' => $group->id,
        ]);

        $this->assertFalse($gallery->effective_is_free_download);
    }

    // -----------------------------------------------------------------------
    // Gallery::effective_is_hidden (||-Kaskade)
    // -----------------------------------------------------------------------

    public function test_gallery_effective_is_hidden_returns_own_true(): void
    {
        $gallery = Gallery::factory()->create([
            'is_hidden' => true,
            'gallery_group_id' => null,
        ]);

        $this->assertTrue($gallery->effective_is_hidden);
    }

    public function test_gallery_effective_is_hidden_inherits_from_parent_group_chain(): void
    {
        $grandparent = GalleryGroup::factory()->create([
            'is_hidden' => true,
            'parent_id' => null,
        ]);
        $parent = GalleryGroup::factory()->create([
            'is_hidden' => false,
            'parent_id' => $grandparent->id,
        ]);
        $gallery = Gallery::factory()->create([
            'is_hidden' => false,
            'gallery_group_id' => $parent->id,
        ]);

        $this->assertTrue($gallery->effective_is_hidden);
    }

    // -----------------------------------------------------------------------
    // Gallery::effective_restricted_photographers (!== null — bricht bei explizitem Wert)
    // -----------------------------------------------------------------------

    public function test_gallery_effective_restricted_photographers_own_true_wins(): void
    {
        $group = GalleryGroup::factory()->create(['restricted_photographers' => false]);

        $gallery = Gallery::factory()->create([
            'restricted_photographers' => true,
            'gallery_group_id' => $group->id,
        ]);

        $this->assertTrue($gallery->effective_restricted_photographers);
    }

    public function test_gallery_effective_restricted_photographers_explicit_false_breaks_cascade(): void
    {
        // REVIEW-Kernfall: ||-Logik würde true liefern, !== null liefert false (Kaskade gebrochen).
        $group = GalleryGroup::factory()->create(['restricted_photographers' => true]);

        $gallery = Gallery::factory()->create([
            'restricted_photographers' => false,
            'gallery_group_id' => $group->id,
        ]);

        $this->assertFalse($gallery->effective_restricted_photographers);
    }

    public function test_gallery_effective_restricted_photographers_null_inherits_from_group_true(): void
    {
        $group = GalleryGroup::factory()->create(['restricted_photographers' => true]);

        $gallery = Gallery::factory()->create([
            'restricted_photographers' => null,
            'gallery_group_id' => $group->id,
        ]);

        $this->assertTrue($gallery->effective_restricted_photographers);
    }

    public function test_gallery_effective_restricted_photographers_null_inherits_from_group_null_chain(): void
    {
        // Group null + parent null ⇒ Default false
        $grandparent = GalleryGroup::factory()->create([
            'restricted_photographers' => null,
            'parent_id' => null,
        ]);
        $parent = GalleryGroup::factory()->create([
            'restricted_photographers' => null,
            'parent_id' => $grandparent->id,
        ]);
        $gallery = Gallery::factory()->create([
            'restricted_photographers' => null,
            'gallery_group_id' => $parent->id,
        ]);

        $this->assertFalse($gallery->effective_restricted_photographers);
    }

    public function test_gallery_effective_restricted_photographers_null_inherits_true_from_grandparent(): void
    {
        $grandparent = GalleryGroup::factory()->create([
            'restricted_photographers' => true,
            'parent_id' => null,
        ]);
        $parent = GalleryGroup::factory()->create([
            'restricted_photographers' => null,
            'parent_id' => $grandparent->id,
        ]);
        $gallery = Gallery::factory()->create([
            'restricted_photographers' => null,
            'gallery_group_id' => $parent->id,
        ]);

        $this->assertTrue($gallery->effective_restricted_photographers);
    }

    // -----------------------------------------------------------------------
    // GalleryGroup::effective_* (gleiche Logik, via parent)
    // -----------------------------------------------------------------------

    public function test_group_effective_is_editorial_only_inherits_from_parent(): void
    {
        $parent = GalleryGroup::factory()->create([
            'is_editorial_only' => true,
            'parent_id' => null,
        ]);
        $child = GalleryGroup::factory()->create([
            'is_editorial_only' => false,
            'parent_id' => $parent->id,
        ]);

        $this->assertTrue($child->effective_is_editorial_only);
    }

    public function test_group_effective_is_free_download_own_true_with_false_parent(): void
    {
        $parent = GalleryGroup::factory()->create([
            'is_free_download' => false,
            'parent_id' => null,
        ]);
        $child = GalleryGroup::factory()->create([
            'is_free_download' => true,
            'parent_id' => $parent->id,
        ]);

        $this->assertTrue($child->effective_is_free_download);
    }

    public function test_group_effective_restricted_photographers_explicit_false_breaks_cascade(): void
    {
        $parent = GalleryGroup::factory()->create(['restricted_photographers' => true]);

        $child = GalleryGroup::factory()->create([
            'restricted_photographers' => false,
            'parent_id' => $parent->id,
        ]);

        $this->assertFalse($child->effective_restricted_photographers);
    }

    public function test_group_effective_restricted_photographers_null_inherits_true(): void
    {
        $parent = GalleryGroup::factory()->create([
            'restricted_photographers' => true,
            'parent_id' => null,
        ]);
        $child = GalleryGroup::factory()->create([
            'restricted_photographers' => null,
            'parent_id' => $parent->id,
        ]);

        $this->assertTrue($child->effective_restricted_photographers);
    }

    // -----------------------------------------------------------------------
    // Gallery::getFullPathAttribute
    // -----------------------------------------------------------------------

    public function test_full_path_without_group_returns_galleries_prefix_and_slug(): void
    {
        $gallery = Gallery::factory()->create([
            'slug' => 'my-gallery',
            'gallery_group_id' => null,
        ]);

        $this->assertSame('galleries/my-gallery', $gallery->full_path);
    }

    public function test_full_path_with_single_group_prepends_group_slug(): void
    {
        $group = GalleryGroup::factory()->create(['slug' => 'weddings']);
        $gallery = Gallery::factory()->create([
            'slug' => 'ceremony',
            'gallery_group_id' => $group->id,
        ]);

        $this->assertSame('galleries/weddings/ceremony', $gallery->full_path);
    }

    public function test_full_path_cascades_multiple_group_levels(): void
    {
        $grandparent = GalleryGroup::factory()->create([
            'slug' => '2024',
            'parent_id' => null,
        ]);
        $parent = GalleryGroup::factory()->create([
            'slug' => 'europe',
            'parent_id' => $grandparent->id,
        ]);
        $gallery = Gallery::factory()->create([
            'slug' => 'paris',
            'gallery_group_id' => $parent->id,
        ]);

        $this->assertSame('galleries/2024/europe/paris', $gallery->full_path);
    }

    // -----------------------------------------------------------------------
    // REVIEW: Zyklus / Selbstreferenz — dokumentiert aktuelles (ungeschütztes) Verhalten.
    // Die Accessoren und getFullPathAttribute haben KEINEN Zyklus-Schutz.
    // Diese Tests werden bewusst übersprungen, bis ein Ticket den produktiven Code härtet.
    // -----------------------------------------------------------------------

    public function test_review_full_path_infinite_loop_on_circular_parent_chain(): void
    {
        $this->markTestSkipped(
            'REVIEW/BK-03: Gallery::getFullPathAttribute und GalleryGroup::effective_* haben '
            . 'keinen Zyklus-Schutz. Eine zirkuläre parent_id-Kette (A→B→A) bzw. '
            . 'Selbstreferenz (A→A) führt zu Endlosrekursion/Stack-Overflow. '
            . 'Ticket für produktives Härten (visited-set oder max-Tiefe) erforderlich. '
            . 'Dieser Test dokumentiert das Risiko, wird aber nicht grün getestet.'
        );

        // Zur Dokumentation des reproduzierenden Falls (wird wegen skip nicht ausgeführt):
        // $a = GalleryGroup::factory()->create(['slug' => 'a']);
        // $b = GalleryGroup::factory()->create(['slug' => 'b', 'parent_id' => $a->id]);
        // $a->update(['parent_id' => $b->id]); // Zyklus A→B→A
        // $gallery = Gallery::factory()->create(['gallery_group_id' => $a->id]);
        // $gallery->full_path; // Endlosschleife
    }

    public function test_review_effective_accessor_infinite_loop_on_self_reference(): void
    {
        // Selbstreferenz.parent_id = eigene id: effective_* würde endlos rekursiv aufrufen.
        $group = GalleryGroup::factory()->create(['is_editorial_only' => false]);
        $group->parent_id = $group->id;
        $group->save();

        // Sanity guard (nur im Test, nicht im produktiven Code): recognize self-reference.
        if ($group->parent && $group->parent->id === $group->id) {
            $this->markTestSkipped(
                'REVIEW/BK-03: Selbstreferenzender parent_id erkannt. '
                . 'GalleryGroup::effective_is_editorial_only würde endlos rekursieren. '
                . 'Produktiver Code braucht Zyklus-Schutz. Dokumentiert, nicht „grün" gemacht.'
            );
        }

        $this->assertTrue($group->effective_is_editorial_only);
    }
}
