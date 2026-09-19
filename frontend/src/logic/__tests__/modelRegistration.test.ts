import { describe, it, expect } from 'vitest';
import {
    buildProfilePhotoPayload,
    buildRegistrationFormData,
    contactChannelsWithoutCarrier,
    createProfileAnswersSchema,
    createRegistrationSchema,
    defaultAnswerForQuestion,
    experienceMapFromAnswers,
    groupModelAnswersBySection,
    groupWillingnessByLevel,
    isAgeProofRequired,
    isCatalogOutdated,
    isContactChannelAvailable,
    isQuestionVisible,
    isScaleQuestion,
    isSkillRowRelevant,
    isTruthyAnswer,
    makeActAnswers,
    makePersonAnswers,
    makeRegistrationValues,
    modelActTypeLabel,
    modelActTypeLabels,
    modelActTypeOptions,
    modelAnswerSection,
    modelCategoryLabel,
    modelExperienceLabel,
    modelExperienceOptions,
    modelFilterCategories,
    modelGenderLabel,
    modelGenderOptions,
    modelLifecycleLabel,
    modelSkillMatrix,
    modelWillingnessCategories,
    modelWillingnessLabel,
    modelWillingnessOptions,
    normalizeAnswersForForm,
    questionOptionLabel,
    requiresAgeProof,
    scaleOptionLabel,
    sections,
    selectedCategories,
    sortSkillMatrixRows,
    willingnessLevelFromOrdinal,
    willingnessOrdinal,
    type ModelProfileAccessPhoto,
    type ModelRegistrationCheck,
    type ModelProfileAnswer,
    type RegistrationFormValues,
} from '../modelRegistration';

const catalog: ModelRegistrationCheck = {
    brand: 'rp',
    status: 'open',
    email: 'manager@example.com',
    person_count: 0,
    expires_at: null,
    catalog_version: 'v1',
    categories: [
        { key: 'portrait', label: 'Portrait', description: '', requires_age_proof: false },
        { key: 'bikini', label: 'Bikini', description: '', requires_age_proof: true },
    ],
    sections: {
        basisdaten: {
            key: 'basisdaten',
            label: 'Basisdaten',
            questions: [
                { key: 'first_name', label: 'Vorname', type: 'text', required: true, scope: 'person' },
                { key: 'email', label: 'E-Mail', type: 'email', required: true, scope: 'person' },
                { key: 'phone', label: 'Telefon', type: 'tel', required: false, scope: 'person' },
                { key: 'instagram', label: 'Instagram', type: 'text', required: false, scope: 'person' },
                { key: 'portfolio_url', label: 'Portfolio', type: 'url', required: false, scope: 'person' },
                { key: 'preferred_contact', label: 'Kontaktweg', type: 'multiselect', required: false, scope: 'person', options: ['E-Mail', 'Telefon', 'WhatsApp', 'Instagram', 'Sonstiges'] },
                {
                    key: 'willingness_bikini',
                    label: 'Bereitschaft: Bikini',
                    type: 'select',
                    required: true,
                    scope: 'person',
                    options: ['nein', 'gerne', 'sehr_gerne'],
                    option_labels: { nein: 'Nein', gerne: 'Gerne', sehr_gerne: 'Sehr gerne' },
                },
                { key: 'experience_bikini', label: 'Erfahrung: Bikini', type: 'select', required: false, scope: 'person', options: ['keine', 'Anfänger'] },
            ],
        },
        aussehen: {
            key: 'aussehen',
            label: 'Aussehen & Maße',
            questions: [
                { key: 'height_cm', label: 'Körpergröße (cm)', type: 'number', required: false, scope: 'person' },
            ],
        },
        einwilligungen: {
            key: 'einwilligungen',
            label: 'Einwilligungen',
            questions: [
                { key: 'age_proof', label: 'Altersnachweis', type: 'file', required: true, scope: 'person' },
                {
                    key: 'consent_privacy',
                    label: 'Datenschutzerklärung',
                    type: 'checkbox',
                    required: true,
                    scope: 'person',
                    description: 'Ich habe die Datenschutzerklärung gelesen und akzeptiere sie.',
                    url: '/privacy',
                },
                {
                    key: 'consent_all_persons',
                    label: 'Alle Personen einverstanden',
                    type: 'checkbox',
                    required: true,
                    scope: 'person',
                    visible_if: [{ type: 'is_manager' }, { type: 'multiple_persons' }],
                },
            ],
        },
        sonstiges: {
            key: 'sonstiges',
            label: 'Sonstiges',
            questions: [
                { key: 'act_notes', label: 'Anmerkungen zum Act', type: 'textarea', required: false, scope: 'act' },
            ],
        },
    },
};

