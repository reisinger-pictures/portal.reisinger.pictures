<?php

use App\Http\Controllers\AIController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ContractController;
use App\Http\Controllers\ContractJoinController;
use App\Http\Controllers\CouponAdminController;
use App\Http\Controllers\CouponCheckoutController;
use App\Http\Controllers\ContractDownloadController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\InvoiceDownloadController;
use App\Http\Controllers\PhotoDownloadController;
use App\Http\Controllers\FileDeliveryController;
use App\Http\Controllers\FtpController;
use App\Http\Controllers\GalleryController;
use App\Http\Controllers\GalleryFrontendController;
use App\Http\Controllers\ImageController;
use App\Http\Controllers\InviteController;
use App\Http\Controllers\LicenseCatalogController;
use App\Http\Controllers\LightroomCatalogController;
use App\Http\Controllers\MailController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\QuoteController;
use App\Http\Controllers\PhotoController;
use App\Http\Controllers\PhotoJobBoardController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProjectBoardController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\StatsController;
use App\Http\Controllers\OrgController;
use App\Http\Controllers\OrgInviteController;
use App\Http\Controllers\TextSnippetController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WebhookController;
use App\Models\DownloadLog;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

$throttleLimit = config('app.throttle_auth', 5);
Route::middleware("throttle:$throttleLimit,1")->group(function () {
    Route::post('/auth/login', [AuthController::class, 'login'])->name('api.auth.login');
    Route::post('/auth/register', [AuthController::class, 'register'])->name('api.auth.register');
    Route::post('/auth/reset-password', [AuthController::class, 'resetPassword'])->name('api.auth.reset-password');
});
Route::get('/ping', function () {
    return response()->json(['message' => 'API OK']);
})->name('api.ping');
// R-01 (naming): Lizenzbedingungen + Preisfaktoren — öffentlich, KEINE Bank-/Firmendaten
// (diese liegen in /settings/billing-details hinter auth:api).
Route::get('/settings/brand-config', [SettingsController::class, 'getBrandConfig'])->name('api.settings.brand-config');
Route::get('/settings/license-terms', [SettingsController::class, 'getLicenseTerms'])->name('api.settings.license-terms');
Route::get('/settings/license-catalog', [LicenseCatalogController::class, 'index'])->name('api.settings.license-catalog');

if (app()->environment('local', 'testing')) {
    Route::delete('/test/cleanup-user/{id}', function ($id) {
        $user = User::find($id);
        if ($user) {
            \App\Models\PhotographerStatement::where('user_id', $id)->delete();
            $orderIds = \App\Models\Order::where('user_id', $id)->pluck('id');
            if ($orderIds->isNotEmpty()) {
                \App\Models\InvoiceSnapshot::whereIn('order_id', $orderIds)->delete();
                \App\Models\Order::whereIn('id', $orderIds)->delete();
            }
            DownloadLog::where('user_id', $id)->delete();
            $user->delete();
        }
        return response()->json(['success' => true]);
    })->name('api.test.cleanup-user');

}

Route::post('/test/flush-queue', function () {
    if (app()->environment('local', 'testing')) {
        Artisan::call('queue:work', ['--stop-when-empty' => true]);
    }
    return response()->json(['success' => true]);
})->name('api.test.flush-queue');

Route::post('/webhooks/stripe', [WebhookController::class, 'handleStripe'])->name('api.webhooks.stripe')->middleware('throttle:10,1');

Route::get('/sitemap-galleries.xml', [SitemapController::class, 'galleries'])->name('api.sitemap.galleries');
Route::get('/sitemap-images.xml', [SitemapController::class, 'images'])->name('api.sitemap.images');

Route::get('/invites/{token}', [InviteController::class, 'check'])->name('api.invites.check');
Route::get('/org-invites/{token}', [OrgInviteController::class, 'check'])->name('api.org-invites.check');

