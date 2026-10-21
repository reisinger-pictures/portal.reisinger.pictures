<?php

namespace App\Http\Controllers;

use App\Models\ModelProfile;
use App\Services\ModelContactSheetLinkService;
use App\Services\ModelContactSheetService;
use App\Support\BrandRegistry;
use Illuminate\Support\Facades\Log;

/**
 * Öffentliche Auslieferung des **externen** Contact Sheets über einen
 * signierten Link (Phase 4).
 *
 * Der Endpunkt ist bewusst **ohne Authentifizierung** erreichbar: der Link
 * selbst ist das Bearer-Credential. Alles, was daraus folgt, ist fail-closed:
 *
 *  - Der Token muss gültig, unexpired und an die **Brand des Hosts** gebunden
 *    sein (`BrandRegistry::currentIdOrNull()`). Eine fremde Brand → 404.
 *  - Jeder Fehlerfall (ungültig, abgelaufen, falsche Brand, kein Profil mehr
 *    vorhanden) antwortet mit **404** und derselben generischen Meldung — es
 *    gibt keine Token-Orakel, die den Grund unterscheidbar machen.
 *  - Geliefert wird **immer** die externe Variante. Die interne Variante
 *    enthält PII und ist nicht teilbar.
 *  - Profile, die inzwischen gelöscht oder in eine andere Brand verschoben
 *    wurden, werden übersprungen — und im PDF **benannt** (Anzahl, PII-frei).
 *    Ein stilles Verengen der Auswahl ist nicht erlaubt.
 */
class ModelContactSheetPublicController extends Controller
{
    /**
     * Die einzige Meldung für jeden Fehlerfall (kein Token-Orakel).
     */
    private const INVALID_LINK_MESSAGE = 'Dieser Link ist ungültig oder abgelaufen.';

    public function __construct(
        private readonly ModelContactSheetLinkService $links,
        private readonly ModelContactSheetService $contactSheets,
    ) {}

    public function show(string $token)
    {
        $brand = BrandRegistry::currentIdOrNull();
        if ($brand === null) {
            return $this->invalidLink();
        }

        $payload = $this->links->verify($token, $brand);
        if ($payload === null) {
            return $this->invalidLink();
        }

        $profileIds = $payload['model_profile_ids'];

        $profiles = ModelProfile::query()
            ->forBrand($brand)
            ->whereIn('id', $profileIds)
            ->get()
            ->keyBy(fn (ModelProfile $profile): string => (string) $profile->getKey());

        // Reihenfolge des Tokens beibehalten und die nicht mehr lieferbaren
        // Profile zählen — sie werden im PDF benannt.
        $delivered = [];
        $omitted = 0;
        foreach ($profileIds as $profileId) {
            $profile = $profiles->get($profileId);
            if (! $profile instanceof ModelProfile) {
                $omitted++;

                continue;
            }

            $delivered[] = $profile;
        }

        if ($delivered === []) {
            return $this->invalidLink();
        }

        $pdf = $this->contactSheets->renderCollection(
            $delivered,
            ModelContactSheetService::VARIANT_EXTERNAL,
            $omitted,
        );

        // PII-frei und **ohne** den Token: der Token ist ein Credential.
        Log::info('model.contact_sheet.share_link.accessed', [
            'link_id' => $payload['link_id'],
            'count' => count($delivered),
            'brand' => $brand,
        ]);

        $filename = sprintf('models-contact-sheet-%s-%s.pdf', ModelContactSheetService::VARIANT_EXTERNAL, now()->format('Ymd'));

        return response()->stream(function () use ($pdf): void {
            echo $pdf;
        }, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    private function invalidLink()
    {
        return response()->json(['error' => self::INVALID_LINK_MESSAGE], 404);
    }
}
