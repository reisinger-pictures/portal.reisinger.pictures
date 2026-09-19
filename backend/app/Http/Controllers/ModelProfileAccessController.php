<?php

namespace App\Http\Controllers;

use App\Enums\Brand;
use App\Models\Customer;
use App\Models\ModelAccessToken;
use App\Models\ModelPhoto;
use App\Models\ModelProfile;
use App\Services\ModelFileStore;
use App\Services\ModelQuestionnaire;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Profil-Zugang für Models: öffentlicher Magic-Link (Token = Credential) zum
 * Einsehen/Bestätigen/Aktualisieren des eigenen Profils sowie „Meine Profile"
 * für eingeloggte Portal-Konten.
 *
 * Die öffentlichen Endpunkte halten sich an die 404/410-Semantik des
 * Invite-Flows: unbekanntes Token = 404, widerrufen/abgelaufen = 410.
 */
class ModelProfileAccessController extends Controller
{
    public function __construct(
        private readonly ModelQuestionnaire $questionnaire,
        private readonly ModelFileStore $fileStore,
    ) {}

    public function show(string $token)
    {
        $accessToken = $this->resolveToken($token);
        $profile = $this->profileFor($accessToken);
        $customer = $profile->customer;

        $this->touch($accessToken);

        return response()->json($this->serialize($accessToken, $profile, $customer));
    }

    public function confirm(string $token)
    {
        $accessToken = $this->resolveToken($token);
        $profile = $this->profileFor($accessToken);

        $profile->forceFill(['last_confirmed_at' => now()])->save();
        $this->touch($accessToken);

        return response()->json([
            'success' => true,
            'last_confirmed_at' => $profile->last_confirmed_at?->toIso8601String(),
            'lifecycle_status' => $profile->lifecycleStatus(),
        ]);
    }

    public function update(Request $request, string $token)
    {
        $accessToken = $this->resolveToken($token);
        $profile = $this->profileFor($accessToken);
        $customer = $profile->customer;
        $version = $this->questionnaire->currentVersion();

        $answers = (array) $request->input('answers', []);
        $isManager = $customer->actMembers()->where('role', 'manager')->exists();
        $context = ['is_manager' => $isManager, 'person_count' => 1];

        $rules = ['answers' => ['required', 'array']];
        foreach ($this->questionnaire->rules('person', $answers, $context) as $key => $questionRules) {
            $rules["answers.{$key}"] = $questionRules;
        }

        // Invariant: the profile must have an age proof after this update
        // (current catalogue makes it mandatory). An upload is only required
        // while no proof exists yet; an existing one may be replaced optionally.
        $rules['age_proof'] = $profile->age_proof_path
            ? ['sometimes', 'nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240']
            : ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'];

        // The owner may adjust visibility / primary flag of existing photos.
        $rules['photos'] = ['sometimes', 'array'];
        $rules['photos.*.id'] = ['required', 'uuid', 'distinct'];
        $rules['photos.*.visibility'] = ['sometimes', 'in:public,internal'];
        $rules['photos.*.is_primary'] = ['sometimes'];

        $validator = Validator::make($request->all(), $rules);

        foreach ($this->questionnaire->contactChannelErrors($answers) as $field => $message) {
            $validator->after(function ($validator) use ($field, $message): void {
                $validator->errors()->add("answers.{$field}", $message);
            });
        }

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        // Resolve (and validate) before storing the age proof, so a rejected
        // primary/visibility combination cannot orphan a freshly stored file.
        $photoUpdates = $this->resolvePhotoUpdates($request, $profile);

        $previousAgeProof = $profile->age_proof_path;
        $newAgeProof = $request->file('age_proof');
        $storedAgeProof = null;
        if ($newAgeProof instanceof UploadedFile) {
            $stored = $this->fileStore->storeUpload("model-age-proofs/{$profile->customer_id}", $newAgeProof);
            $storedAgeProof = $stored['path'];
        }

        $profile->forceFill([
            'catalog_version' => $version,
            'answers' => $this->questionnaire->buildSnapshot('person', $answers, $context),
            'gender' => $this->questionnaire->genderCode($answers['gender'] ?? null),
            'age_proof_required' => true,
            'age_proof_path' => $storedAgeProof ?? $profile->age_proof_path,
            'age_proof_uploaded_at' => $storedAgeProof ? now() : $profile->age_proof_uploaded_at,
            'submitted_at' => now(),
            'last_confirmed_at' => now(),
        ]);

        try {
            // All metadata writes are atomic; the encrypted file store stays
            // outside the transaction and is cleaned up on failure below.
            DB::transaction(function () use ($profile, $photoUpdates, $customer, $answers, $accessToken): void {
                $profile->save();
                $this->persistPhotoUpdates($photoUpdates, $profile);
                $this->applyCustomerFields($customer, $answers);
                $this->touch($accessToken);
            });
        } catch (\Throwable $exception) {
            // Never orphan a freshly stored proof on a failed metadata write.
            if ($storedAgeProof !== null) {
                $this->fileStore->delete($storedAgeProof);
            }

            throw $exception;
        }

        // Replaced proof: drop the superseded file only after the new path stuck.
        if ($storedAgeProof !== null && $previousAgeProof && $previousAgeProof !== $storedAgeProof) {
            $this->fileStore->delete($previousAgeProof);
        }

        return response()->json([
            'success' => true,
            'catalog_version' => $profile->catalog_version,
            'last_confirmed_at' => $profile->last_confirmed_at?->toIso8601String(),
        ]);
    }

