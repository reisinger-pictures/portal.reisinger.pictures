import { useState } from 'react';
import { useParams } from 'react-router-dom';
import { Controller, useFieldArray, useForm, useWatch, type Control, type FieldErrors, type FieldPath, type FieldValues, type UseFormGetValues, type UseFormRegister, type UseFormSetValue } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { t } from '@lingui/core/macro';
import { Trans } from '@lingui/react/macro';
import GuestLayout from './components/GuestLayout';
import ErrorMessage from './components/ErrorMessage';
import { useModelRegistration } from '../logic/useModelRegistration';
import { calcAge } from '../logic/utils';
import {
    AGE_PROOF_KEY,
    MAX_PERSONS_PER_REGISTRATION,
    MAX_PHOTOS_PER_PERSON,
    createRegistrationSchema,
    groupWillingnessByLevel,
    isAgeProofRequired,
    isContactChannelAvailable,
    isQuestionVisible,
    isScaleQuestion,
    isWillingnessQuestion,
    makePersonAnswers,
    makeRegistrationValues,
    modelWillingnessLabel,
    personQuestions,
    questionOptionLabel,
    scaleOptionLabel,
    sections,
    willingnessBadgeClass,
    willingnessRangeClass,
    type AnswersRecord,
    type ModelRegistrationCheck,
    type ModelRegistrationSubmitResult,
    type PersonPhotoUpload,
    type PhotoVisibility,
    type RegistrationQuestion,
    type RegistrationFormValues,
    type VisibilityContext,
} from '../logic/modelRegistration';

const SERVER_ERROR_MAP_PREFIX: Array<[string, string]> = [
    ['act.answers.', 'act_answers.'],
];

function mapServerErrorKey(key: string): string {
    for (const [from, to] of SERVER_ERROR_MAP_PREFIX) {
        if (key.startsWith(from)) return to + key.slice(from.length);
    }
    return key;
}

/**
 * A 422 from the backend carries Laravel's `{errors: {key: [msg]}}` payload.
 * `ApiError` is an interface (not a class), so detect it structurally.
 */
function extractValidationErrors(error: unknown): Record<string, string[]> | null {
    if (!(error instanceof Error)) return null;
    const candidate = error as { status?: unknown; info?: unknown };
    if (candidate.status !== 422) return null;
    if (!candidate.info || typeof candidate.info !== 'object') return null;
    const errors = (candidate.info as { errors?: unknown }).errors;
    if (!errors || typeof errors !== 'object') return null;
    return errors as Record<string, string[]>;
}

/**
 * HTTP status of an API error, if any. `ApiError` is an interface, so read the
 * property structurally.
 */
function extractStatus(error: unknown): number | null {
    if (!(error instanceof Error)) return null;
    const status = (error as { status?: unknown }).status;
    return typeof status === 'number' ? status : null;
}

/**
 * Fatal submit statuses terminate the invite link just like a failed `check`
 * (unknown / expired / already redeemed / lost race). These must surface as the
 * error page, never as an inline form error, because the form can no longer be
 * submitted.
 */
const FATAL_SUBMIT_STATUSES: readonly number[] = [404, 409, 410];

function isFatalSubmitError(error: unknown): boolean {
    const status = extractStatus(error);
    return status !== null && FATAL_SUBMIT_STATUSES.includes(status);
}

function errorMessage(error: unknown, fallback: string): string {
    return error instanceof Error && error.message ? error.message : fallback;
}

function fieldError(errors: FieldErrors<RegistrationFormValues>, path: string): string | undefined {
    const parts = path.split('.');
    let current: unknown = errors;
    for (const part of parts) {
        if (typeof current !== 'object' || current === null) return undefined;
        current = (current as Record<string, unknown>)[part];
    }
    if (current && typeof current === 'object' && 'message' in current) {
        const message = (current as { message?: unknown }).message;
        return typeof message === 'string' ? message : undefined;
    }
    return undefined;
}

/** Route → link label; unknown routes fall back to a generic label (§2.9). */
function legalLinkLabel(url: string): string {
    if (url.startsWith('/privacy')) return t`Datenschutzerklärung`;
    if (url.startsWith('/license-terms')) return t`AGB & Lizenzbedingungen`;
    if (url.startsWith('/widerruf')) return t`Widerrufsbelehrung`;
    if (url.startsWith('/impressum')) return t`Impressum`;
    return t`Mehr erfahren`;
}