function person(answers: Record<string, unknown>, overrides: Partial<RegistrationFormValues['persons'][number]> = {}) {
    return {
        answers: { willingness_bikini: 'nein', ...answers },
        create_account: false,
        // v2 makes the age proof mandatory; tests that exercise the missing
        // proof pass `age_proof: null` explicitly.
        age_proof: new File(['x'], 'id.jpg', { type: 'image/jpeg' }) as File | null,
        photos: [] as RegistrationFormValues['persons'][number]['photos'],
        ...overrides,
    };
}

function validValues(overrides: Partial<RegistrationFormValues> = {}): RegistrationFormValues {
    return {
        persons: [
            person({
                first_name: 'Maria',
                email: 'maria@example.com',
                portfolio_url: '',
                preferred_contact: [],
                experience_bikini: 'keine',
                consent_privacy: true,
                consent_all_persons: true,
            }),
        ],
        act_answers: { act_notes: '' },
        manager_index: 0,
        ...overrides,
    };
}

describe('createRegistrationSchema', () => {
    it('accepts a minimal valid person', () => {
        const schema = createRegistrationSchema(catalog);
        expect(schema.safeParse(validValues()).success).toBe(true);
    });

    it('rejects an empty person list', () => {
        const schema = createRegistrationSchema(catalog);
        expect(schema.safeParse(validValues({ persons: [] })).success).toBe(false);
    });

    it('requires mandatory text, email and willingness fields', () => {
        const schema = createRegistrationSchema(catalog);
        const result = schema.safeParse(
            validValues({
                persons: [person({ first_name: '', email: 'not-an-email', consent_privacy: true, consent_all_persons: true })],
            }),
        );
        expect(result.success).toBe(false);
        if (!result.success) {
            const paths = result.error.issues.map(issue => issue.path.join('.'));
            expect(paths).toContain('persons.0.answers.first_name');
            expect(paths).toContain('persons.0.answers.email');
        }
    });

    it('validates optional url only when filled', () => {
        const schema = createRegistrationSchema(catalog);
        const invalid = schema.safeParse(
            validValues({
                persons: [person({ first_name: 'Maria', email: 'maria@example.com', portfolio_url: 'not-a-url', consent_privacy: true, consent_all_persons: true })],
            }),
        );
        expect(invalid.success).toBe(false);
        expect(schema.safeParse(validValues()).success).toBe(true);
    });

    it('requires the manager consent only for the manager of a multi-person act', () => {
        const schema = createRegistrationSchema(catalog);
        const twoPersons = validValues({
            manager_index: 0,
            persons: [
                person({ first_name: 'Maria', email: 'maria@example.com', consent_privacy: true, consent_all_persons: true }),
                person({ first_name: 'Anna', email: 'anna@example.com', consent_privacy: true }),
            ],
        });
        expect(schema.safeParse(twoPersons).success).toBe(true);

        const managerWithoutConsent = validValues({
            manager_index: 0,
            persons: [
                person({ first_name: 'Maria', email: 'maria@example.com', consent_privacy: true }),
                person({ first_name: 'Anna', email: 'anna@example.com', consent_privacy: true }),
            ],
        });
        const result = schema.safeParse(managerWithoutConsent);
        expect(result.success).toBe(false);
        if (!result.success) {
            expect(result.error.issues.map(issue => issue.path.join('.'))).toContain('persons.0.answers.consent_all_persons');
        }
    });

    it('does not require the manager consent for a single-person act', () => {
        const schema = createRegistrationSchema(catalog);
        const single = validValues({
            persons: [person({ first_name: 'Maria', email: 'maria@example.com', consent_privacy: true })],
        });
        expect(schema.safeParse(single).success).toBe(true);
    });

    it('always demands an age proof in v2', () => {
        const schema = createRegistrationSchema(catalog);
        const withoutProof = validValues({
            persons: [person({ first_name: 'Maria', email: 'maria@example.com', consent_privacy: true }, { age_proof: null })],
        });
        const failed = schema.safeParse(withoutProof);
        expect(failed.success).toBe(false);
        if (!failed.success) {
            expect(failed.error.issues.map(issue => issue.path.join('.'))).toContain('persons.0.age_proof');
        }

        const withProof = validValues({
            persons: [person(
                { first_name: 'Maria', email: 'maria@example.com', consent_privacy: true },
                { age_proof: new File(['x'], 'id.jpg', { type: 'image/jpeg' }) },
            )],
        });
        expect(schema.safeParse(withProof).success).toBe(true);
    });

    it('rejects a manager index outside the person list', () => {
        const schema = createRegistrationSchema(catalog);
        const result = schema.safeParse(validValues({ manager_index: 5 }));
        expect(result.success).toBe(false);
        if (!result.success) {
            expect(result.error.issues.map(issue => issue.path.join('.'))).toContain('manager_index');
        }
    });

    it('mirrors the contact-channel gating in the schema', () => {
        const schema = createRegistrationSchema(catalog);
        const missingCarrier = validValues({
            persons: [person({
                first_name: 'Maria',
                email: 'maria@example.com',
                preferred_contact: ['WhatsApp'],
                consent_privacy: true,
                consent_all_persons: true,
            })],
        });
        const failed = schema.safeParse(missingCarrier);
        expect(failed.success).toBe(false);
        if (!failed.success) {
            expect(failed.error.issues.map(issue => issue.path.join('.'))).toContain('persons.0.answers.preferred_contact');
        }

        const withCarrier = validValues({
            persons: [person({
                first_name: 'Maria',
                email: 'maria@example.com',
                phone: '+43 660 1',
                preferred_contact: ['WhatsApp'],
                consent_privacy: true,
                consent_all_persons: true,
            })],
        });
        expect(schema.safeParse(withCarrier).success).toBe(true);
    });

    it('accepts a valid multiselect and rejects unknown options', () => {
        const schema = createRegistrationSchema(catalog);
        const valid = validValues({
            persons: [person({ first_name: 'Maria', email: 'maria@example.com', preferred_contact: ['E-Mail'], consent_privacy: true, consent_all_persons: true })],
        });
        expect(schema.safeParse(valid).success).toBe(true);

        const invalid = validValues({
            persons: [person({ first_name: 'Maria', email: 'maria@example.com', preferred_contact: ['Brieftaube'], consent_privacy: true, consent_all_persons: true })],
        });
        expect(schema.safeParse(invalid).success).toBe(false);
    });
});

