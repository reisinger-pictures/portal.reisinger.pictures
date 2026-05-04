<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Gallery;
use App\Models\Photo;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Services\PhotoProcessingService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;

class FtpController extends Controller
{
    public function status()
    {
        $user = auth('api')->user();
        $inboxPath = $this->getInboxPath($user);

        $fileCount = 0;
        if (is_dir($inboxPath)) {
            $files = glob($inboxPath . '/*.{jpg,jpeg,JPG,JPEG}', GLOB_BRACE);
            $fileCount = count($files);
        }

        $user->load('currentFtpGallery');

        return response()->json([
            'ftp_folder' => '/' . ($user->ftp_slug ?? $user->id),
            'file_count' => $fileCount,
            'current_target_gallery' => $user->currentFtpGallery
        ]);
    }

    public function setTarget(Request $request)
    {
        $request->validate(['gallery_id' => 'nullable|string|exists:galleries,id']);
        $user = auth('api')->user();

        if ($request->gallery_id && !$user->is_photographer) {
            return response()->json(['error' => 'Keine Rechte für diese Aktion.'], 403);
        }

        $user->update(['current_ftp_gallery_id' => $request->gallery_id]);
        return response()->json(['success' => true]);
    }

    public function process(Request $request)
    {
        $user = auth('api')->user();
        if (!$user->is_photographer) {
            return response()->json(['error' => 'Nur Fotografen dürfen den FTP-Import anstoßen.'], 403);
        }
        if (!$user->current_ftp_gallery_id) {
            return response()->json(['error' => 'Keine Ziel-Galerie ausgewählt.'], 400);
        }

        $gallery = Gallery::find($user->current_ftp_gallery_id);
        $inboxPath = $this->getInboxPath($user);

        if (!is_dir($inboxPath)) return response()->json(['success' => true, 'processed' => 0]);

        $imageFiles = glob($inboxPath . '/*.{jpg,jpeg,JPG,JPEG}', GLOB_BRACE);
        if (empty($imageFiles)) return response()->json(['success' => true, 'processed' => 0]);

        $processedCount = 0;
        $photoService = app(PhotoProcessingService::class);

        foreach ($imageFiles as $file) {
            $originalName = pathinfo($file, PATHINFO_FILENAME);
            $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if ($extension === 'jpeg') $extension = 'jpg';

            // Dateinamen IMMER aus UUID generieren!
            $photoId = (string) Str::uuid();
            $filename = $photoId . '.' . $extension;

            $targetDir = (string) $gallery->id;
            $targetRelativePath = $targetDir . '/' . $filename;
            $thumbsDir = $targetDir . '/_thumbs';

            Storage::disk('photos')->put($targetRelativePath, file_get_contents($file));
            unlink($file);

            $targetPath = Storage::disk('photos')->path($targetRelativePath);
            $thumbPath = Storage::disk('photos')->path($thumbsDir . '/' . md5($filename . '1024') . '.webp');

            if (!is_dir(dirname($thumbPath))) {
                mkdir(dirname($thumbPath), 0755, true);
            }

            $meta = $photoService->processImage($targetPath, $thumbPath, $gallery);
            if (empty($meta['title'])) {
                $meta['title'] = $originalName; // Dateiname als Titel retten
            }

            DB::transaction(function () use ($gallery, $meta, $photoId, $user) {
                $photo = new Photo();
                $photo->forceFill(
                    array_merge([
                        'id' => $photoId,
                        'gallery_id' => $gallery->id,
                        'lr_uuid' => 'ftp-' . uniqid(),
                        'user_id' => $user->id
                    ], $meta)
                )->save();
            });

            $processedCount++;
        }

        return response()->json(['success' => true, 'processed' => $processedCount]);
    }

    private function getInboxPath($user)
    {
        $folder = $user->ftp_slug ?? $user->id;
        return Storage::disk('ftp_inbox')->path((string) $folder);
    }
}
