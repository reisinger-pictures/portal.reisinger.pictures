<?php

declare(strict_types=1);

namespace Tests\Feature\Eur;

use App\Models\EurProject;
use App\Support\BrandRegistry;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

/** EUE-026: der Projektstamm ist jahresübergreifend pro Brand eindeutig (V072). */
class EurProjectUniquenessTest extends EurBookkeepingTestCase
{
    public function test_a_project_name_is_unique_per_brand(): void
    {
        EurProject::create(['brand' => BrandRegistry::currentId(), 'name' => 'Objektiv 35mm']);

        $this->expectException(QueryException::class);
        EurProject::create(['brand' => BrandRegistry::currentId(), 'name' => 'Objektiv 35mm']);
    }

    public function test_the_same_name_is_allowed_in_another_brand(): void
    {
        EurProject::create(['brand' => BrandRegistry::currentId(), 'name' => 'Objektiv 35mm']);
        EurProject::create(['brand' => self::OTHER_BRAND, 'name' => 'Objektiv 35mm']);

        $this->assertSame(2, EurProject::query()->where('name', 'Objektiv 35mm')->count());
    }

    public function test_projects_are_year_crossing_not_year_scoped(): void
    {
        $project = EurProject::create(['brand' => BrandRegistry::currentId(), 'name' => 'Objektiv 35mm']);

        $this->assertDatabaseCount('eur_projects', 1);
        $this->assertSame('Objektiv 35mm', $project->name);
        $this->assertFalse(Schema::hasColumn('eur_projects', 'year'));
    }
}