describe('createProfileAnswersSchema', () => {
    it('rejects an internal photo as the main image (server rule mirrored)', () => {
        const schema = createRegistrationSchema(catalog);
        const result = schema.safeParse(validValues({
            persons: [person(
                { first_name: 'Maria', email: 'maria@example.com', consent_privacy: true, consent_all_persons: true },
                { photos: [{ id: 'p1', file: new File(['x'], 'a.jpg', { type: 'image/jpeg' }), visibility: 'internal', is_primary: true }] },
            )],
        }));
        expect(result.success).toBe(false);
        if (!result.success) {
            expect(result.error.issues.map(issue => issue.path.join('.'))).toContain('persons.0.photos.0.is_primary');
        }
    });

    it('validates the flat answers map and mirrors contact gating', () => {
        const schema = createProfileAnswersSchema(catalog);
        const valid = { answers: { first_name: 'Maria', email: 'maria@example.com', willingness_bikini: 'nein', consent_privacy: true } };
        expect(schema.safeParse(valid).success).toBe(true);

        const missingCarrier = { answers: { first_name: 'Maria', email: 'maria@example.com', willingness_bikini: 'nein', consent_privacy: true, preferred_contact: ['Telefon'] } };
        const result = schema.safeParse(missingCarrier);
        expect(result.success).toBe(false);
        if (!result.success) {
            expect(result.error.issues.map(issue => issue.path.join('.'))).toContain('answers.preferred_contact');
        }
    });
});

