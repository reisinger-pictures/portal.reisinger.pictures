import { t } from '@lingui/core/macro';
import { Trans } from '@lingui/react/macro';
import PageLayout from './components/PageLayout';
import ErrorMessage from './components/ErrorMessage';
import EmptyState from './components/EmptyState';
import { useMyModels } from '../logic/useModelProfileAccess';
import {
    modelCategoryLabel,
    modelLifecycleBadgeClass,
    modelLifecycleLabel,
} from '../logic/modelRegistration';

function formatDate(value: string | null): string {
    if (!value) return '–';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '–';
    return date.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' });
}

export default function MyModelsView() {
    const { models, error, isLoading } = useMyModels(true);

    if (isLoading && !models) return <PageLayout><div className="p-10 flex justify-center"><span className="loading loading-spinner loading-lg"></span></div></PageLayout>;
    if (error) return <PageLayout><div className="p-10"><ErrorMessage message={t`Fehler beim Laden deiner Profile.`} /></div></PageLayout>;

    return (
        <PageLayout>
            <div className="p-6 md:p-10 max-w-5xl mx-auto w-full">
                <div className="mb-8">
                    <h1 className="text-4xl font-bold mb-2"><Trans>Meine Profile</Trans></h1>
                    <p className="opacity-70"><Trans>Übersicht deiner mit diesem Konto verknüpften Model-Profile.</Trans></p>
                </div>

                {models && models.length === 0 && (
                    <EmptyState icon="mdi--account-outline" title={t`Noch keine Profile verknüpft.`} className="py-16" />
                )}

                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    {models?.map(model => (
                        <div key={model.id} className="card bg-base-100 border border-base-300 shadow-sm" data-testid="my-model-card">
                            <div className="card-body gap-3">
                                <div className="flex items-center justify-between gap-3">
                                    <h2 className="card-title text-lg">{model.display_name ?? t`Unbenannt`}</h2>
                                    <span className={`badge ${modelLifecycleBadgeClass(model.lifecycle_status)}`}>
                                        {modelLifecycleLabel(model.lifecycle_status)}
                                    </span>
                                </div>
                                <div className="flex flex-wrap gap-1">
                                    {model.categories.map(category => (
                                        <span key={category} className="badge badge-outline badge-sm">{modelCategoryLabel(category)}</span>
                                    ))}
                                    {model.categories.length === 0 && <span className="opacity-40">–</span>}
                                </div>
                                <div className="text-sm opacity-70">
                                    <Trans>Letzte Bestätigung:</Trans> {formatDate(model.last_confirmed_at)}
                                </div>
                                {model.is_catalog_outdated && (
                                    <div className="alert alert-info py-1 px-3 text-xs" role="status">
                                        <span className="iconify mdi--information-outline"></span>
                                        <span><Trans>Profil kann auf den aktuellen Katalog aktualisiert werden.</Trans></span>
                                    </div>
                                )}
                            </div>
                        </div>
                    ))}
                </div>
            </div>
        </PageLayout>
    );
}
