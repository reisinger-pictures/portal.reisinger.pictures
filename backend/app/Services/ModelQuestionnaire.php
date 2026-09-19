<?php

namespace App\Services;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Code-first Fragenkatalog der Model-Registrierung (Single-Katalog `v1`).
 *
 * Der Katalog ist inhaltlich: Bereitschaft VOR Erfahrung (inkl. Stock-Fotos),
 * Ausweis IMMER Pflicht, Einwilligungen als verständliche Sätze mit Verlinkung,
 * strukturiertes Agentur-Feld (`agency_name` + `agency_link`), kein Erotik.
 *
 * `catalog_version` wird weiterhin im Snapshot festgehalten: ein künftiger
 * neuer Fragenkatalog-Stand erfordert einen Bump von `CURRENT` + eine
 * eingefrorene Altdefinition, damit Einreichungen dauerhaft lesbar bleiben.
 */
class ModelQuestionnaire
{
    public const CURRENT = 'v1';

    public const EXPERIENCE_LEVELS = ['--', '-', '0', '+', '++'];

    /**
     * Sentinel der Erfahrungsskala ohne Erfahrung (`--` → „Keine").
     */
    public const EXPERIENCE_NONE = '--';

    /**
     * Deutsche Anzeige-Labels zur stabilen Erfahrungsskala.
     */
    public const EXPERIENCE_LABELS = [
        '--' => 'Keine',
        '-' => 'Wenig',
        '0' => 'Mittel',
        '+' => 'Erfahren',
        '++' => 'Profi',
    ];

    /**
     * Ordinale Bereitschafts-Skala: stabile Codes, Anzeige über deutsche
     * Labels. Höherer Ordinalwert = höhere Bereitschaft.
     */
    public const WILLINGNESS_LEVELS = ['nein', 'eher_nicht', 'wenn_es_sein_muss', 'gerne', 'sehr_gerne'];

    /** @var array<string, int> */
    public const WILLINGNESS_ORDINALS = [
        'nein' => 0,
        'eher_nicht' => 1,
        'wenn_es_sein_muss' => 2,
        'gerne' => 3,
        'sehr_gerne' => 4,
    ];

    /** @var array<string, string> */
    public const WILLINGNESS_LABELS = [
        'nein' => 'Nein',
        'eher_nicht' => 'Eher nicht',
        'wenn_es_sein_muss' => 'Eher ja',
        'gerne' => 'Gerne',
        'sehr_gerne' => 'Sehr gerne',
    ];

    /**
     * Kontaktwege mit Feld-Abhängigkeit (§2.3): ein gewählter Kanal verlangt
     * das jeweils hinterlegte, ausgefüllte Trägerfeld.
     */
    public const CONTACT_CHANNEL_FIELDS = [
        'Telefon' => 'phone',
        'WhatsApp' => 'phone',
        'Instagram' => 'instagram',
    ];

    /**
     * Shooting-Kategorien (stabil: Key/Label/Beschreibung). Fashion/Editorial
     * und Business/Corporate bleiben bewusst getrennt von Portrait.
     */
    public const SHOOTING_CATEGORIES = [
        'portrait' => [
            'label' => 'Portrait',
            'description' => 'Klassisches Portrait, Kopf bis Oberkörper; Ausdruck und Licht im Fokus.',
            'requires_age_proof' => false,
        ],
        'fashion' => [
            'label' => 'Fashion / Editorial',
            'description' => 'Stylische Looks, Mode- und Konzeptaufnahmen.',
            'requires_age_proof' => false,
        ],
        'business' => [
            'label' => 'Business / Corporate',
            'description' => 'Professionelle Business-Portraits und Team-Bilder.',
            'requires_age_proof' => false,
        ],
        'boudoir' => [
            'label' => 'Boudoir',
            'description' => 'Private, ästhetische Aufnahmen in Dessous/Reizwäsche.',
            'requires_age_proof' => false,
        ],
        'bikini' => [
            'label' => 'Bikini',
            'description' => 'Aufnahmen in Bade-/Strandmode.',
            'requires_age_proof' => true,
        ],
        'akt' => [
            'label' => 'Akt',
            'description' => 'Künstlerische Aufnahmen mit freier Darstellung des Körpers.',
            'requires_age_proof' => true,
        ],
        'sport' => [
            'label' => 'Sport / Fitness',
            'description' => 'Dynamische Aufnahmen in Sport-/Funktionskleidung.',
            'requires_age_proof' => false,
        ],
        'couple_family' => [
            'label' => 'Paar / Familie',
            'description' => 'Gemeinsame Shootings mehrerer Personen.',
            'requires_age_proof' => false,
        ],
    ];