interface QuestionFieldProps<TFieldValues extends FieldValues> {
    question: RegistrationQuestion;
    name: FieldPath<TFieldValues>;
    idPrefix: string;
    error?: string;
    register: UseFormRegister<TFieldValues>;
    /** Required for scale (slider) questions; optional for plain fields. */
    control?: Control<TFieldValues>;
    answers?: AnswersRecord;
    /** Live age hint rendered next to the birthdate input. */
    ageHint?: string | null;
    /** Called after a text/tel/email field changed (contact-channel sync). */
    onAnswerChange?: (key: string, value: string) => void;
}

/**
 * Generic catalogue question renderer. Shared by the public registration form
 * and the profile-access edit form (both RHF-based).
 */
export function QuestionField<TFieldValues extends FieldValues>({
    question,
    name,
    idPrefix,
    error,
    register,
    control,
    answers,
    ageHint,
    onAnswerChange,
}: QuestionFieldProps<TFieldValues>) {
    const id = `${idPrefix}-${question.key}`;
    const inputClass = `input input-bordered w-full ${error ? 'input-error' : ''}`;

    // Scale questions (willingness / experience) render as sliders, not selects.
    if (question.type === 'select' && isScaleQuestion(question) && control) {
        const options = question.options ?? [];
        const firstOption = options[0] ?? '';
        const isWillingness = isWillingnessQuestion(question);
        return (
            <div className="form-control" data-testid={`scale-${question.key}`}>
                <Controller
                    control={control}
                    name={name}
                    render={({ field }) => {
                        const value = typeof field.value === 'string' && options.includes(field.value) ? field.value : firstOption;
                        const index = Math.max(0, options.indexOf(value));
                        const stepLabel = scaleOptionLabel(question, value);
                        return (
                            <>
                                {/* Row 1: heading + current value (slider on its own full-width row). */}
                                <div className="flex flex-wrap items-center justify-between gap-2 min-w-0">
                                    <span className="label-text font-bold min-w-0 break-words">{question.label}</span>
                                    <span
                                        className={`badge shrink-0 h-auto whitespace-normal ${isWillingness ? willingnessBadgeClass(value) : 'badge-outline'}`}
                                        data-testid={`scale-value-${question.key}`}
                                    >
                                        {stepLabel}
                                    </span>
                                </div>
                                <input
                                    type="range"
                                    min={0}
                                    max={options.length - 1}
                                    step={1}
                                    value={index}
                                    aria-label={question.label}
                                    className={`range w-full mt-1 ${isWillingness ? willingnessRangeClass(value) : 'text-base-content'}`}
                                    onChange={event => field.onChange(options[Number(event.target.value)] ?? firstOption)}
                                    onBlur={field.onBlur}
                                />
                                <div className="flex justify-between gap-2 text-xs opacity-60 mt-1">
                                    {options.map(option => <span key={option} className="min-w-0 truncate">{scaleOptionLabel(question, option)}</span>)}
                                </div>
                            </>
                        );
                    }}
                />
                {question.description && <span className="label-text-alt opacity-70 mt-1">{question.description}</span>}
                {error && <span className="text-error text-xs mt-1">{error}</span>}
            </div>
        );
    }

    if (question.type === 'checkbox') {
        // Render a single legal link (prefer `url`, else the first `urls` entry).
        const href = question.url ?? question.urls?.[0];
        const text = question.description ?? question.label;
        return (
            <div className="form-control">
                <label className="label cursor-pointer justify-start gap-3 rounded-box p-3 hover:bg-base-300/50" htmlFor={id}>
                    <input
                        id={id}
                        type="checkbox"
                        required={question.required}
                        className="checkbox checkbox-primary shrink-0"
                        {...register(name)}
                    />
                    <span className="label-text min-w-0">
                        {text}
                        {href && (
                            <span>
                                {' '}
                                <a href={href} target="_blank" rel="noopener noreferrer" className="link link-primary">
                                    {legalLinkLabel(href)}
                                </a>
                            </span>
                        )}
                    </span>
                </label>
                {error && <span className="text-error text-xs mt-1">{error}</span>}
            </div>
        );
    }

    if (question.type === 'multiselect') {
        const allOptions = question.options ?? [];
        const options = question.key === 'preferred_contact' && answers
            ? allOptions.filter(option => isContactChannelAvailable(option, answers)
                || (Array.isArray(answers.preferred_contact) && answers.preferred_contact.includes(option)))
            : allOptions;
        return (
            <div className="form-control">
                <span className="label-text font-bold mb-1">{question.label}</span>
                <div role="group" aria-label={question.label} className="flex flex-wrap gap-3">
                    {options.map(option => (
                        <label key={option} className="label cursor-pointer justify-start gap-2 rounded-box border border-base-300 px-3 py-2">
                            <input type="checkbox" value={option} className="checkbox checkbox-primary checkbox-sm" {...register(name)} />
                            <span className="label-text">{option}</span>
                        </label>
                    ))}
                </div>
                {question.description && <span className="label-text-alt opacity-70 mt-1">{question.description}</span>}
                {error && <span className="text-error text-xs mt-1">{error}</span>}
            </div>
        );
    }

    const label = (
        <label className="label" htmlFor={id}>
            <span className="label-text font-bold">{question.label}</span>
        </label>
    );

    let fieldControl: React.ReactNode;
    if (question.type === 'textarea') {
        fieldControl = <textarea id={id} rows={4} required={question.required} className={`textarea textarea-bordered w-full ${error ? 'textarea-error' : ''}`} {...register(name)} />;
    } else if (question.type === 'select') {
        fieldControl = (
            <select id={id} required={question.required} className={`select select-bordered w-full ${error ? 'select-error' : ''}`} {...register(name)}>
                <option value="">{t`Bitte wählen`}</option>
                {(question.options ?? []).map(option => (
                    <option key={option} value={option}>{questionOptionLabel(question, option)}</option>
                ))}
            </select>
        );
    } else {
        const onChange = onAnswerChange
            ? (event: React.ChangeEvent<HTMLInputElement>) => onAnswerChange(question.key, event.target.value)
            : undefined;
        fieldControl = (
            <div className="flex items-center gap-3">
                <input
                    id={id}
                    type={question.type}
                    required={question.required}
                    className={inputClass}
                    {...register(name, onChange ? { onChange } : undefined)}
                />
                {question.key === 'birthdate' && ageHint && (
                    <span className="text-sm font-bold whitespace-nowrap" data-testid="birthdate-age">{ageHint}</span>
                )}
            </div>
        );
    }

    return (
        <div className="form-control">
            {label}
            {fieldControl}
            {question.description && <span className="label-text-alt opacity-70 mt-1">{question.description}</span>}
            {error && <span className="text-error text-xs mt-1">{error}</span>}
        </div>
    );
}

