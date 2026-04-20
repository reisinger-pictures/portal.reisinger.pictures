<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Gallery;
use App\Models\Photo;
use Illuminate\Support\Str;
use App\Services\PhotoProcessingService;

use Illuminate\Support\Facades\Storage;

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
        
        // Exiftool Pre-Check
        $process = new \Symfony\Component\Process\Process(['exiftool', '-MIMEType', '-S', $file->getPathname()]);
        $process->run();
        if (!$process->isSuccessful() || !str_contains($process->getOutput(), 'image/')) {
            return response()->json(['error' => 'Die hochgeladene Datei ist kein gültiges oder lesbares Bild.'], 422);
        }

        $originalName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $extension = $file->extension(); // Safer: Derives from actual MIME type, not client input
        $filename = Str::slug($originalName) . '.' . $extension;
        $mimeType = $file->getClientMimeType();
        
        $targetDir = (string) $gallery->id;
        $thumbsDir = $targetDir . '/_thumbs';
        $isLrUpload = $request->lr_uuid && !\Illuminate\Support\Str::startsWith($request->lr_uuid, ['web-', 'ftp-']);
        $lrUuid = $request->lr_uuid ?? \Illuminate\Support\Str::uuid()->toString();

        return \Illuminate\Support\Facades\DB::transaction(function () use ($file, $gallery, $user, $filename, $mimeType, $targetDir, $thumbsDir, $isLrUpload, $lrUuid, $request) {
            $query = Photo::where('gallery_id', $gallery->id);
            if ($isLrUpload) {
                $query->where('lr_uuid', $lrUuid);
            } elseif ($request->boolean('replace')) {
                $query->where('filename', $filename);
            } else {
                $query->where('id', 'invalid-id-to-force-create');
            }
            $existingPhoto = $query->lockForUpdate()->first();

            \Illuminate\Support\Facades\Storage::disk('photos')->makeDirectory($targetDir);
            \Illuminate\Support\Facades\Storage::disk('photos')->makeDirectory($thumbsDir);

            if (!$file->storeAs($targetDir, $filename, ['disk' => 'photos'])) {
                throw new \Exception('Datei konnte nicht gespeichert werden.');
            }

            $targetPath = \Illuminate\Support\Facades\Storage::disk('photos')->path($targetDir . '/' . $filename);
            $thumbPath = \Illuminate\Support\Facades\Storage::disk('photos')->path($thumbsDir . '/' . md5($filename . '1024') . '.webp');
            
            $photoService = app(\App\Services\PhotoProcessingService::class);
            $meta = $photoService->processImage($targetPath, $thumbPath, $gallery);
            $meta['mime_type'] = $mimeType;

            if ($existingPhoto) {
                $existingPhoto->update(array_merge([
                    'filename' => $filename, 
                    'user_id' => $user->id,
                    'lr_uuid' => $lrUuid
                ], $meta));
                $photo = $existingPhoto;
            } else {
                $photo = Photo::create(array_merge([
                    'gallery_id' => $gallery->id, 
                    'lr_uuid' => $lrUuid, 
                    'filename' => $filename, 
                    'user_id' => $user->id
                ], $meta));
            }

            return response()->json(['success' => true, 'photo_id' => $photo->id]);
        });
    }
}
