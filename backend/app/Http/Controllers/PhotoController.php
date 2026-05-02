<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Photo;
use App\Models\PhotoMetadataVersion;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class PhotoController extends Controller
{
        public function updateMetadata(Request $request, $id)
    {
        $photo = Photo::with('gallery')->findOrFail($id);
        $user = auth('api')->user();
        
        if (\Illuminate\Support\Facades\Gate::denies('updateMetadata', $photo)) {
            return response()->json(['error' => 'Keine Berechtigung, Metadaten zu bearbeiten.'], 403);
        }

        $isClient = !($user->is_super_admin || $user->is_admin || ($user->is_photographer && $user->canPhotographerAccessGallery($photo->gallery_id)));

        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
            'headline' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            
            'keywords' => 'nullable|string',
            'location' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:255',
            'state' => 'nullable|string|max:255',
            'country' => 'nullable|string|max:255',
            'iso_country' => 'nullable|string|max:2',
            'is_editorial_only' => 'nullable|boolean',
        ]);

        return \Illuminate\Support\Facades\DB::transaction(function () use ($photo, $user, $isClient, $validated) {
            // Versionierung: Nur wenn ein Kunde ändert, speichern wir den Vorzustand
            if ($isClient) {
                PhotoMetadataVersion::create([
                    'photo_id' => $photo->id,
                    'user_id' => $user->id,
                    'title' => $photo->title,
                    'headline' => $photo->headline,
                    'description' => $photo->description,
                    
                    'keywords' => $photo->keywords,
                    'location' => $photo->location,
                    'city' => $photo->city,
                    'state' => $photo->state,
                    'country' => $photo->country,
                    'iso_country' => $photo->iso_country,
                ]);
                
                // SECURITY: Kunden dürfen niemals den Urheber (Artist) überschreiben
                
            }

            $photo->update($validated);

            return response()->json(['success' => true, 'photo' => $photo]);
        });
    }

    
    public function getVersions($id)
    {
        $photo = Photo::with('gallery')->findOrFail($id);
        $user = auth('api')->user();

        if (\Illuminate\Support\Facades\Gate::denies('viewVersions', $photo)) {
            return response()->json(['error' => 'Keine Berechtigung. Nur für Fotografen/Admins.'], 403);
        }

        $versions = PhotoMetadataVersion::with('user:id,name')->where('photo_id', $photo->id)->orderBy('id', 'desc')->get();
        return response()->json($versions);
    }

    public function revertMetadata(Request $request, $id, $versionId)
    {
        $photo = Photo::with('gallery')->findOrFail($id);
        $user = auth('api')->user();

        if (\Illuminate\Support\Facades\Gate::denies('revertMetadata', $photo)) {
            return response()->json(['error' => 'Keine Berechtigung für Revert. Nur für Fotografen/Admins.'], 403);
        }

        $version = PhotoMetadataVersion::where('photo_id', $photo->id)->findOrFail($versionId);

        $photo->update([
            'title' => $version->title,
            'headline' => $version->headline,
            'description' => $version->description,
            
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

        if (\Illuminate\Support\Facades\Gate::denies('delete', $photo)) {
            return response()->json(['error' => 'Keine Löschberechtigung.'], 403);
        }

        // Dispatch Job to delete files asynchronously
        \App\Jobs\DeletePhotoFilesJob::dispatch((string) $photo->gallery_id, $photo->filename, (string) $photo->id);

        $photo->delete();

        return response()->json(['success' => true]);
    }
}
