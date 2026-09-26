import { useState } from 'react';
import { useParams } from 'react-router-dom';
import { useForm, useWatch, type FieldPath } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { t } from '@lingui/core/macro';
import { Trans } from '@lingui/react/macro';
import GuestLayout from './components/GuestLayout';
import ErrorMessage from './components/ErrorMessage';
import { useUI } from './components/UIContext';
import { useModelProfileAccess } from '../logic/useModelProfileAccess';
import { calcAge } from '../logic/utils';
import { QuestionField } from './ModelRegistrationView';
import {
    answersRecordFromSnapshot,
    buildProfilePhotoPayload,
    createProfileAnswersSchema,
    isQuestionVisible,
    makePersonAnswers,
    managerTransferCandidates,
    modelActTypeLabel,
    normalizeAnswersForForm,
    modelLifecycleBadgeClass,
    modelLifecycleLabel,
    sections,
    type AnswersRecord,
    type ModelProfileAccess,
    type ModelProfileAccessAct,
    type ModelProfileAccessPhoto,
    type ModelProfilePhotoUpdate,
    type ModelRegistrationCheck,
    type PhotoVisibility,
    type RegistrationQuestion,
    type VisibilityContext,
} from '../logic/modelRegistration';

interface ProfileFormValues {
    answers: AnswersRecord;
}

function channelsForCarrier(key: string): string[] {
    if (key === 'email') return ['E-Mail'];
    if (key === 'phone') return ['Telefon', 'WhatsApp'];
    if (key === 'instagram') return ['Instagram'];
    return [];
}

