<?php

namespace App\Http\Controllers;

use App\Enums\Brand;
use App\Enums\UserRole;
use App\Mail\ActivateAccountMail;
use App\Mail\ModelRegistrationSuccessMail;
use App\Models\Act;
use App\Models\ActMember;
use App\Models\Customer;
use App\Models\ModelPhoto;
use App\Models\ModelProfile;
use App\Models\ModelRegistrationInvite;
use App\Models\Role;
use App\Models\User;
use App\Services\ModelFileStore;
use App\Services\ModelQuestionnaire;
use App\Support\BrandRegistry;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Öffentlicher Flow der Model-Registrierung über Einmal-Einladungstoken.
 *
 * Kein Login nötig: der Token ist das Credential. Der Submit verbraucht den
 * Token atomar und legt je Person einen CRM-Customer + ModelProfile an, dazu
 * einen Act mit act_members und optional ein passwortloses Portal-Konto.
 */
class ModelRegistrationController extends Controller
{
    private const AGE_PROOF_RULES = ['file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'];

    private const PHOTO_RULES = ['file', 'mimes:jpg,jpeg,png,webp', 'max:10240'];

    private const MAX_PHOTOS_PER_PERSON = 5;

    public function __construct(
        private readonly ModelQuestionnaire $questionnaire,
        private readonly ModelFileStore $fileStore,
    ) {}

    public function check(string $token)
    {
        $invite = ModelRegistrationInvite::where('token', $token)->first();

        if (! $invite) {
            return response()->json(['error' => 'Einladung nicht gefunden.'], 404);
        }

        if ($invite->used_at !== null) {
            return response()->json(['error' => 'Diese Einladung wurde bereits verwendet.'], 410);
        }

        if ($invite->expires_at !== null && $invite->expires_at->isPast()) {
            return response()->json(['error' => 'Diese Einladung ist abgelaufen.'], 410);
        }

        return response()->json([
            'brand' => $invite->brandValue(),
            'status' => 'open',
            'email' => $invite->email,
            'person_count' => 0,
            'expires_at' => $invite->expires_at?->toIso8601String(),
            'catalog_version' => $this->questionnaire->currentVersion(),
            'categories' => $this->questionnaire->categoriesPayload(),
            'sections' => $this->questionnaire->sections(),
        ]);
    }

