<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Gallery;
use App\Models\Photo;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Services\PhotoProcessingService;
use Illuminate\Support\Facades\Storage;

class FtpController extends Controller
{
    /**
     * Gibt den Status der FTP-Inbox für den eingeloggten User zurück.
     */
    public function status()
    {
        $user = auth('api')->user();
        $inboxPath = $this->getInboxPath($user->id);

        $fileCount = 0;
        if (is_dir($inboxPath)) {
            // Wir suchen nach Bildern direkt im User-Ordner
            $files = glob($inboxPath . '/*.{jpg,jpeg,JPG,JPEG}', GLOB_BRACE);
            $fileCount = count($files);
        }

        $user->load('currentFtpGallery');

        return response()->json([
            'ftp_folder' => '/ftp/' . $user->id,
            'file_count' => $fileCount,
            'current_target_gallery' => $user->currentFtpGallery
        ]);
    }

    /**
     * Setzt die Ziel-Galerie für den FTP-Import.
     */
    public function setTarget(Request $request)
    {
        $request->validate(['gallery_id' => 'nullable|integer|exists:galleries,id']);
        $user = auth('api')->user();

        if ($request->gallery_id && !$user->is_photographer) {
            return response()->json(['error' => 'Keine Rechte für diese Aktion.'], 403);
        }

        $user->update(['current_ftp_gallery_id' => $request->gallery_id]);
        return response()->json(['success' => true]);
    }

    /**
     * Verschiebt Bilder aus der FTP-Inbox in die gewählte Galerie.
     */
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
        $inboxPath = $this->getInboxPath($user->id);

        if (!is_dir($inboxPath)) {
            return response()->json(['success' => true, 'processed' => 0]);
        }

        $imageFiles = glob($inboxPath . '/*.{jpg,jpeg,JPG,JPEG}', GLOB_BRACE);
        if (empty($imageFiles)) {
            return response()->json(['success' => true, 'processed' => 0]);
        }

        $processedCount = 0;
        $defaultArtist = $user->metadata_copyright ?: $user->name;
        $photoService = app(PhotoProcessingService::class);

        foreach ($imageFiles as $file) {
            $originalName = pathinfo($file, PATHINFO_FILENAME);
            $extension = pathinfo($file, PATHINFO_EXTENSION);
            $filename = Str::slug($originalName) . '.' . strtolower($extension);

            // Zielverzeichnis in der 'photos' disk (e.g. photos/1/mein-bild.jpg)
            $targetDir = (string) $gallery->id;
            $targetRelativePath = $targetDir . '/' . $filename;
            $thumbsDir = $targetDir . '/_thumbs';

            // Datei verschieben
            Storage::disk('photos')->put($targetRelativePath, file_get_contents($file));
            unlink($file);

            // Thumbnail und Metadaten verarbeiten
            $targetPath = Storage::disk('photos')->path($targetRelativePath);
            $thumbPath = Storage::disk('photos')->path($thumbsDir . '/' . md5($filename . '1024') . '.webp');

            // Falls das Thumbs-Verzeichnis fehlt
            if (!is_dir(dirname($thumbPath))) {
                mkdir(dirname($thumbPath), 0755, true);
            }

            $meta = $photoService->processImage($targetPath, $thumbPath, $gallery, $defaultArtist);

            Photo::updateOrCreate(
                ['gallery_id' => $gallery->id, 'filename' => $filename],
                array_merge(['lr_uuid' => 'ftp-' . uniqid()], $meta)
            );

            $processedCount++;
        }

        return response()->json(['success' => true, 'processed' => $processedCount]);
    }

    /**
     * Hilfsmethode zur Bestimmung des lokalen Pfads der User-Inbox.
     */
    private function getInboxPath($userId)
    {
        // Nutzt den Pfad aus der Filesystem-Config
        return Storage::disk('ftp_inbox')->path((string) $userId);
    }
}