/**
 * A 422 from the backend carries Laravel's `{errors: {key: [msg]}}` payload.
 * `ApiError` is an interface, so detect it structurally.
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

function formatDateTime(value: string | null): string {
    if (!value) return '–';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '–';
    return date.toLocaleString('de-DE', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function ProfileEditForm({ profile, onSave, photoError, ageProofError }: {
    profile: ModelProfileAccess;
    onSave: (answers: AnswersRecord, photos: ModelProfilePhotoUpdate[], ageProof: File | null) => Promise<void>;
    photoError?: string;
    ageProofError?: string;
}) {
    const [photos, setPhotos] = useState<ModelProfileAccessPhoto[]>(profile.photos);
    const [ageProof, setAgeProof] = useState<File | null>(null);
    const catalog: ModelRegistrationCheck = {
        brand: profile.brand,
        status: 'open',
        email: profile.email,
        person_count: 0,
        expires_at: profile.expires_at,
        catalog_version: profile.catalog_version,
        categories: profile.categories,
        sections: profile.sections,
    };
    const schema = createProfileAnswersSchema(catalog);

    // Merge catalogue defaults (willingness `nein`, experience `keine`) with the
    // normalized snapshot so a v1 profile can be migrated without empty selects.
    const defaultAnswers: AnswersRecord = {
        ...makePersonAnswers(catalog),
        ...normalizeAnswersForForm(catalog, answersRecordFromSnapshot(profile.answers)),
    };

    const { control, register, handleSubmit, setValue, getValues, formState: { errors, isSubmitting } } = useForm<ProfileFormValues>({
        resolver: zodResolver(schema),
        defaultValues: { answers: defaultAnswers },
        mode: 'onSubmit',
    });

    const answers = useWatch({ control, name: 'answers' }) ?? {};
    const visibility: VisibilityContext = { categories: profile.categories, isManager: false, personCount: 1 };
    const contactPath = 'answers.preferred_contact' as FieldPath<ProfileFormValues>;

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
            setValue(contactPath, filtered, { shouldDirty: true });
        }
    };

    const setPhotoVisibility = (id: string, nextVisibility: PhotoVisibility) => {
        setPhotos(previous => previous.map(photo => photo.id === id
            // Demoting the main photo to internal clears the election.
            ? { ...photo, visibility: nextVisibility, is_primary: photo.is_primary && nextVisibility === 'public' }
            : photo));
    };

    const setPhotoPrimary = (id: string) => {
        const target = photos.find(photo => photo.id === id);
        if (!target || target.visibility !== 'public') return;
        setPhotos(previous => previous.map(photo => ({ ...photo, is_primary: photo.id === id })));
    };

    const errorFor = (key: string): string | undefined => {
        const message = (errors.answers as Record<string, { message?: string }> | undefined)?.[key]?.message;
        return typeof message === 'string' ? message : undefined;
    };

    const field = (question: RegistrationQuestion) => (
        <div key={question.key} className={question.type === 'textarea' ? 'md:col-span-2' : ''}>
            <QuestionField
                question={question}
                name={`answers.${question.key}` as FieldPath<ProfileFormValues>}
                idPrefix="profile"
                error={errorFor(question.key)}
                register={register}
                control={control}
                answers={answers}
                ageHint={ageHint}
                onAnswerChange={syncContactChannels}
            />
        </div>
    );

    return (
        <form onSubmit={handleSubmit(values => onSave(values.answers, buildProfilePhotoPayload(photos), ageProof))} className="space-y-6" noValidate>
            {sections(catalog).map(section => {
                const questions = section.questions.filter(question =>
                    question.scope === 'person' &&
                    question.type !== 'file' &&
                    isQuestionVisible(question, answers, visibility),
                );
                if (questions.length === 0) return null;
                const grid = <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mt-3">{questions.map(field)}</div>;
                if (section.key === 'aussehen') {
                    return (
                        <details key={section.key} className="border-t border-base-300 pt-4">
                            <summary className="text-sm font-bold uppercase tracking-wide opacity-60 px-1 cursor-pointer">{section.label}</summary>
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

            {profile.age_proof_required && !profile.age_proof_uploaded_at && (
                <div className="border-t border-base-300 pt-4" data-testid="profile-age-proof">
                    <label className="label" htmlFor="profile-age-proof">
                        <span className="label-text font-bold">{t`Altersnachweis (Ausweis)`}</span>
                    </label>
                    <input
                        id="profile-age-proof"
                        type="file"
                        required
                        accept=".jpg,.jpeg,.png,.webp,.pdf"
                        className="file-input file-input-bordered w-full"
                        onChange={event => setAgeProof(event.target.files?.[0] ?? null)}
                    />
                    {ageProof && <span className="label-text-alt opacity-70 mt-1">{ageProof.name}</span>}
                    <span className="label-text-alt opacity-70 mt-1">
                        <Trans>Pflicht, solange noch kein Ausweis hinterlegt ist. JPG, PNG, WebP oder PDF, max. 10 MB.</Trans>
                    </span>
                    {ageProofError && (
                        <span className="text-error text-xs mt-1" data-testid="profile-age-proof-error">{ageProofError}</span>
                    )}
                </div>
            )}

            {photos.length > 0 && (
                <div className="border-t border-base-300 pt-4" data-testid="profile-photos">
                    <span className="label-text font-bold"><Trans>Meine Fotos</Trans></span>
                    <div className="flex flex-col gap-2 mt-2">
                        {photos.map(photo => (
                            <div key={photo.id} className="flex flex-col sm:flex-row sm:items-center gap-2 rounded-box bg-base-200 p-2">
                                <span className="flex-1 text-sm truncate min-w-0">{photo.mime_type ?? t`Foto`}</span>
                                <label className="label cursor-pointer gap-2">
                                    <input
                                        type="radio"
                                        name="profile-primary-photo"
                                        className="radio radio-primary radio-sm"
                                        checked={photo.is_primary}
                                        disabled={photo.visibility !== 'public'}
                                        onChange={() => setPhotoPrimary(photo.id)}
                                    />
                                    <span className={`label-text text-xs ${photo.visibility !== 'public' ? 'opacity-40' : ''}`}>
                                        <Trans>Hauptbild</Trans>
                                    </span>
                                </label>
                                <select
                                    className="select select-bordered select-sm"
                                    aria-label={t`Sichtbarkeit`}
                                    value={photo.visibility}
                                    onChange={event => setPhotoVisibility(photo.id, event.target.value as PhotoVisibility)}
                                >
                                    <option value="internal">{t`Intern`}</option>
                                    <option value="public">{t`Öffentlich`}</option>
                                </select>
                            </div>
                        ))}
                    </div>
                    <span className="label-text-alt opacity-70 mt-1">
                        <Trans>Nur öffentliche Fotos können als Hauptbild dienen.</Trans>
                    </span>
                    {photoError && (
                        <span className="text-error text-xs mt-1" data-testid="profile-photos-error">{photoError}</span>
                    )}
                </div>
            )}

            <button type="submit" className="btn btn-primary w-full" disabled={isSubmitting} data-testid="model-profile-save">
                {isSubmitting ? <span className="loading loading-spinner"></span> : <Trans>Änderungen speichern</Trans>}
            </button>
        </form>
    );
}

/**
 * Owner section: acts the token holder belongs to, current manager, and the
 * hand-over control. Only shown for acts the owner currently manages.
 */
