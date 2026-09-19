<?php

namespace App\Services;

use App\Enums\Brand;
use App\Models\Act;
use App\Models\ActMember;
use App\Models\Customer;
use App\Models\ModelAccessToken;
use Illuminate\Support\Facades\Log;

/**
 * Restlose Löschung eines Model-Profils (DSGVO-Endpoint + Lifecycle-Expiry).
 *
 * Gemeinsame Routine für beide Aufrufer (DRY): löscht Customer samt
 * ModelProfile/Snapshot, allen Fotos + verschlüsselten Dateien, Altersnachweis,
 * Zugangs-Tokens und act_members; mitgliederlose Acts werden entfernt. Ein
 * verknüpftes Portal-Konto wird nur entknüpft (`user_id = null`), nie gelöscht.
 *
 * Die Datei-Bereinigung übernimmt der CustomerObserver beim `delete()`; bewusst
 * ohne explizite DB-Transaktion, damit der Observer die Dateien erst nach dem
 * DELETE-Statement entfernt (kein Rollback-Risiko).
 */
class ModelProfileEraser
{
    /**
     * @return array{photo_count: int, had_age_proof: bool, affected_acts: int}
     */
    public function erase(Customer $customer, string $reason, ?string $actorId = null): array
    {
        $photoCount = $customer->modelPhotos()->count();
        $hadAgeProof = (bool) $customer->modelProfile?->age_proof_path;

        // Capture affected acts before act_members cascade away.
        $actIds = ActMember::where('customer_id', $customer->id)->pluck('act_id')->all();

        // The portal account is unlinked, never deleted.
        if ($customer->user_id !== null) {
            $customer->user_id = null;
            $customer->save();
        }

        ModelAccessToken::where('customer_id', $customer->id)->delete();

        // Fires CustomerObserver (file cleanup) + Scout removal; cascades remove
        // model_profiles, model_photos, model_access_tokens and act_members.
        $customer->delete();

        if ($actIds !== []) {
            Act::whereIn('id', $actIds)->whereDoesntHave('members')->delete();
        }

        $stats = [
            'photo_count' => $photoCount,
            'had_age_proof' => $hadAgeProof,
            'affected_acts' => count($actIds),
        ];

        // Audit trail without PII: who/what deleted which customer and why.
        Log::info('model.profile.deleted', array_merge([
            'customer_id' => $customer->id,
            'user_id' => $actorId,
            'brand' => $this->brandValue($customer),
            'reason' => $reason,
        ], $stats));

        return $stats;
    }

    private function brandValue(Customer $customer): ?string
    {
        $brand = $customer->brand;

        if ($brand instanceof Brand) {
            return $brand->value;
        }

        return $brand !== null ? (string) $brand : null;
    }
}