describe('catalogue helpers', () => {
    it('exposes sections and detects selected categories', () => {
        expect(sections(catalog).map(section => section.key)).toEqual(['basisdaten', 'aussehen', 'einwilligungen', 'sonstiges']);
        expect(selectedCategories({ experience_bikini: 'Anfänger' }, catalog.categories)).toEqual(['bikini']);
        expect(selectedCategories({ experience_bikini: 'keine' }, catalog.categories)).toEqual([]);
        expect(requiresAgeProof({ experience_bikini: 'Profi' }, catalog.categories)).toBe(true);
        expect(requiresAgeProof({ experience_portrait: 'Profi' }, catalog.categories)).toBe(false);
    });

    it('evaluates visible_if conditions including multiple_persons', () => {
        const managerConsent = sections(catalog)[2].questions[2];
        expect(isQuestionVisible(managerConsent, {}, { categories: catalog.categories, isManager: true, personCount: 2 })).toBe(true);
        expect(isQuestionVisible(managerConsent, {}, { categories: catalog.categories, isManager: true, personCount: 1 })).toBe(false);
        expect(isQuestionVisible(managerConsent, {}, { categories: catalog.categories, isManager: false, personCount: 2 })).toBe(false);
    });

    it('reports the v2 age proof as unconditionally required', () => {
        const ageProof = sections(catalog)[2].questions[0];
        expect(isAgeProofRequired(ageProof, {}, { categories: catalog.categories, isManager: false, personCount: 1 })).toBe(true);
        expect(isAgeProofRequired(undefined, {}, { categories: catalog.categories, isManager: false })).toBe(false);
    });

    it('builds default answers with click-saving willingness/experience defaults', () => {
        const answers = makePersonAnswers(catalog);
        expect(answers.first_name).toBe('');
        expect(answers.consent_privacy).toBe(false);
        expect(answers.preferred_contact).toEqual([]);
        expect(answers.willingness_bikini).toBe('nein');
        // Fixture uses legacy labels; the real catalogue uses `--`.
        expect(answers.experience_bikini).toBe('keine');
        expect('age_proof' in answers).toBe(false);

        expect(makeActAnswers(catalog)).toEqual({ act_notes: '' });
        expect(makeRegistrationValues(catalog).persons).toHaveLength(1);
        expect(makeRegistrationValues(catalog).persons[0].photos).toEqual([]);
    });

    it('derives per-question defaults', () => {
        expect(defaultAnswerForQuestion({ key: 'willingness_stock', label: 'x', type: 'select', required: true, scope: 'person', options: ['nein', 'gerne', 'sehr_gerne'] })).toBe('nein');
        expect(defaultAnswerForQuestion({ key: 'experience_akt', label: 'x', type: 'select', required: false, scope: 'person', options: ['--', '-', '0', '+', '++'] })).toBe('--');
        expect(defaultAnswerForQuestion({ key: 'experience_akt', label: 'x', type: 'select', required: false, scope: 'person' })).toBe('');
        expect(defaultAnswerForQuestion({ key: 'gender', label: 'x', type: 'select', required: false, scope: 'person' })).toBe('');
    });

    it('gates contact channels on their carrier fields', () => {
        expect(isContactChannelAvailable('E-Mail', { email: 'a@b.de' })).toBe(true);
        expect(isContactChannelAvailable('E-Mail', { email: '' })).toBe(false);
        expect(isContactChannelAvailable('WhatsApp', { phone: '+43 1' })).toBe(true);
        expect(isContactChannelAvailable('Sonstiges', {})).toBe(true);
        expect(contactChannelsWithoutCarrier({ preferred_contact: ['Telefon'], phone: '' })).toEqual(['Telefon']);
        expect(contactChannelsWithoutCarrier({ preferred_contact: ['Telefon'], phone: '+43 1' })).toEqual([]);
    });

    it('normalizes snapshot answers for form defaults', () => {
        expect(isTruthyAnswer('1')).toBe(true);
        expect(isTruthyAnswer('0')).toBe(false);
        expect(isTruthyAnswer('true')).toBe(true);
        expect(isTruthyAnswer(false)).toBe(false);

        const normalized = normalizeAnswersForForm(catalog, {
            consent_privacy: '1',
            consent_all_persons: '0',
            preferred_contact: 'E-Mail',
        });
        expect(normalized.consent_privacy).toBe(true);
        expect(normalized.consent_all_persons).toBe(false);
        expect(normalized.preferred_contact).toEqual(['E-Mail']);
    });

    it('maps option codes through option_labels', () => {
        const question = sections(catalog)[0].questions.find(item => item.key === 'willingness_bikini')!;
        expect(questionOptionLabel(question, 'sehr_gerne')).toBe('Sehr gerne');
        expect(questionOptionLabel(question, 'unknown')).toBe('unknown');
    });
});