    public function submit(Request $request, string $token)
    {
        $version = $this->questionnaire->currentVersion();

        $invite = ModelRegistrationInvite::where('token', $token)->first();
        if (! $invite) {
            return response()->json(['error' => 'Einladung nicht gefunden.'], 404);
        }
        if ($invite->used_at !== null) {
            return response()->json(['error' => 'Diese Einladung wurde bereits verwendet.'], 410);
        }
        if ($invite->expires_at !== null && $invite->expires_at->isPast()) {
            return response()->json(['error' => 'Diese Einladung ist abgelaufen.'], 410);
        }

        $personsInput = (array) $request->input('persons', []);
        $actAnswers = (array) $request->input('act.answers', []);
        $managerIndex = (int) $request->input('manager_index', 0);
        if ($managerIndex < 0 || $managerIndex >= count($personsInput)) {
            $managerIndex = 0;
        }

        $brand = $invite->brandValue() ?? BrandRegistry::currentOrDefault()->value;

        $rules = [
            'persons' => ['required', 'array', 'min:1'],
            'persons.*.answers' => ['required', 'array'],
            // Tolerant by design: the multipart frontend value may be a string
            // ("true"/"false"/"1"/"0"/"on"/"off"); evaluation is done via
            // toBoolean() below.
            'persons.*.create_account' => ['sometimes'],
            'act' => ['sometimes', 'array'],
            'act.answers' => ['sometimes', 'array'],
            'manager_index' => ['sometimes', 'integer', 'min:0'],
        ];

        $personCount = count($personsInput);

        foreach ($personsInput as $index => $person) {
            $answers = (array) ($person['answers'] ?? []);
            $context = ['is_manager' => $index === $managerIndex, 'person_count' => $personCount];

            foreach ($this->questionnaire->rules('person', $answers, $context) as $key => $questionRules) {
                $rules["persons.{$index}.answers.{$key}"] = $questionRules;
            }

            // The age proof is mandatory for every person (§2.8).
            $rules["persons.{$index}.age_proof"] = array_merge(['required'], self::AGE_PROOF_RULES);

            // Personen-Fotos (max. 5/Person, server-autoritativ §2.4). The cap is
            // enforced per request here and against existing photos after
            // validation below.
            $rules["persons.{$index}.photos"] = ['sometimes', 'array', 'max:'.self::MAX_PHOTOS_PER_PERSON];
            $rules["persons.{$index}.photos.*.file"] = array_merge(['required'], self::PHOTO_RULES);
            $rules["persons.{$index}.photos.*.visibility"] = ['sometimes', 'in:public,internal'];
            $rules["persons.{$index}.photos.*.is_primary"] = ['sometimes'];
        }

        foreach ($this->questionnaire->rules('act', $actAnswers) as $key => $questionRules) {
            $rules["act.answers.{$key}"] = $questionRules;
        }

        $validator = Validator::make($request->all(), $rules);

        // Each person needs a distinct e-mail address within one submission.
        $seenEmails = [];
        foreach ($personsInput as $index => $person) {
            $email = strtolower((string) ($person['answers']['email'] ?? ''));
            if ($email === '') {
                continue;
            }
            if (isset($seenEmails[$email])) {
                $validator->after(function ($validator) use ($index): void {
                    $validator->errors()->add(
                        "persons.{$index}.answers.email",
                        'Diese E-Mail darf innerhalb einer Registrierung nur einmal vorkommen.'
                    );
                });
            }
            $seenEmails[$email] = true;
        }

        // Defense-in-depth mirror of the client-side contact gating (§2.3):
        // a selected channel requires its backing field. Field-level errors keep
        // the frontend's 422 mapping (`persons.<i>.answers.<field>`) intact.
        foreach ($personsInput as $index => $person) {
            $answers = (array) ($person['answers'] ?? []);
            foreach ($this->questionnaire->contactChannelErrors($answers) as $field => $message) {
                $validator->after(function ($validator) use ($index, $field, $message): void {
                    $validator->errors()->add("persons.{$index}.answers.{$field}", $message);
                });
            }
        }

        // Server-authoritative photo cap: existing photos + new uploads ≤ 5.
        foreach ($personsInput as $index => $person) {
            $newPhotos = $person['photos'] ?? null;
            if (! is_array($newPhotos) || $newPhotos === []) {
                continue;
            }

            $email = strtolower((string) ($person['answers']['email'] ?? ''));
            $existing = 0;
            if ($email !== '') {
                $existingCustomer = Customer::where('brand', $brand)->where('email', $email)->first();
                $existing = $existingCustomer?->modelPhotos()->count() ?? 0;
            }

            if ($existing + count($newPhotos) > self::MAX_PHOTOS_PER_PERSON) {
                $validator->after(function ($validator) use ($index): void {
                    $validator->errors()->add(
                        "persons.{$index}.photos",
                        'Maximal '.self::MAX_PHOTOS_PER_PERSON.' Fotos pro Person.'
                    );
                });
            }
        }

        // Primary photo must be public: a requested primary (is_primary=true)
        // with internal visibility is rejected with a field error.
        foreach ($personsInput as $index => $person) {
            $photos = $person['photos'] ?? null;
            if (! is_array($photos)) {
                continue;
            }

            foreach ($photos as $key => $photo) {
                if (! is_array($photo) || ! $this->toBoolean($photo['is_primary'] ?? false)) {
                    continue;
                }

                $visibility = $photo['visibility'] ?? ModelPhoto::VISIBILITY_INTERNAL;
                if ($visibility !== ModelPhoto::VISIBILITY_PUBLIC) {
                    $validator->after(function ($validator) use ($index, $key): void {
                        $validator->errors()->add(
                            "persons.{$index}.photos.{$key}.is_primary",
                            'Das Hauptbild muss öffentlich sein.'
                        );
                    });
                }
            }
        }

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $validated = $validator->validated();

        /** @var array<int, string> $storedPaths */
        $storedPaths = [];
        /** @var array<int, string> $supersededPaths */
        $supersededPaths = [];
        /** @var array<int, \Closure> $deferredMails */
        $deferredMails = [];

        try {
            // The public, login-free flow must never depend on Meilisearch being
            // reachable: persist the CRM customers without search syncing.
            $act = Customer::withoutSyncingToSearch(function () use ($request, $invite, $validated, $version, $brand, $managerIndex, &$storedPaths, &$supersededPaths, &$deferredMails) {
                return DB::transaction(function () use ($request, $invite, $validated, $version, $brand, $managerIndex, &$storedPaths, &$supersededPaths, &$deferredMails) {
                    // Atomic one-time claim. 0 affected rows → someone else redeemed first.
                    $claimed = ModelRegistrationInvite::where('id', $invite->id)
                        ->whereNull('used_at')
                        ->update(['used_at' => now()]);

                    if ($claimed === 0) {
                        throw new HttpResponseException(
                            response()->json(['error' => 'Diese Einladung wurde bereits verwendet.'], 409)
                        );
                    }

                    $persons = $validated['persons'];
                    $actAnswers = (array) ($validated['act']['answers'] ?? []);

                    /** @var array<int, Customer> $customers */
                    $customers = [];
                    /** @var array<int, array<string, mixed>> $personSummaries */
                    $personSummaries = [];
                    $managerCustomer = null;

                    foreach ($persons as $index => $person) {
                        $answers = $person['answers'];
                        $isManager = $index === $managerIndex;
                        $personContext = ['is_manager' => $isManager, 'person_count' => count($persons)];

                        $customer = $this->resolveCustomer($answers, $brand);
                        $ageProofRequired = true;

                        $profile = ModelProfile::updateOrCreate(
                            ['customer_id' => $customer->id],
                            [
                                'catalog_version' => $version,
                                'answers' => $this->questionnaire->buildSnapshot(
                                    'person',
                                    $answers,
                                    $personContext
                                ),
                                'gender' => $this->questionnaire->genderCode($answers['gender'] ?? null),
                                'age_proof_required' => $ageProofRequired,
                                'submitted_at' => now(),
                                'last_confirmed_at' => now(),
                            ]
                        );

                        if ($ageProofRequired) {
                            $storedPath = $this->storeAgeProof($request, $index, $profile, $supersededPaths);
                            if ($storedPath !== null) {
                                $storedPaths[] = $storedPath;
                            }
                        }

                        $this->storePhotos($request, $index, $customer, $profile, $storedPaths);

                        if ($this->toBoolean($person['create_account'] ?? false)) {
                            $this->provisionPortalAccount($customer, $answers, $brand, $deferredMails);
                        }

                        $customers[$index] = $customer->fresh();
                        if ($isManager) {
                            $managerCustomer = $customers[$index];
                        }

                        $personSummaries[] = $this->buildPersonSummary($customers[$index], $profile);
                    }

                    $managerCustomer ??= $customers[array_key_first($customers)];

                    $act = Act::create([
                        'brand' => $brand,
                        'manager_customer_id' => $managerCustomer->id,
                        'act_type' => Act::deriveType(count($customers)),
                        'catalog_version' => $version,
                        'answers' => $this->questionnaire->buildSnapshot('act', $actAnswers),
                        'person_count' => count($customers),
                        'submitted_at' => now(),
                    ]);

                    foreach ($customers as $index => $customer) {
                        ActMember::create([
                            'act_id' => $act->id,
                            'customer_id' => $customer->id,
                            'role' => $index === $managerIndex ? 'manager' : 'member',
                            'position' => $index,
                        ]);
                    }

                    ModelRegistrationInvite::where('id', $invite->id)->update([
                        'act_id' => $act->id,
                        'customer_id' => $managerCustomer->id,
                    ]);

                    $inviter = User::find($invite->invited_by);
                    if ($inviter) {
                        $successMail = new ModelRegistrationSuccessMail(
                            $inviter->name,
                            $managerCustomer->name ?? '',
                            count($customers),
                            $act->act_type,
                            $personSummaries,
                            Brand::tryFrom($brand),
                        );
                        $recipient = $inviter->email;
                        $deferredMails[] = static function () use ($recipient, $successMail): void {
                            Mail::to($recipient)->queue($successMail);
                        };
                    }

                    return $act;
                });
            });
        } catch (\Throwable $exception) {
            // A rolled-back transaction must not leave orphaned age proofs behind.
            if ($storedPaths !== []) {
                Storage::disk('local')->delete($storedPaths);
            }

            throw $exception;
        }

        // The superseded age proof is removed only after a successful commit:
        // deleting it inside the transaction would leave the rolled-back DB row
        // pointing at a file that no longer exists if anything fails later.
        if ($supersededPaths !== []) {
            $this->fileStore->delete($supersededPaths);
        }

        // Mails are dispatched only after the transaction committed, so a
        // rollback can never send a notification for data that was never stored.
        foreach ($deferredMails as $sendMail) {
            $sendMail();
        }

        return response()->json([
            'success' => true,
            'act_id' => $act->id,
            'person_count' => $act->person_count,
        ], 201);
    }

