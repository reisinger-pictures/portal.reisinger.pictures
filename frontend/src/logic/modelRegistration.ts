import { t } from "@lingui/core/macro";
import { z } from "zod";
import { apiMutate, apiUpload, fetcher } from "../api";

/**
 * Public model registration over a one-time invite token.
 *
 * The backend (App\Services\ModelQuestionnaire) owns a code-first, versioned
 * catalogue. This module mirrors the frozen API contract WITHOUT hardcoding
 * individual questions: the form is rendered dynamically from the `sections`
 * payload and validated with a Zod schema factory built from that same payload.
 *
 * The factory is a module-level function whose body calls `t`. It must only be
 * invoked inside a component render / test body — never at module scope
 * (Lingui module-scope regression, see frontend/AGENTS.md).
 */

export type RegistrationQuestionType =
    | 'text'
    | 'textarea'
    | 'date'
    | 'select'
    | 'multiselect'
    | 'number'
    | 'checkbox'
    | 'file'
    | 'url'
    | 'tel'
    | 'email';

export interface RegistrationVisibleIf {
    type: 'requires_age_proof' | 'is_manager' | 'multiple_persons' | 'answer_equals';
    key?: string;
    value?: string | boolean | null;
}

export interface RegistrationQuestion {
    key: string;
    label: string;
    type: RegistrationQuestionType;
    required: boolean;
    scope: 'act' | 'person';
    options?: string[];
    /** Optional display labels for stable option codes (e.g. willingness). */
    option_labels?: Record<string, string>;
    description?: string;
    /** Optional legal link(s) for consent/description text (v2). */
    url?: string;
    urls?: string[];
    searchable?: boolean;
    visible_if?: RegistrationVisibleIf[];
}

export interface RegistrationSection {
    key: string;
    label: string;
    questions: RegistrationQuestion[];
}

export interface RegistrationCategory {
    key: string;
    label: string;
    description: string;
    requires_age_proof: boolean;
}

export interface ModelRegistrationCheck {
    brand: string | null;
    status: 'open';
    email: string | null;
    person_count: number;
    expires_at: string | null;
    catalog_version: string;
    categories: RegistrationCategory[];
    sections: Record<string, RegistrationSection>;
}

/** Answers are dynamic: strings, string[] (multiselect) or booleans (checkbox). */
export type AnswersRecord = Record<string, unknown>;

export type PhotoVisibility = 'public' | 'internal';

export const MAX_PHOTOS_PER_PERSON = 5;

export interface PersonPhotoUpload {
    /** Stable client-side key for drag & drop reordering. */
    id: string;
    file: File;
    visibility: PhotoVisibility;
    is_primary: boolean;
    /** Object URL preview (images only); revoked when the photo is removed. */
    previewUrl?: string;
}

export interface PersonFormValues {
    answers: AnswersRecord;
    create_account: boolean;
    age_proof: File | null;
    photos: PersonPhotoUpload[];
}

export interface RegistrationFormValues {
    persons: PersonFormValues[];
    act_answers: AnswersRecord;
    manager_index: number;
}

export interface ModelRegistrationSubmitResult {
    success: true;
    act_id: string;
    person_count: number;
}

/** Snapshot answer line as persisted by the backend (`buildSnapshot`). */
export interface ModelProfileAnswer {
    scope: 'act' | 'person';
    key: string;
    label: string;
    type: RegistrationQuestionType;
    value: unknown;
}

/** Public profile access payload (`GET /api/model-profil/{token}`). */
export interface ModelProfileAccess {
    brand: string | null;
    display_name: string | null;
    email: string | null;
    catalog_version: string;
    current_catalog_version: string;
    is_catalog_outdated: boolean;
    answers: ModelProfileAnswer[];
    gender: string | null;
    age_proof_required: boolean;
    age_proof_uploaded_at: string | null;
    submitted_at: string | null;
    last_confirmed_at: string | null;
    lifecycle_status: string;
    categories: RegistrationCategory[];
    sections: Record<string, RegistrationSection>;
    photos: ModelProfileAccessPhoto[];
    expires_at: string | null;
}

export interface ModelProfileAccessPhoto {
    id: string;
    visibility: PhotoVisibility;
    is_primary: boolean;
    mime_type: string | null;
}

export interface ModelProfileUpdateResult {
    success: true;
    catalog_version: string;
    last_confirmed_at: string | null;
}

export interface ModelProfileConfirmResult {
    success: true;
    last_confirmed_at: string | null;
    lifecycle_status: string;
}

/** „Meine Profile" entry (`GET /api/me/models`). */
export interface MyModel {
    id: string;
    customer_id: string;
    display_name: string | null;
    brand: string | null;
    catalog_version: string;
    current_catalog_version: string;
    is_catalog_outdated: boolean;
    last_confirmed_at: string | null;
    lifecycle_status: string;
    categories: string[];
    age_proof_required: boolean;
    photos: ModelProfileAccessPhoto[];
}

