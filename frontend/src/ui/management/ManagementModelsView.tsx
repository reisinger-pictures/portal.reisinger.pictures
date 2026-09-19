import { useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { t } from '@lingui/core/macro';
import { Trans } from '@lingui/react/macro';
import ErrorMessage from '../components/ErrorMessage';
import EmptyState from '../components/EmptyState';
import ModelDetailModal from './components/ModelDetailModal';
import ModelInviteDialog from './components/ModelInviteDialog';
import { usePermissions } from '../../logic/usePermissions';
import {
    EMPTY_MODEL_FILTERS,
    parseModelFilters,
    serializeModelFilters,
    useModels,
    type ManagedModel,
    type ManagedModelPhoto,
    type ModelFilters,
} from '../../logic/useModels';
import {
    experienceMapFromAnswers,
    isSkillRowRelevant,
    modelActTypeOptions,
    modelCategoryLabel,
    modelExperienceLabel,
    modelFilterCategories,
    modelGenderOptions,
    modelLifecycleBadgeClass,
    modelLifecycleLabel,
    modelSkillMatrix,
    modelWillingnessCategories,
    sortSkillMatrixRows,
    modelWillingnessLabel,
    modelWillingnessOptions,
    willingnessBadgeClass,
    willingnessLevelFromOrdinal,
    willingnessOrdinal,
    willingnessRangeClass,
} from '../../logic/modelRegistration';

function primaryPhoto(model: ManagedModel): ManagedModelPhoto | null {
    return model.photos.find(photo => photo.id === model.primary_photo_id)
        ?? model.photos.find(photo => photo.is_primary)
        ?? model.photos.find(photo => photo.visibility === 'public')
        ?? null;
}

function ModelCard({ model, matchedCategories, pinnedCategories, onOpen }: {
    model: ManagedModel;
    matchedCategories: string[];
    pinnedCategories: string[];
    onOpen: () => void;
}) {
    const photo = primaryPhoto(model);
    // Stock-Fotos is not an experience category but must always be visible;
    // pinned (matched) categories stay on top, the rest sorts by experience/desire.
    const matrixRows = sortSkillMatrixRows(
        modelSkillMatrix(model.willingness, experienceMapFromAnswers(model.answers))
            .filter(row => pinnedCategories.includes(row.key) || row.key === 'stock' || isSkillRowRelevant(row)),
        pinnedCategories,
    );

    return (
        <button
            type="button"
            className="card bg-base-100 border border-base-300 shadow-sm hover:shadow-xl transition-shadow text-left overflow-hidden"
            onClick={onOpen}
            data-testid={`model-card-${model.id}`}
        >
            <div className="h-48 bg-base-300 flex items-center justify-center overflow-hidden">
                {photo ? (
                    <img src={photo.download_url} alt={model.display_name ?? t`Model`} loading="lazy" className="w-full h-full object-cover" />
                ) : (
                    <span className="iconify mdi--account-outline text-6xl opacity-30"></span>
                )}
            </div>
            <div className="card-body p-4 gap-2">
                <div className="flex flex-wrap items-start justify-between gap-2">
                    <h3 className="card-title text-base min-w-0 break-words flex-1">{model.display_name ?? t`Unbenanntes Model`}</h3>
                    <span className={`badge badge-sm shrink-0 ${modelLifecycleBadgeClass(model.lifecycle_status)}`}>
                        {modelLifecycleLabel(model.lifecycle_status)}
                    </span>
                </div>
                <div className="text-sm opacity-70">
                    {[model.age ? `${model.age} ${t`Jahre`}` : null, [model.city, model.country].filter(Boolean).join(', ')].filter(Boolean).join(' · ') || '–'}
                </div>
                {matrixRows.length === 0 ? (
                    <span className="opacity-40 text-sm">–</span>
                ) : (
                    // No inner scroll: the grid keeps cards wide enough for the
                    // matrix to fit on mobile and desktop.
                    <table className="table table-xs w-full" data-testid={`model-matrix-${model.id}`}>
                        <thead>
                            <tr>
                                <th><Trans>Kategorie</Trans></th>
                                <th><Trans>Erfahrung</Trans></th>
                                <th className="text-right"><Trans>Lust</Trans></th>
                            </tr>
                        </thead>
                        <tbody>
                            {matrixRows.map(row => {
                                const matched = matchedCategories.includes(row.key);
                                return (
                                    <tr key={row.key}>
                                        <td className={matched ? 'font-bold text-primary' : ''}>{row.label}</td>
                                        <td className="whitespace-nowrap">{row.experience ? modelExperienceLabel(row.experience) : '–'}</td>
                                        <td className="text-right whitespace-nowrap">
                                            {row.willingness
                                                ? <span className={`badge badge-xs sm:badge-sm ${willingnessBadgeClass(row.willingness)}`}>{modelWillingnessLabel(row.willingness)}</span>
                                                : '–'}
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                )}
                {model.age_proof_required && !model.age_proof_uploaded_at && (
                    <span className="badge badge-warning badge-sm h-auto whitespace-normal text-left py-1"><Trans>Altersnachweis ausstehend</Trans></span>
                )}
            </div>
        </button>
    );
}

export default function ManagementModelsView() {
    const [searchParams, setSearchParams] = useSearchParams();
    const { isAdmin, isSuperAdmin } = usePermissions();
    const [inviteOpen, setInviteOpen] = useState(false);
    // Filters live in the URL (shareable/bookmarkable; Back/Forward consistent).
    const filters = parseModelFilters(searchParams);
    const { models, error, isLoading, mutate } = useModels(filters);

    // Deeplink: `/admin-models?model=<profileId|customerId>` coexists with filters.
    const modelParam = searchParams.get('model');
    const selected = (models ?? []).find(model => model.id === modelParam || model.customer_id === modelParam) ?? null;
    const openModel = (model: ManagedModel) => {
        setSearchParams(previous => {
            const next = new URLSearchParams(previous);
            next.set('model', model.id);
            return next;
        });
    };
    const closeModel = () => {
        setSearchParams(previous => {
            const next = new URLSearchParams(previous);
            next.delete('model');
            return next;
        }, { replace: true });
    };

    const updateFilters = (updater: (previous: ModelFilters) => ModelFilters) => {
        setSearchParams(previous => {
            const next = serializeModelFilters(updater(parseModelFilters(previous)));
            const model = previous.get('model');
            if (model) next.set('model', model);
            return next;
        }, { replace: true });
    };

    // Label lists are built in the render body (Lingui: no module-scope `t`).
    const genderOptions = modelGenderOptions();
    const categoryOptions = modelFilterCategories();
    const actTypeOptions = modelActTypeOptions();
    const willingnessOptions = modelWillingnessOptions();
    const willingnessCategories = modelWillingnessCategories();

    const update = (key: keyof ModelFilters, value: string) => {
        updateFilters(previous => ({ ...previous, [key]: value }));
    };

    const toggleCategory = (category: string) => {
        updateFilters(previous => {
            const current = previous.category ?? [];
            const next = current.includes(category)
                ? current.filter(entry => entry !== category)
                : [...current, category];
            return { ...previous, category: next };
        });
    };

    const toggleWillingnessCategory = (category: string) => {
        updateFilters(previous => {
            const current = previous.willingness_categories ?? [];
            const next = current.includes(category)
                ? current.filter(entry => entry !== category)
                : [...current, category];
            return { ...previous, willingness_categories: next };
        });
    };

    const setWillingnessLevel = (level: string) => {
        updateFilters(previous => ({ ...previous, willingness_level: level }));
    };

    const clearWillingnessLevel = () => {
        updateFilters(previous => ({ ...previous, willingness_level: '' }));
    };

    const resetFilters = () => updateFilters(() => EMPTY_MODEL_FILTERS);

    if (isLoading && !models) return <div className="p-10 flex justify-center"><span className="loading loading-spinner loading-lg"></span></div>;
    if (error) return <div className="p-10"><ErrorMessage message={t`Fehler beim Laden der Models.`} /></div>;

    // Lifecycle filtering happens server-side (`lifecycle_status`; default active).
    const visibleModels = models ?? [];

    const matchedCategories = filters.category ?? [];
    const matchedLabels = matchedCategories.map(modelCategoryLabel);
    // Rows pinned on top: matched categories + willingness-filter categories.
    const pinnedCategories = [...matchedCategories, ...(filters.willingness_categories ?? [])]
        .filter((key, index, all) => all.indexOf(key) === index);
    const chosenLevel = filters.willingness_level ?? '';

    return (
        <div className="p-6 md:p-10 max-w-7xl mx-auto w-full">
            <div className="mb-8 flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h1 className="text-4xl font-bold mb-2"><Trans>Models</Trans></h1>
                    <p className="opacity-70"><Trans>Durchsuche registrierte Models und filtere nach Kriterien.</Trans></p>
                </div>
                {isAdmin && (
                    <button
                        type="button"
                        className="btn btn-primary"
                        data-testid="model-invite-open"
                        onClick={() => setInviteOpen(true)}
                    >
                        <span className="iconify mdi--account-plus"></span>
                        <Trans>Neue Einladung</Trans>
                    </button>
                )}
            </div>

            <div className="bg-base-100 border border-base-300 rounded-box p-6 shadow-sm mb-6 space-y-4">
                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                    <div className="form-control lg:col-span-2">
                        <label className="label" htmlFor="model-filter-q"><span className="label-text font-bold"><Trans>Suche</Trans></span></label>
                        <input
                            id="model-filter-q"
                            type="text"
                            className="input input-bordered w-full"
                            placeholder={t`Name, E-Mail oder Ort`}
                            value={filters.q ?? ''}
                            onChange={event => update('q', event.target.value)}
                        />
                    </div>
                    <div className="form-control">
                        <label className="label" htmlFor="model-filter-gender"><span className="label-text font-bold"><Trans>Geschlecht</Trans></span></label>
                        <select id="model-filter-gender" className="select select-bordered w-full" value={filters.gender ?? ''} onChange={event => update('gender', event.target.value)}>
                            <option value="">{t`Alle`}</option>
                            {genderOptions.map(option => <option key={option.value} value={option.value}>{option.label}</option>)}
                        </select>
                    </div>
                    <div className="form-control">
                        <label className="label" htmlFor="model-filter-city"><span className="label-text font-bold"><Trans>Ort</Trans></span></label>
                        <input id="model-filter-city" type="text" className="input input-bordered w-full" value={filters.city ?? ''} onChange={event => update('city', event.target.value)} />
                    </div>
                    <div className="form-control">
                        <label className="label" htmlFor="model-filter-country"><span className="label-text font-bold"><Trans>Land</Trans></span></label>
                        <input id="model-filter-country" type="text" className="input input-bordered w-full" value={filters.country ?? ''} onChange={event => update('country', event.target.value)} />
                    </div>
                    <div className="form-control">
                        <label className="label" htmlFor="model-filter-act-type"><span className="label-text font-bold"><Trans>Act-Typ</Trans></span></label>
                        <select id="model-filter-act-type" className="select select-bordered w-full" value={filters.act_type ?? ''} onChange={event => update('act_type', event.target.value)}>
                            <option value="">{t`Alle`}</option>
                            {actTypeOptions.map(option => <option key={option.value} value={option.value}>{option.label}</option>)}
                        </select>
                    </div>
                    <div className="form-control">
                        <label className="label" htmlFor="model-filter-age-min"><span className="label-text font-bold"><Trans>Alter von</Trans></span></label>
                        <input id="model-filter-age-min" type="number" min={0} className="input input-bordered w-full" value={filters.age_min ?? ''} onChange={event => update('age_min', event.target.value)} />
                    </div>
                    <div className="form-control">
                        <label className="label" htmlFor="model-filter-age-max"><span className="label-text font-bold"><Trans>Alter bis</Trans></span></label>
                        <input id="model-filter-age-max" type="number" min={0} className="input input-bordered w-full" value={filters.age_max ?? ''} onChange={event => update('age_max', event.target.value)} />
                    </div>
                    <div className="form-control">
                        <label className="label" htmlFor="model-filter-lifecycle"><span className="label-text font-bold"><Trans>Status</Trans></span></label>
                        <select id="model-filter-lifecycle" className="select select-bordered w-full" value={filters.lifecycle_status ?? ''} onChange={event => update('lifecycle_status', event.target.value)}>
                            <option value="">{t`Aktiv`}</option>
                            <option value="inactive">{t`Inaktiv`}</option>
                            <option value="all">{t`Alle`}</option>
                        </select>
                    </div>
                </div>

                <div className="form-control">
                    <span className="label-text font-bold mb-1"><Trans>Kategorien (beliebige)</Trans></span>
                    <div className="flex flex-wrap gap-2" role="group" aria-label={t`Kategorien`}>
                        {categoryOptions.map(option => {
                            const active = matchedCategories.includes(option.key);
                            return (
                                <button
                                    key={option.key}
                                    type="button"
                                    className={`btn btn-sm ${active ? 'btn-primary' : 'btn-outline'}`}
                                    aria-pressed={active}
                                    onClick={() => toggleCategory(option.key)}
                                >
                                    {option.label}
                                </button>
                            );
                        })}
                    </div>
                </div>

                <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
                    <div className="form-control">
                        <span className="label-text font-bold mb-1"><Trans>Bereitschaft (Kategorien, beliebige)</Trans></span>
                        <div className="flex flex-wrap gap-2" role="group" aria-label={t`Bereitschafts-Kategorien`}>
                            {willingnessCategories.map(option => {
                                const active = (filters.willingness_categories ?? []).includes(option.key);
                                return (
                                    <button
                                        key={option.key}
                                        type="button"
                                        className={`btn btn-sm ${active ? 'btn-primary' : 'btn-outline'}`}
                                        aria-pressed={active}
                                        onClick={() => toggleWillingnessCategory(option.key)}
                                    >
                                        {option.label}
                                    </button>
                                );
                            })}
                        </div>
                    </div>
                    <div className="form-control">
                        <div className="flex items-center justify-between gap-2 mb-1">
                            <span className="label-text font-bold"><Trans>Bereitschaft mindestens</Trans></span>
                            <button
                                type="button"
                                className={`badge badge-sm shrink-0 h-auto whitespace-normal ${chosenLevel ? willingnessBadgeClass(chosenLevel) : 'badge-ghost'}`}
                                aria-pressed={!!chosenLevel}
                                disabled={!chosenLevel}
                                title={t`Stufe abwählen`}
                                onClick={clearWillingnessLevel}
                            >
                                {chosenLevel ? modelWillingnessLabel(chosenLevel) : t`Egal`}
                            </button>
                        </div>
                        <input
                            type="range"
                            min={0}
                            max={willingnessOptions.length - 1}
                            step={1}
                            value={chosenLevel ? willingnessOrdinal(chosenLevel) : 0}
                            disabled={(filters.willingness_categories ?? []).length === 0}
                            aria-label={t`Bereitschaftsstufe`}
                            className={`range w-full ${chosenLevel ? willingnessRangeClass(chosenLevel) : 'text-base-content'}`}
                            onChange={event => setWillingnessLevel(willingnessLevelFromOrdinal(Number(event.target.value)))}
                        />
                    </div>
                </div>

                <div className="form-control max-w-sm">
                    <label className="label" htmlFor="model-filter-sort"><span className="label-text font-bold"><Trans>Sortierung</Trans></span></label>
                    <select id="model-filter-sort" className="select select-bordered w-full" value={filters.sort ?? ''} onChange={event => update('sort', event.target.value)}>
                        <option value="">{t`Beste Übereinstimmung`}</option>
                        <option value="newest">{t`Neueste zuerst`}</option>
                        <option value="willingness">{t`Bereitschaft (höchste zuerst)`}</option>
                        <option value="experience">{t`Erfahrung (in Kategorie)`}</option>
                    </select>
                    <span className="label-text-alt opacity-60 mt-1">
                        <Trans>„Beste Übereinstimmung" gewichtet Lust stärker als Erfahrung.</Trans>
                    </span>
                </div>

                <div className="flex justify-end">
                    <button type="button" className="btn btn-ghost btn-sm" onClick={resetFilters}>
                        <span className="iconify mdi--filter-remove"></span>
                        <Trans>Filter zurücksetzen</Trans>
                    </button>
                </div>
            </div>

            {isSuperAdmin && filters.lifecycle_status !== 'inactive' && filters.lifecycle_status !== 'all' && (
                <p className="text-xs opacity-60 mb-3" data-testid="lifecycle-admin-hint">
                    <Trans>Inaktive Profile sind über den Status-Filter „Inaktiv" oder „Alle" für die DSGVO-Löschung findbar.</Trans>
                </p>
            )}

            {matchedLabels.length > 0 && visibleModels.length > 0 && (
                <div className="mb-4 flex flex-wrap items-center gap-2 text-sm" data-testid="models-match-summary">
                    <span className="font-bold">{visibleModels.length}</span>
                    <span><Trans>Models für Kategorie</Trans></span>
                    {matchedLabels.map(label => <span key={label} className="badge badge-primary badge-sm">{label}</span>)}
                </div>
            )}

            {visibleModels.length === 0 ? (
                <div className="bg-base-100 border border-base-300 rounded-box p-6 shadow-sm">
                    <EmptyState icon="mdi--account-search-outline" title={t`Keine Models gefunden.`} className="py-10" />
                </div>
            ) : (
                <div className="grid grid-cols-1 lg:grid-cols-2 gap-4" data-testid="models-grid">
                    {visibleModels.map(model => (
                        <ModelCard
                            key={model.id}
                            model={model}
                            matchedCategories={matchedCategories}
                            pinnedCategories={pinnedCategories}
                            onOpen={() => openModel(model)}
                        />
                    ))}
                </div>
            )}

            <ModelDetailModal model={selected} onClose={closeModel} onChanged={() => mutate()} pinnedCategories={pinnedCategories} />

            {inviteOpen && <ModelInviteDialog onClose={() => setInviteOpen(false)} />}
        </div>
    );
}