    /**
     * Resolve the CRM customer for one person: reuse an existing same-brand
     * customer (e-mail dedupe) or create a new one.
     *
     * @param  array<string, mixed>  $answers
     */
    private function resolveCustomer(array $answers, string $brand): Customer
    {
        $email = $answers['email'];
        $name = trim(($answers['first_name'] ?? '').' '.($answers['last_name'] ?? ''));

        $customer = Customer::where('brand', $brand)->where('email', $email)->first();

        if (! $customer) {
            return Customer::create([
                'name' => $name !== '' ? $name : null,
                'email' => $email,
                'street' => $answers['street'] ?? null,
                'zip' => $answers['zip'] ?? null,
                'city' => $answers['city'] ?? null,
                'country' => $answers['country'] ?? null,
                'birthdate' => $answers['birthdate'] ?? null,
                'brand' => $brand,
                'is_model' => true,
            ]);
        }

        $customer->fill(array_filter([
            'name' => $customer->name ?: $name,
            'street' => $customer->street ?: ($answers['street'] ?? null),
            'zip' => $customer->zip ?: ($answers['zip'] ?? null),
            'city' => $customer->city ?: ($answers['city'] ?? null),
            'country' => $customer->country ?: ($answers['country'] ?? null),
            'birthdate' => $customer->birthdate ?: ($answers['birthdate'] ?? null),
        ], static fn ($value) => $value !== null && $value !== ''));
        $customer->is_model = true;
        $customer->save();

        return $customer;
    }