/**
 * Mirrors `ModelQuestionnaire::CURRENT`. A new backend catalogue version
 * requires a matching bump here so the admin "Profil aktualisieren" banner
 * can flag outdated snapshots.
 */
export const CURRENT_MODEL_CATALOG_VERSION = 'v1';

/**
 * Admin filter/label helpers.
 *
 * These are factory/lookup FUNCTIONS (not module-scope constants) so the `t`
 * macros expand inside a call body at render time — never at module scope.
 * Labels mirror the frozen German catalogue (`ModelQuestionnaire`) so the admin
 * UI shows the same wording as the public form. The authoritative question
 * catalogue still comes from the public `check` payload.
 */

export function modelFilterCategories(): Array<{ key: string; label: string }> {
    return [
        { key: 'portrait', label: t`Portrait` },
        { key: 'fashion', label: t`Fashion / Editorial` },
        { key: 'business', label: t`Business / Corporate` },
        { key: 'boudoir', label: t`Boudoir` },
        { key: 'bikini', label: t`Bikini` },
        { key: 'akt', label: t`Akt` },
        { key: 'sport', label: t`Sport / Fitness` },
        { key: 'couple_family', label: t`Paar / Familie` },
    ];
}

export function modelGenderOptions(): Array<{ value: string; label: string }> {
    return [
        { value: 'female', label: t`Weiblich` },
        { value: 'male', label: t`Männlich` },
        { value: 'diverse', label: t`Divers` },
    ];
}

export function modelActTypeOptions(): Array<{ value: string; label: string }> {
    return [
        { value: 'single', label: t`Einzelperson` },
        { value: 'couple', label: t`Paar` },
        { value: 'group', label: t`Gruppe` },
    ];
}

/** Willingness scale (stable codes, German labels) — mirrors v2 `WILLINGNESS_LEVELS`. */
export function modelWillingnessOptions(): Array<{ value: string; label: string }> {
    return [
        { value: 'nein', label: t`Nein` },
        { value: 'eher_nicht', label: t`Eher nicht` },
        { value: 'wenn_es_sein_muss', label: t`Eher ja` },
        { value: 'gerne', label: t`Gerne` },
        { value: 'sehr_gerne', label: t`Sehr gerne` },
    ];
}

export function modelWillingnessLabel(value: string | null | undefined): string | null {
    if (!value) return null;
    return modelWillingnessOptions().find(option => option.value === value)?.label ?? value;
}

/** Willingness categories incl. stock (the admin filter must not drop it). */
export function modelWillingnessCategories(): Array<{ key: string; label: string }> {
    return [...modelFilterCategories(), { key: 'stock', label: t`Stock-Fotos` }];
}

/**
 * Experience scale (stable codes `--`, `-`, `0`, `+`, `++`). Mirrors the
 * backend catalogue; labels are the German UI wording.
 */
export function modelExperienceOptions(): Array<{ value: string; label: string }> {
    return [
        { value: '--', label: t`Keine` },
        { value: '-', label: t`Wenig` },
        { value: '0', label: t`Mittel` },
        { value: '+', label: t`Erfahren` },
        { value: '++', label: t`Profi` },
    ];
}

export function modelExperienceLabel(value: string | null | undefined): string | null {
    if (!value) return null;
    return modelExperienceOptions().find(option => option.value === value)?.label ?? value;
}

/** Legacy values that mean "no experience" (version-independent reading). */
const EXPERIENCE_NONE = ['', 'keine', '--'];

export function isNoExperience(value: unknown): boolean {
    return typeof value !== 'string' || EXPERIENCE_NONE.includes(value);
}

/** Ordinal (0..4) of a willingness code; 0 for unknown/missing. */
export function willingnessOrdinal(value: unknown): number {
    const index = modelWillingnessOptions().findIndex(option => option.value === value);
    return index >= 0 ? index : 0;
}

export function willingnessLevelFromOrdinal(ordinal: number): string {
    const options = modelWillingnessOptions();
    const clamped = Math.min(Math.max(0, Math.trunc(ordinal)), options.length - 1);
    return options[clamped].value;
}

/** Static colour classes for the 5-step willingness scale (JIT-safe). */
const WILLINGNESS_BADGE: Record<string, string> = {
    nein: 'bg-red-600 text-white',
    eher_nicht: 'bg-orange-400 text-black',
    wenn_es_sein_muss: 'bg-yellow-400 text-black',
    gerne: 'bg-lime-400 text-black',
    sehr_gerne: 'bg-green-700 text-white',
};

const WILLINGNESS_RANGE: Record<string, string> = {
    nein: 'text-red-600',
    eher_nicht: 'text-orange-400',
    wenn_es_sein_muss: 'text-yellow-400',
    gerne: 'text-lime-500',
    sehr_gerne: 'text-green-700',
};

export function willingnessBadgeClass(value: string | null | undefined): string {
    return WILLINGNESS_BADGE[value ?? ''] ?? 'bg-base-300 text-base-content';
}

