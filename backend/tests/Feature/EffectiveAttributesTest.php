<?php

namespace Tests\Feature;

use App\Models\Gallery;
use App\Models\GalleryGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
    // R-03 (BK-03): Zyklus-Schutz in getFullPathAttribute + effective_*-Accessoren.
    // Produktiver Code terminiert jetzt bei zirkulärer / selbstreferenzieller parent_id
    // (visited-set) und lehnt zyklische parent_id beim Speichern ab.
    // -----------------------------------------------------------------------

    /**
     * Zyklus in der Group-Kette (A→B→A) darf getFullPathAttribute nicht zum Überlaufen bringen.
     * Da der saving-Hook das Schreiben eines Zyklus verhindert, wird der Zyklus hier per
     * Query-Builder direkt in die DB geschrieben (umgeht Model-Events) und testet genau den
     * defensiven Runtime-Schutz gegen bereits vorhandene fehlerhafte Daten.
     */
    public function test_full_path_terminates_on_circular_parent_chain(): void
    {
        $a = GalleryGroup::factory()->create(['slug' => 'a']);
        $b = GalleryGroup::factory()->create(['slug' => 'b', 'parent_id' => $a->id]);

        // Zyklus A→B→A direkt in der DB erzwingen (saving-Hook umgangen).
        DB::table('gallery_groups')->where('id', $a->id)->update(['parent_id' => $b->id]);

        // Frische Instanz laden, damit die parent-Relation neu aufgelöst wird.
        $a = GalleryGroup::find($a->id);
        $gallery = Gallery::factory()->create([
            'slug' => 'shoot',
            'gallery_group_id' => $a->id,
        ]);

        // Terminiert ohne Stack-Overflow. Exakte Slug-Reihenfolge im Zyklus ist nicht Teil der Invariante.
        $path = $gallery->full_path;

        $this->assertStringStartsWith('galleries/', $path);
        $this->assertStringContainsString('shoot', $path);
    }

    /**
     * Selbstreferenz (A→A) terminiert in getFullPathAttribute.
     */
    public function test_full_path_terminates_on_self_referencing_parent(): void
    {
        $group = GalleryGroup::factory()->create(['slug' => 'self']);
        DB::table('gallery_groups')->where('id', $group->id)->update(['parent_id' => $group->id]);

        $group = GalleryGroup::find($group->id);
        $gallery = Gallery::factory()->create([
            'slug' => 'g',
            'gallery_group_id' => $group->id,
        ]);

        $path = $gallery->full_path;

        $this->assertStringStartsWith('galleries/', $path);
        $this->assertStringContainsString('/g', $path);
    }

    /**
     * effective_*-Accessoren terminieren bei Selbstreferenz und liefern den defensiven
     * Fallback (false), da kein echter true-Wert in der Kette steht.
     */
    public function test_effective_accessor_terminates_on_self_reference(): void
    {
        $group = GalleryGroup::factory()->create(['is_editorial_only' => false]);
        DB::table('gallery_groups')->where('id', $group->id)->update(['parent_id' => $group->id]);

        $group = GalleryGroup::find($group->id);

        // Terminiert (kein Overflow) und liefert false, weil is_editorial_only false ist.
        $this->assertFalse($group->effective_is_editorial_only);
        $this->assertFalse($group->effective_is_hidden);
        $this->assertFalse($group->effective_is_free_download);
        $this->assertFalse($group->effective_restricted_photographers);
    }

    /**
     * effective_*-Accessoren terminieren bei zirkulärer Kette UND ein echter true-Wert in der
     * Kette wird weiterhin korrekt propagiert.
     */
    public function test_effective_accessor_finds_true_in_circular_chain(): void
    {
        $a = GalleryGroup::factory()->create(['slug' => 'a', 'is_editorial_only' => true]);
        $b = GalleryGroup::factory()->create(['slug' => 'b', 'is_editorial_only' => false, 'parent_id' => $a->id]);

        DB::table('gallery_groups')->where('id', $a->id)->update(['parent_id' => $b->id]);

        $b = GalleryGroup::find($b->id);

        // Terminiert trotz Zyklus und a.is_editorial_only=true wird gefunden.
        $this->assertTrue($b->effective_is_editorial_only);
    }

    /**
     * DB-Schicht: saving-Hook lehnt Selbstreferenz ab.
     */
    public function test_saving_rejects_self_referencing_parent_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $group = GalleryGroup::factory()->create();
        $group->parent_id = $group->id;
        $group->save();
    }

    /**
     * DB-Schicht: saving-Hook lehnt einen Zyklus (A→B→A) beim Update ab.
     */
    public function test_saving_rejects_circular_parent_chain(): void
    {
        $a = GalleryGroup::factory()->create();
        $b = GalleryGroup::factory()->create(['parent_id' => $a->id]);

        $this->expectException(\InvalidArgumentException::class);

        // Würde A→B→A erzeugen.
        $a->parent_id = $b->id;
        $a->save();
    }

    /**
     * DB-Schicht: eine legitime tiefe, aber azyklische Kette wird weiterhin akzeptiert
     * (kein False Positive im Zyklus-Schutz).
     */
    public function test_saving_allows_deep_acyclic_chain(): void
    {
        $root = GalleryGroup::factory()->create(['parent_id' => null]);
        $current = $root;

        for ($i = 0; $i < 5; $i++) {
            $current = GalleryGroup::factory()->create(['parent_id' => $current->id]);
        }

        // Alle Speichern-Operationen waren erfolgreich (kein Exception).
        $this->assertNotNull($current->parent_id);
        $this->assertIsBool($current->effective_is_editorial_only);
    }
}
