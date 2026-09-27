import { useState } from 'react';
import { t } from '@lingui/core/macro';
import { Trans } from '@lingui/react/macro';
import {
    ageProofDownloadUrl,
    createModelAccessLink,
    deleteModel,
    deleteModelPhoto,
    isModelOutdated,
    revokeModelAccessLink,
    setPrimaryModelPhoto,
    type ManagedModel,
} from '../../../logic/useModels';
import {
    downloadModelContactSheet,
    type ModelContactSheetVariant,
} from '../../../logic/modelContactSheet';
import { usePermissions } from '../../../logic/usePermissions';
import {
    experienceMapFromAnswers,
    groupModelAnswersBySection,
    isTruthyAnswer,
    modelActTypeLabels,
    modelCategoryLabel,
    modelExperienceLabel,
    modelGenderLabel,
    modelLifecycleBadgeClass,
    modelLifecycleLabel,
    modelSkillMatrix,
    modelWillingnessCategories,
    modelWillingnessLabel,
    sortSkillMatrixRows,
    willingnessBadgeClass,
    type ModelAnswerSectionKey,
    type ModelProfileAnswer,
} from '../../../logic/modelRegistration';
import { useUI } from '../../components/UIContext';
import ModalShell from '../../components/ModalShell';

interface Props {
    model: ManagedModel | null;
    onClose: () => void;
    onChanged: () => void;
    /** Matched/filter categories pinned to the top of the skill matrix. */
    pinnedCategories?: string[];
}