/** `currentColor` drives daisyUI's range progress, so a text colour tints it. */
export function willingnessRangeClass(value: string | null | undefined): string {
    return WILLINGNESS_RANGE[value ?? ''] ?? 'text-base-content';
}

/** Scale questions render as sliders (willingness coloured, experience neutral). */
export function isWillingnessQuestion(question: RegistrationQuestion): boolean {
    return question.key.startsWith('willingness_');
}

export function isExperienceQuestion(question: RegistrationQuestion): boolean {
    return question.key.startsWith('experience_');
}

export function isScaleQuestion(question: RegistrationQuestion): boolean {
    return (isWillingnessQuestion(question) || isExperienceQuestion(question)) && (question.options?.length ?? 0) > 0;
}

/** Slider label for a scale option, using catalogue labels where present. */
export function scaleOptionLabel(question: RegistrationQuestion, value: string): string {
    if (question.option_labels?.[value]) return question.option_labels[value];
    if (isExperienceQuestion(question)) return modelExperienceLabel(value) ?? value;
    if (isWillingnessQuestion(question)) return modelWillingnessLabel(value) ?? value;
    return value;
}

export type ModelAnswerSectionKey = 'basisdaten' | 'aussehen' | 'erfahrung' | 'einwilligungen' | 'sonstiges';

/** Ordered form sections for the read-only admin detail view. */
export function modelAnswerSections(): Array<{ key: ModelAnswerSectionKey; label: string }> {
    return [
        { key: 'basisdaten', label: t`Basisdaten` },
        { key: 'aussehen', label: t`Aussehen & Maße` },
        { key: 'erfahrung', label: t`Bereitschaft & Erfahrung` },
        { key: 'einwilligungen', label: t`Einwilligungen` },
        { key: 'sonstiges', label: t`Sonstiges` },
    ];
}

const ANSWER_SECTION_KEYS: Record<Exclude<ModelAnswerSectionKey, 'sonstiges'>, string[]> = {
    basisdaten: [
        'first_name', 'last_name', 'stage_name', 'salutation', 'birthdate', 'gender', 'email', 'phone',
        'street', 'zip', 'city', 'country', 'preferred_contact', 'instagram', 'socials', 'portfolio_url',
    ],
    aussehen: [
        'height_cm', 'measurements_bust', 'measurements_waist', 'measurements_hips', 'hair_color',
        'hair_length', 'eye_color', 'tattoos_piercings', 'tattoos_piercings_details',
    ],
    // `skills`, `languages` and `about_me` were removed from the catalogue; the
    // remaining freetext is `skills_details` (label "Fähigkeiten").
    erfahrung: [
        'previous_shoots', 'references', 'agency_name', 'agency_link', 'skills_details',
    ],
    einwilligungen: ['age_proof'],
};

/** Section a snapshot answer belongs to; unknown keys fall back to "Sonstiges". */
export function modelAnswerSection(key: string): ModelAnswerSectionKey {
    if (key.startsWith('willingness_') || key.startsWith('experience_')) return 'erfahrung';
    if (key.startsWith('consent_')) return 'einwilligungen';
    for (const [section, keys] of Object.entries(ANSWER_SECTION_KEYS) as Array<[Exclude<ModelAnswerSectionKey, 'sonstiges'>, string[]]>) {
        if (keys.includes(key)) return section;
    }
    return 'sonstiges';
}

/**
 * Group a snapshot into ordered form sections (empty sections dropped),
 * preserving the snapshot order within each section.
 */
export function groupModelAnswersBySection(
    answers: ModelProfileAnswer[],
): Array<{ key: ModelAnswerSectionKey; label: string; answers: ModelProfileAnswer[] }> {
    return modelAnswerSections()
        .map(section => ({
            ...section,
            answers: answers.filter(answer => modelAnswerSection(answer.key) === section.key),
        }))
        .filter(section => section.answers.length > 0);
}

export interface ModelSkillRow {
    key: string;
    label: string;
    experience: string | null;
    willingness: string | null;
}

/** Experience codes from a snapshot, keyed `experience_<category>`. */
export function experienceMapFromAnswers(answers: ModelProfileAnswer[]): Record<string, string> {
    const map: Record<string, string> = {};
    for (const answer of answers) {
        if (answer.key.startsWith('experience_') && typeof answer.value === 'string') {
            map[answer.key] = answer.value;
        }
    }
    return map;
}

/**
 * Per-category matrix (rows = shooting categories + stock) combining the
 * `willingness` map with the `experience_*` snapshot values.
 */
export function modelSkillMatrix(
    willingness: Record<string, string>,
    experience: Record<string, string>,
): ModelSkillRow[] {
    return modelWillingnessCategories().map(({ key, label }) => ({
        key,
        label,
        experience: experience[`experience_${key}`] ?? null,
        willingness: willingness[`willingness_${key}`] ?? null,
    }));
}

