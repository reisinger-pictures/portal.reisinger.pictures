<?php

namespace Tests\Feature\Authorization;

use App\Enums\Brand;
use App\Enums\UserRole;
use App\Models\Gallery;
use App\Models\GalleryGroup;
use App\Models\Org;
use App\Models\Role;
use App\Models\User;
use App\Services\AuthorizationService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * BASELINE — the measured query cost of two read endpoints. NOT a target.
 *
 * `AuthorizationService::getOrgGroupScope()` resolves the scope of an
 * organization once per request: the `gallery_group_org` pivot is read and
 * the group subtree is walked once, and the result is memoized for the
 * duration of the request.
 *
 * The memo lives in its own scoped container binding
 * (`authorization.org_group_scope`, registered in `AppServiceProvider`) and
 * not in the service instance — so that the invalidation reaches every
 * service instance, including one that was constructed directly. The store
 * is dropped with the other scoped instances: before every queue job
 * (`QueueServiceProvider` → `forgetScopedInstances()`) and, for HTTP, by the
 * fresh application instance that php-fpm builds per request. The scope is
 * never cached across requests.
 *
 * The board wants the current cost pinned so that every change of the cost
 * becomes a visible, reviewed decision. The pinned numbers are the MEASURED
 * BASELINE of the small, fixed fixture of `fixture()`. They are deliberately
 * not a budget and not a correctness requirement: a change of the query count
 * turns this test red on purpose, so that every change of the cost becomes a
 * visible, reviewed decision. A deliberate optimisation of the scope is
 * expected to change these numbers and to update this test in the same change.
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
    private const ME_STATEMENTS = 22;

    /**
     * The measured number of `getOrgGroupScope()` reads inside
     * `GET /api/auth/me`.
     */
    private const ME_SCOPE_READS = 1;

    /**
     * The measured number of database statements of
     * `GET /api/management/orgs/{id}`.
     */
    private const ORG_DETAIL_STATEMENTS = 25;

    /**
     * The measured number of `getOrgGroupScope()` reads inside
     * `GET /api/management/orgs/{id}`.
     */
    private const ORG_DETAIL_SCOPE_READS = 1;

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
            'The measured number of getOrgGroupScope() reads in GET /api/auth/me changed. The scope is resolved '
            .'once per request and memoized for that request, so this is the number of times the org scope '
            .'was read for one request.'
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
            'The measured number of getOrgGroupScope() reads in GET /api/management/orgs/{id} changed. The '
            .'scope is resolved once per request and memoized for that request, so this is the number of '
            .'times the org scope was read for one request.'
        );
    }

    /**
     * A write that revokes a delegation must drop the memoized scope.
     *
     * The memo of `AuthorizationService::getOrgGroupScope()` is only valid
     * while the pivot and the group tree stay unchanged. A new descendant
     * extends the scope, a detached pivot group revokes it — and the scope
     * that is read afterwards must reflect the write, not the memo.
     */
    public function test_a_write_to_the_pivot_or_to_the_group_tree_drops_the_memoized_scope(): void
    {
        [$org, $user] = $this->fixture();
        $service = app(AuthorizationService::class);

        $pivotGroupId = (string) $org->galleryGroups()->first()->id;

        // The pivot group and its child.
        $this->assertCount(2, $service->getOrgGroupScope($org));

        // A new descendant extends the subtree of the pivot group.
        GalleryGroup::factory()->create([
            'brand' => Brand::B2B,
            'parent_id' => $pivotGroupId,
        ]);
        $this->assertCount(
            3,
            $service->getOrgGroupScope($org),
            'A new descendant of the pivot group must extend the scope — a memoized scope would hide the write.'
        );

        // A detached pivot group revokes the delegation.
        $org->galleryGroups()->detach($pivotGroupId);
        $this->assertSame(
            [],
            $service->getOrgGroupScope($org),
            'A detached pivot group must revoke the scope — a memoized scope would hand out a revoked delegation.'
        );
    }

    /**
     * The memo must not outlive the request.
     *
     * The service is a scoped container binding: the container shares one
     * instance inside a request and drops it with the other scoped instances
     * — a new application instance per HTTP request and
     * `forgetScopedInstances()` before every queue job.
     */
    public function test_the_memoized_scope_does_not_outlive_the_scoped_service_instance(): void
    {
        [$org, $user] = $this->fixture();

        $service = app(AuthorizationService::class);

        $this->assertSame(
            $service,
            app(AuthorizationService::class),
            'The authorization service must be shared inside a request — otherwise the memo is never hit.'
        );

        app()->forgetScopedInstances();

        $this->assertNotSame(
            $service,
            app(AuthorizationService::class),
            'The scoped instance must be dropped with the request — otherwise a scope would survive it.'
        );
    }

    /**
     * A directly constructed service must not hold a stale scope.
     *
     * The memo lives in the request-scoped store of the container and not in
     * the service instance, so the invalidation reaches every instance —
     * including one that was constructed directly (`new AuthorizationService`)
     * and therefore is not the container's shared instance.
     */
    public function test_a_directly_constructed_service_does_not_hold_a_stale_scope(): void
    {
        [$org, $user] = $this->fixture();

        $shared = app(AuthorizationService::class);
        $direct = new AuthorizationService;

        $pivotGroupId = (string) $org->galleryGroups()->first()->id;

        // Both instances read the same scope.
        $this->assertCount(2, $shared->getOrgGroupScope($org));
        $this->assertCount(2, $direct->getOrgGroupScope($org));

        // The write revokes the delegation.
        $org->galleryGroups()->detach($pivotGroupId);

        $this->assertSame([], $shared->getOrgGroupScope($org));
        $this->assertSame(
            [],
            $direct->getOrgGroupScope($org),
            'A directly constructed service must not keep a stale scope: the memo lives in the shared store.'
        );
    }

    /**
     * The classifier of the scope write guard.
     *
     * It has to recognise every write shape against the two tables that carry
     * the scope — in any case — and nothing else.
     */
    public function test_the_scope_write_classifier_covers_every_write_shape(): void
    {
        $mutating = [
            'insert into "gallery_group_org" ("org_id", "gallery_group_id") values (?, ?)',
            'delete from "gallery_group_org" where "org_id" = ?',
            'update "gallery_groups" set "parent_id" = ? where "id" = ?',
            'insert into "gallery_groups" ("id") values (?)',
            'delete from "gallery_groups" where "id" = ?',
            'truncate table "gallery_group_org"',
            'INSERT INTO GALLERY_GROUP_ORG (ORG_ID, GALLERY_GROUP_ID) VALUES (?, ?)',
            'UPDATE GALLERY_GROUPS SET PARENT_ID = ? WHERE ID = ?',
        ];

        foreach ($mutating as $sql) {
            $this->assertTrue(
                AuthorizationService::mutatesOrgGroupScope($sql),
                "The statement must be recognised as a scope write: {$sql}"
            );
        }

        $notMutating = [
            'select * from "gallery_group_org" where "org_id" = ?',
            'select "id", "parent_id", "brand" from "gallery_groups" where "id" in (?)',
            'insert into "user_gallery_groups" ("user_id", "gallery_group_id") values (?, ?)',
            'delete from "user_gallery_groups" where "user_id" = ?',
            'select * from "galleries" where "id" in (?, ?) and "brand" = ?',
        ];

        foreach ($notMutating as $sql) {
            $this->assertFalse(
                AuthorizationService::mutatesOrgGroupScope($sql),
                "The statement must not be recognised as a scope write: {$sql}"
            );
        }
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
     * and is memoized for the request, so the number of those statements is
     * the number of calls. The eager load of the org's groups reads the same
     * pivot table with a join and is therefore not counted here.
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