    /**
     * Store the age proof encrypted on the private disk and return its path
     * (for cleanup if the surrounding transaction rolls back). A superseded
     * previous proof is collected in $supersededPaths so it can be removed only
     * after the transaction committed.
     *
     * @param  array<int, string>  $supersededPaths
     */
    private function storeAgeProof(Request $request, int $index, ModelProfile $profile, array &$supersededPaths): ?string
    {
        $file = $request->file("persons.{$index}.age_proof");
        if (! $file instanceof UploadedFile) {
            return null;
        }

        $stored = $this->fileStore->storeUpload("model-age-proofs/{$profile->customer_id}", $file);
        $path = $stored['path'];
        $previousPath = $profile->age_proof_path;

        $profile->forceFill([
            'age_proof_path' => $path,
            'age_proof_uploaded_at' => now(),
        ]);

        try {
            $profile->save();
        } catch (\Throwable $exception) {
            // The caller only tracks the path once save() succeeds; if the
            // metadata write fails, remove the already-stored file here so it
            // cannot be orphaned on the private disk.
            $this->fileStore->delete($path);

            throw $exception;
        }

        // A re-submit replaces the age proof. The superseded file is only
        // collected here and deleted after a successful commit by the caller,
        // so a later rollback keeps the DB-referenced old file intact.
        if ($previousPath && $previousPath !== $path) {
            $supersededPaths[] = $previousPath;
        }

        return $path;
    }

    /**
     * Store the uploaded person photos (max. 5, §2.4) encrypted, then elect a
     * single primary photo. Paths are tracked for rollback cleanup.
     *
     * @param  array<int, string>  $storedPaths
     */
    private function storePhotos(Request $request, int $index, Customer $customer, ModelProfile $profile, array &$storedPaths): void
    {
        $uploads = $request->file("persons.{$index}.photos");
        if (! is_array($uploads) || $uploads === []) {
            return;
        }

        $meta = (array) $request->input("persons.{$index}.photos", []);
        $start = (int) ($profile->photos()->max('position') ?? -1) + 1;

        /** @var array<int, ModelPhoto> $created */
        $created = [];
        $requestedPrimary = null;
        $position = $start;

        // Match metadata via the stable photo key (not a re-indexed offset):
        // clients may submit non-sequential array keys (e.g. photos[2], photos[5]).
        foreach ($uploads as $key => $upload) {
            $file = $upload['file'] ?? null;
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $stored = $this->fileStore->storeUpload("model-photos/{$customer->id}", $file);
            $storedPaths[] = $stored['path'];

            $photoMeta = is_array($meta[$key] ?? null) ? $meta[$key] : [];

            $visibility = $photoMeta['visibility'] ?? ModelPhoto::VISIBILITY_INTERNAL;
            if (! in_array($visibility, [ModelPhoto::VISIBILITY_PUBLIC, ModelPhoto::VISIBILITY_INTERNAL], true)) {
                $visibility = ModelPhoto::VISIBILITY_INTERNAL;
            }

            $photo = ModelPhoto::create([
                'customer_id' => $customer->id,
                'model_profile_id' => $profile->id,
                'path' => $stored['path'],
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $stored['mime'],
                'size_bytes' => $stored['size'],
                'visibility' => $visibility,
                'is_primary' => false,
                'position' => $position++,
            ]);

            if ($requestedPrimary === null && $this->toBoolean($photoMeta['is_primary'] ?? false)) {
                $requestedPrimary = $photo;
            }

            $created[] = $photo;
        }

        if ($created === []) {
            return;
        }

        // Exactly one primary per profile, and the primary must be public:
        // requested (already validated public) → first new public photo →
        // an existing public photo → none.
        $requestedPrimary = $requestedPrimary !== null
            && $requestedPrimary->visibility === ModelPhoto::VISIBILITY_PUBLIC
                ? $requestedPrimary
                : null;

        $createdIds = array_map(static fn (ModelPhoto $photo) => $photo->id, $created);

        $primary = $requestedPrimary
            ?? collect($created)->first(fn (ModelPhoto $photo) => $photo->visibility === ModelPhoto::VISIBILITY_PUBLIC)
            ?? $profile->photos()
                ->where('visibility', ModelPhoto::VISIBILITY_PUBLIC)
                ->whereNotIn('id', $createdIds)
                ->first();

        if ($primary !== null) {
            ModelPhoto::where('model_profile_id', $profile->id)->update(['is_primary' => false]);
            $primary->forceFill(['is_primary' => true])->save();
        }
    }