/** A row is worth showing when the person has experience or more than "nein" desire. */
export function isSkillRowRelevant(row: ModelSkillRow): boolean {
    return !isNoExperience(row.experience) || (row.willingness !== null && row.willingness !== 'nein');
}

/** Ordinal of an experience code (0..4); legacy/absent values sort lowest. */
export function experienceOrdinal(value: string | null | undefined): number {
    if (value === null || value === undefined || value === '') return -1;
    const index = modelExperienceOptions().findIndex(option => option.value === value);
    if (index >= 0) return index;
    if (isNoExperience(value)) return 0;
    return -1;
}

/**
 * Row order inside a matrix: pinned (matched/filter) categories first, in
 * filter order, then the rest sorted by experience desc, willingness desc.
 */
export function sortSkillMatrixRows(rows: ModelSkillRow[], pinnedKeys: string[] = []): ModelSkillRow[] {
    const pinned: ModelSkillRow[] = [];
    for (const key of pinnedKeys) {
        const row = rows.find(candidate => candidate.key === key);
        if (row && !pinned.includes(row)) pinned.push(row);
    }
    const pinnedSet = new Set(pinned.map(row => row.key));
    const rest = rows
        .filter(row => !pinnedSet.has(row.key))
        .slice()
        .sort((a, b) => {
            const byExperience = experienceOrdinal(b.experience) - experienceOrdinal(a.experience);
            if (byExperience !== 0) return byExperience;
            const desireA = a.willingness === null ? -1 : willingnessOrdinal(a.willingness);
            const desireB = b.willingness === null ? -1 : willingnessOrdinal(b.willingness);
            return desireB - desireA;
        });
    return [...pinned, ...rest];
}

/** Group willingness entries by level, highest level first, then by label. */
export function groupWillingnessByLevel<T extends { level: string; label: string }>(entries: T[]): Array<{ level: string; label: string; items: T[] }> {
    const groups = new Map<string, T[]>();
    for (const entry of entries) {
        const list = groups.get(entry.level) ?? [];
        list.push(entry);
        groups.set(entry.level, list);
    }
    return modelWillingnessOptions()
        .slice()
        .reverse()
        .filter(option => groups.has(option.value))
        .map(option => ({
            level: option.value,
            label: option.label,
            items: (groups.get(option.value) ?? []).slice().sort((a, b) => a.label.localeCompare(b.label, 'de')),
        }));
}

/** Human-readable label for a stored `model_profiles.gender` code. */
export function modelGenderLabel(value: string | null | undefined): string {
    if (!value) return '–';
    return modelGenderOptions().find(option => option.value === value)?.label ?? value;
}

/** Human-readable label for a stored `acts.act_type` code. */
export function modelActTypeLabel(value: string): string {
    return modelActTypeOptions().find(option => option.value === value)?.label ?? value;
}

/** Human-readable label for a shooting-category key. Unknown keys fall back to the raw key. */
export function modelCategoryLabel(key: string): string {
    return modelFilterCategories().find(option => option.key === key)?.label ?? key;
}

/** Labels for a list of stored `act_type` codes, preserving order. */
export function modelActTypeLabels(values: string[]): string[] {
    return values.map(modelActTypeLabel);
}

/** Human-readable label for a profile lifecycle status. */
export function modelLifecycleLabel(status: string | null | undefined): string {
    switch (status) {
        case 'active':
            return t`Aktiv`;
        case 'inactive':
            return t`Inaktiv`;
        case 'expired':
            return t`Abgelaufen`;
        default:
            return status ?? '–';
    }
}

/** daisyUI badge class for a lifecycle status (static mapping, JIT-safe). */
export function modelLifecycleBadgeClass(status: string | null | undefined): string {
    switch (status) {
        case 'active':
            return 'badge-success';
        case 'inactive':
            return 'badge-warning';
        case 'expired':
            return 'badge-ghost';
        default:
            return 'badge-ghost';
    }
}

/** Display label for a stable option code, falling back to the raw value. */
export function questionOptionLabel(question: RegistrationQuestion, value: string): string {
    return question.option_labels?.[value] ?? value;
}

/** The file question key that maps to `persons[i][age_proof]`. */
export const AGE_PROOF_KEY = 'age_proof';

/** Contact channels with their backing field dependency (§2.3). */
export const CONTACT_CHANNEL_FIELDS: Record<string, string | null> = {
    'E-Mail': 'email',
    'Telefon': 'phone',
    'WhatsApp': 'phone',
    'Instagram': 'instagram',
    'Sonstiges': null,
};

export function sections(catalog: ModelRegistrationCheck): RegistrationSection[] {
    return Object.values(catalog.sections);
}

export function personQuestions(catalog: ModelRegistrationCheck): RegistrationQuestion[] {
    return sections(catalog)
        .flatMap(section => section.questions)
        .filter(question => question.scope === 'person');
}

