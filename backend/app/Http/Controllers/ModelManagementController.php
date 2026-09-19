<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AdminOnly;
use App\Models\Customer;
use App\Models\ModelAccessToken;
use App\Models\ModelPhoto;
use App\Models\ModelProfile;
use App\Services\AuthorizationService;
use App\Services\ModelContactSheetService;
use App\Services\ModelFileStore;
use App\Services\ModelProfileEraser;
use App\Services\ModelQuestionnaire;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Admin-Suche nach Models (ModelProfile) inkl. auth-gated Altersnachweis- und
 * Foto-Downloads sowie Verwaltung des Profil-Zugangslinks.
 *
 * Alle Abfragen sind auf die Marke des Admins gescoped. Ausweise und Fotos
 * liegen verschlüsselt auf der privaten `local`-Disk und werden nie über
 * `public` ausgeliefert. Jeder Download wird audit-geloggt.
 */
class ModelManagementController extends Controller
{
    use AdminOnly;

    /**
     * Age proofs do not persist their MIME type; the encrypted file extension
     * is derived from the upload MIME at store time (ModelFileStore), so it is
     * the authoritative source for the download Content-Type.
     */
    private const AGE_PROOF_MIME_BY_EXTENSION = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'pdf' => 'application/pdf',
    ];

    public function __construct(
        private readonly ModelFileStore $fileStore,
        private readonly ModelQuestionnaire $questionnaire,
        private readonly ModelContactSheetService $contactSheetService,
    ) {}

    public function index(Request $request)
    {
        $this->authorizeAdmin();
        $brand = $this->adminBrand();

        $query = ModelProfile::query()
            ->forBrand($brand)
            ->with(['customer.modelAccessTokens', 'photos']);

        $this->applyFilters($query, $request);

        // `sort=newest` keeps the legacy DB ordering; any other mode (including
        // the default match score) is applied in-memory below.
        if ($request->query('sort') === 'newest') {
            $query->orderByDesc('submitted_at');
        }

        $profiles = $query->get();

        // Lifecycle filter (in-memory, derived from last_confirmed_at):
        // default only active; `lifecycle_status=inactive|all` explicit (§3.4).
        $status = (string) $request->query('lifecycle_status', ModelProfile::LIFECYCLE_ACTIVE);
        if (! in_array($status, [ModelProfile::LIFECYCLE_ACTIVE, ModelProfile::LIFECYCLE_INACTIVE, 'all'], true)) {
            $status = ModelProfile::LIFECYCLE_ACTIVE;
        }
        if ($status !== 'all') {
            $profiles = $profiles
                ->filter(fn (ModelProfile $profile) => $profile->lifecycleStatus() === $status)
                ->values();
        }

        // Category filter: in-memory against the answers snapshot
        // (single catalog v1). Multi-select = OR.
        $categories = array_values(array_filter((array) $request->query('category', [])));
        if ($categories !== []) {
            $profiles = $profiles
                ->filter(fn (ModelProfile $profile) => array_intersect($profile->categories(), $categories) !== [])
                ->values();
        }

        $thresholds = $this->willingnessThresholds($request);
        if ($thresholds !== []) {
            // Minimum-threshold semantics: a profile matches when it reaches at
            // least the requested level in ANY selected category (OR) — incl.
            // `willingness_stock`.
            $profiles = $profiles
                ->filter(function (ModelProfile $profile) use ($thresholds): bool {
                    $answers = $profile->answersMap();
                    foreach ($thresholds as $key => $level) {
                        $minimum = $this->questionnaire->willingnessOrdinal($level);
                        $ordinal = $this->questionnaire->willingnessOrdinal($answers[$key] ?? null);
                        if ($minimum !== null && $ordinal !== null && $ordinal >= $minimum) {
                            return true;
                        }
                    }

                    return false;
                })
                ->values();
        }

        $sort = $request->query('sort');

        // Default ordering = match score (willingness dominates experience),
        // newest as tiebreak. `sort=newest` keeps the DB order.
        if (! in_array($sort, ['newest', 'willingness', 'experience'], true)) {
            $profiles = $profiles
                ->sortByDesc(fn (ModelProfile $profile) => [
                    $this->questionnaire->matchScore($profile->answersMap()),
                    $profile->submitted_at?->getTimestamp() ?? 0,
                ])
                ->values();
        }

        if ($sort === 'willingness') {
            $category = $this->firstScalar($request->query('willingness_category'))
                ?? ($thresholds === [] ? null : substr((string) array_key_first($thresholds), strlen('willingness_')))
                ?? ($categories[0] ?? null);

            if (is_string($category) && $category !== '') {
                $profileKey = 'willingness_'.$category;
                $profiles = $profiles
                    ->sortByDesc(fn (ModelProfile $profile) => $this->questionnaire->willingnessOrdinal($profile->answersMap()[$profileKey] ?? null) ?? -1)
                    ->values();
            }
        }

        if ($sort === 'experience') {
            $category = $this->firstScalar($request->query('category'));

            if (is_string($category) && $category !== '') {
                // Category-specific: experience ordinal desc; tiebreak willingness.
                $profiles = $profiles
                    ->sortByDesc(function (ModelProfile $profile) use ($category): array {
                        $answers = $profile->answersMap();

                        return [
                            $this->questionnaire->experienceOrdinal($answers['experience_'.$category] ?? null) ?? -1,
                            $this->questionnaire->willingnessOrdinal($answers['willingness_'.$category] ?? null) ?? -1,
                        ];
                    })
                    ->values();
            } else {
                // No category: max experience ordinal over all categories,
                // tiebreak max willingness.
                $profiles = $profiles
                    ->sortByDesc(function (ModelProfile $profile): array {
                        $answers = $profile->answersMap();

                        return [
                            $this->questionnaire->maxExperienceOrdinal($answers),
                            $this->questionnaire->maxWillingnessOrdinal($answers),
                        ];
                    })
                    ->values();
            }
        }

        return response()->json($profiles->map(fn (ModelProfile $profile) => $this->serialize($profile)));
    }

    public function ageProof(string $id)
    {
        $this->authorizeAdmin();
        $brand = $this->adminBrand();

        $profile = ModelProfile::query()
            ->forBrand($brand)
            ->findOrFail($id);

        if (! $profile->age_proof_path || ! $this->fileStore->exists($profile->age_proof_path)) {
            return response()->json(['error' => 'Kein Altersnachweis vorhanden.'], 404);
        }

        $this->logDownload('age_proof', $profile, null);

        $extension = pathinfo($profile->age_proof_path, PATHINFO_EXTENSION) ?: 'bin';

        return $this->streamFile(
            $profile->age_proof_path,
            'altersnachweis.'.$extension,
            self::AGE_PROOF_MIME_BY_EXTENSION[strtolower($extension)] ?? 'application/octet-stream',
        );
    }

    /**
     * Druckfähiges Contact Sheet als PDF (`variant=internal|external`).
     *
     * Intern: alle Fotos + PII; extern: nur `public`-Fotos, kein PII, mit
     * Wasserzeichen. Jeder Export wird PII-frei audit-geloggt.
     */
    public function contactSheet(Request $request, string $id)
    {
        $this->authorizeAdmin();
        $brand = $this->adminBrand();

        $variant = $request->query('variant');
        if (! is_string($variant) || ! in_array($variant, ModelContactSheetService::VARIANTS, true)) {
            throw ValidationException::withMessages([
                'variant' => 'Ungültige Variante. Erlaubt sind „internal" und „external".',
            ]);
        }

        $profile = ModelProfile::query()
            ->forBrand($brand)
            ->findOrFail($id);

        $pdf = $this->contactSheetService->render($profile, $variant);

        Log::info('model.contact_sheet.export', [
            'model_profile_id' => $profile->id,
            'customer_id' => $profile->customer_id,
            'variant' => $variant,
            'user_id' => auth('api')->id(),
        ]);

        $filename = sprintf('model-%s-%s-%s.pdf', $profile->id, $variant, now()->format('Ymd'));

        return response()->streamDownload(function () use ($pdf): void {
            echo $pdf;
        }, $filename, ['Content-Type' => 'application/pdf']);
    }

    public function photo(string $id, string $photoId)
    {
        $this->authorizeAdmin();
        $brand = $this->adminBrand();

        $profile = ModelProfile::query()->forBrand($brand)->findOrFail($id);
        $photo = $this->resolvePhoto($profile, $photoId);

        $this->logDownload('photo', $profile, $photo);

        return $this->streamFile(
            $photo->path,
            $photo->original_name ?: 'foto',
            $photo->mime_type ?: 'application/octet-stream',
        );
    }

    public function destroyPhoto(string $id, string $photoId)
    {
        $this->authorizeAdmin();
        $brand = $this->adminBrand();

        $profile = ModelProfile::query()->forBrand($brand)->findOrFail($id);
        $photo = $this->resolvePhoto($profile, $photoId, requireFile: false);

        // Delete the row first, then the file: a failed file delete leaves an
        // orphan (recoverable) rather than a DB row without a file.
        $path = $photo->path;
        $photo->delete();
        $this->fileStore->delete($path);

        // Deleting the primary photo clears the flag (no auto-promotion, §2.4).
        if ($photo->is_primary) {
            ModelPhoto::where('model_profile_id', $profile->id)->update(['is_primary' => false]);
        }

        return response()->json(['success' => true]);
    }

    public function setPrimaryPhoto(string $id, string $photoId)
    {
        $this->authorizeAdmin();
        $brand = $this->adminBrand();

        $profile = ModelProfile::query()->forBrand($brand)->findOrFail($id);
        $photo = $this->resolvePhoto($profile, $photoId);

        // The primary photo must be public.
        if ($photo->visibility !== ModelPhoto::VISIBILITY_PUBLIC) {
            throw ValidationException::withMessages([
                'is_primary' => 'Das Hauptbild muss öffentlich sein.',
            ]);
        }

        ModelPhoto::where('model_profile_id', $profile->id)->update(['is_primary' => false]);
        $photo->forceFill(['is_primary' => true])->save();

        return response()->json(['success' => true, 'primary_photo_id' => $photo->id]);
    }

    /**
     * Issue (or rotate) the 24h profile access link for one model customer.
     */
    public function accessLink(string $customer)
    {
        $this->authorizeAdmin();
        $brand = $this->adminBrand();

        $model = Customer::where('brand', $brand)->where('is_model', true)->findOrFail($customer);

        $token = ModelAccessToken::issueFor($model, auth('api')->id());

        return response()->json([
            'success' => true,
            'link' => $token->url(),
            'expires_at' => $token->expires_at?->toIso8601String(),
        ], 201);
    }

    public function revokeAccessLink(string $customer)
    {
        $this->authorizeAdmin();
        $brand = $this->adminBrand();

        $model = Customer::where('brand', $brand)->where('is_model', true)->findOrFail($customer);

        ModelAccessToken::where('customer_id', $model->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'updated_at' => now()]);

        return response()->json(['success' => true]);
    }

    /**
     * DSGVO-Löschung eines Model-Profils (Super-Admin only, status-agnostisch).
     *
     * Nutzt die gemeinsame Löschroutine {@see ModelProfileEraser} (auch vom
     * Lifecycle-Expiry verwendet).
     */
    public function destroyModel(string $customer)
    {
        // Defense-in-depth: the route is additionally gated by the
        // `super_admin` middleware (isSuperAdmin).
        $user = auth('api')->user();
        if (! $user || ! app(AuthorizationService::class)->isSuperAdmin($user)) {
            abort(response()->json(['error' => 'Keine Berechtigung.'], 403));
        }

        $brand = $this->adminBrand();

        $model = Customer::where('brand', $brand)
            ->where('is_model', true)
            ->findOrFail($customer);

        app(ModelProfileEraser::class)->erase($model, 'dsgvo', $user->id);

        return response()->json(['success' => true]);
    }

    private function resolvePhoto(ModelProfile $profile, string $photoId, bool $requireFile = true): ModelPhoto
    {
        $photo = ModelPhoto::where('model_profile_id', $profile->id)->findOrFail($photoId);

        if ($requireFile && ! $this->fileStore->exists($photo->path)) {
            abort(response()->json(['error' => 'Foto nicht gefunden.'], 404));
        }

        return $photo;
    }

    private function streamFile(string $path, string $name, string $mime)
    {
        return response()->streamDownload(function () use ($path): void {
            $this->fileStore->streamDecrypted($path, function (string $chunk): void {
                echo $chunk;
            });
        }, $name, ['Content-Type' => $mime]);
    }

    private function logDownload(string $type, ModelProfile $profile, ?ModelPhoto $photo): void
    {
        Log::info('model.file.download', [
            'type' => $type,
            'model_profile_id' => $profile->id,
            'customer_id' => $profile->customer_id,
            'photo_id' => $photo?->id,
            'user_id' => auth('api')->id(),
        ]);
    }

    private function applyFilters(Builder $query, Request $request): void
    {
        if ($q = $request->query('q')) {
            $query->whereHas('customer', function (Builder $customer) use ($q): void {
                $customer->where(function (Builder $inner) use ($q): void {
                    $inner->where('name', 'like', "%{$q}%")
                        ->orWhere('email', 'like', "%{$q}%")
                        ->orWhere('city', 'like', "%{$q}%");
                });
            });
        }

        if ($gender = $request->query('gender')) {
            $query->where('gender', $gender);
        }

        if ($city = $request->query('city')) {
            $query->whereHas('customer', fn (Builder $customer) => $customer->where('city', 'like', "%{$city}%"));
        }

        if ($country = $request->query('country')) {
            $query->whereHas('customer', fn (Builder $customer) => $customer->where('country', 'like', "%{$country}%"));
        }

        if ($ageMin = $request->query('age_min')) {
            $query->whereHas('customer', fn (Builder $customer) => $customer->where(
                'birthdate',
                '<=',
                now()->subYears((int) $ageMin)->format('Y-m-d')
            ));
        }

        if ($ageMax = $request->query('age_max')) {
            $query->whereHas('customer', fn (Builder $customer) => $customer->where(
                'birthdate',
                '>=',
                now()->subYears((int) $ageMax + 1)->addDay()->format('Y-m-d')
            ));
        }

        if ($actType = $request->query('act_type')) {
            $query->whereHas('customer.actMembers.act', fn (Builder $act) => $act->where('act_type', $actType));
        }
    }

    /**
     * Parsed willingness thresholds, keyed by answer key.
     *
     * Single-threshold semantics: `willingness_<category>=<level>` (also
     * `willingness_stock=<level>`), or the alias
     * `willingness_category=<category>&willingness_level=<level>`, means
     * "at least this level". One level per category; multiple categories are OR.
     *
     * @return array<string, string>
     */
    private function willingnessThresholds(Request $request): array
    {
        $thresholds = [];

        foreach ($request->query() as $key => $value) {
            if (! is_string($key) || ! str_starts_with($key, 'willingness_')) {
                continue;
            }
            if (in_array($key, ['willingness_category', 'willingness_level', 'willingness_levels'], true)) {
                continue;
            }

            $level = $this->firstScalar($value);
            if ($level !== null) {
                $thresholds[$key] = $level;
            }
        }

        $category = $this->firstScalar($request->query('willingness_category'));
        $level = $this->firstScalar($request->query('willingness_level'))
            ?? $this->firstScalar($request->query('willingness_levels', []));
        if ($category !== null && $level !== null) {
            $thresholds['willingness_'.$category] = $level;
        }

        return $thresholds;
    }

    /**
     * First non-empty scalar of a query value (scalar or array).
     */
    private function firstScalar(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = reset($value);
        }

        if (! is_scalar($value)) {
            return null;
        }

        $value = (string) $value;

        return $value === '' ? null : $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(ModelProfile $profile): array
    {
        $customer = $profile->customer;
        $activeToken = $customer?->modelAccessTokens
            ?->first(fn (ModelAccessToken $token) => $token->isActive());

        return [
            'id' => $profile->id,
            'customer_id' => $profile->customer_id,
            'display_name' => $customer?->name,
            'birthdate' => $customer?->birthdate?->format('Y-m-d'),
            'age' => $customer?->birthdate?->age,
            'gender' => $profile->gender,
            'city' => $customer?->city,
            'country' => $customer?->country,
            'categories' => $profile->categories(),
            'act_types' => $profile->actTypes(),
            'catalog_version' => $profile->catalog_version,
            'age_proof_required' => (bool) $profile->age_proof_required,
            'age_proof_uploaded_at' => $profile->age_proof_uploaded_at?->toIso8601String(),
            'submitted_at' => $profile->submitted_at?->toIso8601String(),
            'last_confirmed_at' => $profile->last_confirmed_at?->toIso8601String(),
            'lifecycle_status' => $profile->lifecycleStatus(),
            'answers' => $profile->answers,
            'willingness' => collect($profile->answersMap())
                ->filter(fn ($value, $key) => str_starts_with((string) $key, 'willingness_'))
                ->all(),
            'photos' => $profile->photos->map(fn (ModelPhoto $photo) => $this->serializePhoto($profile, $photo))->values(),
            'primary_photo_id' => $profile->photos->firstWhere('is_primary', true)?->id,
            'access_link' => $activeToken ? [
                'url' => $activeToken->url(),
                'expires_at' => $activeToken->expires_at?->toIso8601String(),
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializePhoto(ModelProfile $profile, ModelPhoto $photo): array
    {
        return [
            'id' => $photo->id,
            'visibility' => $photo->visibility,
            'is_primary' => (bool) $photo->is_primary,
            'original_name' => $photo->original_name,
            'mime_type' => $photo->mime_type,
            'size_bytes' => $photo->size_bytes,
            'download_url' => "/api/management/models/{$profile->id}/photos/{$photo->id}",
            'created_at' => $photo->created_at?->toIso8601String(),
        ];
    }
}