    /**
     * Facts for one person for the inviter's success mail. Built from the
     * in-transaction submit context — no extra queries beyond the loaded
     * relations.
     *
     * @return array<string, mixed>
     */
    private function buildPersonSummary(Customer $customer, ModelProfile $profile): array
    {
        $answers = $profile->answersMap();

        $categories = [];
        foreach (array_slice($profile->categories(), 0, 3) as $category) {
            $level = $answers['willingness_'.$category] ?? null;
            $categories[] = [
                'label' => ModelQuestionnaire::SHOOTING_CATEGORIES[$category]['label'] ?? $category,
                'willingness' => is_string($level) && $level !== ''
                    ? (ModelQuestionnaire::WILLINGNESS_LABELS[$level] ?? $level)
                    : null,
            ];
        }

        return [
            'id' => $customer->id,
            'name' => $customer->name,
            'age' => $customer->birthdate?->age,
            'city' => $customer->city,
            'categories' => $categories,
            'age_proof_uploaded' => $profile->age_proof_path !== null,
            'portal_account' => $customer->user_id !== null,
        ];
    }

    /**
     * Optional passwordless portal account (client role + activation mail),
     * mirroring AuthController::register().
     *
     * @param  array<string, mixed>  $answers
     * @param  array<int, \Closure>  $deferredMails
     */
    private function provisionPortalAccount(Customer $customer, array $answers, string $brand, array &$deferredMails): void
    {
        if (! $customer->email) {
            return;
        }

        $user = User::where('email', $customer->email)->first();

        if (! $user) {
            $user = User::create([
                'name' => $customer->name ?: ($answers['first_name'] ?? 'Model'),
                'email' => $customer->email,
                'password' => null,
                'brand' => $brand,
            ]);

            $this->assignClientRole($user);

            $token = Str::random(64);
            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $user->email],
                ['token' => Hash::make($token), 'created_at' => now()]
            );

            $link = BrandRegistry::frontendUrl(Brand::tryFrom($brand))
                .'/reset-password?token='.$token.'&email='.urlencode($user->email);

            $activationMail = new ActivateAccountMail(
                $user->name,
                'Für dich wurde ein Portal-Konto angelegt. Klicke hier, um ein Passwort zu vergeben:',
                $link,
                'Account aktivieren',
                'Dein Portal-Konto',
                Brand::tryFrom($brand),
            );
            $recipient = $user->email;
            $deferredMails[] = static function () use ($recipient, $activationMail): void {
                Mail::to($recipient)->send($activationMail);
            };
        } else {
            // Brand isolation: never link a foreign-brand account. Existing
            // cross-brand (brand = null) or same-brand accounts are linked.
            $userBrand = $user->brand instanceof Brand ? $user->brand->value : $user->brand;
            if ($userBrand !== null && $userBrand !== $brand) {
                return;
            }

            $this->assignClientRole($user);
        }

        $customer->user_id = $user->id;
        $customer->save();
    }

    private function assignClientRole(User $user): void
    {
        $role = Role::firstOrCreate(['name' => UserRole::CLIENT->value]);

        if (! $user->roles->contains($role->id)) {
            $user->roles()->attach($role->id);
        }
    }

    /**
     * Tolerant boolean parsing for multipart form values (e.g. "true", "on").
     */
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
}