interface PersonPhotosFieldProps {
    photos: PersonPhotoUpload[];
    inputId: string;
    error?: string;
    onPhotosChange: (photos: PersonPhotoUpload[]) => void;
}

function isImageFile(file: File): boolean {
    return file.type.startsWith('image/');
}

/** jsdom (tests) lacks `URL.createObjectURL`; degrade to no preview. */
function createPreviewUrl(file: File): string | undefined {
    if (!isImageFile(file) || typeof URL.createObjectURL !== 'function') return undefined;
    return URL.createObjectURL(file);
}

function revokePreviewUrl(url: string | null | undefined): void {
    if (url && typeof URL.revokeObjectURL === 'function') URL.revokeObjectURL(url);
}

function PersonPhotosField({ photos, inputId, error, onPhotosChange }: PersonPhotosFieldProps) {
    const remaining = MAX_PHOTOS_PER_PERSON - photos.length;

    const handleFiles = (event: React.ChangeEvent<HTMLInputElement>) => {
        const files = Array.from(event.target.files ?? []);
        if (files.length === 0) return;
        const accepted = files.slice(0, remaining);
        const additions: PersonPhotoUpload[] = accepted.map(file => ({
            id: crypto.randomUUID(),
            file,
            visibility: 'internal',
            // Internal photos cannot be the main image; no auto-primary.
            is_primary: false,
            previewUrl: createPreviewUrl(file),
        }));
        onPhotosChange([...photos, ...additions]);
        event.target.value = '';
    };

    const setVisibility = (index: number, visibility: PhotoVisibility) => {
        onPhotosChange(photos.map((photo, i) => {
            if (i !== index) return photo;
            // Demoting the primary photo to internal clears the election.
            return { ...photo, visibility, is_primary: photo.is_primary && visibility === 'public' };
        }));
    };

    const setPrimary = (index: number) => {
        if (photos[index]?.visibility !== 'public') return;
        onPhotosChange(photos.map((photo, i) => ({ ...photo, is_primary: i === index })));
    };

    const removePhoto = (index: number) => {
        revokePreviewUrl(photos[index]?.previewUrl);
        onPhotosChange(photos.filter((_, i) => i !== index));
    };

    const movePhoto = (from: number, to: number) => {
        if (to < 0 || to >= photos.length || from === to) return;
        const next = [...photos];
        const [moved] = next.splice(from, 1);
        next.splice(to, 0, moved);
        onPhotosChange(next);
    };

    return (
        <div className="border-t border-base-300 pt-4" data-testid="model-photos">
            <div className="flex items-center justify-between gap-3">
                <span className="label-text font-bold"><Trans>Fotos</Trans></span>
                <span className="text-xs opacity-60">{photos.length} / {MAX_PHOTOS_PER_PERSON}</span>
            </div>
            <input
                id={inputId}
                type="file"
                multiple
                accept=".jpg,.jpeg,.png,.webp"
                disabled={remaining <= 0}
                className="file-input file-input-bordered w-full mt-2"
                onChange={handleFiles}
            />
            <span className="label-text-alt opacity-70 mt-1">
                <Trans>Maximal 5 Fotos pro Person. Standardmäßig intern; nur öffentliche Fotos können als Hauptbild dienen.</Trans>
            </span>
            {error && <span className="text-error text-xs mt-1" data-testid="model-photos-error">{error}</span>}
            <div className="flex flex-col gap-2 mt-2">
                {photos.map((photo, index) => (
                    <div
                        key={photo.id}
                        draggable
                        onDragStart={event => event.dataTransfer.setData('text/plain', String(index))}
                        onDragOver={event => event.preventDefault()}
                        onDrop={event => {
                            event.preventDefault();
                            movePhoto(Number(event.dataTransfer.getData('text/plain')), index);
                        }}
                        className="flex flex-col sm:flex-row sm:items-center gap-2 rounded-box bg-base-200 p-2"
                        data-testid={`model-photo-row-${index}`}
                    >
                        <div className="flex items-center gap-2 flex-1 min-w-0">
                            <span className="iconify mdi--drag-vertical opacity-40 cursor-grab hidden sm:inline" aria-hidden="true"></span>
                            {photo.previewUrl ? (
                                <img src={photo.previewUrl} alt={photo.file.name} className="h-10 w-10 rounded object-cover bg-base-300" />
                            ) : (
                                <span className="iconify mdi--file-pdf-box text-2xl text-error" aria-hidden="true"></span>
                            )}
                            <span className="text-sm truncate min-w-0">{photo.file.name}</span>
                        </div>
                        <div className="flex items-center gap-1">
                            <button type="button" className="btn btn-ghost btn-xs" aria-label={t`Nach oben`} disabled={index === 0} onClick={() => movePhoto(index, index - 1)}>
                                <span className="iconify mdi--arrow-up"></span>
                            </button>
                            <button type="button" className="btn btn-ghost btn-xs" aria-label={t`Nach unten`} disabled={index === photos.length - 1} onClick={() => movePhoto(index, index + 1)}>
                                <span className="iconify mdi--arrow-down"></span>
                            </button>
                        </div>
                        <label className="label cursor-pointer gap-2">
                            <input
                                type="radio"
                                name={`primary-photo-${inputId}`}
                                className="radio radio-primary radio-sm"
                                checked={photo.is_primary}
                                disabled={photo.visibility !== 'public'}
                                onChange={() => setPrimary(index)}
                            />
                            <span className={`label-text text-xs ${photo.visibility !== 'public' ? 'opacity-40' : ''}`}><Trans>Hauptbild</Trans></span>
                        </label>
                        <select
                            className="select select-bordered select-sm"
                            aria-label={t`Sichtbarkeit`}
                            value={photo.visibility}
                            onChange={event => setVisibility(index, event.target.value as PhotoVisibility)}
                        >
                            <option value="internal">{t`Intern`}</option>
                            <option value="public">{t`Öffentlich`}</option>
                        </select>
                        <button type="button" className="btn btn-ghost btn-xs text-error" onClick={() => removePhoto(index)}>
                            <span className="iconify mdi--trash-can"></span>
                            <Trans>Entfernen</Trans>
                        </button>
                    </div>
                ))}
            </div>
        </div>
    );
}