export function actQuestions(catalog: ModelRegistrationCheck): RegistrationQuestion[] {
    return sections(catalog)
        .flatMap(section => section.questions)
        .filter(question => question.scope === 'act');
}

/**
 * Category keys a person selected with an experience level other than `keine`,
 * mirroring `ModelQuestionnaire::selectedCategories`.
 */
export function selectedCategories(answers: AnswersRecord, categories: RegistrationCategory[]): string[] {
    const selected: string[] = [];
    for (const category of categories) {
        const level = answers[`experience_${category.key}`];
        if (!isNoExperience(level)) {
            selected.push(category.key);
        }
    }
    return selected;
}

/**
 * v1 category-derived age-proof evaluation. Kept for reading legacy snapshots
 * and for the v1 `visible_if` condition. v2 makes the proof unconditional; the
 * form/schema use `isAgeProofRequired()` instead (see §2.8).
 */
export function requiresAgeProof(answers: AnswersRecord, categories: RegistrationCategory[]): boolean {
    return selectedCategories(answers, categories).some(key => {
        const category = categories.find(candidate => candidate.key === key);
        return category?.requires_age_proof === true;
    });
}

/**
 * Whether the age-proof question is required for the current answers/context.
 * v2 (`required = true`, no `visible_if`) always returns true; v1 keeps the
 * category-based visibility.
 */
export function isAgeProofRequired(
    question: RegistrationQuestion | undefined,
    answers: AnswersRecord,
    context: VisibilityContext,
): boolean {
    if (!question || !question.required) return false;
    return isQuestionVisible(question, answers, context);
}

/** A selected channel is available when its backing field is filled. */
export function isContactChannelAvailable(channel: string, answers: AnswersRecord): boolean {
    const field = CONTACT_CHANNEL_FIELDS[channel];
    if (field === undefined || field === null) return true;
    const value = answers[field];
    return typeof value === 'string' && value.trim() !== '';
}

/** Filter the catalogue option list down to the currently selectable channels. */
export function availableContactChannels(answers: AnswersRecord, options: string[]): string[] {
    return options.filter(option => isContactChannelAvailable(option, answers));
}

/** Channels currently selected although their backing field is empty. */
export function contactChannelsWithoutCarrier(answers: AnswersRecord): string[] {
    const selected = answers.preferred_contact;
    if (!Array.isArray(selected)) return [];
    return selected.filter(
        (entry): entry is string => typeof entry === 'string' && !isContactChannelAvailable(entry, answers),
    );
}

export interface VisibilityContext {
    categories: RegistrationCategory[];
    isManager: boolean;
    personCount?: number;
}

export function isQuestionVisible(
    question: RegistrationQuestion,
    answers: AnswersRecord,
    context: VisibilityContext,
): boolean {
    for (const condition of question.visible_if ?? []) {
        if (condition.type === 'requires_age_proof' && !requiresAgeProof(answers, context.categories)) {
            return false;
        }
        if (condition.type === 'is_manager' && !context.isManager) {
            return false;
        }
        if (condition.type === 'multiple_persons' && (context.personCount ?? 1) <= 1) {
            return false;
        }
        if (condition.type === 'answer_equals') {
            const key = condition.key ?? '';
            if (answers[key] !== condition.value) {
                return false;
            }
        }
    }
    return true;
}

function isEmail(value: string): boolean {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
}

function isUrl(value: string): boolean {
    try {
        new URL(value);
        return true;
    } catch {
        return false;
    }
}

/**
 * Validate one catalogue question against its raw form value. Conditional
 * questions are skipped by the caller via `isQuestionVisible`.
 */
function validateQuestion(
    question: RegistrationQuestion,
    raw: unknown,
    path: (string | number)[],
    context: z.RefinementCtx,
): void {
    const type = question.type;
    const required = question.required;

    if (type === 'checkbox') {
        if (required && raw !== true) {
            context.addIssue({ code: 'custom', message: t`Dieses Feld ist erforderlich.`, path });
        }
        return;
    }

    if (type === 'multiselect') {
        const values = Array.isArray(raw) ? raw.filter((entry): entry is string => typeof entry === 'string') : [];
        if (required && values.length === 0) {
            context.addIssue({ code: 'custom', message: t`Bitte mindestens eine Option wählen.`, path });
        }
        for (const value of values) {
            if (question.options && !question.options.includes(value)) {
                context.addIssue({ code: 'custom', message: t`Ungültige Auswahl.`, path });
            }
        }
        return;
    }

    const value = typeof raw === 'string' ? raw.trim() : '';

    if (value === '') {
        if (required) {
            context.addIssue({ code: 'custom', message: t`Dieses Feld ist erforderlich.`, path });
        }
        return;
    }

    const invalid = (message: string) => context.addIssue({ code: 'custom', message, path });

    switch (type) {
        case 'email':
            if (!isEmail(value)) invalid(t`Bitte eine gültige E-Mail-Adresse angeben.`);
            break;
        case 'url':
            if (!isUrl(value)) invalid(t`Bitte eine gültige URL angeben.`);
            break;
        case 'tel':
            if (value.length > 50) invalid(t`Die Telefonnummer ist zu lang.`);
            break;
        case 'date':
            if (Number.isNaN(Date.parse(value)) || value >= new Date().toISOString().slice(0, 10)) {
                invalid(t`Bitte ein Datum in der Vergangenheit angeben.`);
            }
            break;
        case 'number':
            if (Number.isNaN(Number(value))) invalid(t`Bitte eine Zahl angeben.`);
            break;
        case 'select':
            if (question.options && !question.options.includes(value)) invalid(t`Ungültige Auswahl.`);
            break;
        case 'textarea':
            if (value.length > 20000) invalid(t`Der Text ist zu lang.`);
            break;
        case 'text':
        default:
            if (value.length > 255) invalid(t`Der Text ist zu lang.`);
            break;
    }
}

