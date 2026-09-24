<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdatePhotoMetadataRequest;
use App\Jobs\DeletePhotoFilesJob;
use App\Models\Photo;
use App\Models\PhotoMetadataVersion;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class PhotoController extends Controller
{
    public function updateMetadata(UpdatePhotoMetadataRequest $request, $id)
    {
        $photo = Photo::with('gallery')->findOrFail($id);
        $user = auth('api')->user();

        if (Gate::denies('updateMetadata', $photo)) {
            return response()->json(['error' => 'Keine Berechtigung, Metadaten zu bearbeiten.'], 403);
        }

        $validated = $request->validated();

        return DB::transaction(function () use ($photo, $user, $validated) {
            // Versionierung: Vorzustand für alle Rollen speichern (vollständiges Audit-Trail)
            $this->createMetadataVersion($photo, $user);

            $photo->update($validated);

            return response()->json(['success' => true, 'photo' => $photo]);
        });
    }

    public function getVersions($id)
    {
        $photo = Photo::with('gallery')->findOrFail($id);
        $user = auth('api')->user();

        if (Gate::denies('viewVersions', $photo)) {
            return response()->json(['error' => 'Keine Berechtigung. Nur für Fotografen/Admins.'], 403);
        }

        $versions = PhotoMetadataVersion::with('user:id,name')->where('photo_id', $photo->id)->orderBy('id', 'desc')->get();

        return response()->json($versions);
    }

    public function revertMetadata(Request $request, $id, $versionId)
    {
        $photo = Photo::with('gallery')->findOrFail($id);
        $user = auth('api')->user();

        if (Gate::denies('revertMetadata', $photo)) {
            return response()->json(['error' => 'Keine Berechtigung für Revert. Nur für Fotografen/Admins.'], 403);
        }

        $version = PhotoMetadataVersion::where('photo_id', $photo->id)->findOrFail($versionId);

        return DB::transaction(function () use ($photo, $user, $version) {
            // A revert is another metadata mutation. Preserve the state that is
            // about to be replaced and attribute that snapshot to the actor
            // performing the revert before restoring the selected version.
            $this->createMetadataVersion($photo, $user);

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
        });
    }

    private function createMetadataVersion(Photo $photo, User $user): void
    {
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
    }

    public function destroy($id)
    {
        $photo = Photo::with('gallery')->findOrFail($id);
        $user = auth('api')->user();

        if (Gate::denies('delete', $photo)) {
            return response()->json(['error' => 'Keine Löschberechtigung.'], 403);
        }

        // Dispatch Job to delete files asynchronously
        DeletePhotoFilesJob::dispatch((string) $photo->gallery_id, $photo->filename, (string) $photo->id);

        $photo->delete();

        return response()->json(['success' => true]);
    }
}