function ManagerSection({ acts, onTransfer }: {
    acts: ModelProfileAccessAct[];
    onTransfer: (actId: string, newManagerCustomerId: string) => Promise<void>;
}) {
    const [selection, setSelection] = useState<Record<string, string>>({});

    if (acts.length === 0) return null;

    return (
        <div className="card bg-base-100 border border-base-300 shadow-sm" data-testid="model-profile-manager">
            <div className="card-body gap-3">
                <h2 className="card-title text-xl"><Trans>Meine Acts</Trans></h2>
                {acts.map(act => {
                    const candidates = managerTransferCandidates(act);
                    const selected = selection[act.id] ?? '';
                    return (
                        <div
                            key={act.id}
                            className="rounded-box bg-base-200 p-3 space-y-2"
                            data-testid={`model-profile-act-${act.id}`}
                        >
                            <div className="text-sm">
                                <span className="font-bold">{modelActTypeLabel(act.act_type)}</span>
                                <span className="opacity-70"> · {act.person_count} {t`Person(en)`}</span>
                            </div>
                            <div className="text-xs opacity-70">
                                <Trans>Aktueller Manager</Trans>:{' '}
                                <span className="font-bold">{act.manager_name ?? '–'}</span>
                            </div>
                            {act.is_manager && candidates.length > 0 && (
                                <div className="flex flex-col sm:flex-row gap-2 sm:items-end">
                                    <label className="form-control flex-1">
                                        <span className="label-text text-xs"><Trans>Verwaltung abgeben an</Trans></span>
                                        <select
                                            className="select select-bordered select-sm"
                                            aria-label={t`Verwaltung abgeben an`}
                                            value={selected}
                                            onChange={event => setSelection(previous => ({ ...previous, [act.id]: event.target.value }))}
                                        >
                                            <option value="">{t`Bitte wählen`}</option>
                                            {candidates.map(member => (
                                                <option key={member.customer_id} value={member.customer_id}>
                                                    {member.name ?? member.customer_id}
                                                </option>
                                            ))}
                                        </select>
                                    </label>
                                    <button
                                        type="button"
                                        className="btn btn-outline btn-sm"
                                        disabled={selected === ''}
                                        onClick={() => onTransfer(act.id, selected)}
                                        data-testid={`model-profile-transfer-${act.id}`}
                                    >
                                        <Trans>Verwaltung abgeben</Trans>
                                    </button>
                                </div>
                            )}
                        </div>
                    );
                })}
            </div>
        </div>
    );
}