    public function currentVersion(): string
    {
        return self::CURRENT;
    }

    /**
     * Complete catalog (sections with questions).
     *
     * @return array<string, array{key: string, label: string, questions: array<int, array<string, mixed>>}>
     */
    public function catalog(): array
    {
        return $this->catalogDefinition();
    }

    /**
     * @return array<string, array{key: string, label: string, questions: array<int, array<string, mixed>>}>
     */
    public function sections(): array
    {
        return $this->catalog();
    }

    /**
     * Flat list of questions for a scope (`person` or `act`).
     *
     * @return array<int, array<string, mixed>>
     */
    public function questions(string $scope): array
    {
        $questions = [];
        foreach ($this->catalog() as $section) {
            foreach ($section['questions'] as $question) {
                if (($question['scope'] ?? 'person') === $scope) {
                    $questions[] = $question;
                }
            }
        }

        return $questions;
    }

    /**
     * @return array<string, array{label: string, description: string, requires_age_proof: bool}>
     */
    public function categories(): array
    {
        return self::SHOOTING_CATEGORIES;
    }

    /**
     * @return array<int, array{key: string, label: string, description: string, requires_age_proof: bool}>
     */
    public function categoriesPayload(): array
    {
        $payload = [];
        foreach (self::SHOOTING_CATEGORIES as $key => $category) {
            $payload[] = array_merge(['key' => $key], $category);
        }

        return $payload;
    }

    public function categoryRequiresAgeProof(string $key): bool
    {
        return (bool) (self::SHOOTING_CATEGORIES[$key]['requires_age_proof'] ?? false);
    }

    /**
     * Category keys a person selected with an experience level other than the
     * no-experience sentinel (`--`).
     *
     * @param  array<string, mixed>  $answers
     * @return array<int, string>
     */
    public function selectedCategories(array $answers): array
    {
        $selected = [];
        foreach (array_keys(self::SHOOTING_CATEGORIES) as $key) {
            $level = $answers['experience_'.$key] ?? null;
            if (is_string($level) && $level !== '' && $level !== self::EXPERIENCE_NONE) {
                $selected[] = $key;
            }
        }

        return $selected;
    }

    /**
     * Ordinal value of a willingness level (higher = more willing), or null for
     * unknown/missing values.
     */
    public function willingnessOrdinal(?string $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::WILLINGNESS_ORDINALS[$value] ?? null;
    }

    /**
     * Ordinal value of an experience level (higher = more experienced), or null
     * for unknown/missing values. Derived from the ordered EXPERIENCE_LEVELS.
     */
    public function experienceOrdinal(?string $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $index = array_search($value, self::EXPERIENCE_LEVELS, true);

        return $index === false ? null : (int) $index;
    }

    /**
     * Admin match score: max over all shooting categories (plus `stock`) of
     * `willingnessOrdinal * 10 + experienceOrdinal`; missing values count as 0.
     *
     * A willing but inexperienced person therefore outranks a highly experienced
     * but unwilling one. Used as the default admin-list ordering.
     *
     * @param  array<string, mixed>  $answers
     */
    public function matchScore(array $answers): int
    {
        $score = 0;
        foreach (array_merge(array_keys(self::SHOOTING_CATEGORIES), ['stock']) as $category) {
            $willingness = $this->willingnessOrdinal($answers['willingness_'.$category] ?? null) ?? 0;
            $experience = $this->experienceOrdinal($answers['experience_'.$category] ?? null) ?? 0;
            $score = max($score, $willingness * 10 + $experience);
        }

        return $score;
    }

