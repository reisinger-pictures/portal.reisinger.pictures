<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Photo;
use App\Models\PhotoMetadataVersion;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class PhotoController extends Controller
{
    private function checkMetadataRights($photo, $user)
    {
        if (!$user) return ['allowed' => false, 'is_client' => false];
        
        $isPhotographerOrAdmin = $user->is_admin || ($user->is_photographer && $user->galleries()->where('galleries.id', $photo->gallery_id)->exists());
        
        if ($isPhotographerOrAdmin) {
            return ['allowed' => true, 'is_client' => false];
        }

        // Kunden-Prüfung
        $isClientWithRights = $photo->gallery->allow_client_metadata_edit 
            && $user->can_edit_metadata 
            && $user->galleries()->where('galleries.id', $photo->gallery_id)->exists();
        
        if ($isClientWithRights) {
            return ['allowed' => true, 'is_client' => true];
        }

        return ['allowed' => false, 'is_client' => false];
    }

    public function updateMetadata(Request $request, $id)
    {
        $photo = Photo::with('gallery')->findOrFail($id);
        $user = auth('api')->user();
        
        $rights = $this->checkMetadataRights($photo, $user);

        if (!$rights['allowed']) {
            return response()->json(['error' => 'Keine Berechtigung, Metadaten zu bearbeiten.'], 403);
        }

        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'artist' => 'nullable|string|max:255',
            'headline' => 'nullable|string|max:255',
            'keywords' => 'nullable|string|max:255',
            'location' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:255',
            'state' => 'nullable|string|max:255',
            'country' => 'nullable|string|max:255',
            'iso_country' => 'nullable|string|max:2',
        ]);

        // Versionierung: Nur wenn ein Kunde ändert, speichern wir den Vorzustand
        if ($rights['is_client']) {
            PhotoMetadataVersion::create([
                'photo_id' => $photo->id,
                'user_id' => $user->id,
                'title' => $photo->title,
                'description' => $photo->description,
                'artist' => $photo->artist,
                'headline' => $photo->headline,
                'keywords' => $photo->keywords,
                'location' => $photo->location,
                'city' => $photo->city,
                'state' => $photo->state,
                'country' => $photo->country,
                'iso_country' => $photo->iso_country,
            ]);
            
            // SECURITY: Kunden dürfen niemals den Urheber (Artist) überschreiben
            unset($validated['artist']);
        }

        $photo->update($validated);

        return response()->json(['success' => true, 'photo' => $photo]);
    }

    
    public function getVersions($id)
    {
        $photo = Photo::with('gallery')->findOrFail($id);
        $user = auth('api')->user();

        $isPhotographerOrAdmin = $user->is_admin || ($user->is_photographer && $user->galleries()->where('galleries.id', $photo->gallery_id)->exists());
        
        if (!$isPhotographerOrAdmin) {
            return response()->json(['error' => 'Keine Berechtigung. Nur für Fotografen/Admins.'], 403);
        }

        $versions = PhotoMetadataVersion::with('user:id,name')->where('photo_id', $photo->id)->orderBy('id', 'desc')->get();
        return response()->json($versions);
    }

    public function revertMetadata(Request $request, $id, $versionId)
    {
        $photo = Photo::with('gallery')->findOrFail($id);
        $user = auth('api')->user();

        $isPhotographerOrAdmin = $user->is_admin || ($user->is_photographer && $user->galleries()->where('galleries.id', $photo->gallery_id)->exists());
        
        if (!$isPhotographerOrAdmin) {
            return response()->json(['error' => 'Keine Berechtigung für Revert. Nur für Fotografen/Admins.'], 403);
        }

        $version = PhotoMetadataVersion::where('photo_id', $photo->id)->findOrFail($versionId);

        $photo->update([
            'title' => $version->title,
            'description' => $version->description,
            'artist' => $version->artist,
            'headline' => $version->headline,
            'keywords' => $version->keywords,
            'location' => $version->location,
            'city' => $version->city,
            'state' => $version->state,
            'country' => $version->country,
            'iso_country' => $version->iso_country,
        ]);

        return response()->json(['success' => true, 'photo' => $photo]);
    }

    public function destroy($id)
    {
        $photo = Photo::with('gallery')->findOrFail($id);
        $user = auth('api')->user();

        if (!$user->is_admin && !$user->galleries()->where('galleries.id', $photo->gallery_id)->exists()) {
            return response()->json(['error' => 'Keine Löschberechtigung.'], 403);
        }

        $thumbName = md5($photo->filename . '1024') . '.webp';
        Storage::disk('photos')->delete([
            $photo->gallery->id . '/' . $photo->filename,
            $photo->gallery->id . '/_watermarked/' . $photo->filename,
            $photo->gallery->id . '/_thumbs/' . $thumbName,
            $photo->gallery->id . '/_thumbs/_watermarked/' . $thumbName
        ]);

        $photo->delete();

        return response()->json(['success' => true]);
    }
}