export default function ModelProfileAccessView() {
    const { token } = useParams<{ token: string }>();
    const { profile, error, isLoading, update, confirm, transfer } = useModelProfileAccess(token);
    const { showToast, confirm: confirmDialog } = useUI();
    const [isConfirming, setIsConfirming] = useState(false);
    const [fatalError, setFatalError] = useState('');
    const [photoError, setPhotoError] = useState('');
    const [ageProofError, setAgeProofError] = useState('');

    const errorMessage = fatalError
        || (error ? (error instanceof Error && error.message ? error.message : t`Dieser Profil-Link ist ungültig oder abgelaufen.`) : '');

    const handleSave = async (answers: AnswersRecord, photos: ModelProfilePhotoUpdate[], ageProof: File | null) => {
        setPhotoError('');
        setAgeProofError('');
        try {
            const result = await update(answers, photos, ageProof);
            showToast('success', t`Profil wurde gespeichert.`);
            if (result.last_confirmed_at) setFatalError('');
        } catch (err: unknown) {
            if (err instanceof Error && (err as { status?: number }).status === 410) {
                setFatalError(err.message);
                return;
            }
            // Surface field-level 422s (age proof / photo visibility / primary)
            // instead of a silent generic toast.
            const validationErrors = extractValidationErrors(err);
            if (validationErrors) {
                const entries = Object.entries(validationErrors);
                const ageProofMessage = entries.find(([key]) => key === 'age_proof')?.[1]?.[0];
                if (ageProofMessage) {
                    setAgeProofError(ageProofMessage);
                    showToast('error', ageProofMessage);
                    return;
                }
                const photoMessage = entries.find(([key]) => key.startsWith('photos'))?.[1]?.[0];
                if (photoMessage) {
                    setPhotoError(photoMessage);
                    showToast('error', photoMessage);
                    return;
                }
                showToast('error', entries[0]?.[1]?.[0] ?? t`Bitte prüfe die markierten Felder.`);
                return;
            }
            showToast('error', err instanceof Error ? err.message : t`Fehler beim Speichern.`);
        }
    };

    const handleConfirm = async () => {
        if (!(await confirmDialog({
            title: t`Profil bestätigen?`,
            message: t`Damit wird die letzte Bestätigung auf jetzt gesetzt. Der Fragenkatalog wird dabei nicht verändert.`,
            confirmColor: 'primary',
        }))) return;
        setIsConfirming(true);
        try {
            await confirm();
            showToast('success', t`Profil wurde bestätigt.`);
        } catch (err: unknown) {
            if (err instanceof Error && (err as { status?: number }).status === 410) {
                setFatalError(err.message);
                return;
            }
            showToast('error', err instanceof Error ? err.message : t`Fehler beim Bestätigen.`);
        } finally {
            setIsConfirming(false);
        }
    };

    const handleTransfer = async (actId: string, newManagerCustomerId: string) => {
        if (newManagerCustomerId === '') return;
        if (!(await confirmDialog({
            title: t`Verwaltung abgeben?`,
            message: t`Die gewählte Person wird neuer Manager dieses Acts. Du kannst danach die Verwaltung nicht mehr für diesen Act übernehmen.`,
            confirmColor: 'warning',
        }))) return;
        try {
            await transfer(actId, newManagerCustomerId);
            showToast('success', t`Verwaltung wurde abgegeben.`);
        } catch (err: unknown) {
            if (err instanceof Error && (err as { status?: number }).status === 410) {
                setFatalError(err.message);
                return;
            }
            const validationErrors = extractValidationErrors(err);
            const message = validationErrors
                ? (Object.values(validationErrors)[0]?.[0] ?? t`Verwaltung konnte nicht abgegeben werden.`)
                : (err instanceof Error && err.message ? err.message : t`Verwaltung konnte nicht abgegeben werden.`);
            showToast('error', message);
        }
    };

    return (
        <GuestLayout>
            <div className="mx-auto w-full max-w-4xl p-4 md:p-8">
                {isLoading && (
                    <div className="flex h-64 items-center justify-center">
                        <span className="loading loading-spinner loading-lg text-primary"></span>
                    </div>
                )}

                {!isLoading && errorMessage && (
                    <div className="flex h-64 items-center justify-center p-4" data-testid="model-profile-error">
                        <ErrorMessage message={errorMessage} className="max-w-md shadow-lg mx-auto" />
                    </div>
                )}

                {!isLoading && !errorMessage && profile && (
                    <div className="space-y-6" data-testid="model-profile-access">
                        <div className="card bg-base-100 border border-base-300 shadow-sm">
                            <div className="card-body gap-3">
                                <div className="flex flex-wrap items-center justify-between gap-3">
                                    <h1 className="text-2xl md:text-3xl font-bold">
                                        {profile.display_name ?? t`Mein Model-Profil`}
                                    </h1>
                                    <span className={`badge ${modelLifecycleBadgeClass(profile.lifecycle_status)}`}>
                                        {modelLifecycleLabel(profile.lifecycle_status)}
                                    </span>
                                </div>
                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
                                    <div className="rounded-box bg-base-200 p-3">
                                        <div className="text-xs opacity-60"><Trans>Letzte Bestätigung</Trans></div>
                                        <div className="font-bold">{formatDateTime(profile.last_confirmed_at)}</div>
                                    </div>
                                    <div className="rounded-box bg-base-200 p-3">
                                        <div className="text-xs opacity-60"><Trans>Fotos</Trans></div>
                                        <div className="font-bold">{profile.photos.length}</div>
                                    </div>
                                </div>
                                {profile.is_catalog_outdated && (
                                    <div className="alert alert-info" role="status">
                                        <span className="iconify mdi--information-outline text-xl"></span>
                                        <span><Trans>Beim nächsten Speichern wird dein Profil auf den aktuellen Fragenkatalog aktualisiert.</Trans></span>
                                    </div>
                                )}
                                <div className="flex flex-col sm:flex-row gap-2">
                                    <button
                                        type="button"
                                        className="btn btn-outline"
                                        onClick={handleConfirm}
                                        disabled={isConfirming}
                                        data-testid="model-profile-confirm"
                                    >
                                        {isConfirming ? <span className="loading loading-spinner"></span> : <span className="iconify mdi--check"></span>}
                                        <Trans>Profil bestätigen</Trans>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <ManagerSection acts={profile.acts} onTransfer={handleTransfer} />

                        <div className="card bg-base-100 border border-base-300 shadow-sm">
                            <div className="card-body">
                                <h2 className="card-title text-xl"><Trans>Profil aktualisieren</Trans></h2>
                                <ProfileEditForm profile={profile} onSave={handleSave} photoError={photoError} ageProofError={ageProofError} />
                            </div>
                        </div>
                    </div>
                )}
            </div>
        </GuestLayout>
    );
}