    /**
     * Highest experience ordinal across all shooting categories; -1 when no
     * category has an experience level. Used by `sort=experience` without a
     * category.
     *
     * @param  array<string, mixed>  $answers
     */
    public function maxExperienceOrdinal(array $answers): int
    {
        $max = -1;
        foreach (array_keys(self::SHOOTING_CATEGORIES) as $category) {
            $max = max($max, $this->experienceOrdinal($answers['experience_'.$category] ?? null) ?? -1);
        }

        return $max;
    }

    /**
     * Highest willingness ordinal across all categories plus `stock`; -1 when
     * none is set.
     *
     * @param  array<string, mixed>  $answers
     */
    public function maxWillingnessOrdinal(array $answers): int
    {
        $max = -1;
        foreach (array_merge(array_keys(self::SHOOTING_CATEGORIES), ['stock']) as $category) {
            $max = max($max, $this->willingnessOrdinal($answers['willingness_'.$category] ?? null) ?? -1);
        }

        return $max;
    }

    /**
     * Defense-in-depth mirror of the `preferred_contact` gating (§2.3): a
     * selected channel requires its backing field to be filled. Returns
     * field-key => error message, ready to be attached per person.
     *
     * @param  array<string, mixed>  $answers
     * @return array<string, string>
     */
    public function contactChannelErrors(array $answers): array
    {
        $selected = $answers['preferred_contact'] ?? [];
        if (is_string($selected)) {
            $selected = [$selected];
        }
        if (! is_array($selected)) {
            return [];
        }

        $errors = [];
        foreach (array_unique(array_map('strval', $selected)) as $channel) {
            $field = self::CONTACT_CHANNEL_FIELDS[$channel] ?? null;
            if ($field === null) {
                continue;
            }

            $value = $answers[$field] ?? null;
            if (! is_string($value) || trim($value) === '') {
                $errors[$field] = "Für den gewählten Kontaktweg „{$channel}“ ist das Feld „{$field}“ erforderlich.";
            }
        }

        return $errors;
    }

    /**
     * Map the German catalog value to the stored model_profiles.gender code.
     */
    public function genderCode(?string $value): ?string
    {
        return match ($value) {
            'weiblich' => 'female',
            'männlich' => 'male',
            'divers' => 'diverse',
            default => null,
        };
    }

    /**
     * Validation rules for one scope, keyed by question key (no prefix).
     *
     * `visible_if` conditions are evaluated against the raw answers so hidden
     * questions are neither required nor validated.
     *
     * @param  array<string, mixed>  $answers
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function rules(string $scope, array $answers, array $context = []): array
    {
        $rules = [];
        foreach ($this->questions($scope) as $question) {
            if (! $this->isVisible($question, $answers, $context)) {
                continue;
            }

            $type = $question['type'] ?? 'text';
            if ($type === 'file') {
                // Uploads are validated at request level (nested files).
                continue;
            }

            $key = $question['key'];
            $rules[$key] = $this->rulesForQuestion($question);

            if ($type === 'multiselect' && ! empty($question['options'])) {
                $rules[$key.'.*'] = [Rule::in($question['options'])];
            }
        }

        return $rules;
    }

    /**
     * Validate a single scope's answers and return the validated payload.
     *
     * @param  array<string, mixed>  $answers
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function validateAnswers(string $scope, array $answers, array $context = []): array
    {
        $validator = Validator::make($answers, $this->rules($scope, $answers, $context));

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $validator->validated();
    }

    /**
     * Build the frozen answers snapshot for one scope.
     *
     * @param  array<string, mixed>  $answers
     * @param  array<string, mixed>  $context
     * @return array<int, array{scope: string, key: string, label: string, type: string, value: mixed}>
     */
    public function buildSnapshot(string $scope, array $answers, array $context = []): array
    {
        $snapshot = [];
        foreach ($this->questions($scope) as $question) {
            if (! $this->isVisible($question, $answers, $context)) {
                continue;
            }

            // File uploads are stored as dedicated metadata (age_proof_path),
            // not as a misleading `value => null` answer.
            if (($question['type'] ?? 'text') === 'file') {
                continue;
            }

            $type = $question['type'] ?? 'text';

            $snapshot[] = [
                'scope' => $question['scope'] ?? $scope,
                'key' => $question['key'],
                'label' => $question['label'],
                'type' => $type,
                'value' => $this->snapshotValue($type, $answers[$question['key']] ?? null),
            ];
        }

        return $snapshot;
    }

