<?php

namespace App\Http\Controllers;

use App\Exceptions\AIImageProcessingException;
use App\Models\Gallery;
use App\Models\Photo;
use App\Models\User;
use App\Services\AIService;
use App\Services\AuthorizationService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AIController extends Controller
{
    public function __construct(private AIService $aiService) {}

    public function status()
    {
        return response()->json([
            'enabled' => $this->aiService->isAvailable(),
            'status' => $this->aiService->isDisabled() ? 'disabled'
                : ($this->aiService->isAvailable() ? 'available' : 'unconfigured'),
            'type' => config('services.ai.type'),
            'model' => config('services.ai.model'),
        ]);
    }

    public function generateMetadata(Request $request)
    {
        $user = auth('api')->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Reject actors who have no metadata capability before resolving a
        // target. A concrete PhotoPolicy decision then runs before service
        // availability, request validation, and image processing.
        if (! $this->hasCoarseMetadataGenerationCapability($user)) {
            return response()->json(['error' => 'Keine Berechtigung für KI-Generierung.'], 403);
        }

        $photoId = $request->input('photo_id');
        $photo = is_string($photoId) && $photoId !== ''
            ? Photo::with('gallery')->find($photoId)
            : null;

        // can_edit_metadata and transient invite grants are target-scoped
        // capabilities, not stand-alone roles. Without a resolvable target they
        // cannot pass PhotoPolicy, so return the same opaque 403 as for an
        // existing but inaccessible photo. This also avoids an existence oracle
        // (unknown IDs must not receive a different validation response).
        if ($photo === null && $this->requiresTargetScopedPhotoAuthorization($user)) {
            return response()->json(['error' => 'Keine Berechtigung für KI-Generierung.'], 403);
        }

        if ($photo !== null && Gate::forUser($user)->denies('updateMetadata', $photo)) {
            return response()->json(['error' => 'Keine Berechtigung für KI-Generierung.'], 403);
        }

        if (! $this->aiService->isAvailable()) {
            return response()->json(['error' => 'KI-Dienst ist nicht verfügbar.'], 503);
        }

        $request->validate([
            'photo_id' => 'required|string|exists:photos,id',
            'global_context' => 'nullable|string|max:1000',
            'specific_context' => 'nullable|string|max:1000',
            'session_id' => 'nullable|string|max:128',
        ]);

        $photo ??= Photo::with('gallery')->findOrFail($request->photo_id);

        try {
            $result = $this->aiService->generateMetadata(
                $photo,
                $request->global_context ?? '',
                $request->specific_context ?? null,
                $request->session_id
            );

            return response()->json($result);
        } catch (ConnectionException $e) {
            return response()->json(['error' => 'KI-Dienst ist derzeit nicht erreichbar.'], 503);
        } catch (AIImageProcessingException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }
    }

    public function generateMetadataText(Request $request)
    {
        // The text flow is only used while creating gallery defaults. Keep the
        // provider behind the same gallery-creation authorization used by the
        // management endpoint, and evaluate it before service availability so
        // unauthorized users cannot probe or spend the provider quota.
        $user = auth('api')->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (Gate::forUser($user)->denies('create', Gallery::class)) {
            return response()->json(['error' => 'Keine Berechtigung für KI-Generierung.'], 403);
        }

        if (! $this->aiService->isAvailable()) {
            return response()->json(['error' => 'KI-Dienst ist nicht verfügbar.'], 503);
        }

        $request->validate([
            'text_input' => 'required|string|max:2000',
            'global_context' => 'nullable|string|max:1000',
            'session_id' => 'nullable|string|max:128',
        ]);

        try {
            $result = $this->aiService->generateMetadataFromText(
                $request->text_input,
                $request->global_context ?? '',
                $request->session_id
            );

            return response()->json($result);
        } catch (ConnectionException $e) {
            return response()->json(['error' => 'KI-Dienst ist derzeit nicht erreichbar.'], 503);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }
    }

    /**
     * Determine whether the actor is in a category that can possibly edit
     * photo metadata. This coarse pre-check deliberately runs before the photo
     * lookup so actors with no capability always receive an opaque 403 without
     * learning whether the submitted ID exists.
     */
    private function hasCoarseMetadataGenerationCapability(User $user): bool
    {
        $authorization = app(AuthorizationService::class);

        if ($authorization->isReservedNullBrandActor($user)) {
            return false;
        }

        if ($authorization->isAdmin($user) || $authorization->isPhotographer($user)) {
            return true;
        }

        // Transient identities and registered users carrying an active invite
        // grant remain target-scoped; the exact PhotoPolicy gate is authoritative.
        if ($authorization->isTransientGuest($user) || ! empty($user->transient_meta_galleries)) {
            return $authorization->getActiveTransientMetaGalleryIds($user) !== [];
        }

        return (bool) $user->can_edit_metadata;
    }

    /**
     * Client metadata flags and invite grants are meaningful only for a real
     * photo. Role-capable actors retain the normal validation contract for
     * missing or unknown IDs once they have passed the coarse pre-check.
     */
    private function requiresTargetScopedPhotoAuthorization(User $user): bool
    {
        $authorization = app(AuthorizationService::class);

        return ! $authorization->isAdmin($user) && ! $authorization->isPhotographer($user);
    }
}