    /**
     * „Meine Profile": owner-scoped profiles for the logged-in portal account.
     */
    public function mine(Request $request)
    {
        $user = auth('api')->user();

        $query = ModelProfile::query()
            ->with(['customer.modelAccessTokens', 'photos'])
            ->whereHas('customer', function ($customer) use ($user): void {
                $customer->where('user_id', $user->id)->where('is_model', true);
                $brand = $user->brand instanceof Brand ? $user->brand->value : $user->brand;
                if ($brand !== null) {
                    $customer->where('brand', $brand);
                }
            });

        $profiles = $query->orderByDesc('updated_at')->get();

        return response()->json($profiles->map(fn (ModelProfile $profile) => $this->serializeMine($profile)));
    }

    private function resolveToken(string $token): ModelAccessToken
    {
        $accessToken = ModelAccessToken::where('token', $token)->first();

        if (! $accessToken) {
            abort(response()->json(['error' => 'Profil-Link nicht gefunden.'], 404));
        }

        if ($accessToken->revoked_at !== null) {
            abort(response()->json(['error' => 'Dieser Profil-Link wurde widerrufen.'], 410));
        }

        if ($accessToken->expires_at !== null && $accessToken->expires_at->isPast()) {
            abort(response()->json(['error' => 'Dieser Profil-Link ist abgelaufen.'], 410));
        }

        return $accessToken;
    }

    private function profileFor(ModelAccessToken $accessToken): ModelProfile
    {
        $profile = ModelProfile::with('customer')->where('customer_id', $accessToken->customer_id)->first();

        if (! $profile) {
            abort(response()->json(['error' => 'Profil nicht gefunden.'], 404));
        }

        return $profile;
    }

    private function touch(ModelAccessToken $accessToken): void
    {
        $accessToken->forceFill(['last_used_at' => now()])->save();
    }

    /**
     * @param  array<string, mixed>  $answers
     */
    private function applyCustomerFields(Customer $customer, array $answers): void
    {
        $name = trim(($answers['first_name'] ?? '').' '.($answers['last_name'] ?? ''));

        $customer->fill(array_filter([
            'name' => $name !== '' ? $name : $customer->name,
            'street' => $answers['street'] ?? null,
            'zip' => $answers['zip'] ?? null,
            'city' => $answers['city'] ?? null,
            'country' => $answers['country'] ?? null,
            'birthdate' => $answers['birthdate'] ?? null,
        ], static fn ($value) => $value !== null && $value !== ''));
        $customer->save();
    }