/**
 * Zod schema factory. Must be called inside a component render body (Lingui).
 *
 * `visible_if`, the per-person age-proof requirement, the manager scope and the
 * contact-channel gating are evaluated in `superRefine` against the current
 * values, so a single schema instance stays valid while the form state changes.
 */
export function createRegistrationSchema(catalog: ModelRegistrationCheck) {
    const personList = personQuestions(catalog);
    const actList = actQuestions(catalog);
    const ageProofQuestion = personList.find(question => question.key === AGE_PROOF_KEY);

    return z
        .object({
            persons: z
                .array(
                    z.object({
                        answers: z.record(z.string(), z.unknown()),
                        create_account: z.boolean(),
                        age_proof: z.custom<File | null>(
                            value => value === null || (typeof File !== 'undefined' && value instanceof File),
                            { message: t`Ungültige Datei.` },
                        ),
                        photos: z
                            .array(
                                z.object({
                                    id: z.string(),
                                    file: z.custom<File>(
                                        value => typeof File !== 'undefined' && value instanceof File,
                                        { message: t`Ungültige Datei.` },
                                    ),
                                    visibility: z.enum(['public', 'internal']),
                                    is_primary: z.boolean(),
                                    previewUrl: z.string().optional(),
                                }),
                            )
                            .max(MAX_PHOTOS_PER_PERSON, t`Maximal 5 Fotos pro Person.`),
                    }),
                )
                .min(1, t`Mindestens eine Person muss erfasst werden.`),
            act_answers: z.record(z.string(), z.unknown()),
            manager_index: z.number().int().min(0),
        })
        .superRefine((value, context) => {
            if (value.manager_index >= value.persons.length) {
                context.addIssue({
                    code: 'custom',
                    message: t`Bitte eine gültige Managerperson wählen.`,
                    path: ['manager_index'],
                });
            }

            value.persons.forEach((person, index) => {
                const visibility: VisibilityContext = {
                    categories: catalog.categories,
                    isManager: index === value.manager_index,
                    personCount: value.persons.length,
                };

                for (const question of personList) {
                    if (question.type === 'file') continue;
                    if (!isQuestionVisible(question, person.answers, visibility)) continue;
                    validateQuestion(question, person.answers[question.key], ['persons', index, 'answers', question.key], context);
                }

                // Mirror the server-side contact gating (§2.3): a selected
                // channel whose backing field got cleared is an error.
                if (contactChannelsWithoutCarrier(person.answers).length > 0) {
                    context.addIssue({
                        code: 'custom',
                        message: t`Für den gewählten Kontaktweg fehlt die hinterlegte Angabe.`,
                        path: ['persons', index, 'answers', 'preferred_contact'],
                    });
                }

                if (
                    isAgeProofRequired(ageProofQuestion, person.answers, visibility) &&
                    !(person.age_proof instanceof File)
                ) {
                    context.addIssue({
                        code: 'custom',
                        message: t`Bitte einen Altersnachweis hochladen.`,
                        path: ['persons', index, 'age_proof'],
                    });
                }

                // Mirror the server rule "primary photo must be public": an
                // internal photo cannot be elected as the main image.
                person.photos.forEach((photo, photoIndex) => {
                    if (photo.is_primary && photo.visibility !== 'public') {
                        context.addIssue({
                            code: 'custom',
                            message: t`Nur öffentliche Fotos können als Hauptbild dienen.`,
                            path: ['persons', index, 'photos', photoIndex, 'is_primary'],
                        });
                    }
                });
            });

            for (const question of actList) {
                if (!isQuestionVisible(question, value.act_answers, { categories: catalog.categories, isManager: false, personCount: value.persons.length })) {
                    continue;
                }
                validateQuestion(question, value.act_answers[question.key], ['act_answers', question.key], context);
            }
        });
}

/**
 * Flat answers schema for the profile-access edit form (`POST /api/model-profil/{token}`).
 *
 * The public update endpoint only accepts `answers` (no file/photo uploads) and
 * validates the person scope with `person_count = 1`; the manager consent is
 * therefore never visible here. Must be called inside a render body (Lingui).
 */