describe('buildRegistrationFormData', () => {
    it('serialises persons, booleans, files and photos per contract', () => {
        const file = new File(['x'], 'id.jpg', { type: 'image/jpeg' });
        const photoA = new File(['a'], 'a.jpg', { type: 'image/jpeg' });
        const photoB = new File(['b'], 'b.png', { type: 'image/png' });
        const formData = buildRegistrationFormData({
            persons: [
                {
                    answers: {
                        first_name: 'Maria',
                        preferred_contact: ['E-Mail', 'Telefon'],
                        consent_privacy: true,
                        experience_bikini: 'Anfänger',
                    },
                    create_account: true,
                    age_proof: file,
                    photos: [
                        { id: 'p1', file: photoA, visibility: 'public', is_primary: true },
                        { id: 'p2', file: photoB, visibility: 'internal', is_primary: false },
                    ],
                },
                {
                    answers: { first_name: 'Anna', consent_privacy: false },
                    create_account: false,
                    age_proof: null,
                    photos: [],
                },
            ],
            act_answers: { act_notes: 'Notiz' },
            manager_index: 1,
        });

        expect(formData.get('persons[0][answers][first_name]')).toBe('Maria');
        expect(formData.getAll('persons[0][answers][preferred_contact][]')).toEqual(['E-Mail', 'Telefon']);
        expect(formData.get('persons[0][answers][consent_privacy]')).toBe('1');
        expect(formData.get('persons[0][create_account]')).toBe('1');
        expect(formData.get('persons[0][age_proof]')).toBe(file);
        expect(formData.get('persons[0][photos][0][file]')).toBe(photoA);
        expect(formData.get('persons[0][photos][0][visibility]')).toBe('public');
        expect(formData.get('persons[0][photos][0][is_primary]')).toBe('1');
        expect(formData.get('persons[0][photos][1][file]')).toBe(photoB);
        expect(formData.get('persons[0][photos][1][is_primary]')).toBe('0');
        expect(formData.get('persons[1][create_account]')).toBe('0');
        expect(formData.get('persons[1][answers][consent_privacy]')).toBe('0');
        expect(formData.get('act[answers][act_notes]')).toBe('Notiz');
        expect(formData.get('manager_index')).toBe('1');
    });
});

describe('isCatalogOutdated', () => {
    it('flags missing and older versions', () => {
        // Current catalogue is v1 (single-v1): equal or newer = current.
        expect(isCatalogOutdated('v1')).toBe(false);
        expect(isCatalogOutdated('v2')).toBe(false);
        expect(isCatalogOutdated(null)).toBe(true);
        expect(isCatalogOutdated('')).toBe(true);
        expect(isCatalogOutdated('v0')).toBe(true);
    });
});