    /**
     * Resolve and validate the owner's photo changes (visibility / primary).
     *
     * Rule: a primary photo must be public. Setting an internal photo as primary
     * yields a 422 field error. An explicit `is_primary: false` on the current
     * primary clears it (no primary) instead of failing — so a single public
     * photo can be demoted to internal without a replacement.
     *
     * @return array{visibility: array<string, string>, primary: ?string, clear: bool}
     */
    private function resolvePhotoUpdates(Request $request, ModelProfile $profile): array
    {
        $changes = (array) $request->input('photos', []);
        if ($changes === []) {
            return ['visibility' => [], 'primary' => null, 'clear' => false];
        }

        $owned = $profile->photos()->get()->keyBy('id');
        $errors = [];
        $visibility = [];
        $requestedPrimaryId = null;
        $requestedPrimaryIndex = null;
        $clearPrimary = false;
        $currentPrimaryId = $owned->firstWhere('is_primary', true)?->id;

        foreach ($changes as $index => $change) {
            if (! is_array($change)) {
                continue;
            }

            $id = $change['id'] ?? null;
            if (! is_string($id) || ! $owned->has($id)) {
                $errors["photos.{$index}.id"] = 'Unbekanntes Foto.';

                continue;
            }

            if (array_key_exists('visibility', $change)) {
                $value = $change['visibility'];
                if (! in_array($value, [ModelPhoto::VISIBILITY_PUBLIC, ModelPhoto::VISIBILITY_INTERNAL], true)) {
                    $errors["photos.{$index}.visibility"] = 'Ungültige Sichtbarkeit.';

                    continue;
                }
                $visibility[$id] = $value;
            }

            if (array_key_exists('is_primary', $change)) {
                if ($this->toBoolean($change['is_primary'])) {
                    if ($requestedPrimaryId === null) {
                        $requestedPrimaryId = $id;
                        $requestedPrimaryIndex = $index;
                    }
                } elseif ($id === $currentPrimaryId) {
                    // Explicitly demoting the current primary clears it instead
                    // of failing — the owner may prefer no primary at all.
                    $clearPrimary = true;
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        if ($requestedPrimaryId !== null) {
            $primaryId = $requestedPrimaryId;
        } elseif ($clearPrimary) {
            $primaryId = null;
        } else {
            $primaryId = $currentPrimaryId;
        }

        // "Primary must be public" only applies when a primary remains set.
        if ($primaryId !== null) {
            $resolved = $visibility[$primaryId] ?? $owned->get($primaryId)->visibility;
            if ($resolved !== ModelPhoto::VISIBILITY_PUBLIC) {
                $key = $requestedPrimaryIndex !== null ? "photos.{$requestedPrimaryIndex}.is_primary" : 'photos';

                throw ValidationException::withMessages([$key => 'Das Hauptbild muss öffentlich sein.']);
            }
        }

        return [
            'visibility' => $visibility,
            'primary' => $primaryId,
            'clear' => $primaryId === null && $clearPrimary,
        ];
    }

    /**
     * @param  array{visibility: array<string, string>, primary: ?string, clear: bool}  $updates
     */
    private function persistPhotoUpdates(array $updates, ModelProfile $profile): void
    {
        foreach ($updates['visibility'] as $id => $visibility) {
            ModelPhoto::where('model_profile_id', $profile->id)
                ->where('id', $id)
                ->update(['visibility' => $visibility]);
        }

        if ($updates['primary'] !== null) {
            ModelPhoto::where('model_profile_id', $profile->id)->update(['is_primary' => false]);
            ModelPhoto::where('model_profile_id', $profile->id)
                ->where('id', $updates['primary'])
                ->update(['is_primary' => true]);
        } elseif (! empty($updates['clear'])) {
            ModelPhoto::where('model_profile_id', $profile->id)->update(['is_primary' => false]);
        }
    }

    private function toBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (! is_scalar($value)) {
            return false;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(ModelAccessToken $accessToken, ModelProfile $profile, ?Customer $customer): array
    {
        return [
            'brand' => $profile->brandValue(),
            'display_name' => $customer?->name,
            'email' => $customer?->email,
            'catalog_version' => $profile->catalog_version,
            'current_catalog_version' => $this->questionnaire->currentVersion(),
            'is_catalog_outdated' => $profile->catalog_version !== $this->questionnaire->currentVersion(),
            'answers' => $profile->answers,
            'gender' => $profile->gender,
            'age_proof_required' => (bool) $profile->age_proof_required,
            'age_proof_uploaded_at' => $profile->age_proof_uploaded_at?->toIso8601String(),
            'submitted_at' => $profile->submitted_at?->toIso8601String(),
            'last_confirmed_at' => $profile->last_confirmed_at?->toIso8601String(),
            'lifecycle_status' => $profile->lifecycleStatus(),
            'categories' => $this->questionnaire->categoriesPayload(),
            'sections' => $this->questionnaire->sections(),
            'photos' => $profile->photos->map(fn (ModelPhoto $photo) => [
                'id' => $photo->id,
                'visibility' => $photo->visibility,
                'is_primary' => (bool) $photo->is_primary,
                'mime_type' => $photo->mime_type,
            ])->values(),
            'expires_at' => $accessToken->expires_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeMine(ModelProfile $profile): array
    {
        return [
            'id' => $profile->id,
            'customer_id' => $profile->customer_id,
            'display_name' => $profile->customer?->name,
            'brand' => $profile->brandValue(),
            'catalog_version' => $profile->catalog_version,
            'current_catalog_version' => $this->questionnaire->currentVersion(),
            'is_catalog_outdated' => $profile->catalog_version !== $this->questionnaire->currentVersion(),
            'last_confirmed_at' => $profile->last_confirmed_at?->toIso8601String(),
            'lifecycle_status' => $profile->lifecycleStatus(),
            'categories' => $profile->categories(),
            'age_proof_required' => (bool) $profile->age_proof_required,
            'photos' => $profile->photos->map(fn (ModelPhoto $photo) => [
                'id' => $photo->id,
                'visibility' => $photo->visibility,
                'is_primary' => (bool) $photo->is_primary,
                'mime_type' => $photo->mime_type,
            ])->values(),
        ];
    }
}