export function createProfileAnswersSchema(catalog: ModelRegistrationCheck) {
    const personList = personQuestions(catalog).filter(question => question.type !== 'file');
    const visibility: VisibilityContext = { categories: catalog.categories, isManager: false, personCount: 1 };

    return z
        .object({ answers: z.record(z.string(), z.unknown()) })
        .superRefine((value, context) => {
            for (const question of personList) {
                if (!isQuestionVisible(question, value.answers, visibility)) continue;
                validateQuestion(question, value.answers[question.key], ['answers', question.key], context);
            }

            if (contactChannelsWithoutCarrier(value.answers).length > 0) {
                context.addIssue({
                    code: 'custom',
                    message: t`Für den gewählten Kontaktweg fehlt die hinterlegte Angabe.`,
                    path: ['answers', 'preferred_contact'],
                });
            }
        });
}

/** Defaults avoid a click-forcing empty state (willingness `nein`, experience `--`). */
export function defaultAnswerForQuestion(question: RegistrationQuestion): unknown {
    if (question.type === 'checkbox') return false;
    if (question.type === 'multiselect') return [];
    if (question.type === 'select') {
        const options = question.options ?? [];
        // Willingness defaults to the lowest step; experience to its first code
        // (backend `--`, but a custom test catalogue may use other labels).
        if (question.key.startsWith('willingness_')) return options.includes('nein') ? 'nein' : (options[0] ?? '');
        if (question.key.startsWith('experience_')) return options[0] ?? '';
    }
    return '';
}

export function makePersonAnswers(catalog: ModelRegistrationCheck): AnswersRecord {
    const answers: AnswersRecord = {};
    for (const question of personQuestions(catalog)) {
        if (question.type === 'file') continue;
        answers[question.key] = defaultAnswerForQuestion(question);
    }
    return answers;
}

export function makeActAnswers(catalog: ModelRegistrationCheck): AnswersRecord {
    const answers: AnswersRecord = {};
    for (const question of actQuestions(catalog)) {
        if (question.type === 'file') continue;
        answers[question.key] = defaultAnswerForQuestion(question);
    }
    return answers;
}

export function makeRegistrationValues(catalog: ModelRegistrationCheck): RegistrationFormValues {
    return {
        persons: [
            {
                answers: makePersonAnswers(catalog),
                create_account: false,
                age_proof: null,
                photos: [],
            },
        ],
        act_answers: makeActAnswers(catalog),
        manager_index: 0,
    };
}

function appendAnswer(formData: FormData, prefix: string, key: string, value: unknown): void {
    if (value === undefined || value === null) return;
    if (Array.isArray(value)) {
        for (const entry of value) {
            if (typeof entry === 'string' && entry !== '') {
                formData.append(`${prefix}[${key}][]`, entry);
            }
        }
        return;
    }
    if (typeof value === 'boolean') {
        formData.append(`${prefix}[${key}]`, value ? '1' : '0');
        return;
    }
    if (typeof value === 'string') {
        formData.append(`${prefix}[${key}]`, value);
    }
}

/** Build the multipart body exactly as the backend contract expects it. */
export function buildRegistrationFormData(values: RegistrationFormValues): FormData {
    const formData = new FormData();

    values.persons.forEach((person, index) => {
        for (const [key, value] of Object.entries(person.answers)) {
            appendAnswer(formData, `persons[${index}][answers]`, key, value);
        }
        formData.append(`persons[${index}][create_account]`, person.create_account ? '1' : '0');
        if (person.age_proof instanceof File) {
            formData.append(`persons[${index}][age_proof]`, person.age_proof);
        }
        person.photos.forEach((photo, photoIndex) => {
            formData.append(`persons[${index}][photos][${photoIndex}][file]`, photo.file);
            formData.append(`persons[${index}][photos][${photoIndex}][visibility]`, photo.visibility);
            formData.append(`persons[${index}][photos][${photoIndex}][is_primary]`, photo.is_primary ? '1' : '0');
        });
    });

    for (const [key, value] of Object.entries(values.act_answers)) {
        appendAnswer(formData, 'act[answers]', key, value);
    }

    formData.append('manager_index', String(values.manager_index));
    return formData;
}

export function fetchModelRegistration(token: string): Promise<ModelRegistrationCheck> {
    return fetcher<ModelRegistrationCheck>(`/api/model-registration/${encodeURIComponent(token)}`);
}

export function submitModelRegistration(
    token: string,
    values: RegistrationFormValues,
): Promise<ModelRegistrationSubmitResult> {
    return apiUpload<ModelRegistrationSubmitResult>(
        `/api/model-registration/${encodeURIComponent(token)}`,
        buildRegistrationFormData(values),
    );
}