Route::get('/contracts/join/{token}', [ContractJoinController::class, 'check'])->name('api.contracts.join.check');
Route::post('/contracts/join/{token}', [ContractJoinController::class, 'join'])->name('api.contracts.join.join');
Route::get('/contracts/sign/{personalToken}', [ContractJoinController::class, 'contractContent'])->name('api.contracts.sign.content');
Route::post('/contracts/sign/{personalToken}', [ContractJoinController::class, 'sign'])->name('api.contracts.sign.sign');
Route::middleware('throttle:60,1')->post('/contracts/sign/{personalToken}/page-exit', [ContractJoinController::class, 'pageExit'])->name('api.contracts.sign.page-exit');
Route::middleware("throttle:$throttleLimit,1")->post('/invites/redeem', [InviteController::class, 'redeem'])->name('api.invites.redeem');
Route::middleware("throttle:$throttleLimit,1")->post('/org-invites/redeem', [OrgInviteController::class, 'redeem'])->name('api.org-invites.redeem');

Route::get('/search', [SearchController::class, 'search'])->name('api.search');
Route::get('/search/locations', [SearchController::class, 'locations'])->name('api.search.locations');
Route::get('/photos/{id}/context', [SearchController::class, 'photoContext'])->name('api.photos.context');
Route::get('/galleries/{slug}', [GalleryFrontendController::class, 'show'])->name('api.galleries.show');
Route::get('/media/{slug}/{filename}', [FileDeliveryController::class, 'serve'])->name('api.media.serve')->where('filename', '.*');

$downloadThrottle = config('app.throttle_download', 60);
Route::middleware("throttle:$downloadThrottle,1")->get('/photos/{id}/download', [PhotoDownloadController::class, 'downloadSingle'])->name('api.photos.download');
Route::get('/orders/quote-decode', [QuoteController::class, 'decodeQuoteLink'])->name('api.orders.quote-decode');
Route::middleware('throttle:' . config('app.throttle_zip_download', 3) . ',1')->get('/galleries/{galleryId}/download-zip', [PhotoDownloadController::class, 'downloadZip'])->name('api.galleries.download-zip');

Route::middleware(['auth:api', 'throttle:api'])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout'])->name('api.auth.logout');
    Route::post('/auth/refresh', [AuthController::class, 'refresh'])->name('api.auth.refresh');
    Route::get('/auth/me', [AuthController::class, 'me'])->name('api.auth.me');
    Route::put('/auth/profile', [AuthController::class, 'updateProfile'])->name('api.auth.profile');
    // R-01 (naming): Bankverbindung & Impressum — sensibel, nur authentifiziert.
    Route::get('/settings/billing-details', [SettingsController::class, 'getBillingDetails'])->name('api.settings.billing-details');
    Route::post('/photos/{photoId}/rate', [GalleryFrontendController::class, 'rate'])->name('api.photos.rate');
    Route::post('/galleries/{id}/finish-rating', [MailController::class, 'finishRating'])->name('api.galleries.finish-rating');

    Route::get('/notifications/preferences', [NotificationController::class, 'preferences'])->name('api.notifications.preferences');
    Route::post('/galleries/{id}/opt-in', [NotificationController::class, 'toggleGalleryOptIn'])->name('api.galleries.opt-in');
    Route::post('/gallery-groups/{id}/opt-in', [NotificationController::class, 'toggleGroupOptIn'])->name('api.gallery-groups.opt-in');
    Route::put('/photos/{id}/meta', [PhotoController::class, 'updateMetadata'])->name('api.photos.meta');
    Route::get('/photos/{id}/versions', [PhotoController::class, 'getVersions'])->name('api.photos.versions');
    Route::post('/photos/{id}/revert/{versionId}', [PhotoController::class, 'revertMetadata'])->name('api.photos.revert');
    Route::post('/orders/checkout', [CheckoutController::class, 'checkout'])->name('api.orders.checkout');
    Route::get('/orders', [OrderController::class, 'index'])->name('api.orders.index');
    Route::get('/orders/{id}/invoice', [InvoiceDownloadController::class, 'downloadInvoice'])->name('api.orders.invoice');
    Route::middleware('throttle:' . config('app.throttle_zip_download', 3) . ',1')->get('/orders/{id}/download-zip', [PhotoDownloadController::class, 'downloadOrderZip'])->name('api.orders.download-zip');
    Route::delete('/photos/{id}', [PhotoController::class, 'destroy'])->name('api.photos.destroy');
    Route::get('/payouts/my-statements', [\App\Http\Controllers\PayoutController::class, 'myStatements'])->name('api.payouts.my-statements');

    // AI Metadata Generation
    Route::get('/ai/status', [AIController::class, 'status'])->name('api.ai.status');
    Route::post('/ai/generate-metadata', [AIController::class, 'generateMetadata'])->name('api.ai.generate-metadata');
    Route::post('/ai/generate-metadata-text', [AIController::class, 'generateMetadataText'])->name('api.ai.generate-metadata-text');

    // Coupon validation (public, auth-required)
    Route::post('/coupons/validate', [CouponCheckoutController::class, 'validateCoupon'])->name('api.coupons.validate')->middleware('throttle:coupon-validate');
});