interface PersonBlockProps {
    index: number;
    catalog: ModelRegistrationCheck;
    control: Control<RegistrationFormValues>;
    register: UseFormRegister<RegistrationFormValues>;
    setValue: UseFormSetValue<RegistrationFormValues>;
    getValues: UseFormGetValues<RegistrationFormValues>;
    errors: FieldErrors<RegistrationFormValues>;
    answers: Record<string, unknown>;
    photos: PersonPhotoUpload[];
    isManager: boolean;
    personCount: number;
    canRemove: boolean;
    onRemove: () => void;
    onPhotosChange: (photos: PersonPhotoUpload[]) => void;
}

/** Channels that lose their selection when a carrier field is emptied. */
function channelsForCarrier(key: string): string[] {
    if (key === 'email') return ['E-Mail'];
    if (key === 'phone') return ['Telefon', 'WhatsApp'];
    if (key === 'instagram') return ['Instagram'];
    return [];
}

function PersonBlock({
    index,
    catalog,
    control,
    register,
    setValue,
    getValues,
    errors,
    answers,
    photos,
    isManager,
    personCount,
    canRemove,
    onRemove,
    onPhotosChange,
}: PersonBlockProps) {
    const visibility: VisibilityContext = { categories: catalog.categories, isManager, personCount };
    const ageProofQuestion = personQuestions(catalog).find(question => question.key === AGE_PROOF_KEY);
    const ageProofVisible = ageProofQuestion ? isQuestionVisible(ageProofQuestion, answers, visibility) : false;
    const ageProofRequired = isAgeProofRequired(ageProofQuestion, answers, visibility);
    const idPrefix = `person-${index}`;
    const managerId = `manager-${index}`;
    const contactPath = `persons.${index}.answers.preferred_contact` as FieldPath<RegistrationFormValues>;
    const [ageProofPreview, setAgeProofPreview] = useState<string | null>(null);

    const handleAgeProofChange = (file: File | null, onFieldChange: (value: File | null) => void) => {
        revokePreviewUrl(ageProofPreview);
        setAgeProofPreview(file ? createPreviewUrl(file) ?? null : null);
        onFieldChange(file);
    };

    const willingnessQuestions = personQuestions(catalog).filter(question =>
        isWillingnessQuestion(question) && isQuestionVisible(question, answers, visibility));
    const willingnessGroups = groupWillingnessByLevel(willingnessQuestions.map(question => ({
        level: typeof answers[question.key] === 'string' ? answers[question.key] as string : 'nein',
        label: question.label.replace(/^Bereitschaft:\s*/, ''),
    })));

    const birthdate = typeof answers.birthdate === 'string' ? answers.birthdate : '';
    let ageHint: string | null = null;
    if (birthdate) {
        const parsed = new Date(birthdate);
        if (!Number.isNaN(parsed.getTime())) ageHint = `${calcAge(parsed)} ${t`Jahre`}`;
    }

    const syncContactChannels = (carrierKey: string, nextValue: string) => {
        const affected = channelsForCarrier(carrierKey);
        if (affected.length === 0) return;
        const current = getValues(contactPath);
        if (!Array.isArray(current)) return;
        const filtered = current.filter(channel => !(nextValue.trim() === '' && affected.includes(String(channel))));
        if (filtered.length !== current.length) {
            setValue(contactPath, filtered as RegistrationFormValues['persons'][number]['answers'][string], { shouldDirty: true });
        }
    };

    return (
        <div role="group" aria-label={`Person ${index + 1}`} data-testid={`model-person-${index}`} className="card bg-base-100 border border-base-300 shadow-sm">
            <div className="card-body gap-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h3 className="card-title text-xl">
                        <span className="iconify mdi--account text-primary"></span>
                        <Trans>Person</Trans> {index + 1}
                    </h3>
                    <div className="flex items-center gap-3">
                        {personCount > 1 && (
                            <label className="label cursor-pointer justify-start gap-2" htmlFor={managerId}>
                                <input id={managerId} type="radio" value={index} className="radio radio-primary radio-sm" {...register('manager_index', { valueAsNumber: true })} />
                                <span className="label-text"><Trans>Managerperson</Trans></span>
                            </label>
                        )}
                        {canRemove && (
                            <button type="button" className="btn btn-ghost btn-sm text-error" onClick={onRemove}>
                                <span className="iconify mdi--trash-can"></span>
                                <Trans>Entfernen</Trans>
                            </button>
                        )}
                    </div>
                </div>

                {sections(catalog).map(section => {
                    const visibleQuestions = section.questions.filter(question =>
                        question.scope === 'person' &&
                        question.key !== AGE_PROOF_KEY &&
                        question.type !== 'file' &&
                        isQuestionVisible(question, answers, visibility),
                    );
                    if (visibleQuestions.length === 0) return null;

                    const grid = (
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mt-3">
                            {visibleQuestions.map(question => (
                                <div key={question.key} className={question.type === 'textarea' ? 'md:col-span-2' : ''}>
                                    <QuestionField
                                        question={question}
                                        name={`persons.${index}.answers.${question.key}` as FieldPath<RegistrationFormValues>}
                                        idPrefix={idPrefix}
                                        error={fieldError(errors, `persons.${index}.answers.${question.key}`)}
                                        register={register}
                                        control={control}
                                        answers={answers}
                                        ageHint={ageHint}
                                        onAnswerChange={syncContactChannels}
                                    />
                                </div>
                            ))}
                        </div>
                    );

                    // "Aussehen & Maße" is collapsed by default (§2.5).
                    if (section.key === 'aussehen') {
                        return (
                            <details key={section.key} className="border-t border-base-300 pt-4">
                                <summary className="text-sm font-bold uppercase tracking-wide opacity-60 px-1 cursor-pointer">
                                    {section.label}
                                </summary>
                                {grid}
                            </details>
                        );
                    }

                    return (
                        <fieldset key={section.key} className="border-t border-base-300 pt-4">
                            <legend className="text-sm font-bold uppercase tracking-wide opacity-60 px-1">{section.label}</legend>
                            {grid}
                        </fieldset>
                    );
                })}

                {willingnessGroups.length > 0 && (
                    <div className="border-t border-base-300 pt-4" data-testid="willingness-overview">
                        <span className="label-text font-bold"><Trans>Bereitschafts-Übersicht</Trans></span>
                        <div className="space-y-2 mt-2">
                            {willingnessGroups.map(group => (
                                <div key={group.level} className="flex flex-wrap items-center gap-2">
                                    <span className={`badge ${willingnessBadgeClass(group.level)}`}>
                                        {modelWillingnessLabel(group.level)}
                                    </span>
                                    {group.items.map(item => (
                                        <span key={item.label} className="badge badge-outline badge-sm">{item.label}</span>
                                    ))}
                                </div>
                            ))}
                        </div>
                    </div>
                )}

                {ageProofQuestion && ageProofVisible && (
                    <fieldset className="border-t border-base-300 pt-4">
                        <legend className="text-sm font-bold uppercase tracking-wide opacity-60 px-1">{t`Altersnachweis`}</legend>
                        <Controller
                            control={control}
                            name={`persons.${index}.age_proof`}
                            render={({ field }) => (
                                <div className="form-control mt-3">
                                    <label className="label" htmlFor={`${idPrefix}-age-proof`}>
                                        <span className="label-text font-bold">{ageProofQuestion.label}</span>
                                    </label>
                                    <input
                                        id={`${idPrefix}-age-proof`}
                                        type="file"
                                        required={ageProofRequired}
                                        accept=".jpg,.jpeg,.png,.webp,.pdf"
                                        className={`file-input file-input-bordered w-full ${fieldError(errors, `persons.${index}.age_proof`) ? 'file-input-error' : ''}`}
                                        onChange={event => handleAgeProofChange(event.target.files?.[0] ?? null, field.onChange)}
                                    />
                                    {field.value && (
                                        <span className="flex items-center gap-2 label-text-alt opacity-70 mt-1">
                                            {ageProofPreview
                                                ? <img src={ageProofPreview} alt={field.value.name} className="h-10 w-10 rounded object-cover bg-base-300" />
                                                : <span className="iconify mdi--file-pdf-box text-2xl text-error" aria-hidden="true"></span>}
                                            {field.value.name}
                                        </span>
                                    )}
                                    {ageProofQuestion.description && <span className="label-text-alt opacity-70 mt-1">{ageProofQuestion.description}</span>}
                                    {fieldError(errors, `persons.${index}.age_proof`) && (
                                        <span className="text-error text-xs mt-1">{fieldError(errors, `persons.${index}.age_proof`)}</span>
                                    )}
                                </div>
                            )}
                        />
                    </fieldset>
                )}

                <PersonPhotosField
                    photos={photos}
                    inputId={`${idPrefix}-photos`}
                    error={fieldError(errors, `persons.${index}.photos`)}
                    onPhotosChange={onPhotosChange}
                />

                <div className="border-t border-base-300 pt-4">
                    <label className="label cursor-pointer justify-start gap-3 rounded-box p-3 hover:bg-base-300/50" htmlFor={`${idPrefix}-create-account`}>
                        <input id={`${idPrefix}-create-account`} type="checkbox" className="checkbox checkbox-primary shrink-0" {...register(`persons.${index}.create_account`)} />
                        <span className="label-text"><Trans>Portal-Konto für diese Person anlegen</Trans></span>
                    </label>
                </div>
            </div>
        </div>
    );
}

