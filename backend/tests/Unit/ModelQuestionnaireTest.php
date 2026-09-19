<?php

namespace Tests\Unit;

use App\Services\ModelQuestionnaire;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ModelQuestionnaireTest extends TestCase
{
    private ModelQuestionnaire $questionnaire;

    protected function setUp(): void
    {
        parent::setUp();
        $this->questionnaire = new ModelQuestionnaire;
    }

    /**
     * @return array<string, string>
     */
    private function willingnessDefaults(string $level = 'nein'): array
    {
        $defaults = ['willingness_stock' => $level];
        foreach (array_keys(ModelQuestionnaire::SHOOTING_CATEGORIES) as $category) {
            $defaults['willingness_'.$category] = $level;
        }

        return $defaults;
    }

    /**
     * @return array<string, mixed>
     */
    private function validPersonAnswers(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Anna',
            'last_name' => 'Beispiel',
            'birthdate' => '1995-05-05',
            'email' => 'anna@example.com',
            'phone' => '+43 660 1234567',
            'street' => 'Teststraße 1',
            'zip' => '4020',
            'city' => 'Linz',
            'country' => 'Österreich',
            'consent_privacy' => true,
            'consent_accuracy' => true,
            'consent_contact' => true,
            'consent_photos' => true,
        ], $this->willingnessDefaults(), $overrides);
    }

    public function test_current_version_is_v1(): void
    {
        $this->assertSame('v1', $this->questionnaire->currentVersion());
        $this->assertSame(ModelQuestionnaire::CURRENT, $this->questionnaire->currentVersion());
    }

    public function test_catalog_excludes_erotik_with_expected_categories(): void
    {
        $keys = array_column($this->questionnaire->categoriesPayload(), 'key');

        $this->assertSame([
            'portrait', 'fashion', 'business', 'boudoir', 'bikini', 'akt', 'sport', 'couple_family',
        ], $keys);
        $this->assertNotContains('erotik', $keys);
        $this->assertArrayNotHasKey('erotik', $this->questionnaire->categories());
        $this->assertNull(
            collect($this->questionnaire->questions('person'))->firstWhere('key', 'experience_erotik')
        );
    }

    public function test_category_age_proof_metadata_is_preserved(): void
    {
        $this->assertTrue($this->questionnaire->categoryRequiresAgeProof('bikini'));
        $this->assertTrue($this->questionnaire->categoryRequiresAgeProof('akt'));
        $this->assertFalse($this->questionnaire->categoryRequiresAgeProof('portrait'));
        $this->assertFalse($this->questionnaire->categoryRequiresAgeProof('unknown'));
    }

    public function test_catalog_contains_expected_sections_and_scopes(): void
    {
        $sections = $this->questionnaire->sections();

        $this->assertSame(
            ['basisdaten', 'aussehen', 'erfahrung', 'einwilligungen', 'sonstiges'],
            array_keys($sections)
        );

        $actQuestions = $this->questionnaire->questions('act');
        $this->assertCount(1, $actQuestions);
        $this->assertSame('act_notes', $actQuestions[0]['key']);

        $personQuestions = $this->questionnaire->questions('person');
        $this->assertNotEmpty($personQuestions);

        // Willingness + experience per category, plus the stock question.
        foreach (array_keys(ModelQuestionnaire::SHOOTING_CATEGORIES) as $category) {
            $this->assertNotNull(
                collect($personQuestions)->firstWhere('key', 'willingness_'.$category),
                "Fehlende Bereitschaftsfrage für {$category}"
            );
            $this->assertNotNull(
                collect($personQuestions)->firstWhere('key', 'experience_'.$category),
                "Fehlende Erfahrungsfrage für {$category}"
            );
        }
        $this->assertNotNull(collect($personQuestions)->firstWhere('key', 'willingness_stock'));

        // No weight question (explicitly excluded).
        $this->assertNull(collect($personQuestions)->firstWhere('key', 'weight'));
        // The legacy free-text agency field is replaced by name + link.
        $this->assertNull(collect($personQuestions)->firstWhere('key', 'agency'));
        $this->assertNotNull(collect($personQuestions)->firstWhere('key', 'agency_name'));
        $this->assertNotNull(collect($personQuestions)->firstWhere('key', 'agency_link'));

        // Skills: free text only — the multiselect and the former
        // languages/about_me questions were removed.
        $this->assertNull(collect($personQuestions)->firstWhere('key', 'skills'));
        $this->assertNull(collect($personQuestions)->firstWhere('key', 'languages'));
        $this->assertNull(collect($personQuestions)->firstWhere('key', 'about_me'));

        $skills = collect($personQuestions)->firstWhere('key', 'skills_details');
        $this->assertNotNull($skills);
        $this->assertSame('Fähigkeiten', $skills['label']);
        $this->assertSame('textarea', $skills['type']);
    }

    public function test_willingness_precedes_experience(): void
    {
        $questions = $this->questionnaire->catalog()['erfahrung']['questions'];
        $keys = array_column($questions, 'key');

        $willingness = array_search('willingness_portrait', $keys, true);
        $experience = array_search('experience_portrait', $keys, true);

        $this->assertNotFalse($willingness);
        $this->assertNotFalse($experience);
        $this->assertSame($willingness + 1, $experience);

        // The stock willingness question follows the whole matrix.
        $stock = array_search('willingness_stock', $keys, true);
        $lastExperience = array_search('experience_couple_family', $keys, true);
        $this->assertNotFalse($stock);
        $this->assertNotFalse($lastExperience);
        $this->assertGreaterThan($lastExperience, $stock);
    }

    public function test_age_proof_is_always_required(): void
    {
        $ageProof = collect($this->questionnaire->questions('person'))->firstWhere('key', 'age_proof');
        $this->assertNotNull($ageProof);
        $this->assertTrue($ageProof['required']);
        $this->assertArrayNotHasKey('visible_if', $ageProof);
    }

    public function test_selected_categories_ignores_no_experience(): void
    {
        $selected = $this->questionnaire->selectedCategories([
            'experience_portrait' => '-',
            'experience_bikini' => '--',
            'experience_akt' => '+',
        ]);

        $this->assertSame(['portrait', 'akt'], $selected);
    }

    public function test_experience_scale_uses_stable_codes_and_labels(): void
    {
        $question = collect($this->questionnaire->questions('person'))
            ->firstWhere('key', 'experience_portrait');

        $this->assertNotNull($question);
        $this->assertSame(['--', '-', '0', '+', '++'], ModelQuestionnaire::EXPERIENCE_LEVELS);
        $this->assertSame(ModelQuestionnaire::EXPERIENCE_LEVELS, $question['options']);
        $this->assertSame([
            '--' => 'Keine',
            '-' => 'Wenig',
            '0' => 'Mittel',
            '+' => 'Erfahren',
            '++' => 'Profi',
        ], $question['option_labels']);
    }

    public function test_build_snapshot_preserves_scope_key_label_type_and_value(): void
    {
        $answers = $this->validPersonAnswers(['stage_name' => 'Nova']);
        $snapshot = $this->questionnaire->buildSnapshot('person', $answers, ['is_manager' => false, 'person_count' => 2]);

        $firstName = collect($snapshot)->firstWhere('key', 'first_name');
        $this->assertSame([
            'scope' => 'person',
            'key' => 'first_name',
            'label' => 'Vorname',
            'type' => 'text',
            'value' => 'Anna',
        ], $firstName);

        $stageName = collect($snapshot)->firstWhere('key', 'stage_name');
        $this->assertSame('Nova', $stageName['value']);

        $willingness = collect($snapshot)->firstWhere('key', 'willingness_portrait');
        $this->assertSame('nein', $willingness['value']);

        // Manager-only consent is not part of a member snapshot.
        $this->assertNull(collect($snapshot)->firstWhere('key', 'consent_all_persons'));
    }

    public function test_snapshot_excludes_file_questions(): void
    {
        $snapshot = $this->questionnaire->buildSnapshot(
            'person',
            $this->validPersonAnswers(),
            ['is_manager' => false, 'person_count' => 2]
        );

        // The upload is stored as dedicated metadata, not as a `value => null` answer.
        $this->assertNull(collect($snapshot)->firstWhere('key', 'age_proof'));

        // Sanity: the file question is still part of the catalog (for the frontend).
        $this->assertNotNull(
            collect($this->questionnaire->questions('person'))->firstWhere('key', 'age_proof')
        );
    }

    public function test_snapshot_coerces_checkbox_values_to_booleans(): void
    {
        $snapshot = $this->questionnaire->buildSnapshot('person', $this->validPersonAnswers([
            'consent_privacy' => '1',
            'consent_accuracy' => '0',
            'consent_contact' => 'on',
            'consent_photos' => 'off',
        ]), ['is_manager' => false, 'person_count' => 2]);

        $this->assertSame(true, collect($snapshot)->firstWhere('key', 'consent_privacy')['value']);
        $this->assertSame(false, collect($snapshot)->firstWhere('key', 'consent_accuracy')['value']);
        $this->assertSame(true, collect($snapshot)->firstWhere('key', 'consent_contact')['value']);
        $this->assertSame(false, collect($snapshot)->firstWhere('key', 'consent_photos')['value']);
    }

    public function test_snapshot_defaults_missing_checkbox_to_false(): void
    {
        $answers = $this->validPersonAnswers();
        unset($answers['consent_photos']);

        $snapshot = $this->questionnaire->buildSnapshot('person', $answers, [
            'is_manager' => false,
            'person_count' => 2,
        ]);

        $this->assertSame(false, collect($snapshot)->firstWhere('key', 'consent_photos')['value']);
    }

    public function test_manager_consent_requires_manager_and_multiple_persons(): void
    {
        $memberRules = $this->questionnaire->rules('person', $this->validPersonAnswers(), [
            'is_manager' => false,
            'person_count' => 2,
        ]);
        $this->assertArrayNotHasKey('consent_all_persons', $memberRules);

        // Single-person acts are implicitly managed; no foreign-data consent needed.
        $singleManagerRules = $this->questionnaire->rules('person', $this->validPersonAnswers(), [
            'is_manager' => true,
            'person_count' => 1,
        ]);
        $this->assertArrayNotHasKey('consent_all_persons', $singleManagerRules);

        $managerRules = $this->questionnaire->rules('person', $this->validPersonAnswers(), [
            'is_manager' => true,
            'person_count' => 2,
        ]);
        $this->assertArrayHasKey('consent_all_persons', $managerRules);
        $this->assertContains('accepted', $managerRules['consent_all_persons']);
    }

    public function test_tattoo_details_are_visible_only_when_yes(): void
    {
        $no = $this->questionnaire->rules('person', $this->validPersonAnswers(['tattoos_piercings' => 'Nein']));
        $this->assertArrayNotHasKey('tattoos_piercings_details', $no);

        $yes = $this->questionnaire->rules('person', $this->validPersonAnswers(['tattoos_piercings' => 'Ja']));
        $this->assertArrayHasKey('tattoos_piercings_details', $yes);
    }

    public function test_willingness_is_a_required_select_with_stable_levels(): void
    {
        $rules = $this->questionnaire->rules('person', $this->validPersonAnswers());

        $levels = ModelQuestionnaire::WILLINGNESS_LEVELS;
        foreach (array_keys(ModelQuestionnaire::SHOOTING_CATEGORIES) as $category) {
            $key = 'willingness_'.$category;
            $this->assertContains('required', $rules[$key]);
            $this->assertContains('string', $rules[$key]);
        }
        $this->assertContains('required', $rules['willingness_stock']);

        // Experience stays optional.
        $this->assertContains('nullable', $rules['experience_portrait']);

        $this->assertSame(['nein', 'eher_nicht', 'wenn_es_sein_muss', 'gerne', 'sehr_gerne'], $levels);

        // Stable key, display label may be reworded (User 2026-09-19).
        $this->assertSame('Eher ja', ModelQuestionnaire::WILLINGNESS_LABELS['wenn_es_sein_muss']);
    }

    public function test_validate_answers_rejects_missing_willingness(): void
    {
        $answers = $this->validPersonAnswers();
        unset($answers['willingness_portrait']);

        $this->expectException(ValidationException::class);
        $this->questionnaire->validateAnswers('person', $answers);
    }

    public function test_validate_answers_rejects_unknown_willingness_level(): void
    {
        $this->expectException(ValidationException::class);
        $this->questionnaire->validateAnswers(
            'person',
            $this->validPersonAnswers(['willingness_portrait' => 'unmoeglich'])
        );
    }

    public function test_validate_answers_rejects_missing_required_field(): void
    {
        $answers = $this->validPersonAnswers();
        unset($answers['last_name']);

        $this->expectException(ValidationException::class);
        $this->questionnaire->validateAnswers('person', $answers);
    }

    public function test_validate_answers_rejects_invalid_select_option(): void
    {
        $this->expectException(ValidationException::class);
        $this->questionnaire->validateAnswers('person', $this->validPersonAnswers(['gender' => 'alien']));
    }

    public function test_validate_answers_accepts_multiselect_options(): void
    {
        $validated = $this->questionnaire->validateAnswers(
            'person',
            $this->validPersonAnswers(['preferred_contact' => ['E-Mail', 'Telefon']])
        );

        $this->assertSame(['E-Mail', 'Telefon'], $validated['preferred_contact']);
    }

    public function test_validate_answers_rejects_invalid_multiselect_option(): void
    {
        $this->expectException(ValidationException::class);
        $this->questionnaire->validateAnswers(
            'person',
            $this->validPersonAnswers(['preferred_contact' => ['Brieftaube']])
        );
    }

    public function test_contact_channel_errors_mirror_the_multiselect_gating(): void
    {
        $errors = $this->questionnaire->contactChannelErrors([
            'preferred_contact' => ['WhatsApp', 'Instagram'],
        ]);
        $this->assertArrayHasKey('phone', $errors);
        $this->assertArrayHasKey('instagram', $errors);

        // Fulfilled channels produce no errors.
        $this->assertSame([], $this->questionnaire->contactChannelErrors([
            'preferred_contact' => ['Telefon', 'WhatsApp', 'Instagram', 'E-Mail'],
            'phone' => '+43 660 1234567',
            'instagram' => '@anna',
        ]));

        // Channels without a backing field never block.
        $this->assertSame([], $this->questionnaire->contactChannelErrors([
            'preferred_contact' => ['E-Mail', 'Sonstiges'],
        ]));

        // A whitespace-only phone does not satisfy a phone channel.
        $this->assertArrayHasKey('phone', $this->questionnaire->contactChannelErrors([
            'preferred_contact' => ['Telefon'],
            'phone' => '   ',
        ]));
    }

    public function test_willingness_ordinal_lookup(): void
    {
        $this->assertSame(4, $this->questionnaire->willingnessOrdinal('sehr_gerne'));
        $this->assertSame(0, $this->questionnaire->willingnessOrdinal('nein'));
        $this->assertNull($this->questionnaire->willingnessOrdinal('unknown'));
        $this->assertNull($this->questionnaire->willingnessOrdinal(null));
    }

    public function test_experience_ordinal_lookup(): void
    {
        $this->assertSame(0, $this->questionnaire->experienceOrdinal('--'));
        $this->assertSame(1, $this->questionnaire->experienceOrdinal('-'));
        $this->assertSame(2, $this->questionnaire->experienceOrdinal('0'));
        $this->assertSame(3, $this->questionnaire->experienceOrdinal('+'));
        $this->assertSame(4, $this->questionnaire->experienceOrdinal('++'));
        $this->assertNull($this->questionnaire->experienceOrdinal('unknown'));
        $this->assertNull($this->questionnaire->experienceOrdinal(null));
    }

    public function test_match_score_prioritizes_willingness_over_experience(): void
    {
        // Willing but inexperienced: 3*10 + 0.
        $this->assertSame(30, $this->questionnaire->matchScore([
            'willingness_portrait' => 'gerne',
            'experience_portrait' => '--',
        ]));

        // Experienced but unwilling: 0*10 + 4.
        $this->assertSame(4, $this->questionnaire->matchScore([
            'willingness_portrait' => 'nein',
            'experience_portrait' => '++',
        ]));

        // Max across categories wins.
        $this->assertSame(30, $this->questionnaire->matchScore([
            'willingness_portrait' => 'nein',
            'experience_portrait' => '++',
            'willingness_sport' => 'gerne',
        ]));

        // Missing values count as 0.
        $this->assertSame(0, $this->questionnaire->matchScore([]));
    }

    public function test_max_ordinals_across_categories(): void
    {
        $answers = [
            'experience_portrait' => '-',
            'experience_sport' => '++',
            'willingness_portrait' => 'sehr_gerne',
            'willingness_stock' => 'eher_nicht',
        ];

        $this->assertSame(4, $this->questionnaire->maxExperienceOrdinal($answers));
        $this->assertSame(4, $this->questionnaire->maxWillingnessOrdinal($answers));
        $this->assertSame(-1, $this->questionnaire->maxExperienceOrdinal([]));
        $this->assertSame(-1, $this->questionnaire->maxWillingnessOrdinal([]));
    }

    public function test_consent_questions_carry_sentences_and_links(): void
    {
        $questions = collect($this->questionnaire->questions('person'));

        $privacy = $questions->firstWhere('key', 'consent_privacy');
        $this->assertNotNull($privacy);
        $this->assertStringContainsString('Datenschutzerklärung', $privacy['description']);
        $this->assertSame('/privacy', $privacy['url']);

        $photos = $questions->firstWhere('key', 'consent_photos');
        $this->assertNotNull($photos);
        $this->assertTrue($photos['required']);
        $this->assertSame(['/license-terms', '/privacy'], $photos['urls']);

        foreach (['consent_privacy', 'consent_accuracy', 'consent_contact', 'consent_all_persons'] as $key) {
            $question = $questions->firstWhere('key', $key);
            $this->assertNotNull($question, "Fehlende Einwilligung {$key}");
            $this->assertNotEmpty($question['description'] ?? null, "Fehlender Satz für {$key}");
        }
    }

    public function test_gender_code_mapping(): void
    {
        $this->assertSame('female', $this->questionnaire->genderCode('weiblich'));
        $this->assertSame('male', $this->questionnaire->genderCode('männlich'));
        $this->assertSame('diverse', $this->questionnaire->genderCode('divers'));
        $this->assertNull($this->questionnaire->genderCode('keine Angabe'));
        $this->assertNull($this->questionnaire->genderCode(null));
    }

    public function test_file_questions_have_no_inline_validation_rules(): void
    {
        $rules = $this->questionnaire->rules('person', $this->validPersonAnswers());

        $this->assertArrayNotHasKey('age_proof', $rules);
    }
}
