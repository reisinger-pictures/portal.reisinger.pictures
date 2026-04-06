<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ImageController;
use App\Http\Controllers\GalleryController;
use App\Http\Controllers\GalleryFrontendController;
use App\Http\Controllers\DownloadController;
use App\Http\Controllers\InviteController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\MailController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\DomainMappingController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\FtpController;
use App\Http\Controllers\PhotoController;
use App\Http\Controllers\StatsController;
use App\Http\Controllers\EmailTemplateController;
use App\Http\Controllers\FileDeliveryController;

$throttleLimit = env('AUTH_THROTTLE_LIMIT', 9999);
Route::middleware("throttle:$throttleLimit,1")->group(function () {
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/reset-password', [AuthController::class, 'resetPassword']);
});
Route::get('/ping', function() { return response()->json(['message' => 'API OK']); });

Route::post('/test/flush-queue', function() {
    if (app()->environment('local', 'testing')) {
        \Illuminate\Support\Facades\Artisan::call('queue:work', ['--stop-when-empty' => true]);
    }
    return response()->json(['success' => true]);
});

Route::get('/sitemap-galleries.xml', [SitemapController::class, 'galleries']);
Route::get('/sitemap-images.xml', [SitemapController::class, 'images']);

Route::get('/invites/{token}', [InviteController::class, 'check']);
Route::middleware("throttle:$throttleLimit,1")->post('/invites/redeem', [InviteController::class, 'redeem']);

Route::get('/search', [SearchController::class, 'search']);
Route::get('/search/locations', [SearchController::class, 'locations']);
Route::get('/photos/{id}/context', [SearchController::class, 'photoContext']);
Route::get('/galleries/{slug}', [GalleryFrontendController::class, 'show']);
Route::get('/media/{slug}/{filename}', [FileDeliveryController::class, 'serve'])->where('filename', '.*');

Route::get('/photos/{photoId}/download', [DownloadController::class, 'downloadSingle']);
Route::get('/galleries/{galleryId}/download-zip', [DownloadController::class, 'downloadZip']);

Route::middleware('auth:api')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/auth/refresh', [AuthController::class, 'refresh']);
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::put('/auth/profile', [AuthController::class, 'updateProfile']);
    Route::post('/photos/{photoId}/rate', [GalleryFrontendController::class, 'rate']);
    Route::post('/galleries/{id}/finish-rating', [MailController::class, 'finishRating']);

        Route::get('/notifications/preferences', [\App\Http\Controllers\NotificationController::class, 'preferences']);
    Route::post('/galleries/{id}/opt-in', [\App\Http\Controllers\NotificationController::class, 'toggleGalleryOptIn']);
    Route::post('/gallery-groups/{id}/opt-in', [\App\Http\Controllers\NotificationController::class, 'toggleGroupOptIn']);
    Route::put('/photos/{id}/meta', [PhotoController::class, 'updateMetadata']);
    Route::get('/photos/{id}/versions', [PhotoController::class, 'getVersions']);
    Route::post('/photos/{id}/revert/{versionId}', [PhotoController::class, 'revertMetadata']);
    Route::delete('/photos/{id}', [PhotoController::class, 'destroy']);
});

Route::middleware(['auth:api', 'management'])->group(function () {
    Route::get('/management/galleries', [GalleryController::class, 'indexAdmin']);
    Route::post('/management/gallery-groups', [GalleryController::class, 'storeGroup']);
    Route::put('/management/gallery-groups/{id}', [GalleryController::class, 'updateGroup']);
    Route::delete('/management/gallery-groups/{id}', [GalleryController::class, 'deleteGroup']);
    Route::post('/management/galleries', [GalleryController::class, 'storeGallery']);
    Route::put('/management/galleries/{id}', [GalleryController::class, 'updateGallery']);
    Route::delete('/management/galleries/{id}', [GalleryController::class, 'destroyGallery']);
    Route::get('/management/gallery-groups/{id}', [GalleryController::class, 'showGroup']);
    Route::get('/management/galleries/{id}/export', [GalleryController::class, 'exportRatings']);
    Route::get('/management/galleries/{id}/rating-status', [GalleryController::class, 'ratingStatus']);
    
    Route::get('/management/galleries/{id}/invites', [InviteController::class, 'index']);
    Route::post('/management/galleries/{id}/invites', [InviteController::class, 'generate']);
    Route::put('/management/invites/{id}', [InviteController::class, 'update']);
    Route::delete('/management/invites/{id}', [InviteController::class, 'destroy']);
    Route::post('/management/galleries/{id}/invites/send', [InviteController::class, 'sendEmail']);
    Route::post('/management/upload', [ImageController::class, 'upload']);
    Route::post('/management/galleries/{id}/send-custom-email', [MailController::class, 'sendCustom']);

    Route::get('/management/roles', [UserController::class, 'roles']);
    Route::get('/management/users', [UserController::class, 'index']);
    Route::post('/management/users', [UserController::class, 'store']);
    Route::put('/management/users/{id}', [UserController::class, 'update']);
    
    Route::get('/management/domain-mappings', [DomainMappingController::class, 'index']);
    Route::post('/management/domain-mappings', [DomainMappingController::class, 'store']);
    Route::delete('/management/domain-mappings/{id}', [DomainMappingController::class, 'destroy']);

    Route::get('/management/settings/watermark', [SettingsController::class, 'getWatermark']);
    Route::get('/management/settings/watermark/image', [SettingsController::class, 'getWatermarkImage']);
    Route::get('/management/settings/watermark/image', [SettingsController::class, 'getWatermarkImage']);
    Route::post('/management/settings/watermark', [SettingsController::class, 'updateWatermark']);

    Route::get('/management/ftp/status', [FtpController::class, 'status']);
    Route::post('/management/ftp/target', [FtpController::class, 'setTarget']);
    Route::post('/management/ftp/process', [FtpController::class, 'process']);

    Route::get('/management/stats', [StatsController::class, 'index']);
    Route::get('/management/logs', [StatsController::class, 'logs']);

                });