describe('admin label helpers', () => {
    it('resolves stored gender codes to German labels', () => {
        expect(modelGenderLabel('female')).toBe('Weiblich');
        expect(modelGenderLabel('male')).toBe('Männlich');
        expect(modelGenderLabel('diverse')).toBe('Divers');
        expect(modelGenderLabel(null)).toBe('–');
        expect(modelGenderLabel('other')).toBe('other');
    });

    it('resolves act_type codes to German labels', () => {
        expect(modelActTypeLabel('single')).toBe('Einzelperson');
        expect(modelActTypeLabel('couple')).toBe('Paar');
        expect(modelActTypeLabel('group')).toBe('Gruppe');
        expect(modelActTypeLabel('unknown')).toBe('unknown');
        expect(modelActTypeLabels(['single', 'group'])).toEqual(['Einzelperson', 'Gruppe']);
    });

    it('resolves category keys to German labels without erotik (v2)', () => {
        expect(modelCategoryLabel('couple_family')).toBe('Paar / Familie');
        expect(modelCategoryLabel('akt')).toBe('Akt');
        expect(modelCategoryLabel('new_category')).toBe('new_category');
    });

    it('resolves willingness codes and lifecycle statuses', () => {
        expect(modelWillingnessOptions().map(option => option.value)).toEqual(['nein', 'eher_nicht', 'wenn_es_sein_muss', 'gerne', 'sehr_gerne']);
        expect(modelWillingnessLabel('sehr_gerne')).toBe('Sehr gerne');
        expect(modelWillingnessLabel('unknown')).toBe('unknown');
        expect(modelWillingnessLabel(null)).toBeNull();
        expect(modelLifecycleLabel('active')).toBe('Aktiv');
        expect(modelLifecycleLabel('expired')).toBe('Abgelaufen');
    });

    it('keeps filter option lists aligned with the v2 backend catalogue keys', () => {
        expect(modelFilterCategories().map(option => option.key)).toEqual([
            'portrait',
            'fashion',
            'business',
            'boudoir',
            'bikini',
            'akt',
            'sport',
            'couple_family',
        ]);
        expect(modelGenderOptions().map(option => option.value)).toEqual(['female', 'male', 'diverse']);
        expect(modelActTypeOptions().map(option => option.value)).toEqual(['single', 'couple', 'group']);
        expect(modelWillingnessCategories().map(option => option.key)).toContain('stock');
    });
});

describe('buildProfilePhotoPayload', () => {
    it('explicitly clears the primary flag when the main photo is internal', () => {
        const photos: ModelProfileAccessPhoto[] = [
            { id: 'p1', visibility: 'internal', is_primary: true, mime_type: 'image/jpeg' },
            { id: 'p2', visibility: 'public', is_primary: false, mime_type: 'image/jpeg' },
        ];
        expect(buildProfilePhotoPayload(photos)).toEqual([
            { id: 'p1', visibility: 'internal', is_primary: false },
            { id: 'p2', visibility: 'public', is_primary: false },
        ]);
    });

    it('keeps a public primary photo', () => {
        expect(buildProfilePhotoPayload([{ id: 'p1', visibility: 'public', is_primary: true, mime_type: null }]))
            .toEqual([{ id: 'p1', visibility: 'public', is_primary: true }]);
    });
});

describe('scale helpers', () => {
    it('maps willingness ordinals and levels', () => {
        expect(willingnessOrdinal('gerne')).toBe(3);
        expect(willingnessOrdinal('nope')).toBe(0);
        expect(willingnessLevelFromOrdinal(4)).toBe('sehr_gerne');
        expect(willingnessLevelFromOrdinal(99)).toBe('sehr_gerne');
    });

    it('detects scale questions and resolves experience labels', () => {
        const willingnessQuestion = sections(catalog)[0].questions.find(question => question.key === 'willingness_bikini')!;
        const experienceQuestion = sections(catalog)[0].questions.find(question => question.key === 'experience_bikini')!;
        expect(isScaleQuestion(willingnessQuestion)).toBe(true);
        expect(isScaleQuestion(experienceQuestion)).toBe(true);
        expect(scaleOptionLabel(willingnessQuestion, 'gerne')).toBe('Gerne');
        expect(scaleOptionLabel(experienceQuestion, '++')).toBe('Profi');
        expect(modelExperienceOptions().map(option => option.value)).toEqual(['--', '-', '0', '+', '++']);
        expect(modelExperienceLabel('0')).toBe('Mittel');
    });

    it('groups willingness by level descending', () => {
        const groups = groupWillingnessByLevel([
            { level: 'nein', label: 'Portrait' },
            { level: 'sehr_gerne', label: 'Akt' },
            { level: 'sehr_gerne', label: 'Bikini' },
        ]);
        expect(groups.map(group => group.level)).toEqual(['sehr_gerne', 'nein']);
        expect(groups[0].items.map(item => item.label)).toEqual(['Akt', 'Bikini']);
    });
});

