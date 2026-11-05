<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Enums\UserRole;
use App\Models\DownloadLog;
use App\Models\Gallery;
use App\Models\Org;
use App\Models\Role;
use App\Models\User;
use App\Services\StatsCalculationService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StatsCalculationServiceTest extends TestCase
{
    use RefreshDatabase;

    private StatsCalculationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new StatsCalculationService;
    }

    public function test_process_domain_stats_aggregates_same_domains()
    {
        $rawStats = (object) [
            new class
            {
                public $domain = 'example.com';

                public $count = 5;
            },
            new class
            {
                public $domain = 'example.com';

                public $count = 3;
            },
        ];

        $result = $this->service->processDomainStats($rawStats);

        $this->assertCount(1, $result);
        $this->assertEquals('example.com', $result[0]['domain']);
        $this->assertEquals(8, $result[0]['count']);
    }

    public function test_process_domain_stats_sorts_by_count_descending()
    {
        $rawStats = (object) [
            new class
            {
                public $domain = 'low.com';

                public $count = 2;
            },
            new class
            {
                public $domain = 'high.com';

                public $count = 10;
            },
            new class
            {
                public $domain = 'mid.com';

                public $count = 5;
            },
        ];

        $result = $this->service->processDomainStats($rawStats);

        $this->assertEquals('high.com', $result[0]['domain']);
        $this->assertEquals('mid.com', $result[1]['domain']);
        $this->assertEquals('low.com', $result[2]['domain']);
    }

    public function test_process_domain_stats_limits_to_10_results()
    {
        $rawStats = [];
        for ($i = 1; $i <= 15; $i++) {
            $rawStats[] = new class($i)
            {
                public $domain;

                public $count;

                public function __construct($i)
                {
                    $this->domain = "domain{$i}.com";
                    $this->count = $i;
                }
            };
        }

        $result = $this->service->processDomainStats((object) $rawStats);

        $this->assertCount(10, $result);
        // Should be sorted descending by count, so highest first
        $this->assertEquals(15, $result[0]['count']);
        $this->assertEquals(6, $result[9]['count']);
    }

    public function test_process_domain_stats_converts_invite_local_to_named_label()
    {
        $rawStats = (object) [
            new class
            {
                public $domain = 'invite.local';

                public $count = 7;
            },
            new class
            {
                public $domain = 'example.com';

                public $count = 3;
            },
        ];

        $result = $this->service->processDomainStats($rawStats);

        $inviteEntry = collect($result)->firstWhere('domain', 'Benannte Invite Links');
        $this->assertNotNull($inviteEntry);
        $this->assertEquals(7, $inviteEntry['count']);
    }

    public function test_process_domain_stats_with_empty_input()
    {
        $result = $this->service->processDomainStats((object) []);

        $this->assertEquals([], $result);
    }

    public function test_get_admin_stats_calculates_total_downloads_correctly()
    {
        $this->createTestDownloadLogs();

        $result = $this->service->getAdminStats();

        // 3 single_image + 5 (from zip) = 8 total
        $this->assertEquals(8, $result['total_downloads']);
    }

    public function test_get_admin_stats_filters_by_tier()
    {
        $this->createTestDownloadLogs();

        $result = $this->service->getAdminStats('web');

        // Should only count web tier downloads
        // 2 single_image web + 3 from web zip = 5 total
        $this->assertEquals(5, $result['total_downloads']);
    }

    public function test_get_admin_stats_counts_guest_downloads()
    {
        DownloadLog::create([
            'user_id' => null,
            'item_type' => 'single_image',
            'resolution_tier' => 'web',
        ]);

        DownloadLog::create([
            'user_id' => null,
            'item_type' => 'single_image',
            'resolution_tier' => 'print',
        ]);

        DownloadLog::create([
            'user_id' => User::factory()->create()->id,
            'item_type' => 'single_image',
            'resolution_tier' => 'web',
        ]);

        $result = $this->service->getAdminStats();

        // Only the 2 with null user_id
        $this->assertEquals(2, $result['guest_downloads']);
    }

    public function test_get_admin_stats_filters_guest_downloads_by_tier()
    {
        DownloadLog::create([
            'user_id' => null,
            'item_type' => 'single_image',
            'resolution_tier' => 'web',
        ]);

        DownloadLog::create([
            'user_id' => null,
            'item_type' => 'single_image',
            'resolution_tier' => 'print',
        ]);

        $result = $this->service->getAdminStats('web');

        // Only web tier guest download
        $this->assertEquals(1, $result['guest_downloads']);
    }

    public function test_get_user_stats_calculates_total_downloads()
    {
        $user = User::factory()->create();
        $gallery = Gallery::factory()->create();

        $user->galleries()->attach($gallery->id);

        // Create some downloads for this gallery
        DownloadLog::create([
            'gallery_id' => $gallery->id,
            'user_id' => User::factory()->create()->id,
            'item_type' => 'single_image',
            'resolution_tier' => 'web',
        ]);

        DownloadLog::create([
            'gallery_id' => $gallery->id,
            'user_id' => User::factory()->create()->id,
            'item_type' => 'single_image',
            'resolution_tier' => 'web',
        ]);

        DownloadLog::create([
            'gallery_id' => $gallery->id,
            'user_id' => User::factory()->create()->id,
            'item_type' => 'single_image',
            'resolution_tier' => 'web',
        ]);

        // Create downloads for other gallery (should not be counted)
        $otherGallery = Gallery::factory()->create();
        DownloadLog::create([
            'gallery_id' => $otherGallery->id,
            'item_type' => 'single_image',
            'resolution_tier' => 'web',
        ]);

        DownloadLog::create([
            'gallery_id' => $otherGallery->id,
            'item_type' => 'single_image',
            'resolution_tier' => 'web',
        ]);

        $result = $this->service->getUserStats($user);

        $this->assertEquals(3, $result['total_downloads']);
        $this->assertEquals(1, $result['galleries_count']);
    }

    public function test_get_user_stats_includes_zip_downloads_photo_count()
    {
        $user = User::factory()->create();
        $gallery = Gallery::factory()->create();
        $user->galleries()->attach($gallery->id);

        DownloadLog::create([
            'gallery_id' => $gallery->id,
            'item_type' => 'single_image',
            'resolution_tier' => 'web',
        ]);

        DownloadLog::create([
            'gallery_id' => $gallery->id,
            'item_type' => 'full_zip',
            'resolution_tier' => 'original',
            'photo_count' => 5,
        ]);

        $result = $this->service->getUserStats($user);

        // 1 single + 5 from zip = 6 total
        $this->assertEquals(6, $result['total_downloads']);
    }

    public function test_get_user_stats_counts_guest_downloads_for_gallery()
    {
        $user = User::factory()->create();
        $gallery = Gallery::factory()->create();
        $user->galleries()->attach($gallery->id);

        DownloadLog::create([
            'gallery_id' => $gallery->id,
            'user_id' => null,
            'item_type' => 'single_image',
            'resolution_tier' => 'web',
        ]);

        DownloadLog::create([
            'gallery_id' => $gallery->id,
            'user_id' => null,
            'item_type' => 'single_image',
            'resolution_tier' => 'print',
        ]);

        $result = $this->service->getUserStats($user);

        $this->assertEquals(2, $result['guest_downloads']);
    }

    public function test_get_user_stats_filters_guest_downloads_by_tier()
    {
        $user = User::factory()->create();
        $gallery = Gallery::factory()->create();
        $user->galleries()->attach($gallery->id);

        DownloadLog::create([
            'gallery_id' => $gallery->id,
            'user_id' => null,
            'item_type' => 'single_image',
            'resolution_tier' => 'web',
        ]);

        DownloadLog::create([
            'gallery_id' => $gallery->id,
            'user_id' => null,
            'item_type' => 'single_image',
            'resolution_tier' => 'print',
        ]);

        $result = $this->service->getUserStats($user, 'web');

        $this->assertEquals(1, $result['guest_downloads']);
    }

    public function test_get_org_admin_stats_filters_by_orgs()
    {
        $org = Org::create(['name' => 'Company Inc', 'invoice_frequency' => 'immediate']);

        $manager = User::factory()->create();
        $manager->roles()->attach(Role::firstOrCreate(['name' => UserRole::ORG_ADMIN->value]));
        $manager->org_id = $org->id;
        $manager->save();

        $user1 = User::factory()->create();
        $user1->org_id = $org->id;
        $user1->save();

        $user2 = User::factory()->create();

        $gallery = Gallery::factory()->create();

        DownloadLog::create([
            'user_id' => $user1->id,
            'gallery_id' => $gallery->id,
            'item_type' => 'single_image',
            'resolution_tier' => 'web',
        ]);

        DownloadLog::create([
            'user_id' => $user1->id,
            'gallery_id' => $gallery->id,
            'item_type' => 'single_image',
            'resolution_tier' => 'web',
        ]);

        DownloadLog::create([
            'user_id' => $user1->id,
            'gallery_id' => $gallery->id,
            'item_type' => 'single_image',
            'resolution_tier' => 'web',
        ]);

        // user2 is not in the org — should be excluded
        DownloadLog::create([
            'user_id' => $user2->id,
            'gallery_id' => $gallery->id,
            'item_type' => 'single_image',
            'resolution_tier' => 'web',
        ]);

        DownloadLog::create([
            'user_id' => $user2->id,
            'gallery_id' => $gallery->id,
            'item_type' => 'single_image',
            'resolution_tier' => 'web',
        ]);

        $result = $this->service->getOrgAdminStats($manager);

        // Only user1 (org member) should be counted
        $this->assertEquals(3, $result['total_downloads']);
    }

    public function test_get_org_admin_stats_guest_downloads_always_zero()
    {
        $org = Org::create(['name' => 'Company Inc', 'invoice_frequency' => 'immediate']);

        $manager = User::factory()->create();
        $manager->roles()->attach(Role::firstOrCreate(['name' => UserRole::ORG_ADMIN->value]));
        $manager->org_id = $org->id;
        $manager->save();

        $result = $this->service->getOrgAdminStats($manager);

        $this->assertEquals(0, $result['guest_downloads']);
    }

    public function test_get_stats_for_user_delegates_to_admin_stats()
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::firstOrCreate(['name' => UserRole::ADMIN->value]));

        $result = $this->service->getStatsForUser($admin);

        $this->assertArrayHasKey('galleries_count', $result);
        $this->assertArrayHasKey('total_downloads', $result);
        $this->assertArrayHasKey('domain_stats', $result);
        $this->assertArrayHasKey('guest_downloads', $result);
        $this->assertArrayHasKey('top_galleries', $result);
    }

    public function test_get_stats_for_user_delegates_to_org_admin_stats()
    {
        $manager = User::factory()->create();
        $manager->roles()->attach(Role::firstOrCreate(['name' => UserRole::ORG_ADMIN->value]));

        $result = $this->service->getStatsForUser($manager);

        $this->assertArrayHasKey('total_downloads', $result);
        $this->assertArrayHasKey('domain_stats', $result);
    }

    public function test_get_stats_for_user_delegates_to_user_stats()
    {
        $user = User::factory()->create();

        $result = $this->service->getStatsForUser($user);

        $this->assertArrayHasKey('total_downloads', $result);
        $this->assertArrayHasKey('guest_downloads', $result);
    }

    public function test_get_admin_stats_includes_galleries_count()
    {
        Gallery::factory()->count(5)->create();

        $result = $this->service->getAdminStats();

        $this->assertEquals(5, $result['galleries_count']);
    }

    public function test_get_admin_stats_with_tier_filter_excludes_other_tiers()
    {
        DownloadLog::create([
            'item_type' => 'single_image',
            'resolution_tier' => 'web',
        ]);

        DownloadLog::create([
            'item_type' => 'single_image',
            'resolution_tier' => 'web',
        ]);

        DownloadLog::create([
            'item_type' => 'single_image',
            'resolution_tier' => 'web',
        ]);

        DownloadLog::create([
            'item_type' => 'single_image',
            'resolution_tier' => 'print',
        ]);

        DownloadLog::create([
            'item_type' => 'single_image',
            'resolution_tier' => 'print',
        ]);

        $result = $this->service->getAdminStats('web');

        $this->assertEquals(3, $result['total_downloads']);
    }

    public function test_get_admin_stats_zip_downloads_add_photo_count()
    {
        DownloadLog::create([
            'item_type' => 'full_zip',
            'resolution_tier' => 'original',
            'photo_count' => 10,
        ]);

        $result = $this->service->getAdminStats();

        $this->assertEquals(10, $result['total_downloads']);
    }

    public function test_get_admin_stats_zip_with_tier_filter()
    {
        DownloadLog::create([
            'item_type' => 'full_zip',
            'resolution_tier' => 'web',
            'photo_count' => 5,
        ]);

        DownloadLog::create([
            'item_type' => 'full_zip',
            'resolution_tier' => 'print',
            'photo_count' => 3,
        ]);

        $result = $this->service->getAdminStats('web');

        $this->assertEquals(5, $result['total_downloads']);
    }

    public function test_process_domain_stats_preserves_domain_case()
    {
        $rawStats = (object) [
            new class
            {
                public $domain = 'Example.COM';

                public $count = 5;
            },
        ];

        $result = $this->service->processDomainStats($rawStats);

        $this->assertEquals('Example.COM', $result[0]['domain']);
    }

    public function test_brand_bound_admin_stats_exclude_a_second_brand(): void
    {
        $admin = User::factory()->create(['brand' => 'srp']);
        $admin->roles()->attach(Role::firstOrCreate(['name' => UserRole::ADMIN->value]));

        $ownGallery = Gallery::factory()->create(['brand' => 'srp']);
        $foreignGallery = Gallery::factory()->create(['brand' => Brand::B2B->value]);
        $ownDownloader = User::factory()->create([
            'brand' => 'srp',
            'email' => 'owner@example.com',
        ]);
        $foreignDownloader = User::factory()->create([
            'brand' => Brand::B2B->value,
            'email' => 'other@foreign.example',
        ]);

        DownloadLog::create([
            'gallery_id' => $ownGallery->id,
            'user_id' => $ownDownloader->id,
            'gallery_name_snapshot' => 'OWN',
            'item_type' => 'single_image',
            'resolution_tier' => 'web',
        ]);
        DownloadLog::create([
            'gallery_id' => $ownGallery->id,
            'user_id' => null,
            'item_type' => 'full_zip',
            'gallery_name_snapshot' => 'OWN',
            'resolution_tier' => 'original',
            'photo_count' => 2,
        ]);
        DownloadLog::create([
            'gallery_id' => $foreignGallery->id,
            'user_id' => $foreignDownloader->id,
            'gallery_name_snapshot' => 'FOREIGN',
            'item_type' => 'single_image',
            'resolution_tier' => 'web',
        ]);
        DownloadLog::create([
            'gallery_id' => $foreignGallery->id,
            'user_id' => null,
            'item_type' => 'full_zip',
            'gallery_name_snapshot' => 'FOREIGN',
            'resolution_tier' => 'original',
            'photo_count' => 3,
        ]);

        $result = $this->service->getStatsForUser($admin);

        $this->assertSame(1, $result['galleries_count']);
        $this->assertSame(3, $result['total_downloads']);
        $this->assertSame(1, $result['guest_downloads']);
        $this->assertSame('OWN', $result['top_galleries']->first()->name);
        $this->assertCount(1, $result['domain_stats']);
        $this->assertSame('example.com', $result['domain_stats'][0]['domain']);
    }

    public function test_null_brand_super_admin_stats_include_both_brands(): void
    {
        $superAdmin = User::factory()->create(['brand' => null]);
        $superAdmin->roles()->attach(Role::firstOrCreate(['name' => UserRole::SUPER_ADMIN->value]));

        $firstGallery = Gallery::factory()->create(['brand' => Brand::B2B->value]);
        $secondGallery = Gallery::factory()->create(['brand' => 'srp']);

        DownloadLog::create([
            'gallery_id' => $firstGallery->id,
            'gallery_name_snapshot' => 'FIRST',
            'item_type' => 'single_image',
            'resolution_tier' => 'web',
        ]);
        DownloadLog::create([
            'gallery_id' => $secondGallery->id,
            'gallery_name_snapshot' => 'SECOND',
            'item_type' => 'full_zip',
            'resolution_tier' => 'original',
            'photo_count' => 5,
        ]);

        $result = $this->service->getStatsForUser($superAdmin);

        $this->assertSame(2, $result['galleries_count']);
        $this->assertSame(6, $result['total_downloads']);
        $this->assertEqualsCanonicalizing(
            ['SECOND', 'FIRST'],
            $result['top_galleries']->pluck('name')->all(),
        );
    }

    public function test_domain_stats_are_identical_across_batch_sizes(): void
    {
        $gallery = Gallery::factory()->create();
        $alpha = User::factory()->create(['email' => 'alpha@example.com']);
        $beta = User::factory()->create(['email' => 'beta@example.com']);
        $gamma = User::factory()->create(['email' => 'gamma@other.test']);
        $invited = User::factory()->create(['email' => 'guest@invite.local']);

        foreach ([[$alpha, 3], [$beta, 1], [$gamma, 2], [$invited, 1]] as [$downloader, $downloads]) {
            for ($i = 0; $i < $downloads; $i++) {
                DownloadLog::create([
                    'gallery_id' => $gallery->id,
                    'user_id' => $downloader->id,
                    'item_type' => 'single_image',
                    'resolution_tier' => 'web',
                ]);
            }
        }

        $expected = [
            ['domain' => 'example.com', 'count' => 4],
            ['domain' => 'other.test', 'count' => 2],
            ['domain' => 'Benannte Invite Links', 'count' => 1],
        ];

        $singleBatch = $this->service->getAdminStats();
        $manyBatches = (new StatsCalculationService(2))->getAdminStats();

        $this->assertSame($expected, $singleBatch['domain_stats']);
        $this->assertSame($expected, $manyBatches['domain_stats']);
        $this->assertSame($singleBatch['domain_stats'], $manyBatches['domain_stats']);
    }

    public function test_domain_stats_preserve_legacy_email_without_at_sign(): void
    {
        $gallery = Gallery::factory()->create();
        $user = User::factory()->create(['email' => 'legacy-address-without-at-sign']);

        DownloadLog::create([
            'gallery_id' => $gallery->id,
            'user_id' => $user->id,
            'item_type' => 'single_image',
            'resolution_tier' => 'web',
        ]);

        $result = $this->service->getAdminStats();

        $this->assertSame([
            ['domain' => 'legacy-address-without-at-sign', 'count' => 1],
        ], $result['domain_stats']);
    }

    public function test_domain_stats_are_aggregated_in_bounded_batches(): void
    {
        $gallery = Gallery::factory()->create();
        $downloader = User::factory()->create(['email' => 'batch@example.com']);

        for ($i = 0; $i < 7; $i++) {
            DownloadLog::create([
                'gallery_id' => $gallery->id,
                'user_id' => $downloader->id,
                'item_type' => 'single_image',
                'resolution_tier' => 'web',
            ]);
        }

        $batchSize = 2;
        $batchQueries = [];

        DB::listen(function (QueryExecuted $query) use (&$batchQueries): void {
            if (str_contains($query->sql, 'download_logs') && str_contains($query->sql, 'email')) {
                $batchQueries[] = $query->sql;
            }
        });

        try {
            $result = (new StatsCalculationService($batchSize))->getAdminStats();
        } finally {
            DB::getEventDispatcher()->forget(QueryExecuted::class);
        }

        $this->assertSame(
            [['domain' => 'example.com', 'count' => 7]],
            $result['domain_stats'],
        );

        // Seven rows need four queries of at most two rows each; a regression to
        // a single materializing query would fail the count and the limit check.
        $this->assertGreaterThanOrEqual(4, count($batchQueries));
        foreach ($batchQueries as $sql) {
            $this->assertStringEndsWith('limit '.$batchSize, strtolower(trim($sql)));
        }
    }

    public function test_user_stats_domain_counts_survive_batching_and_gallery_scope(): void
    {
        $user = User::factory()->create();
        $gallery = Gallery::factory()->create();
        $foreignGallery = Gallery::factory()->create();
        $user->galleries()->attach($gallery->id);

        $alpha = User::factory()->create(['email' => 'alpha@example.com']);
        $beta = User::factory()->create(['email' => 'beta@other.test']);
        $foreign = User::factory()->create(['email' => 'foreign@excluded.test']);

        foreach ([[$gallery, $alpha, 2], [$gallery, $beta, 1], [$foreignGallery, $foreign, 4]] as [$target, $downloader, $downloads]) {
            for ($i = 0; $i < $downloads; $i++) {
                DownloadLog::create([
                    'gallery_id' => $target->id,
                    'user_id' => $downloader->id,
                    'item_type' => 'single_image',
                    'resolution_tier' => 'web',
                ]);
            }
        }

        $result = (new StatsCalculationService(1))->getUserStats($user);

        $this->assertSame([
            ['domain' => 'example.com', 'count' => 2],
            ['domain' => 'other.test', 'count' => 1],
        ], $result['domain_stats']);
    }

    private function createTestDownloadLogs(): void
    {
        $gallery = Gallery::factory()->create();

        // Single image downloads: 2 web, 1 print
        DownloadLog::create([
            'gallery_id' => $gallery->id,
            'item_type' => 'single_image',
            'resolution_tier' => 'web',
        ]);

        DownloadLog::create([
            'gallery_id' => $gallery->id,
            'item_type' => 'single_image',
            'resolution_tier' => 'web',
        ]);

        DownloadLog::create([
            'gallery_id' => $gallery->id,
            'item_type' => 'single_image',
            'resolution_tier' => 'print',
        ]);

        // Zip downloads: 1 web with 3 photos, 1 print with 2 photos
        DownloadLog::create([
            'gallery_id' => $gallery->id,
            'item_type' => 'full_zip',
            'resolution_tier' => 'web',
            'photo_count' => 3,
        ]);

        DownloadLog::create([
            'gallery_id' => $gallery->id,
            'item_type' => 'full_zip',
            'resolution_tier' => 'print',
            'photo_count' => 2,
        ]);
    }
}