Route::middleware(['auth:api', 'management'])->group(function () {
    Route::get('/management/galleries', [GalleryController::class, 'indexAdmin'])->name('api.management.galleries.index');
    Route::post('/management/gallery-groups', [GalleryController::class, 'storeGroup'])->name('api.management.gallery-groups.store');
    Route::put('/management/gallery-groups/{id}', [GalleryController::class, 'updateGroup'])->name('api.management.gallery-groups.update');
    Route::delete('/management/gallery-groups/{id}', [GalleryController::class, 'deleteGroup'])->name('api.management.gallery-groups.destroy');
    Route::post('/management/galleries', [GalleryController::class, 'storeGallery'])->name('api.management.galleries.store');
    Route::post('/management/galleries/{id}/sync-access', [GalleryController::class, 'syncAccess'])->name('api.management.galleries.sync-access');
    Route::post('/management/galleries/{id}/sync-photographers', [GalleryController::class, 'syncPhotographers'])->name('api.management.galleries.sync-photographers');
    Route::post('/management/gallery-groups/{id}/sync-photographers', [GalleryController::class, 'syncGroupPhotographers'])->name('api.management.gallery-groups.sync-photographers');
    Route::put('/management/galleries/{id}', [GalleryController::class, 'updateGallery'])->name('api.management.galleries.update');
    Route::delete('/management/galleries/{id}', [GalleryController::class, 'destroyGallery'])->name('api.management.galleries.destroy');
    Route::get('/management/gallery-groups/{id}', [GalleryController::class, 'showGroup'])->name('api.management.gallery-groups.show');
    Route::get('/management/galleries/{id}/export', [GalleryController::class, 'exportRatings'])->name('api.management.galleries.export');
    Route::get('/management/galleries/{id}/rating-status', [GalleryController::class, 'ratingStatus'])->name('api.management.galleries.rating-status');

    Route::get('/management/galleries/{id}/invites', [InviteController::class, 'index'])->name('api.management.galleries.invites');
    Route::post('/management/galleries/{id}/invites', [InviteController::class, 'generate'])->name('api.management.galleries.invites.generate');
    Route::put('/management/invites/{id}', [InviteController::class, 'update'])->name('api.management.invites.update');
    Route::delete('/management/invites/{id}', [InviteController::class, 'destroy'])->name('api.management.invites.destroy');
    Route::post('/management/galleries/{id}/invites/send', [InviteController::class, 'sendEmail'])->name('api.management.galleries.invites.send');
    Route::post('/management/upload', [ImageController::class, 'upload'])->name('api.management.upload');
    Route::post('/management/galleries/{id}/send-custom-email', [MailController::class, 'sendCustom'])->name('api.management.galleries.send-custom-email');

    Route::get('/management/roles', [UserController::class, 'roles'])->name('api.management.roles');
    Route::get('/management/users', [UserController::class, 'index'])->name('api.management.users.index');
    Route::post('/management/users', [UserController::class, 'store'])->name('api.management.users.store');
    Route::put('/management/users/{id}', [UserController::class, 'update'])->name('api.management.users.update');
    Route::delete('/management/users/{id}', [UserController::class, 'destroy'])->name('api.management.users.destroy');


    Route::get('/management/settings/watermark', [SettingsController::class, 'getWatermark'])->name('api.management.settings.watermark');
    Route::get('/management/settings/watermark/svg', [SettingsController::class, 'getWatermarkSvg'])->name('api.management.settings.watermark-svg');


    Route::post('/management/settings/watermark', [SettingsController::class, 'updateWatermark'])->name('api.management.settings.watermark.update');
    Route::put('/management/settings/license-terms', [SettingsController::class, 'updateLicenseTerms'])->name('api.management.settings.license-terms');
    // R-01: Billing-/Impressum-Konfiguration ist ein Super-Admin-Anliegen (s. isImpressumMissing-
    // Gate im Frontend) → zusätzlich super_admin-gesichert. Lesen (GET /settings/billing-details)
    // bleibt auth:api, da Klienten die Bankdaten für "Kauf auf Rechnung" brauchen.
    Route::put('/management/settings/billing-details', [SettingsController::class, 'updateBillingDetails'])->name('api.management.settings.billing-details')->middleware('super_admin');

    Route::middleware(['super_admin'])->group(function () {
        Route::post('/management/settings/license-use-cases', [LicenseCatalogController::class, 'storeUseCase'])->name('api.management.settings.license-use-cases.store');
        Route::put('/management/settings/license-use-cases/{id}', [LicenseCatalogController::class, 'updateUseCase'])->name('api.management.settings.license-use-cases.update');
        Route::delete('/management/settings/license-use-cases/{id}', [LicenseCatalogController::class, 'destroyUseCase'])->name('api.management.settings.license-use-cases.destroy');
        Route::post('/management/settings/license-modifiers', [LicenseCatalogController::class, 'storeModifier'])->name('api.management.settings.license-modifiers.store');
        Route::put('/management/settings/license-modifiers/{id}', [LicenseCatalogController::class, 'updateModifier'])->name('api.management.settings.license-modifiers.update');
        Route::delete('/management/settings/license-modifiers/{id}', [LicenseCatalogController::class, 'destroyModifier'])->name('api.management.settings.license-modifiers.destroy');

        Route::get('/management/payouts', [\App\Http\Controllers\PayoutController::class, 'adminIndex'])->name('api.management.payouts');
        Route::post('/management/payouts/calculate', [\App\Http\Controllers\PayoutController::class, 'calculate'])->name('api.management.payouts.calculate');
        Route::post('/management/payouts/{id}/approve', [\App\Http\Controllers\PayoutController::class, 'approveStatement'])->name('api.management.payouts.approve');
        Route::post('/management/payouts/{id}/pay', [\App\Http\Controllers\PayoutController::class, 'markAsPaid'])->name('api.management.payouts.pay');

        Route::get('/management/contracts', [ContractController::class, 'index'])->name('api.management.contracts.index');
        Route::post('/management/contracts', [ContractController::class, 'store'])->name('api.management.contracts.store');
        Route::get('/management/contracts/{id}', [ContractController::class, 'show'])->name('api.management.contracts.show');
        Route::put('/management/contracts/{id}', [ContractController::class, 'update'])->name('api.management.contracts.update');
        Route::post('/management/contracts/{id}/open', [ContractController::class, 'open'])->name('api.management.contracts.open');
        Route::post('/management/contracts/{id}/close', [ContractController::class, 'close'])->name('api.management.contracts.close');
        Route::get('/management/contracts/{id}/instances', [ContractController::class, 'instances'])->name('api.management.contracts.instances');
        Route::get('/management/contracts/{id}/download', [ContractDownloadController::class, 'downloadContract'])->name('api.management.contracts.download');

        // Super-Admin SMTP-Verbindungstest: sendet eine Test-Mail an die eigene Adresse.
        Route::post('/management/settings/test-email', [MailController::class, 'sendTest'])->name('api.management.settings.test-email');
    });

    Route::get('/management/settings/system', [SettingsController::class, 'getSystemInfo'])->name('api.management.settings.system');

    Route::get('/management/ftp/status', [FtpController::class, 'status'])->name('api.management.ftp.status');
    Route::post('/management/ftp/target', [FtpController::class, 'setTarget'])->name('api.management.ftp.target');
    Route::post('/management/ftp/process', [FtpController::class, 'process'])->name('api.management.ftp.process');


    Route::get('/management/orgs', [OrgController::class, 'index'])->name('api.management.orgs.index');
    Route::post('/management/orgs', [OrgController::class, 'store'])->name('api.management.orgs.store');
    Route::get('/management/orgs/{id}', [OrgController::class, 'show'])->name('api.management.orgs.show');
    Route::post('/management/orgs/{id}/invites', [OrgInviteController::class, 'invite'])->name('api.management.orgs.invites');
    Route::put('/management/orgs/{id}', [OrgController::class, 'update'])->name('api.management.orgs.update');
    Route::delete('/management/orgs/{id}', [OrgController::class, 'destroy'])->name('api.management.orgs.destroy');
    Route::post('/management/orgs/{id}/collective-invoice', [OrgController::class, 'generateCollectiveInvoice'])->name('api.management.orgs.collective-invoice');
    Route::put('/management/orgs/{id}/users', [OrgController::class, 'syncUsers'])->name('api.management.orgs.sync-users');
    Route::put('/management/orgs/{id}/groups', [OrgController::class, 'syncGroups'])->name('api.management.orgs.sync-groups');

    Route::get('/management/orders', [OrderController::class, 'indexAdmin'])->name('api.management.orders.index');
    Route::put('/management/orders/{id}/status', [OrderController::class, 'updateStatus'])->name('api.management.orders.status');
    Route::post('/management/orders/quote-link', [QuoteController::class, 'generateQuoteLink'])->name('api.management.orders.quote-link');
    Route::post('/management/orders/{id}/send-quote', [QuoteController::class, 'sendQuote'])->name('api.management.orders.send-quote');
    Route::post('/management/invoices/manual', [InvoiceController::class, 'generateManualInvoice'])->name('api.management.invoices.manual');
    Route::post('/management/invoices/extract-offer', [QuoteController::class, 'extractOffer'])->name('api.management.invoices.extract-offer');
        Route::middleware(['super_admin'])->group(function () {
        Route::get('/management/products', [ProductController::class, 'index'])->name('api.management.products.index');
        Route::post('/management/products', [ProductController::class, 'store'])->name('api.management.products.store');
        Route::put('/management/products/{id}', [ProductController::class, 'update'])->name('api.management.products.update');
        Route::delete('/management/products/{id}', [ProductController::class, 'destroy'])->name('api.management.products.destroy');
        Route::get('/management/customers', [CustomerController::class, 'index'])->name('api.management.customers.index');
        Route::post('/management/customers', [CustomerController::class, 'store'])->name('api.management.customers.store');
        Route::put('/management/customers/{id}', [CustomerController::class, 'update'])->name('api.management.customers.update');
        Route::delete('/management/customers/{id}', [CustomerController::class, 'destroy'])->name('api.management.customers.destroy');

        Route::get('/management/text-snippets', [TextSnippetController::class, 'index'])->name('api.management.text-snippets.index');
        Route::post('/management/text-snippets', [TextSnippetController::class, 'store'])->name('api.management.text-snippets.store');
        Route::put('/management/text-snippets/{id}', [TextSnippetController::class, 'update'])->name('api.management.text-snippets.update');
        Route::delete('/management/text-snippets/{id}', [TextSnippetController::class, 'destroy'])->name('api.management.text-snippets.destroy');
    });

    // SRP-01: Coupon management (super_admin, admin, photographer)
    Route::get('/management/coupons', [CouponAdminController::class, 'index'])->name('api.management.coupons.index');
    Route::post('/management/coupons', [CouponAdminController::class, 'store'])->name('api.management.coupons.store');
    Route::put('/management/coupons/{id}', [CouponAdminController::class, 'update'])->name('api.management.coupons.update');
    Route::delete('/management/coupons/{id}', [CouponAdminController::class, 'destroy'])->name('api.management.coupons.destroy');

    // SRP-01: Gallery/Group coupon endpoints
    Route::get('/management/galleries/{id}/coupons', [CouponAdminController::class, 'galleryCoupons'])->name('api.management.galleries.coupons');
    Route::post('/management/galleries/{id}/coupons', [CouponAdminController::class, 'storeGalleryCoupon'])->name('api.management.galleries.coupons.store');
    Route::get('/management/gallery-groups/{id}/coupons', [CouponAdminController::class, 'groupCoupons'])->name('api.management.gallery-groups.coupons');
    Route::post('/management/gallery-groups/{id}/coupons', [CouponAdminController::class, 'storeGroupCoupon'])->name('api.management.gallery-groups.coupons.store');

    Route::get('/management/stats', [StatsController::class, 'index'])->name('api.management.stats');
    Route::get('/management/logs', [StatsController::class, 'logs'])->name('api.management.logs');

    Route::get('/management/projects', [ProjectBoardController::class, 'index'])->name('api.management.projects.index');
    Route::post('/management/projects', [ProjectBoardController::class, 'store'])->name('api.management.projects.store');
    Route::put('/management/projects/{id}', [ProjectBoardController::class, 'update'])->name('api.management.projects.update');
    Route::patch('/management/projects/{id}/move', [ProjectBoardController::class, 'move'])->name('api.management.projects.move');
    Route::post('/management/projects/{id}/handoff', [ProjectBoardController::class, 'handoff'])->name('api.management.projects.handoff');
    Route::delete('/management/projects/{id}', [ProjectBoardController::class, 'destroy'])->name('api.management.projects.destroy');

    Route::get('/management/photo-jobs', [PhotoJobBoardController::class, 'index'])->name('api.management.photo-jobs.index');
    Route::post('/management/photo-jobs', [PhotoJobBoardController::class, 'store'])->name('api.management.photo-jobs.store');
    Route::put('/management/photo-jobs/{id}', [PhotoJobBoardController::class, 'update'])->name('api.management.photo-jobs.update');
    Route::patch('/management/photo-jobs/{id}/move', [PhotoJobBoardController::class, 'move'])->name('api.management.photo-jobs.move');
    Route::delete('/management/photo-jobs/{id}', [PhotoJobBoardController::class, 'destroy'])->name('api.management.photo-jobs.destroy');

    Route::get('/management/lightroom-catalogs', [LightroomCatalogController::class, 'index'])->name('api.management.lightroom-catalogs.index');
    Route::post('/management/lightroom-catalogs', [LightroomCatalogController::class, 'store'])->name('api.management.lightroom-catalogs.store');
    Route::put('/management/lightroom-catalogs/{id}', [LightroomCatalogController::class, 'update'])->name('api.management.lightroom-catalogs.update');
    Route::delete('/management/lightroom-catalogs/{id}', [LightroomCatalogController::class, 'destroy'])->name('api.management.lightroom-catalogs.destroy');

});
