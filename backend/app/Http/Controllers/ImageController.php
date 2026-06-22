<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Gallery;
use App\Models\Photo;
use Illuminate\Support\Str;
use App\Services\PhotoProcessingService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;

class ImageController extends Controller
{
    public function upload(Request $request)
    {
        $request->validate([
            'gallery_id' => 'required|string',
            'lr_uuid' => 'required|string',
            'file' => 'required|image|mimes:jpeg,jpg|max:20480|dimensions:min_width=500,min_height=500,max_width=15000,max_height=15000',
        ]);

        $gallery = Gallery::find($request->gallery_id);
        if (!$gallery) return response()->json(['error' => 'Galerie nicht gefunden'], 404);

        $user = auth('api')->user();

        if (!$user->is_photographer) {
            return response()->json(['error' => 'Nur Fotografen dürfen Bilder hochladen.'], 403);
        }
        
        if (!$user->is_super_admin && !$user->is_admin && !($user->is_photographer && $user->canPhotographerAccessGallery($gallery->id))) {
            return response()->json(['error' => 'Keine Berechtigung für diese Galerie.'], 403);
        }

        $file = $request->file('file');
        
        $process = new \Symfony\Component\Process\Process(['exiftool', '-MIMEType', '-S', $file->getPathname()]);
        $process->run();
        if (!$process->isSuccessful() || !str_contains($process->getOutput(), 'image/')) {
            return response()->json(['error' => 'Die hochgeladene Datei ist kein gültiges oder lesbares Bild.'], 422);
        }

        // Originalnamen merken für IPTC Title Fallback
        $originalName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $extension = strtolower($file->extension());
        if ($extension === 'jpeg') $extension = 'jpg';
        $mimeType = $file->getClientMimeType();
        
        $targetDir = (string) $gallery->id;
        $thumbsDir = $targetDir . '/_thumbs';
        $isLrUpload = $request->lr_uuid && !Str::startsWith($request->lr_uuid, ['web-', 'ftp-']);
        $lrUuid = $request->lr_uuid ?? Str::uuid()->toString();

        // Heavy Lifting (ExifTool CLI) VOR der DB-Transaktion ausführen, um Deadlocks zu vermeiden!
        $photoService = app(\App\Services\PhotoProcessingService::class);
        $meta = $photoService->processImage($file->getPathname(), '', $gallery);
        $meta['mime_type'] = $mimeType;
        if (empty($meta['title'])) {
            $meta['title'] = $originalName;
        }

        return DB::transaction(function () use ($file, $gallery, $user, $extension, $targetDir, $thumbsDir, $isLrUpload, $lrUuid, $request, $meta) {
            
            $query = Photo::where('gallery_id', $gallery->id);
            if ($isLrUpload) {
                $query->where('lr_uuid', $lrUuid);
            } elseif ($request->boolean('replace')) {
                // Suchen nach dem Originalnamen im Titel, da Dateiname jetzt UUID ist
                $query->where('title', $originalName);
            } else {
                $query->where('id', 'invalid-id-to-force-create');
            }
            $existingPhoto = $query->lockForUpdate()->first();

            // Dateinamen IMMER aus UUID generieren!
            $photoId = $existingPhoto ? $existingPhoto->id : (string) Str::uuid();
            $filename = $photoId . '.' . $extension;

            Storage::disk('photos')->makeDirectory($targetDir);
            Storage::disk('photos')->makeDirectory($thumbsDir);

            if (!$file->storeAs($targetDir, $filename, ['disk' => 'photos'])) {
                throw new \Exception('Datei konnte nicht gespeichert werden.');
            }

            $targetPath = Storage::disk('photos')->path($targetDir . '/' . $filename);
            $thumbPath = Storage::disk('photos')->path($thumbsDir . '/' . md5($filename . '1024') . '.webp');

            if ($existingPhoto) {
                $existingPhoto->forceFill(array_merge([
                    'user_id' => $user->id,
                    'lr_uuid' => $lrUuid
                ], $meta))->save();
                $photo = $existingPhoto;
            } else {
                $photo = new Photo();
                $photo->forceFill(array_merge([
                    'id' => $photoId,
                    'gallery_id' => $gallery->id, 
                    'lr_uuid' => $lrUuid, 
                    'user_id' => $user->id
                ], $meta))->save();
            }

            return response()->json(['success' => true, 'photo_id' => $photo->id]);
        }, 3);
    }
}