describe('skill matrix & answer sections', () => {
    it('builds the category matrix from willingness + experience', () => {
        const rows = modelSkillMatrix({ willingness_bikini: 'gerne', willingness_stock: 'nein' }, { experience_bikini: '+' });
        const bikini = rows.find(row => row.key === 'bikini')!;
        expect(bikini.experience).toBe('+');
        expect(bikini.willingness).toBe('gerne');
        const stock = rows.find(row => row.key === 'stock')!;
        expect(stock.experience).toBeNull();
        expect(stock.willingness).toBe('nein');
        expect(isSkillRowRelevant(stock)).toBe(false);
        expect(isSkillRowRelevant(bikini)).toBe(true);
    });

    it('sorts rows by experience desc, then willingness desc as tiebreak', () => {
        const rows = modelSkillMatrix(
            { willingness_portrait: 'sehr_gerne', willingness_bikini: 'nein', willingness_akt: 'gerne', willingness_stock: 'nein' },
            { experience_portrait: '0', experience_bikini: '++', experience_akt: '++' },
        );
        // akt/bikini share experience ++, akt wins the tiebreak (gerne > nein).
        expect(sortSkillMatrixRows(rows).slice(0, 3).map(row => row.key)).toEqual(['akt', 'bikini', 'portrait']);
    });

    it('pins matched categories on top in filter order', () => {
        const rows = modelSkillMatrix(
            { willingness_portrait: 'nein', willingness_stock: 'sehr_gerne' },
            { experience_portrait: '0' },
        );
        const sorted = sortSkillMatrixRows(rows, ['stock', 'portrait']);
        expect(sorted.slice(0, 2).map(row => row.key)).toEqual(['stock', 'portrait']);
    });

    it('reads experience codes from the snapshot', () => {
        const answers: ModelProfileAnswer[] = [
            { scope: 'person', key: 'experience_bikini', label: 'Erfahrung: Bikini', type: 'select', value: '++' },
            { scope: 'person', key: 'first_name', label: 'Vorname', type: 'text', value: 'Maria' },
        ];
        expect(experienceMapFromAnswers(answers)).toEqual({ experience_bikini: '++' });
    });

    it('sections answers like the form and hides unknown internal keys', () => {
        expect(modelAnswerSection('first_name')).toBe('basisdaten');
        expect(modelAnswerSection('height_cm')).toBe('aussehen');
        expect(modelAnswerSection('willingness_bikini')).toBe('erfahrung');
        expect(modelAnswerSection('experience_bikini')).toBe('erfahrung');
        expect(modelAnswerSection('consent_privacy')).toBe('einwilligungen');
        expect(modelAnswerSection('person_notes')).toBe('sonstiges');
        expect(modelAnswerSection('some_unknown_key')).toBe('sonstiges');

        const snapshot: ModelProfileAnswer[] = [
            { scope: 'person', key: 'first_name', label: 'Vorname', type: 'text', value: 'Maria' },
            { scope: 'person', key: 'consent_privacy', label: 'Datenschutz', type: 'checkbox', value: '1' },
            { scope: 'person', key: 'skills_details', label: 'Fähigkeiten', type: 'textarea', value: 'Hi' },
        ];
        expect(groupModelAnswersBySection(snapshot).map(group => group.key))
            .toEqual(['basisdaten', 'erfahrung', 'einwilligungen']);
    });
});