async function copyTextToClipboard(text: string): Promise<void> {
    if (typeof navigator !== 'undefined' && navigator.clipboard?.writeText) {
        await navigator.clipboard.writeText(text);
        return;
    }
    const textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.setAttribute('readonly', '');
    textarea.className = 'fixed opacity-0 pointer-events-none';
    document.body.appendChild(textarea);
    textarea.select();
    const copied = document.execCommand('copy');
    document.body.removeChild(textarea);
    if (!copied) throw new Error('Clipboard copy failed');
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

function formatValue(value: unknown): string {
    if (value === null || value === undefined || value === '') return '–';
    if (typeof value === 'boolean') return value ? t`Ja` : t`Nein`;
    if (Array.isArray(value)) return value.length > 0 ? value.join(', ') : '–';
    return String(value);
}

/** Checkbox snapshots persist as `'1'`/`'0'`; render them as Ja/Nein. */
function formatAnswer(answer: ModelProfileAnswer): string {
    if (answer.type === 'checkbox') return isTruthyAnswer(answer.value) ? t`Ja` : t`Nein`;
    if (answer.key.startsWith('experience_')) {
        return modelExperienceLabel(typeof answer.value === 'string' ? answer.value : null) ?? formatValue(answer.value);
    }
    return formatValue(answer.value);
}

function answerIsEmpty(answer: ModelProfileAnswer): boolean {
    if (answer.type === 'checkbox') return !isTruthyAnswer(answer.value);
    return formatValue(answer.value) === '–';
}

/**
 * Label of a section *inside* the "Profil-Angaben" group, e.g. "Basisdaten".
 *
 * It used to be `h4 font-bold text-lg` — byte-identical to the group heading it
 * sits under, so the UI review read one flat level. It is now one step down on
 * all three axes that carry hierarchy: size (16 vs 18), weight (600 vs 700) and
 * contrast, plus `h5` for the same step in the outline. The spacing carries the
 * rest: this label hugs its own fields (`mb-1`) while the group-to-group gap stays
 * at `mb-6`, so a level group is closer to its own content than to the next one.
 */
const sectionHeadingClass = 'text-base font-semibold text-base-content/70 mb-1';

/**
 * Heading of a *group* in this dialog: "Fotos", "Profil-Zugang", "Profil-Angaben".
 *
 * All three are the same level, but they did not carry the same class: identical
 * classes with two different margins — `mb-2` on two of them, `mb-1` on
 * "Profil-Angaben". Equal siblings that space themselves differently read as two
 * different levels, which is exactly what the UI review saw. `mb-1` is the one to
 * keep: a group heading hugs its OWN group, and the gap to the next group already
 * comes from the `mb-6` on the group wrapper. If one of the three ever looks too
 * tight, change it here for all three — not at one of the three call sites.
 */
const groupHeadingClass = 'font-bold text-lg mb-1';

export default function ModelDetailModal({ model, onClose, onChanged, pinnedCategories = [] }: Props) {
    const { showToast, confirm } = useUI();
    const { isSuperAdmin, isAdmin } = usePermissions();
    const [isWorking, setIsWorking] = useState(false);

    if (!model) return null;

    const outdated = isModelOutdated(model);
    const place = [model.city, model.country].filter(Boolean).join(', ');
    const willingnessCategories = modelWillingnessCategories();
    const categoryWillingness = model.categories
        .map(category => ({
            category,
            label: willingnessCategories.find(option => option.key === category)?.label ?? modelCategoryLabel(category),
            level: model.willingness[`willingness_${category}`],
        }))
        .filter((entry): entry is { category: string; label: string; level: string } => typeof entry.level === 'string' && entry.level !== '');
    const visibleAnswers = model.answers.filter(answer => !answerIsEmpty(answer));
    const sectionGroups = groupModelAnswersBySection(visibleAnswers);
    const accessLink = model.access_link;
    const contactSheetActions: Array<{ variant: ModelContactSheetVariant; testId: string; label: string }> = [
        { variant: 'internal', testId: 'model-contact-sheet-internal', label: t`Contact Sheet (intern)` },
        { variant: 'external', testId: 'model-contact-sheet-external', label: t`Contact Sheet (extern)` },
    ];

    const handleCopy = async (link: string) => {
        try {
            await copyTextToClipboard(link);
            showToast('success', t`Link wurde kopiert.`);
        } catch {
            showToast('error', t`Konnte Link nicht kopieren.`);
        }
    };

    const handleCreateAccessLink = async () => {
        setIsWorking(true);
        try {
            const result = await createModelAccessLink(model.customer_id);
            showToast('success', t`Profil-Link wurde erstellt.`);
            if (result.link) await handleCopy(result.link);
            onChanged();
        } catch (err: unknown) {
            showToast('error', err instanceof Error ? err.message : t`Fehler beim Erstellen des Profil-Links.`);
        } finally {
            setIsWorking(false);
        }
    };

    const handleRevokeAccessLink = async () => {
        if (!(await confirm({
            title: t`Profil-Link widerrufen?`,
            message: t`Der Link wird sofort ungültig und kann nicht mehr verwendet werden.`,
            confirmColor: 'error',
        }))) return;
        setIsWorking(true);
        try {
            await revokeModelAccessLink(model.customer_id);
            showToast('success', t`Profil-Link wurde widerrufen.`);
            onChanged();
        } catch {
            showToast('error', t`Fehler beim Widerrufen des Profil-Links.`);
        } finally {
            setIsWorking(false);
        }
    };

    const handleSetPrimary = async (photoId: string) => {
        setIsWorking(true);
        try {
            await setPrimaryModelPhoto(model.id, photoId);
            showToast('success', t`Hauptbild wurde aktualisiert.`);
            onChanged();
        } catch {
            showToast('error', t`Fehler beim Setzen des Hauptbilds.`);
        } finally {
            setIsWorking(false);
        }
    };

    const handleDeleteModel = async () => {
        if (!(await confirm({
            title: t`Profil endgültig löschen?`,
            message: t`Dieses Profil wird unwiderruflich gelöscht – inklusive aller Fotos, des Altersnachweises und der Act-Mitgliedschaften. Ein verknüpftes Portal-Konto bleibt bestehen.`,
            confirmText: t`Endgültig löschen`,
            confirmColor: 'error',
        }))) return;
        setIsWorking(true);
        try {
            await deleteModel(model.customer_id);
            showToast('success', t`Profil wurde gelöscht.`);
            onChanged();
            onClose();
        } catch (err: unknown) {
            showToast('error', err instanceof Error ? err.message : t`Fehler beim Löschen des Profils.`);
        } finally {
            setIsWorking(false);
        }
    };

    const handleDeletePhoto = async (photoId: string) => {
        if (!(await confirm({
            title: t`Foto löschen?`,
            message: t`Das Foto wird von der privaten Ablage entfernt.`,
            confirmColor: 'error',
        }))) return;
        setIsWorking(true);
        try {
            await deleteModelPhoto(model.id, photoId);
            showToast('success', t`Foto wurde gelöscht.`);
            onChanged();
        } catch {
            showToast('error', t`Fehler beim Löschen des Fotos.`);
        } finally {
            setIsWorking(false);
        }
    };

    const handleContactSheet = async (variant: ModelContactSheetVariant) => {
        setIsWorking(true);
        try {
            await downloadModelContactSheet(model.id, variant);
            showToast('success', t`Contact Sheet wurde heruntergeladen.`);
        } catch (err: unknown) {
            showToast('error', err instanceof Error ? err.message : t`Fehler beim Erstellen des Contact Sheets.`);
        } finally {
            setIsWorking(false);
        }
    };

    const summaryCard = (label: string, value: string) => (
        <div className="rounded-box bg-base-200 p-3">
            <div className="text-xs opacity-60">{label}</div>
            <div className="font-bold">{value}</div>
        </div>
    );

    const renderAnswerRow = (answer: ModelProfileAnswer) => (
        <div key={`${answer.scope}-${answer.key}`} className="border-b border-base-200 pb-1 min-w-0">
            <dt className="text-xs opacity-60">{answer.label}</dt>
            <dd className="whitespace-pre-wrap">{formatAnswer(answer)}</dd>
        </div>
    );

    const renderSection = (section: { key: ModelAnswerSectionKey; label: string; answers: ModelProfileAnswer[] }) => {
        if (section.key === 'erfahrung') {
            const matrix = sortSkillMatrixRows(
                modelSkillMatrix(model.willingness, experienceMapFromAnswers(model.answers)),
                pinnedCategories,
            );
            const otherAnswers = section.answers.filter(answer =>
                !answer.key.startsWith('willingness_') && !answer.key.startsWith('experience_'));
            return (
                <div key={section.key} className="mb-6" data-testid={`model-section-${section.key}`}>
                    <h5 className={sectionHeadingClass}>{section.label}</h5>
                    <div className="overflow-x-auto mb-3">
                        <table className="table table-sm" data-testid="model-skill-matrix">
                            <thead>
                                <tr>
                                    <th><Trans>Kategorie</Trans></th>
                                    <th><Trans>Erfahrung</Trans></th>
                                    <th><Trans>Lust</Trans></th>
                                </tr>
                            </thead>
                            <tbody>
                                {matrix.map(row => (
                                    <tr key={row.key}>
                                        <td>{row.label}</td>
                                        <td>{row.experience ? modelExperienceLabel(row.experience) : '–'}</td>
                                        <td>
                                            {row.willingness
                                                ? <span className={`badge badge-sm ${willingnessBadgeClass(row.willingness)}`}>{modelWillingnessLabel(row.willingness)}</span>
                                                : '–'}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    {otherAnswers.length > 0 && (
                        <dl className="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-2">{otherAnswers.map(renderAnswerRow)}</dl>
                    )}
                </div>
            );
        }
        return (
            <div key={section.key} className="mb-6" data-testid={`model-section-${section.key}`}>
                <h5 className={sectionHeadingClass}>{section.label}</h5>
                <dl className="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-2">{section.answers.map(renderAnswerRow)}</dl>
            </div>
        );
    };

    return (
        // The header splits into `title` (name + icon, shell keeps text-2xl via
        // the inner span) and `secondaryAction` (lifecycle badge). The trailing
        // `modal-action` becomes `footer` and keeps its own "Schließen" button.
        // The shell also renders a labelled close button, so this dialog has two
        // controls named "Schließen" — a header close and a footer close are
        // ordinary together, and dropping the footer one to keep a test locator
        // unambiguous would have removed a user-facing affordance to suit the
        // test. The three CRM specs that close this dialog are scoped to the
        // footer instead.
        //
        // The footer (`modal-action` with the two contact-sheet buttons and
        // "Schließen") is long enough that a user who has scrolled to the bottom
        // of this dialog has to scroll back up to reach it — so the dialog opts
        // into the shell's bounded body: the box caps at 90vh, the body takes the
        // height that is left and scrolls inside it, and the footer keeps its own
        // height below the scroll port. The header stays put for the same reason
        // the footer does: it is navigation, not content.
        //
        // Moving the scroll port from the box to the body moves what the
        // boundary affordances have to sit on. Without one the boundary slices
        // whatever row it lands on — in the UI review it cut "Linz"/"Österreich"
        // through the glyphs, which reads as broken rather than as scrollable.
        // `scroll-fade-bottom` fades the last 2rem and belongs on the element
        // that scrolls, so it moves onto the body together with the `pb-10` that
        // keeps the content clear of the faded band: the mask always covers the
        // last 2rem of the padding box, and without the padding it would fade the
        // content once the body is scrolled to its end. Left on the box it would
        // now fade nothing at all, because the box no longer scrolls. The footer
        // needs no such padding — it is outside the mask, which is the point.
        <ModalShell
            title={<span className="text-2xl min-w-0 break-words">{model.display_name ?? t`Unbenanntes Model`}</span>}
            icon="mdi--account-details"
            onClose={onClose}
            className="z-50"
            boxClassName="max-w-4xl"
            bodyClassName="pb-10 scroll-fade-bottom"
            scrollableBody
            secondaryAction={
                <span className={`badge shrink-0 h-auto whitespace-normal ${modelLifecycleBadgeClass(model.lifecycle_status)}`}>
                    {modelLifecycleLabel(model.lifecycle_status)}
                </span>
            }
            footer={
                <div className="modal-action">
                    {isAdmin && (
                        <div className="flex flex-wrap gap-2 mr-auto" data-testid="model-contact-sheet-actions">
                            {contactSheetActions.map(action => (
                                <button
                                    key={action.variant}
                                    type="button"
                                    className="btn btn-outline btn-sm"
                                    disabled={isWorking}
                                    onClick={() => handleContactSheet(action.variant)}
                                    data-testid={action.testId}
                                >
                                    {isWorking
                                        ? <span className="loading loading-spinner loading-xs"></span>
                                        : <span className="iconify mdi--file-pdf-box"></span>}
                                    {action.label}
                                </button>
                            ))}
                        </div>
                    )}
                    <button type="button" className="btn btn-ghost" onClick={onClose}><Trans>Schließen</Trans></button>
                </div>
            }
        >
                {outdated && (
                    <div className="alert alert-warning shadow-sm mb-6" role="status">
                        <span className="iconify mdi--alert-outline text-xl"></span>
                        <div>
                            <h4 className="font-bold"><Trans>Profil aktualisieren</Trans></h4>
                            <p className="text-sm">
                                <Trans>Dieses Profil wurde mit einem älteren Fragenkatalog erfasst. Beim nächsten Speichern wird es auf den aktuellen Stand migriert.</Trans>
                            </p>
                        </div>
                    </div>
                )}

                <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6" data-testid="model-facts">
                    {model.age !== null && summaryCard(t`Alter`, String(model.age))}
                    {model.gender && summaryCard(t`Geschlecht`, modelGenderLabel(model.gender))}
                    {place && summaryCard(t`Ort`, place)}
                    {model.act_types.length > 0 && summaryCard(t`Act-Typ`, modelActTypeLabels(model.act_types).join(', '))}
                    <div className="rounded-box bg-base-200 p-3 col-span-2 md:col-span-4" data-testid="model-category-willingness">
                        <div className="text-xs opacity-60"><Trans>Top-Kategorien & Bereitschaft</Trans></div>
                        {categoryWillingness.length === 0 ? (
                            <span className="opacity-50">–</span>
                        ) : (
                            <div className="flex flex-wrap items-center gap-2 mt-1">
                                {categoryWillingness.map(entry => (
                                    <span key={entry.category} className="inline-flex items-center gap-1">
                                        <span className="badge badge-outline">{entry.label}</span>
                                        <span className={`badge ${willingnessBadgeClass(entry.level)}`}>
                                            {modelWillingnessLabel(entry.level)}
                                        </span>
                                    </span>
                                ))}
                            </div>
                        )}
                    </div>
                    {/* The age proof and the data state are one pair: on a 2-column
                        grid the age proof used to take a single column (48% of the
                        width) while the data state spanned both, which left ~150px of
                        dead space next to it. `col-span-2` on mobile stacks them at the
                        same width; `md:col-span-1` keeps the desktop 1 + 3 split. */}
                    <div className="rounded-box bg-base-200 p-3 col-span-2 md:col-span-1">
                        <div className="text-xs opacity-60"><Trans>Altersnachweis</Trans></div>
                        <div className="font-bold">
                            {model.age_proof_required
                                ? model.age_proof_uploaded_at
                                    ? `${t`Hochgeladen am`} ${formatDateTime(model.age_proof_uploaded_at)}`
                                    : t`Ausstehend`
                                : t`Nicht erforderlich`}
                        </div>
                        {model.age_proof_uploaded_at && (
                            <a
                                className="link link-primary text-xs"
                                href={ageProofDownloadUrl(model.id)}
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                <Trans>Nachweis öffnen</Trans>
                            </a>
                        )}
                    </div>
                    <div className="rounded-box bg-base-200 p-3 col-span-2 md:col-span-3 text-xs opacity-70" data-testid="model-datasource">
                        <span className="font-bold"><Trans>Datenstand:</Trans></span> {model.catalog_version ?? '–'}
                        {' · '}<Trans>Eingereicht:</Trans> {formatDateTime(model.submitted_at)}
                        {' · '}<Trans>Letzte Bestätigung:</Trans> {formatDateTime(model.last_confirmed_at)}
                    </div>
                </div>

                <div className="mb-6" data-testid="model-photo-gallery">
                    <h4 className={groupHeadingClass}><Trans>Fotos</Trans></h4>
                    {model.photos.length === 0 ? (
                        <p className="opacity-50 text-sm"><Trans>Keine Fotos hinterlegt.</Trans></p>
                    ) : (
                        <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-5 gap-3">
                            {model.photos.map(photo => (
                                <div key={photo.id} className="border border-base-300 rounded-box overflow-hidden bg-base-200" data-testid={`model-photo-${photo.id}`}>
                                    <div className="h-32 bg-base-300 flex items-center justify-center overflow-hidden">
                                        <img src={photo.download_url} alt={photo.original_name ?? t`Foto`} loading="lazy" className="w-full h-full object-cover" />
                                    </div>
                                    <div className="p-2 flex flex-col gap-1">
                                        <div className="flex items-center justify-between gap-1">
                                            <span className={`badge badge-xs ${photo.visibility === 'public' ? 'badge-success' : 'badge-ghost'}`}>
                                                {photo.visibility === 'public' ? t`Öffentlich` : t`Intern`}
                                            </span>
                                            {photo.is_primary && <span className="iconify mdi--star text-warning" title={t`Hauptbild`}></span>}
                                        </div>
                                        <div className="flex items-center gap-1">
                                            <a
                                                href={photo.download_url}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                className="btn btn-ghost btn-xs"
                                                title={t`Öffnen`}
                                            >
                                                <span className="iconify mdi--open-in-new"></span>
                                            </a>
                                            {!photo.is_primary && (
                                                <button
                                                    type="button"
                                                    className="btn btn-ghost btn-xs"
                                                    title={t`Als Hauptbild`}
                                                    disabled={isWorking}
                                                    onClick={() => handleSetPrimary(photo.id)}
                                                >
                                                    <span className="iconify mdi--star-outline"></span>
                                                </button>
                                            )}
                                            <button
                                                type="button"
                                                className="btn btn-ghost btn-xs text-error"
                                                title={t`Löschen`}
                                                disabled={isWorking}
                                                onClick={() => handleDeletePhoto(photo.id)}
                                            >
                                                <span className="iconify mdi--trash-can"></span>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </div>

                <div className="mb-6" data-testid="model-access-link">
                    <h4 className={groupHeadingClass}><Trans>Profil-Zugang</Trans></h4>
                    {accessLink ? (
                        <div className="rounded-box bg-base-200 p-3">
                            <input type="text" readOnly value={accessLink.url} className="input input-bordered input-sm w-full font-mono text-xs" data-testid="model-access-link-url" />
                            <p className="text-xs opacity-60 mt-1">
                                <Trans>Läuft ab:</Trans> {formatDateTime(accessLink.expires_at)}
                            </p>
                            <div className="flex flex-wrap gap-2 mt-2">
                                <button type="button" className="btn btn-sm btn-primary" onClick={() => handleCopy(accessLink.url)}>
                                    <span className="iconify mdi--content-copy"></span>
                                    <Trans>Kopieren</Trans>
                                </button>
                                <button type="button" className="btn btn-sm btn-ghost text-error" disabled={isWorking} onClick={handleRevokeAccessLink}>
                                    <span className="iconify mdi--cancel"></span>
                                    <Trans>Widerrufen</Trans>
                                </button>
                            </div>
                        </div>
                    ) : (
                        <div className="flex flex-wrap items-center gap-3">
                            <p className="text-sm opacity-60"><Trans>Kein aktiver Profil-Link.</Trans></p>
                            <button type="button" className="btn btn-sm btn-primary" disabled={isWorking} onClick={handleCreateAccessLink} data-testid="model-access-link-create">
                                {isWorking ? <span className="loading loading-spinner loading-xs"></span> : <span className="iconify mdi--link-variant"></span>}
                                <Trans>Profil-Link erstellen</Trans>
                            </button>
                        </div>
                    )}
                </div>

                {/* `mb-1` (via `groupHeadingClass`) instead of the `mb-2` the other
                    two group headings used: this heading owns every section below
                    it, so it has to sit closer to the first one than that group
                    does to the next one. The level itself is carried by the scale
                    (see `sectionHeadingClass`), not by the gap. */}
                <h4 className={groupHeadingClass}><Trans>Profil-Angaben</Trans></h4>
                {sectionGroups.length === 0 ? (
                    <p className="opacity-50 text-sm py-4"><Trans>Keine Antworten gespeichert.</Trans></p>
                ) : (
                    <div data-testid="model-answer-sections">
                        {sectionGroups.map(renderSection)}
                    </div>
                )}

                {isSuperAdmin && (
                    <div className="mt-6 border-t border-error/40 pt-4" data-testid="model-delete-zone">
                        <h4 className="font-bold text-lg text-error mb-2"><Trans>DSGVO-Löschung</Trans></h4>
                        <p className="text-sm opacity-70 mb-3">
                            <Trans>Löscht das Profil dauerhaft – inklusive aller Fotos, des Altersnachweises und der Act-Mitgliedschaften. Diese Aktion kann nicht rückgängig gemacht werden.</Trans>
                        </p>
                        <button
                            type="button"
                            className="btn btn-error"
                            disabled={isWorking}
                            onClick={handleDeleteModel}
                            data-testid="model-delete"
                        >
                            <span className="iconify mdi--delete-forever"></span>
                            <Trans>Profil löschen</Trans>
                        </button>
                    </div>
                )}
        </ModalShell>
    );
}