interface RegistrationFormProps {
    catalog: ModelRegistrationCheck;
    submit: (values: RegistrationFormValues) => Promise<ModelRegistrationSubmitResult>;
    onSubmitted: (result: ModelRegistrationSubmitResult) => void;
    onFatalError: (message: string) => void;
}

export function RegistrationForm({ catalog, submit, onSubmitted, onFatalError }: RegistrationFormProps) {
    const schema = createRegistrationSchema(catalog);
    const { control, register, handleSubmit, setError, setValue, getValues, formState: { errors, isSubmitting } } = useForm<RegistrationFormValues>({
        resolver: zodResolver(schema),
        defaultValues: makeRegistrationValues(catalog),
        mode: 'onSubmit',
    });
    const { fields, append, remove } = useFieldArray({ control, name: 'persons' });
    const [submitError, setSubmitError] = useState('');
    const watchedPersons = useWatch({ control, name: 'persons' });
    const managerIndex = useWatch({ control, name: 'manager_index' });

    const actSectionQuestions = sections(catalog).flatMap(section =>
        section.questions.filter(question => question.scope === 'act'),
    );
    const isMultiple = fields.length > 1;
    const canAddPerson = fields.length < MAX_PERSONS_PER_REGISTRATION;

    const addPerson = () => {
        if (!canAddPerson) return;
        append({ answers: makePersonAnswers(catalog), create_account: false, age_proof: null, photos: [] });
    };

    const handleRemovePerson = (index: number) => {
        remove(index);
        // Keep the manager pointer valid after the array shift. `remove` does not
        // touch manager_index, so clamp it into the new range.
        if (typeof managerIndex === 'number' && managerIndex >= index && managerIndex > 0) {
            setValue('manager_index', Math.max(0, managerIndex - 1));
        }
    };

    const selectMultiplePersons = () => {
        if (fields.length <= 1) addPerson();
    };

    const selectSinglePerson = () => {
        for (let i = fields.length - 1; i >= 1; i--) {
            remove(i);
        }
        setValue('manager_index', 0);
    };

    const onSubmit = async (values: RegistrationFormValues) => {
        setSubmitError('');
        try {
            const result = await submit(values);
            onSubmitted(result);
        } catch (error: unknown) {
            // 404/409/410 terminate the link: show the error page instead of
            // leaving a form that can never be submitted successfully.
            if (isFatalSubmitError(error)) {
                onFatalError(errorMessage(error, t`Diese Einladung ist ungültig oder abgelaufen.`));
                return;
            }

            const validationErrors = extractValidationErrors(error);
            if (validationErrors) {
                for (const [key, messages] of Object.entries(validationErrors)) {
                    setError(mapServerErrorKey(key) as FieldPath<RegistrationFormValues>, {
                        type: 'server',
                        message: messages[0] ?? t`Ungültiger Wert.`,
                    });
                }
                setSubmitError(t`Bitte prüfe die markierten Felder.`);
                return;
            }
            setSubmitError(errorMessage(error, t`Fehler beim Absenden.`));
        }
    };

    return (
        <form onSubmit={handleSubmit(onSubmit)} className="space-y-6" noValidate>
            {submitError && <ErrorMessage message={submitError} />}

            <div className="card bg-base-100 border border-primary/30 shadow-sm" data-testid="act-size-entry">
                <div className="card-body gap-3">
                    <h2 className="card-title text-lg">
                        <span className="iconify mdi--account-multiple text-primary"></span>
                        <Trans>Act-Umfang</Trans>
                    </h2>
                    <p className="text-sm opacity-70"><Trans>Gehört zu diesem Act eine Person oder mehrere?</Trans></p>
                    <div role="radiogroup" aria-label={t`Act-Umfang`} className="flex flex-col sm:flex-row gap-3">
                        <label className={`label cursor-pointer justify-start gap-3 rounded-box border p-3 flex-1 ${!isMultiple ? 'border-primary bg-primary/10' : 'border-base-300'}`}>
                            <input type="radio" name="act-size" className="radio radio-primary radio-sm" checked={!isMultiple} onChange={selectSinglePerson} />
                            <span className="label-text font-bold"><Trans>Eine Person</Trans></span>
                        </label>
                        <label className={`label cursor-pointer justify-start gap-3 rounded-box border p-3 flex-1 ${isMultiple ? 'border-primary bg-primary/10' : 'border-base-300'}`}>
                            <input type="radio" name="act-size" className="radio radio-primary radio-sm" checked={isMultiple} onChange={selectMultiplePersons} />
                            <span className="label-text font-bold"><Trans>Mehrere Personen</Trans></span>
                        </label>
                    </div>
                    <div>
                        <button type="button" className="btn btn-outline btn-sm" data-testid="add-person" onClick={addPerson} disabled={!canAddPerson} aria-disabled={!canAddPerson}>
                            <span className="iconify mdi--account-plus"></span>
                            <Trans>Weitere Person hinzufügen</Trans>
                        </button>
                        {!canAddPerson && (
                            <p className="mt-2 text-sm text-warning" role="status">
                                <Trans>Maximal {MAX_PERSONS_PER_REGISTRATION} Personen pro Registrierung.</Trans>
                            </p>
                        )}
                    </div>
                </div>
            </div>

            <div className="space-y-6" data-testid="model-persons">
                {fields.map((field, index) => (
                    <PersonBlock
                        key={field.id}
                        index={index}
                        catalog={catalog}
                        control={control}
                        register={register}
                        setValue={setValue}
                        getValues={getValues}
                        errors={errors}
                        answers={watchedPersons?.[index]?.answers ?? {}}
                        photos={watchedPersons?.[index]?.photos ?? []}
                        isManager={Number(managerIndex ?? 0) === index}
                        personCount={fields.length}
                        canRemove={fields.length > 1}
                        onRemove={() => handleRemovePerson(index)}
                        onPhotosChange={photos => setValue(`persons.${index}.photos`, photos, { shouldDirty: true })}
                    />
                ))}
            </div>

            {isMultiple && actSectionQuestions.length > 0 && (
                <fieldset className="card bg-base-100 border border-base-300 shadow-sm">
                    <div className="card-body">
                        <legend className="card-title text-xl">
                            <span className="iconify mdi--note-text text-primary"></span>
                            <Trans>Abschluss</Trans>
                        </legend>
                        <div className="grid grid-cols-1 gap-4">
                            {actSectionQuestions.map(question => (
                                <QuestionField
                                    key={question.key}
                                    question={question}
                                    name={`act_answers.${question.key}` as FieldPath<RegistrationFormValues>}
                                    idPrefix="act"
                                    error={fieldError(errors, `act_answers.${question.key}`)}
                                    register={register}
                                    control={control}
                                />
                            ))}
                        </div>
                    </div>
                </fieldset>
            )}

            <div className="flex flex-col items-center gap-3">
                <button type="submit" className="btn btn-primary btn-lg w-full" disabled={isSubmitting}>
                    {isSubmitting ? <span className="loading loading-spinner"></span> : <Trans>Registrierung absenden</Trans>}
                </button>
                <p className="text-xs opacity-60 text-center">
                    <Trans>Pflichtfelder sind mit * markiert. Der Altersnachweis wird ausschließlich verschlüsselt auf einem privaten Speicher abgelegt.</Trans>
                </p>
            </div>
        </form>
    );
}

