<?php
namespace App\Http\Controllers;

use App\Models\Photo;
use App\Services\AIService;
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
        if (!$this->aiService->isAvailable()) {
            return response()->json(['error' => 'KI-Dienst ist nicht verfügbar.'], 503);
        }

        $request->validate([
            'photo_id' => 'required|string|exists:photos,id',
            'global_context' => 'nullable|string|max:1000',
            'specific_context' => 'nullable|string|max:1000',
        ]);

        $photo = Photo::with('gallery')->findOrFail($request->photo_id);
        $user = auth('api')->user();

        if (Gate::denies('updateMetadata', $photo)) {
            return response()->json(['error' => 'Keine Berechtigung für KI-Generierung.'], 403);
        }

        try {
            $result = $this->aiService->generateMetadata(
                $photo,
                $request->global_context ?? '',
                $request->specific_context ?? null
            );
            return response()->json($result);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }
    }

    public function generateMetadataText(Request $request)
    {
        if (!$this->aiService->isAvailable()) {
            return response()->json(['error' => 'KI-Dienst ist nicht verfügbar.'], 503);
        }

        $request->validate([
            'text_input' => 'required|string|max:2000',
            'global_context' => 'nullable|string|max:1000',
        ]);

        try {
            $result = $this->aiService->generateMetadataFromText(
                $request->text_input,
                $request->global_context ?? ''
            );
            return response()->json($result);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }
    }
}
