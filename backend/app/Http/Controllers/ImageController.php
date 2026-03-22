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
            'gallery_id' => 'required|integer',
            'lr_uuid' => 'required|string',
            'file' => 'required|image|max:20480',
        ]);

        $gallery = Gallery::find($request->gallery_id);
        if (!$gallery) return response()->json(['error' => 'Galerie nicht gefunden'], 404);

        $user = auth('api')->user();

        if (!$user->is_photographer) {
            return response()->json(['error' => 'Nur Fotografen dürfen Bilder hochladen.'], 403);
        }
        
        if (!$user->canAccessGallery($gallery->id)) {
            return response()->json(['error' => 'Keine Berechtigung für diese Galerie.'], 403);
        }

        $file = $request->file('file');
        
        $originalName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $extension = $file->getClientOriginalExtension();
        $filename = Str::slug($originalName) . '.' . $extension;
        
        $targetDir = (string) $gallery->id;
        $thumbsDir = $targetDir . '/_thumbs';

        Storage::disk('photos')->makeDirectory($targetDir);
        Storage::disk('photos')->makeDirectory($thumbsDir);

        if (!$file->storeAs($targetDir, $filename, ['disk' => 'photos'])) {
            return response()->json(['error' => 'Datei konnte nicht gespeichert werden.'], 500);
        }

        $targetPath = Storage::disk('photos')->path($targetDir . '/' . $filename);
        $thumbPath = Storage::disk('photos')->path($thumbsDir . '/' . md5($filename . '1024') . '.webp');
        
        $photoService = app(PhotoProcessingService::class);
        $meta = $photoService->processImage($targetPath, $thumbPath, $gallery, $user->metadata_copyright ?: $user->name);

        $photo = Photo::updateOrCreate(
            ['gallery_id' => $gallery->id, 'lr_uuid' => $request->lr_uuid],
            array_merge(['filename' => $filename], $meta)
        );

        return response()->json(['success' => true, 'photo_id' => $photo->id, 'filename' => $filename]);
    }
}