export function isCatalogOutdated(version: string | null | undefined): boolean {
    if (!version) return true;
    if (version === CURRENT_MODEL_CATALOG_VERSION) return false;
    const parse = (value: string): number | null => {
        const match = /^v(\d+)$/.exec(value);
        return match ? Number(match[1]) : null;
    };
    const current = parse(CURRENT_MODEL_CATALOG_VERSION);
    const actual = parse(version);
    if (current === null || actual === null) return version !== CURRENT_MODEL_CATALOG_VERSION;
    return actual < current;
}

// --- Profile access (public magic link + „Meine Profile") ---------------------

const modelProfileUrl = (token: string) => `/api/model-profil/${encodeURIComponent(token)}`;

export function fetchModelProfileAccess(token: string): Promise<ModelProfileAccess> {
    return fetcher<ModelProfileAccess>(modelProfileUrl(token));
}

/** Photo management payload for the owner (`POST /api/model-profil/{token}`). */
export interface ModelProfilePhotoUpdate {
    id: string;
    visibility?: PhotoVisibility;
    is_primary?: boolean;
}

/**
 * Build the owner photo-management payload. Always sends `is_primary` so
 * demoting the current main photo to internal clears it explicitly
 * (`is_primary: false`) instead of triggering the server's 422 guard.
 */
export function buildProfilePhotoPayload(photos: ModelProfileAccessPhoto[]): ModelProfilePhotoUpdate[] {
    return photos.map(photo => ({
        id: photo.id,
        visibility: photo.visibility,
        is_primary: photo.is_primary && photo.visibility === 'public',
    }));
}

/**
 * Multipart body for the owner update when an age proof has to be (re)uploaded
 * (JSON cannot carry files). Matches the backend field names `answers[...]`,
 * `photos[i][...]` and `age_proof`.
 */
export function buildProfileUpdateFormData(
    answers: AnswersRecord,
    photos: ModelProfilePhotoUpdate[] | undefined,
    ageProof: File,
): FormData {
    const formData = new FormData();
    for (const [key, value] of Object.entries(answers)) {
        appendAnswer(formData, 'answers', key, value);
    }
    (photos ?? []).forEach((photo, index) => {
        formData.append(`photos[${index}][id]`, photo.id);
        if (photo.visibility) formData.append(`photos[${index}][visibility]`, photo.visibility);
        if (photo.is_primary !== undefined) {
            formData.append(`photos[${index}][is_primary]`, photo.is_primary ? '1' : '0');
        }
    });
    formData.append('age_proof', ageProof);
    return formData;
}

export function updateModelProfileAccess(
    token: string,
    answers: AnswersRecord,
    photos?: ModelProfilePhotoUpdate[],
    ageProof?: File | null,
): Promise<ModelProfileUpdateResult> {
    if (ageProof) {
        return apiUpload<ModelProfileUpdateResult>(
            modelProfileUrl(token),
            buildProfileUpdateFormData(answers, photos, ageProof),
        );
    }
    const body: { answers: AnswersRecord; photos?: ModelProfilePhotoUpdate[] } = { answers };
    if (photos && photos.length > 0) body.photos = photos;
    return apiMutate<ModelProfileUpdateResult>(modelProfileUrl(token), 'POST', body);
}

export function confirmModelProfileAccess(token: string): Promise<ModelProfileConfirmResult> {
    return apiMutate<ModelProfileConfirmResult>(`${modelProfileUrl(token)}/confirm`, 'POST', {});
}

export function fetchMyModels(): Promise<MyModel[]> {
    return fetcher<MyModel[]>('/api/me/models');
}

/** Rehydrate a stored snapshot into the flat answers map used by the form. */
export function answersRecordFromSnapshot(snapshot: ModelProfileAnswer[]): AnswersRecord {
    const answers: AnswersRecord = {};
    for (const answer of snapshot) {
        answers[answer.key] = answer.value;
    }
    return answers;
}

/** Snapshot checkboxes are persisted as the raw multipart value (`'1'`/`'0'`). */
export function isTruthyAnswer(value: unknown): boolean {
    return value === true || value === 1 || value === '1' || value === 'true' || value === 'on' || value === 'yes';
}

/**
 * Coerce a snapshot answer map into RHF-friendly types using the catalogue:
 * checkbox strings become booleans, missing multiselects become `[]`.
 */
export function normalizeAnswersForForm(catalog: ModelRegistrationCheck, answers: AnswersRecord): AnswersRecord {
    const normalized: AnswersRecord = { ...answers };
    for (const question of personQuestions(catalog)) {
        const value = normalized[question.key];
        if (question.type === 'checkbox') {
            normalized[question.key] = isTruthyAnswer(value);
        } else if (question.type === 'multiselect' && !Array.isArray(value)) {
            normalized[question.key] = value === null || value === undefined || value === '' ? [] : [String(value)];
        } else if (isScaleQuestion(question)) {
            // Legacy codes (e.g. experience `keine`) map onto the current scale.
            const options = question.options ?? [];
            if (typeof value !== 'string' || !options.includes(value)) {
                normalized[question.key] = options[0] ?? value;
            }
        }
    }
    return normalized;
}