export default function ModelRegistrationView() {
    const { token } = useParams<{ token: string }>();
    const { registration, error, isLoading, submit } = useModelRegistration(token);
    const [result, setResult] = useState<ModelRegistrationSubmitResult | null>(null);
    const [fatalError, setFatalError] = useState('');

    // A GET 404/410 (SWR error) and a fatal submit 404/409/410 share one error
    // page — the form is no longer offered in either case.
    const errorPageMessage = fatalError
        || (error ? errorMessage(error, t`Diese Einladung ist ungültig oder abgelaufen.`) : '');
    const showErrorPage = !isLoading && errorPageMessage !== '';

    return (
        <GuestLayout>
            <div className="mx-auto w-full max-w-4xl p-4 md:p-8">
                {isLoading && (
                    <div className="flex h-64 items-center justify-center">
                        <span className="loading loading-spinner loading-lg text-primary"></span>
                    </div>
                )}

                {showErrorPage && (
                    <div className="flex h-64 items-center justify-center p-4" data-testid="model-registration-error">
                        <ErrorMessage message={errorPageMessage} className="max-w-md shadow-lg mx-auto" />
                    </div>
                )}

                {!isLoading && !showErrorPage && result && (
                    <div className="flex h-full items-center justify-center p-4">
                        <div className="card w-full max-w-lg bg-base-100 shadow-2xl">
                            <div className="card-body items-center text-center">
                                <span className="iconify mdi--check-circle text-6xl text-success"></span>
                                <h2 className="card-title text-2xl"><Trans>Registrierung erfolgreich</Trans></h2>
                                <p className="opacity-70">
                                    <Trans>Registrierung abgeschlossen. Dein Einladungslink ist damit verbraucht.</Trans>
                                </p>
                                <p className="font-bold">
                                    {result.person_count} <Trans>Person(en) erfasst</Trans>
                                </p>
                            </div>
                        </div>
                    </div>
                )}

                {!isLoading && !showErrorPage && !result && registration && (
                    <div className="space-y-6">
                        <div className="text-center">
                            <h1 className="text-3xl md:text-4xl font-bold"><Trans>Model-Registrierung</Trans></h1>
                            <p className="opacity-70 mt-2">
                                <Trans>Willkommen! Bitte erfasse alle Personen des Acts.</Trans>
                            </p>
                        </div>
                        <RegistrationForm
                            catalog={registration}
                            submit={submit}
                            onSubmitted={setResult}
                            onFatalError={setFatalError}
                        />
                    </div>
                )}
            </div>
        </GuestLayout>
    );
}
