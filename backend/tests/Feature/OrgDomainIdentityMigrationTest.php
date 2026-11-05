<?php

namespace Tests\Feature;

use App\Models\Org;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class OrgDomainIdentityMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_v056_refuses_case_folded_duplicates_without_rewriting_domains(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The collision fixture requires PostgreSQL case-sensitive unique semantics.');
        }

        $first = Org::factory()->create(['domain' => 'first-identity.example']);
        $second = Org::factory()->create(['domain' => 'second-identity.example']);

        DB::statement('DROP INDEX orgs_domain_lower_unique');
        DB::table('orgs')->where('id', $first->id)->update(['domain' => 'Duplicate.Example']);
        DB::table('orgs')->where('id', $second->id)->update(['domain' => 'duplicate.example']);

        try {
            $this->migration()->up();
            $this->fail('V056 accepted organization domains that collide after case folding.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('normalized_domain=duplicate.example', $exception->getMessage());
        }

        $this->assertDatabaseHas('orgs', [
            'id' => $first->id,
            'domain' => 'Duplicate.Example',
        ]);
        $this->assertDatabaseHas('orgs', [
            'id' => $second->id,
            'domain' => 'duplicate.example',
        ]);
    }

    private function migration(): object
    {
        return require database_path('migrations/V056__org_domain_case_insensitive_identity.php');
    }
}
