<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Enums\UserRole;
use App\Models\Gallery;
use App\Models\GalleryGroup;
use App\Models\GalleryInvite;
use App\Models\InvoiceSnapshot;
use App\Models\Order;
use App\Models\Photo;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Pricing\ScopeLicensingStrategy;
use App\Services\AuthorizationService;
use App\Services\CheckoutService;
use App\Services\GalleryTreeService;
use App\Services\ImageProcessor;
use App\Services\OfferTokenService;
use App\Services\QuoteLinkService;
use App\Support\BrandRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Factory;
use PHPOpenSourceSaver\JWTAuth\JWTAuth;
use Tests\TestCase;

/**
 * Focused regressions for the media verifier follow-ups.
 */
class MediaVerifierFollowUpsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // File cache, lock keys, and the fake photo disk are process-global in
        // this test suite.  Reset all three so a stale derivative or watermark
        // bucket from a previous test cannot decide this test's result.
        Cache::flush();
        BrandRegistry::clearCache();
        // These regressions exercise DB/media boundaries, not the search index;
        // use Scout's no-op engine so the test is independent of Meilisearch.
        config(['scout.driver' => 'null']);
        $photoRoot = storage_path(
            'framework/testing/disks/photos-'.(string) Str::uuid(),
        );
        Storage::set('photos', Storage::build([
            'driver' => 'local',
            'root' => $photoRoot,
            'throw' => false,
        ]));
        BrandRegistry::set(Brand::B2B);
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_cr_be_021_stale_reencoded_derivative_is_not_accepted_without_watermark_provenance(): void
    {
        if (! function_exists('imagecreatetruecolor') || ! function_exists('imagepng')) {
            $this->markTestSkipped('GD extension is not available.');
        }

        $this->createWatermarkAssets();

        $disk = Storage::disk('photos');
        $source = $disk->path('media/source.jpg');
        $stale = $disk->path('media/stale.jpg');
        $generated = $disk->path('media/generated.jpg');
        $fixture = file_get_contents(base_path('tests/Fixtures/sample.jpg'));
        $disk->put('media/source.jpg', $fixture);

        $processor = app(ImageProcessor::class);
        $this->assertTrue($processor->scaleImage($source, $stale, 2000));
        $this->assertFalse($processor->isSafeWatermarkedOutput($source, $stale));

        $this->assertTrue($processor->applyCenteredWatermark($source, $generated, 2000));
        $this->assertTrue($processor->isSafeWatermarkedOutput($source, $generated));
    }

    public function test_cr_be_021_file_delivery_rebuilds_a_stale_unwatermarked_cache_entry(): void
    {
        if (! function_exists('imagecreatetruecolor') || ! function_exists('imagepng')) {
            $this->markTestSkipped('GD extension is not available.');
        }

        $this->createWatermarkAssets();
        $gallery = Gallery::factory()->create([
            'type' => 'delivery',
            'is_public' => false,
            'is_free_download' => false,
        ]);
        $user = User::factory()->create(['flatrate_level' => 'none']);
        $user->galleries()->attach($gallery);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);

        $disk = Storage::disk('photos');
        $source = $disk->path($gallery->id.'/'.$photo->filename);
        $stale = $disk->path($gallery->id.'/_watermarked/'.$photo->filename);
        $fixture = file_get_contents(base_path('tests/Fixtures/sample.jpg'));
        $disk->put($gallery->id.'/'.$photo->filename, $fixture);
        $disk->makeDirectory($gallery->id.'/_watermarked');
        $this->assertTrue(app(ImageProcessor::class)->scaleImage($source, $stale, 2000));

        $response = $this->actingAs($user, 'api')
            ->get('/api/media/'.$gallery->slug.'/watermarked/'.$photo->id.'.jpg')
            ->assertOk();
        $this->assertNotSame($fixture, file_get_contents($response->getFile()->getPathname()));
        $this->assertTrue($disk->exists($gallery->id.'/_watermarked/'.$photo->filename.'.watermark.json'));
    }

    public function test_cr_media_001_gallery_zip_rebuilds_a_stale_unwatermarked_cache_entry(): void
    {
        if (! function_exists('imagecreatetruecolor') || ! function_exists('imagepng')) {
            $this->markTestSkipped('GD extension is not available.');
        }

        $this->createWatermarkAssets();
        $gallery = Gallery::factory()->create([
            'brand' => Brand::B2B,
            'type' => 'delivery',
            'is_public' => false,
            'is_free_download' => false,
        ]);
        $user = User::factory()->create([
            'brand' => Brand::B2B,
            'flatrate_level' => 'web',
        ]);
        $user->galleries()->attach($gallery);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);

        $disk = Storage::disk('photos');
        $sourceRelative = $gallery->id.'/'.$photo->filename;
        $watermarkedRelative = $gallery->id.'/_watermarked/'.$photo->filename;
        $sourcePath = $disk->path($sourceRelative);
        $stalePath = $disk->path($watermarkedRelative);
        $fixture = file_get_contents(base_path('tests/Fixtures/sample.jpg'));
        $disk->put($sourceRelative, $fixture);
        $disk->makeDirectory($gallery->id.'/_watermarked');

        $processor = app(ImageProcessor::class);
        $this->assertTrue($processor->scaleImage($sourcePath, $stalePath, 2000));
        $staleHash = hash_file('sha256', $stalePath);
        $this->assertIsString($staleHash);
        $this->assertFalse($processor->isSafeWatermarkedOutput($sourcePath, $stalePath, 'delivery', 2000));

        $response = $this->actingAs($user, 'api')
            ->get('/api/galleries/'.$gallery->id.'/download-zip?tier=web')
            ->assertOk();
        $zipBytes = $this->streamResponseBytes($response);
        $this->assertStringStartsWith('PK', $zipBytes);

        $zipPath = tempnam(sys_get_temp_dir(), 'media-verifier-');
        $this->assertIsString($zipPath);
        file_put_contents($zipPath, $zipBytes);
        $archive = new \ZipArchive;
        $this->assertTrue($archive->open($zipPath) === true);
        $entry = $archive->getFromName($photo->id.'_WEB.jpg');
        $this->assertNotFalse($entry);
        $this->assertNotSame($fixture, $entry);
        $archive->close();
        @unlink($zipPath);

        $this->assertTrue($disk->exists($watermarkedRelative.'.watermark.json'));

        $rebuiltHash = hash_file('sha256', $stalePath);
        $this->assertIsString($rebuiltHash);
        $this->assertNotSame($staleHash, $rebuiltHash);
        $this->assertTrue($processor->isSafeWatermarkedOutput($sourcePath, $stalePath, 'delivery', 2000));
    }

    public function test_cr_media_002_management_tree_and_group_show_filter_parent_brand_mismatches(): void
    {
        $admin = User::factory()->create(['brand' => Brand::B2B]);
        $admin->roles()->attach(Role::firstOrCreate(['name' => UserRole::ADMIN->value]));

        $root = GalleryGroup::factory()->create(['brand' => Brand::B2B]);
        $foreignChild = GalleryGroup::factory()->create([
            'brand' => 'srp',
            'parent_id' => $root->id,
        ]);
        $foreignGallery = Gallery::factory()->create([
            'brand' => Brand::B2B,
            'gallery_group_id' => $foreignChild->id,
        ]);
        $ownChild = GalleryGroup::factory()->create([
            'brand' => Brand::B2B,
            'parent_id' => $root->id,
        ]);
        $ownGallery = Gallery::factory()->create([
            'brand' => Brand::B2B,
            'gallery_group_id' => $ownChild->id,
        ]);
        Photo::factory()->create(['gallery_id' => $ownGallery->id]);

        $tree = app(GalleryTreeService::class)->getAdminTree($admin);
        $encodedTree = json_encode($tree, JSON_THROW_ON_ERROR);
        $this->assertStringContainsString($root->id, $encodedTree);
        $this->assertStringContainsString($ownChild->id, $encodedTree);
        $this->assertStringNotContainsString($foreignChild->id, $encodedTree);
        $this->assertStringNotContainsString($foreignGallery->id, $encodedTree);

        $this->actingAs($admin, 'api')
            ->getJson('/api/management/gallery-groups/'.$root->id)
            ->assertOk()
            ->assertJsonCount(1, 'photos')
            ->assertJsonPath('photos.0.gallery_id', $ownGallery->id)
            ->assertJsonPath('group.children.0.id', $ownChild->id);

        $foreignParent = GalleryGroup::factory()->create(['brand' => 'srp']);
        $nestedOwnChild = GalleryGroup::factory()->create([
            'brand' => Brand::B2B,
            'parent_id' => $foreignParent->id,
        ]);
        $nestedGallery = Gallery::factory()->create([
            'brand' => Brand::B2B,
            'gallery_group_id' => $nestedOwnChild->id,
        ]);
        $authorization = app(AuthorizationService::class);
        $this->assertFalse($authorization->canManageGalleryGroup($admin, $nestedOwnChild));
        $this->assertFalse($authorization->canManageGallery($admin, $nestedGallery->id));
        $this->assertFalse($authorization->canAccessGallery($admin, $nestedGallery->id));
        $this->actingAs($admin, 'api')
            ->getJson('/api/management/gallery-groups/'.$nestedOwnChild->id)
            ->assertForbidden();
    }

    public function test_cr_media_002_license_terms_ignore_a_gallery_with_a_foreign_parent(): void
    {
        Setting::updateOrCreate(
            ['key' => 'pricing_strategy', 'brand' => Brand::B2B->value],
            ['value' => 'scope_licensing'],
        );

        foreach (['srp', null] as $groupBrand) {
            $group = GalleryGroup::factory()->create(['brand' => $groupBrand]);
            $gallery = Gallery::factory()->create([
                'brand' => Brand::B2B,
                'gallery_group_id' => $group->id,
                'licensing_mode' => 'volume_licensing',
            ]);

            $this->getJson('/api/settings/license-terms?gallery_id='.$gallery->id)
                ->assertOk()
                ->assertJsonPath('pricing_strategy', 'scope_licensing')
                ->assertJsonPath('volume_pricing', null);
        }

        // A legacy null-brand gallery without a group keeps the established
        // public fallback; the new parent-chain check must not change that
        // unrelated compatibility behavior.
        $legacyGallery = Gallery::factory()->create([
            'brand' => null,
            'licensing_mode' => 'volume_licensing',
        ]);
        $this->getJson('/api/settings/license-terms?gallery_id='.$legacyGallery->id)
            ->assertOk()
            ->assertJsonPath('pricing_strategy', 'volume_licensing');
    }

    public function test_cr_media_003_parent_brand_mismatch_blocks_invite_finish_rating_and_image_sitemap(): void
    {
        Mail::fake();

        foreach (['srp', null] as $groupBrand) {
            $group = GalleryGroup::factory()->create(['brand' => $groupBrand]);
            $gallery = Gallery::factory()->create([
                'brand' => Brand::B2B,
                'gallery_group_id' => $group->id,
                'type' => 'selection',
                'is_public' => false,
            ]);
            $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
            $userCountBeforeActions = User::query()->count();
            $invite = GalleryInvite::create([
                'gallery_id' => $gallery->id,
                'token' => 'parent-'.($groupBrand ?? 'null').'-'.Str::random(8),
            ]);

            $this->withToken($this->guestToken($gallery->id))
                ->postJson('/api/galleries/'.$gallery->id.'/finish-rating')
                ->assertNotFound();
            $this->getJson('/api/invites/'.$invite->token)->assertNotFound();
            $this->postJson('/api/invites/redeem', [
                'token' => $invite->token,
                'accept_privacy' => true,
            ])->assertNotFound();

            $imageSitemap = $this->get('/api/sitemap-images.xml')->assertOk()->getContent();
            $this->assertStringNotContainsString($photo->filename, $imageSitemap);
            $this->assertSame($userCountBeforeActions, User::query()->count());
        }

        $this->assertDatabaseCount('ratings', 0);
        Mail::assertNothingQueued();
    }

    public function test_cr_media_003_parent_brand_mismatch_blocks_legacy_selection_zip_boundaries(): void
    {
        foreach (['srp', null] as $groupBrand) {
            $group = GalleryGroup::factory()->create(['brand' => $groupBrand]);
            $gallery = Gallery::factory()->create([
                'brand' => Brand::B2B,
                'gallery_group_id' => $group->id,
                'type' => 'selection',
            ]);
            // Simulate a legacy row that bypassed the model/service invariant.
            DB::table('galleries')->whereKey($gallery->id)->update([
                'is_public' => true,
                'is_free_download' => true,
            ]);
            $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
            $user = User::factory()->create([
                'brand' => Brand::B2B,
                'flatrate_level' => 'original',
            ]);
            $user->galleries()->attach($gallery);
            Storage::disk('photos')->put(
                $gallery->id.'/'.$photo->filename,
                file_get_contents(base_path('tests/Fixtures/sample.jpg')),
            );

            $this->actingAs($user, 'api')
                ->getJson('/api/galleries/'.$gallery->id.'/download-zip?tier=original')
                ->assertNotFound();

            $order = Order::factory()->paid()->create([
                'user_id' => $user->id,
                'brand' => Brand::B2B,
            ]);
            InvoiceSnapshot::create([
                'order_id' => $order->id,
                'invoice_number' => 'PARENT-'.strtoupper(Str::random(8)),
                'brand' => Brand::B2B,
                'customer_details' => [
                    'items' => [
                        ['photoId' => $photo->id, 'tier' => 'original', 'price' => 1000],
                    ],
                ],
                'total_net' => 1000,
                'total_gross' => 1000,
                'tax_rate' => 0,
            ]);

            $this->actingAs($user, 'api')
                ->getJson('/api/orders/'.$order->id.'/download-zip')
                ->assertNotFound();
        }

        $this->assertDatabaseCount('download_logs', 0);
    }

    public function test_cr_be_022_current_brand_gallery_under_foreign_or_null_group_is_rejected_by_public_boundaries(): void
    {
        foreach (['srp', null] as $groupBrand) {
            $group = GalleryGroup::factory()->create([
                'brand' => $groupBrand,
                'slug' => 'group-'.($groupBrand ?? 'null'),
            ]);
            $gallery = Gallery::factory()->create([
                'brand' => Brand::B2B,
                'gallery_group_id' => $group->id,
                'type' => 'delivery',
                'is_public' => true,
                'is_free_download' => true,
            ]);
            $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);

            $this->getJson('/api/galleries/'.$gallery->slug)->assertNotFound();
            $this->getJson('/api/media/'.$gallery->slug.'/'.$photo->id.'.jpg')->assertNotFound();
            $this->getJson('/api/photos/'.$photo->id.'/download')->assertNotFound();

            $ratingGallery = Gallery::factory()->create([
                'brand' => Brand::B2B,
                'gallery_group_id' => $group->id,
                'type' => 'selection',
            ]);
            $ratingPhoto = Photo::factory()->create(['gallery_id' => $ratingGallery->id]);
            $user = User::factory()->create(['brand' => Brand::B2B]);
            $user->galleries()->attach($ratingGallery);
            $token = auth('api')->login($user);
            $this->withToken($token)
                ->postJson('/api/photos/'.$ratingPhoto->id.'/rate', ['rating' => 5])
                ->assertNotFound();

            $this->assertDatabaseCount('ratings', 0);
            $this->getJson('/api/search?q=')->assertOk()
                ->assertJsonPath('galleries', []);
            $sitemap = $this->get('/api/sitemap-galleries.xml')->assertOk()->getContent();
            $this->assertStringNotContainsString(htmlspecialchars($gallery->full_path), $sitemap);
        }
    }

    public function test_cr_be_023_finish_rating_and_invite_check_redeem_are_host_brand_bound(): void
    {
        Mail::fake();

        foreach (['srp', null] as $brand) {
            $gallery = Gallery::factory()->create([
                'brand' => $brand,
                'type' => 'selection',
                'is_public' => false,
            ]);
            $guestToken = $this->guestToken($gallery->id);

            $this->withToken($guestToken)
                ->postJson('/api/galleries/'.$gallery->id.'/finish-rating')
                ->assertNotFound();

            $invite = GalleryInvite::create([
                'gallery_id' => $gallery->id,
                'token' => 'foreign-'.($brand ?? 'null'),
            ]);

            $this->getJson('/api/invites/'.$invite->token)->assertNotFound();
            $this->postJson('/api/invites/redeem', [
                'token' => $invite->token,
                'accept_privacy' => true,
            ])->assertNotFound();

            $this->assertDatabaseCount('users', 0);
            $this->assertDatabaseCount('ratings', 0);
        }

        Mail::assertNothingQueued();
    }

    public function test_cr_crm_010_selection_gallery_cannot_be_quoted_or_checked_out(): void
    {
        $gallery = Gallery::factory()->create([
            'type' => 'selection',
            'is_public' => true,
            'is_free_download' => true,
        ]);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);

        $admin = User::factory()->create(['brand' => Brand::B2B]);
        $admin->roles()->attach(Role::firstOrCreate(['name' => UserRole::ADMIN->value]));

        $quoteError = null;
        try {
            app(QuoteLinkService::class)->generateQuoteLink(
                [$photo->id],
                10000,
                issuer: $admin,
            );
        } catch (\InvalidArgumentException $exception) {
            $quoteError = $exception;
        }
        $this->assertNotNull($quoteError);

        $adminToken = auth('api')->login($admin);
        $this->withToken($adminToken)
            ->postJson('/api/management/orders/quote-link', [
                'photo_ids' => [$photo->id],
                'custom_price' => 10000,
            ])
            ->assertUnprocessable();

        $token = app(OfferTokenService::class)->issueQuote(
            [$photo->id],
            10000,
            brand: Brand::B2B->value,
            expiresAt: now()->addDay(),
        );
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $service = new CheckoutService(new ScopeLicensingStrategy);

        $quoteResponse = $service->processCheckout(
            $this->checkoutRequest($photo->id, ['quote_token' => $token]),
            $user,
            'invoice',
        );
        $this->assertSame(422, $quoteResponse->status());

        $cartResponse = $service->processCheckout(
            $this->checkoutRequest($photo->id),
            $user,
            'invoice',
        );
        $this->assertSame(422, $cartResponse->status());
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_cr_crm_010_selection_gallery_cannot_be_downloaded_by_flat_rate_or_exposed_in_sitemaps(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        $gallery = Gallery::factory()->create([
            'type' => 'selection',
            'is_public' => true,
            'is_free_download' => true,
        ]);
        // Simulate a legacy/direct database write that bypasses the model
        // saving guard.  The type/effective rules must still win.
        DB::table('galleries')->whereKey($gallery->id)->update([
            'is_public' => true,
            'is_free_download' => true,
        ]);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        Storage::disk('photos')->put(
            $gallery->id.'/'.$photo->filename,
            file_get_contents(base_path('tests/Fixtures/sample.jpg')),
        );

        $user = User::factory()->create([
            'brand' => Brand::B2B,
            'flatrate_level' => 'original',
        ]);
        $user->galleries()->attach($gallery);
        $token = auth('api')->login($user);

        $this->withToken($token)
            ->getJson('/api/photos/'.$photo->id.'/download?tier=original')
            ->assertForbidden();
        $this->withToken($token)
            ->getJson('/api/galleries/'.$gallery->id.'/download-zip?tier=original')
            ->assertForbidden();
        $this->withToken($token)
            ->getJson('/api/media/'.$gallery->slug.'/'.$photo->id.'.jpg')
            ->assertForbidden();

        $this->assertStringContainsString('/watermarked/', $photo->fresh()->url);
        $this->assertDatabaseCount('download_logs', 0);

        $gallerySitemap = $this->get('/api/sitemap-galleries.xml')->assertOk()->getContent();
        $imageSitemap = $this->get('/api/sitemap-images.xml')->assertOk()->getContent();
        $this->assertStringNotContainsString(htmlspecialchars($gallery->full_path), $gallerySitemap);
        $this->assertStringNotContainsString($photo->filename, $imageSitemap);
    }

    public function test_cr_media_004_selection_legacy_flags_block_stale_gallery_zip_and_legacy_order_zip(): void
    {
        $gallery = Gallery::factory()->create([
            'brand' => Brand::B2B,
            'type' => 'selection',
        ]);
        DB::table('galleries')->whereKey($gallery->id)->update([
            'is_public' => true,
            'is_free_download' => true,
        ]);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        $user = User::factory()->create([
            'brand' => Brand::B2B,
            'flatrate_level' => 'original',
        ]);
        $user->galleries()->attach($gallery);

        $disk = Storage::disk('photos');
        $sourceRelative = $gallery->id.'/'.$photo->filename;
        $staleRelative = $gallery->id.'/_watermarked/'.$photo->filename;
        $fixture = file_get_contents(base_path('tests/Fixtures/sample.jpg'));
        $disk->put($sourceRelative, $fixture);
        $disk->put($staleRelative, $fixture);
        $staleHash = hash_file('sha256', $disk->path($staleRelative));
        $this->assertIsString($staleHash);

        $this->actingAs($user, 'api')
            ->getJson('/api/galleries/'.$gallery->id.'/download-zip?tier=original')
            ->assertForbidden();
        $this->assertSame($staleHash, hash_file('sha256', $disk->path($staleRelative)));

        $order = Order::factory()->paid()->create([
            'user_id' => $user->id,
            'brand' => Brand::B2B,
        ]);
        InvoiceSnapshot::create([
            'order_id' => $order->id,
            'invoice_number' => 'SELECTION-'.strtoupper(Str::random(8)),
            'brand' => Brand::B2B,
            'customer_details' => [
                'items' => [
                    ['photoId' => $photo->id, 'tier' => 'original', 'price' => 1000],
                ],
            ],
            'total_net' => 1000,
            'total_gross' => 1000,
            'tax_rate' => 0,
        ]);

        $this->actingAs($user, 'api')
            ->getJson('/api/orders/'.$order->id.'/download-zip')
            ->assertForbidden();
        $this->assertDatabaseCount('download_logs', 0);
    }

    public function test_cr_media_004_selection_quote_decode_rejects_a_signed_legacy_token(): void
    {
        $gallery = Gallery::factory()->create([
            'brand' => Brand::B2B,
            'type' => 'selection',
        ]);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        $token = app(OfferTokenService::class)->issueQuote(
            [$photo->id],
            10000,
            brand: Brand::B2B->value,
            expiresAt: now()->addDay(),
        );

        $this->getJson('/api/orders/quote-decode?token='.urlencode($token))
            ->assertStatus(410)
            ->assertJsonPath('error', 'Angebot abgelaufen oder ungültig.');
    }

    public function test_cr_media_004_selection_watermarked_preview_is_delivered_but_original_and_zip_are_not(): void
    {
        if (! function_exists('imagecreatetruecolor') || ! function_exists('imagepng')) {
            $this->markTestSkipped('GD extension is not available.');
        }

        $this->createWatermarkAssets();
        $gallery = Gallery::factory()->create([
            'brand' => Brand::B2B,
            'type' => 'selection',
        ]);
        DB::table('galleries')->whereKey($gallery->id)->update([
            'is_public' => true,
            'is_free_download' => true,
        ]);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        $user = User::factory()->create([
            'brand' => Brand::B2B,
            'flatrate_level' => 'original',
        ]);
        $user->galleries()->attach($gallery);

        $disk = Storage::disk('photos');
        $sourceRelative = $gallery->id.'/'.$photo->filename;
        $sourcePath = $disk->path($sourceRelative);
        $disk->put($sourceRelative, file_get_contents(base_path('tests/Fixtures/sample.jpg')));
        $staleThumbRelative = $gallery->id.'/_thumbs/2000/'.$photo->id.'.webp';
        $staleThumbPath = $disk->path($staleThumbRelative);
        $processor = app(ImageProcessor::class);
        $this->assertTrue($processor->scaleImage($sourcePath, $staleThumbPath, 2000));
        $this->assertFalse($processor->isSafeWatermarkedOutput($sourcePath, $staleThumbPath, 'selection'));

        $this->actingAs($user, 'api')
            ->get('/api/media/'.$gallery->id.'/'.$photo->id.'.jpg')
            ->assertForbidden();

        $preview = $this->actingAs($user, 'api')
            ->get('/api/media/'.$gallery->id.'/watermarked/_thumbs/2000/'.$photo->id.'.webp')
            ->assertOk();
        $this->assertNotFalse(getimagesize($preview->getFile()->getPathname()));

        $watermarkedThumbRelative = $gallery->id.'/_thumbs/_watermarked/2000/'.$photo->id.'.webp';
        $watermarkedThumbPath = $disk->path($watermarkedThumbRelative);
        $this->assertTrue($disk->exists($watermarkedThumbRelative.'.watermark.json'));
        $this->assertTrue($processor->isSafeWatermarkedOutput($staleThumbPath, $watermarkedThumbPath, 'selection'));
        $this->assertStringContainsString('watermarked/_thumbs/2000', $photo->url);
        $this->assertDatabaseCount('download_logs', 0);
    }

    private function streamResponseBytes($response): string
    {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }

    private function createWatermarkAssets(): void
    {
        $disk = Storage::disk('photos');
        $disk->makeDirectory('_watermarks');
        foreach ([500, 1000, 2000] as $bucket) {
            foreach (['master_', 'master_selection_'] as $prefix) {
                $image = imagecreatetruecolor(32, 32);
                $color = imagecolorallocatealpha($image, 255, 255, 255, 40);
                imagefill($image, 0, 0, $color);
                imagepng($image, $disk->path('_watermarks/'.$prefix.$bucket.'.png'));
                imagedestroy($image);
            }
        }
    }

    private function guestToken(string $galleryId): string
    {
        $guestId = (string) Str::uuid();
        // The provider now requires a live, current-host invite provenance
        // before it will authenticate a guest token. Keep that capability
        // separate from the target gallery so foreign/null target checks still
        // reach the controller and remain 404 assertions.
        $authGallery = Gallery::factory()->create([
            'brand' => Brand::B2B,
            'type' => 'selection',
        ]);
        $invite = GalleryInvite::create([
            'gallery_id' => $authGallery->id,
            'token' => 'media-verifier-'.Str::random(12),
        ]);
        $payload = app(Factory::class)->customClaims([
            'sub' => 'guest_'.$guestId,
            'guest_id' => $guestId,
            'guest_name' => 'Verifier Guest',
            'guest_invite_id' => $invite->id,
            'transient_galleries' => [$galleryId],
            'transient_invites' => [
                $invite->id => [
                    'gallery_ids' => [$galleryId],
                    'meta_gallery_ids' => [],
                ],
            ],
        ])->make();

        return app(JWTAuth::class)->encode($payload)->get();
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function checkoutRequest(string $photoId, array $extra = []): Request
    {
        return Request::create('/', 'POST', array_merge([
            'items' => [[
                'photoId' => $photoId,
                'tier' => 'original',
                'isQuote' => false,
            ]],
            'billing_name' => 'Verifier',
            'billing_company' => null,
            'billing_street' => 'Street 1',
            'billing_zip' => '1010',
            'billing_city' => 'Vienna',
            'withdrawal_waived' => true,
        ], $extra));
    }
}
