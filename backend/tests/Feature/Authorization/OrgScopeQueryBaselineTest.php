<?php

namespace Tests\Feature\Authorization;

use App\Enums\Brand;
use App\Enums\UserRole;
use App\Models\Gallery;
use App\Models\GalleryGroup;
use App\Models\Org;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * BASELINE — the measured query cost of two read endpoints. NOT a target.
 *
 * `AuthorizationService::getOrgGroupScope()` is not cached: every call reads
 * the `gallery_group_org` pivot and walks the group subtree again. The board
 * wants the current cost pinned BEFORE the scope is optimised, so that the
 * optimisation can be measured against a number instead of a feeling.
 *
 * The pinned numbers are the MEASURED BASELINE of the small, fixed fixture of
 * `fixture()`. They are deliberately not a budget and not a correctness
 * requirement: a change of the query count turns this test red on purpose,
 * so that every change of the cost becomes a visible, reviewed decision. A
 * deliberate optimisation of the scope is expected to change these numbers
 * and to update this test in the same change.
 *
 * The count is deterministic: one fixed fixture, no timing, no parallel
 * workers. The statements are counted with `DB::listen()` — the mechanism
 * this repository already uses for query-count assertions (see
 * `AuthorizationServiceTest` and `OrgAdminDelegationBoundaryTest`).
 */
class OrgScopeQueryBaselineTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The measured number of database statements of `GET /api/auth/me`.
     */
    private const ME_STATEMENTS = 30;

    /**
     * The measured number of `getOrgGroupScope()` reads inside
     * `GET /api/auth/me`.
     */
    private const ME_SCOPE_READS = 3;

    /**
     * The measured number of database statements of
     * `GET /api/management/orgs/{id}`.
     */
    private const ORG_DETAIL_STATEMENTS = 29;

    /**
     * The measured number of `getOrgGroupScope()` reads inside
     * `GET /api/management/orgs/{id}`.
     */
    private const ORG_DETAIL_SCOPE_READS = 2;

    /**
     * `GET /api/auth/me` — the endpoint that answers "who am I and what may
     * I see", and therefore the endpoint every authenticated request of the
     * frontend starts with.
     */
    public function test_me_endpoint_query_count_is_pinned(): void
    {
        [$org, $user] = $this->fixture();

        $statements = $this->collectStatements(function () use ($user): void {
            $this->withHeaders(['Authorization' => 'Bearer '.auth('api')->login($user)])
                ->getJson('/api/auth/me')
                ->assertOk();
        });

        $this->assertSame(
            self::ME_STATEMENTS,
            count($statements),
            'The measured baseline of GET /api/auth/me changed. A deliberate change of the query count must be '
            .'updated here in the same change — this pin is a baseline, not a target.'
        );

        $this->assertSame(
            self::ME_SCOPE_READS,
            $this->countScopeReads($statements),
            'The measured number of getOrgGroupScope() reads in GET /api/auth/me changed. The method is not '
            .'cached, so this is the number of times the org scope was resolved for one request.'
        );
    }

    /**
     * `GET /api/management/orgs/{id}` — the org detail endpoint of the
     * management surface, which resolves the same org scope for the
     * assignment options of an org admin.
     */
    public function test_org_detail_endpoint_query_count_is_pinned(): void
    {
        [$org, $user] = $this->fixture();

        $statements = $this->collectStatements(function () use ($org, $user): void {
            $this->withHeaders(['Authorization' => 'Bearer '.auth('api')->login($user)])
                ->getJson('/api/management/orgs/'.$org->id)
                ->assertOk();
        });

        $this->assertSame(
            self::ORG_DETAIL_STATEMENTS,
            count($statements),
            'The measured baseline of GET /api/management/orgs/{id} changed. A deliberate change of the query '
            .'count must be updated here in the same change — this pin is a baseline, not a target.'
        );

        $this->assertSame(
            self::ORG_DETAIL_SCOPE_READS,
            $this->countScopeReads($statements),
            'The measured number of getOrgGroupScope() reads in GET /api/management/orgs/{id} changed. The method '
            .'is not cached, so this is the number of times the org scope was resolved for one request.'
        );
    }

    /**
     * The statements of one request, counted with `DB::listen()`.
     *
     * @return array<int, string>
     */
    private function collectStatements(callable $callback): array
    {
        $statements = [];

        DB::listen(function (QueryExecuted $query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $callback();

        DB::getEventDispatcher()->forget(QueryExecuted::class);

        return $statements;
    }

    /**
     * The statements that `AuthorizationService::getOrgGroupScope()` issues.
     *
     * The method reads the `gallery_group_org` pivot exactly once per call
     * and is not cached, so the number of those statements is the number of
     * calls. The eager load of the org's groups reads the same pivot table
     * with a join and is therefore not counted here.
     *
     * @param  array<int, string>  $statements
     */
    private function countScopeReads(array $statements): int
    {
        return count(array_filter(
            $statements,
            fn (string $sql): bool => str_contains($sql, 'from "gallery_group_org"')
        ));
    }

    /**
     * A small, fixed org fixture:
     *
     * - one org with one pivot gallery group,
     * - one child group below that pivot group,
     * - one delivery gallery in the child group, attached to the org,
     * - one org admin of that org.
     *
     * The org admin logs in through the guard before the request, so the
     * pinned count is the cost of the endpoint itself and not of the
     * token-to-user lookup.
     *
     * @return array{0: Org, 1: User}
     */
    private function fixture(): array
    {
        $org = Org::factory()->create([
            'brand' => Brand::B2B,
            'invoice_frequency' => 'immediate',
        ]);

        $root = GalleryGroup::factory()->create([
            'brand' => Brand::B2B,
            'parent_id' => null,
        ]);
        $child = GalleryGroup::factory()->create([
            'brand' => Brand::B2B,
            'parent_id' => $root->id,
        ]);
        $org->galleryGroups()->attach($root->id);

        $gallery = Gallery::factory()->create([
            'brand' => Brand::B2B,
            'gallery_group_id' => $child->id,
            'type' => 'delivery',
        ]);
        $gallery->orgs()->attach($org->id);

        $user = User::factory()->create([
            'brand' => Brand::B2B,
            'org_id' => $org->id,
        ]);
        $user->roles()->attach(Role::firstOrCreate(['name' => UserRole::ORG_ADMIN->value]));

        return [$org, $user];
    }
}