    /**
     * Snapshot values are stored typed: checkbox answers become real booleans.
     * Multipart form values arrive as raw strings ("1"/"0"/"on"/"off"), and
     * Laravel's `accepted`/`boolean` rules validate but do not coerce them, so
     * normalising here keeps stored snapshots free of string booleans and lets
     * consumers read them directly without `normalizeAnswersForForm`.
     */
    private function snapshotValue(string $type, mixed $value): mixed
    {
        if ($type !== 'checkbox') {
            return $value;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (! is_scalar($value)) {
            return false;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
    }

    /**
     * @param  array<string, mixed>  $answers
     * @param  array<string, mixed>  $context
     */
    private function isVisible(array $question, array $answers, array $context): bool
    {
        foreach ($question['visible_if'] ?? [] as $condition) {
            $type = $condition['type'] ?? null;
            $visible = match ($type) {
                'is_manager' => (bool) ($context['is_manager'] ?? false),
                'multiple_persons' => ((int) ($context['person_count'] ?? 1)) > 1,
                'answer_equals' => ($answers[$condition['key']] ?? null) === ($condition['value'] ?? null),
                default => true,
            };

            if (! $visible) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $question
     * @return array<int, mixed>
     */
    private function rulesForQuestion(array $question): array
    {
        $type = $question['type'] ?? 'text';
        $required = (bool) ($question['required'] ?? false);

        if ($type === 'checkbox') {
            $rules = [$required ? 'accepted' : 'boolean'];
        } else {
            $rules = [$required ? 'required' : 'nullable'];
        }

        return array_merge($rules, match ($type) {
            'text' => ['string', 'max:255'],
            'textarea' => ['string', 'max:20000'],
            'email' => ['email', 'max:255'],
            'tel' => ['string', 'max:50'],
            'url' => ['url', 'max:2048'],
            'date' => ['date', 'before:today'],
            'number' => ['numeric'],
            'select' => ['string', Rule::in($question['options'] ?? [])],
            'multiselect' => ['array'],
            'checkbox' => [],
            'file' => [],
            default => ['string'],
        });
    }

    /**
     * Single catalog: A Basisdaten, B Aussehen & Maße, C Erfahrung & Portfolio,
     * G Einwilligungen, H Sonstiges.
     *
     * @return array<string, array{key: string, label: string, questions: array<int, array<string, mixed>>}>
     */
    private function catalogDefinition(): array
    {
        return [
            'basisdaten' => [
                'key' => 'basisdaten',
                'label' => 'Basisdaten',
                'questions' => [
                    ['key' => 'first_name', 'label' => 'Vorname', 'type' => 'text', 'required' => true, 'scope' => 'person'],
                    ['key' => 'last_name', 'label' => 'Nachname', 'type' => 'text', 'required' => true, 'scope' => 'person'],
                    ['key' => 'stage_name', 'label' => 'Künstlername / Pseudonym', 'type' => 'text', 'required' => false, 'scope' => 'person'],
                    ['key' => 'salutation', 'label' => 'Pronomen / Anrede', 'type' => 'text', 'required' => false, 'scope' => 'person'],
                    ['key' => 'birthdate', 'label' => 'Geburtsdatum', 'type' => 'date', 'required' => true, 'scope' => 'person'],
                    [
                        'key' => 'gender',
                        'label' => 'Geschlecht',
                        'type' => 'select',
                        'required' => false,
                        'scope' => 'person',
                        'options' => ['weiblich', 'männlich', 'divers', 'keine Angabe'],
                    ],
                    ['key' => 'email', 'label' => 'E-Mail', 'type' => 'email', 'required' => true, 'scope' => 'person'],
                    // Optional at the base level: required only when a phone-based
                    // contact channel is selected (§2.3, mirrored server-side by
                    // contactChannelErrors()).
                    ['key' => 'phone', 'label' => 'Telefon', 'type' => 'tel', 'required' => false, 'scope' => 'person'],
                    ['key' => 'street', 'label' => 'Straße & Nr.', 'type' => 'text', 'required' => true, 'scope' => 'person'],
                    ['key' => 'zip', 'label' => 'PLZ', 'type' => 'text', 'required' => true, 'scope' => 'person'],
                    ['key' => 'city', 'label' => 'Ort', 'type' => 'text', 'required' => true, 'scope' => 'person', 'searchable' => true],
                    ['key' => 'country', 'label' => 'Land', 'type' => 'text', 'required' => true, 'scope' => 'person', 'searchable' => true],
                    [
                        'key' => 'preferred_contact',
                        'label' => 'Bevorzugter Kontaktweg',
                        'type' => 'multiselect',
                        'required' => false,
                        'scope' => 'person',
                        'options' => ['E-Mail', 'Telefon', 'WhatsApp', 'Instagram', 'Sonstiges'],
                    ],
                    ['key' => 'instagram', 'label' => 'Instagram', 'type' => 'text', 'required' => false, 'scope' => 'person'],
                    ['key' => 'socials', 'label' => 'TikTok / sonstige Socials', 'type' => 'text', 'required' => false, 'scope' => 'person'],
                    ['key' => 'portfolio_url', 'label' => 'Portfolio / Website', 'type' => 'url', 'required' => false, 'scope' => 'person'],
                ],
            ],
            'aussehen' => [
                'key' => 'aussehen',
                'label' => 'Aussehen & Maße',
                'questions' => [
                    ['key' => 'height_cm', 'label' => 'Körpergröße (cm)', 'type' => 'number', 'required' => false, 'scope' => 'person'],
                    ['key' => 'measurements_bust', 'label' => 'Maße Brust (cm)', 'type' => 'number', 'required' => false, 'scope' => 'person'],
                    ['key' => 'measurements_waist', 'label' => 'Maße Taille (cm)', 'type' => 'number', 'required' => false, 'scope' => 'person'],
                    ['key' => 'measurements_hips', 'label' => 'Maße Hüfte (cm)', 'type' => 'number', 'required' => false, 'scope' => 'person'],
                    ['key' => 'hair_color', 'label' => 'Haarfarbe', 'type' => 'text', 'required' => false, 'scope' => 'person'],
                    [
                        'key' => 'hair_length',
                        'label' => 'Haarlänge',
                        'type' => 'select',
                        'required' => false,
                        'scope' => 'person',
                        'options' => ['kurz', 'mittel', 'lang', 'sehr lang', 'kahl'],
                    ],
                    ['key' => 'eye_color', 'label' => 'Augenfarbe', 'type' => 'text', 'required' => false, 'scope' => 'person'],
                    [
                        'key' => 'tattoos_piercings',
                        'label' => 'Tattoos / Piercings',
                        'type' => 'select',
                        'required' => false,
                        'scope' => 'person',
                        'options' => ['Ja', 'Nein'],
                    ],
                    [
                        'key' => 'tattoos_piercings_details',
                        'label' => 'Tattoos / Piercings (Details)',
                        'type' => 'textarea',
                        'required' => false,
                        'scope' => 'person',
                        'visible_if' => [
                            ['type' => 'answer_equals', 'key' => 'tattoos_piercings', 'value' => 'Ja'],
                        ],
                    ],
                ],
            ],
            'erfahrung' => [
                'key' => 'erfahrung',
                'label' => 'Erfahrung & Portfolio',
                'questions' => array_merge(
                    $this->willingnessAndExperienceQuestions(),
                    [
                        $this->willingnessQuestion('stock', 'Stock-Fotos'),
                        ['key' => 'previous_shoots', 'label' => 'Bisherige Shootings', 'type' => 'textarea', 'required' => false, 'scope' => 'person'],
                        ['key' => 'references', 'label' => 'Referenzen / Kollaborationen', 'type' => 'textarea', 'required' => false, 'scope' => 'person'],
                        ['key' => 'agency_name', 'label' => 'Agentur (Name)', 'type' => 'text', 'required' => false, 'scope' => 'person'],
                        ['key' => 'agency_link', 'label' => 'Agentur (Link)', 'type' => 'url', 'required' => false, 'scope' => 'person'],
                        ['key' => 'skills_details', 'label' => 'Fähigkeiten', 'type' => 'textarea', 'required' => false, 'scope' => 'person'],
                    ]
                ),
            ],
            'einwilligungen' => [
                'key' => 'einwilligungen',
                'label' => 'Einwilligungen',
                'questions' => [
                    [
                        'key' => 'age_proof',
                        'label' => 'Altersnachweis (Ausweis)',
                        'type' => 'file',
                        'required' => true,
                        'scope' => 'person',
                        'description' => 'Pflicht für jede Person — unabhängig vom Alter. JPG, PNG, WebP oder PDF, max. 10 MB.',
                    ],
                    [
                        'key' => 'consent_privacy',
                        'label' => 'Datenschutzerklärung',
                        'type' => 'checkbox',
                        'required' => true,
                        'scope' => 'person',
                        'description' => 'Ich habe die Datenschutzerklärung gelesen und akzeptiere sie.',
                        'url' => '/privacy',
                    ],
                    [
                        'key' => 'consent_accuracy',
                        'label' => 'Richtigkeit der Angaben',
                        'type' => 'checkbox',
                        'required' => true,
                        'scope' => 'person',
                        'description' => 'Ich versichere die Richtigkeit meiner Angaben.',
                    ],
                    [
                        'key' => 'consent_contact',
                        'label' => 'Kontaktaufnahme',
                        'type' => 'checkbox',
                        'required' => true,
                        'scope' => 'person',
                        'description' => 'Ich stimme der Kontaktaufnahme zu.',
                    ],
                    [
                        'key' => 'consent_photos',
                        'label' => 'Speicherung und Veröffentlichung von Fotos',
                        'type' => 'checkbox',
                        'required' => true,
                        'scope' => 'person',
                        'description' => 'Ich stimme der Speicherung der Personen-Fotos und, falls als öffentlich markiert, deren Veröffentlichung zu.',
                        'url' => '/license-terms',
                        'urls' => ['/license-terms', '/privacy'],
                    ],
                    [
                        'key' => 'consent_all_persons',
                        'label' => 'Einverständnis aller erfassten Personen',
                        'type' => 'checkbox',
                        'required' => true,
                        'scope' => 'person',
                        'description' => 'Ich versichere, dass alle erfassten Personen mit der Angabe ihrer Daten einverstanden sind.',
                        'visible_if' => [
                            ['type' => 'is_manager'],
                            ['type' => 'multiple_persons'],
                        ],
                    ],
                ],
            ],
            'sonstiges' => [
                'key' => 'sonstiges',
                'label' => 'Sonstiges',
                'questions' => [
                    ['key' => 'act_notes', 'label' => 'Anmerkungen zum Act', 'type' => 'textarea', 'required' => false, 'scope' => 'act'],
                    ['key' => 'person_notes', 'label' => 'Anmerkungen zur Person', 'type' => 'textarea', 'required' => false, 'scope' => 'person'],
                ],
            ],
        ];
    }

    /**
     * Interleaved matrix: per category willingness first, then experience.
     *
     * @return array<int, array<string, mixed>>
     */
    private function willingnessAndExperienceQuestions(): array
    {
        $questions = [];
        foreach (self::SHOOTING_CATEGORIES as $key => $category) {
            $questions[] = $this->willingnessQuestion($key, $category['label']);
            $questions[] = [
                'key' => 'experience_'.$key,
                'label' => 'Erfahrung: '.$category['label'],
                'type' => 'select',
                'required' => false,
                'scope' => 'person',
                'options' => self::EXPERIENCE_LEVELS,
                'option_labels' => self::EXPERIENCE_LABELS,
            ];
        }

        return $questions;
    }

    /**
     * @return array<string, mixed>
     */
    private function willingnessQuestion(string $key, string $label): array
    {
        return [
            'key' => 'willingness_'.$key,
            'label' => 'Bereitschaft: '.$label,
            'type' => 'select',
            'required' => true,
            'scope' => 'person',
            'options' => self::WILLINGNESS_LEVELS,
            'option_labels' => self::WILLINGNESS_LABELS,
        ];
    }
}
