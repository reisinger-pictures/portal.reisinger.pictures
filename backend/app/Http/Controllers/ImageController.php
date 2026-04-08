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
            'file' => 'required|image|mimes:jpeg,jpg|max:20480',
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
        $meta = $photoService->processImage($targetPath, $thumbPath, $gallery);

        $isLrUpload = $request->lr_uuid && !\Illuminate\Support\Str::startsWith($request->lr_uuid, ['web-', 'ftp-']);
        
        $query = Photo::where('gallery_id', $gallery->id);
        
        if ($isLrUpload) {
            $query->where('lr_uuid', $request->lr_uuid);
        } elseif ($request->boolean('replace')) {
            $query->where('filename', $filename);
        } else {
            $query->where('id', 'invalid-id-to-force-create');
        }

        $existingPhoto = $query->first();

        if ($existingPhoto) {
            $existingPhoto->update(array_merge([
                'filename' => $filename, 
                'user_id' => $user->id,
                'lr_uuid' => $request->lr_uuid ?? $existingPhoto->lr_uuid
            ], $meta));
            $photo = $existingPhoto;
        } else {
            $photo = Photo::create(array_merge([
                'gallery_id' => $gallery->id, 
                'lr_uuid' => $request->lr_uuid ?? \Illuminate\Support\Str::uuid()->toString(), 
                'filename' => $filename, 
                'user_id' => $user->id
            ], $meta));
        }

        return response()->json(['success' => true, 'photo_id' => $photo->id]);
    }
}
