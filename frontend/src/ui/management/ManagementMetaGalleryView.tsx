import { t } from "@lingui/core/macro";
import { Trans } from "@lingui/react/macro";
import ResponsiveImage from '../components/ResponsiveImage';
import ErrorMessage from '../components/ErrorMessage';
import {useRef, useState} from 'react';
import { usePhotoSwipe } from '../../logic/usePhotoSwipe';
import {useNavigate, useParams, useSearchParams} from 'react-router-dom';
import {flattenGroups, formatMoney} from '../../logic/utils';
import {useProtectedGalleries} from '../../logic/useGalleries';
import {usePermissions} from '../../logic/usePermissions';
import {useMetaGallery} from '../../logic/useMetaGallery';
import {useGalleryLicensing} from '../../logic/useVolumeLicensing';
import {findGalleryGroup, galleryPricingSourcesForMetaGallery} from '../../logic/metaGalleryPricing';
import PageLayout from '../components/PageLayout';
import GalleryModals from '../components/GalleryModals';
import GalleryGroupCouponsTab from './components/GalleryGroupCouponsTab';
import EmptyState from '../components/EmptyState';

export default function ManagementMetaGalleryView() {
    const {id} = useParams<{ id: string }>();
    const navigate = useNavigate();
    const {isAdmin} = usePermissions();
    const {tree, updateGroup, deleteGroup} = useProtectedGalleries();
    const {
        group,
        photos,
        galleryPricingSources: serverGalleryPricingSources,
        downloadsCount,
        isLoading,
        isError,
        size,
        setSize,
        isReachingEnd,
        mutate
    } = useMetaGallery(id);
    const safeGroups = Array.isArray(tree?.groups) ? tree.groups : [];
    const pricingGroup = findGalleryGroup(safeGroups, id) ?? group;
    const pricingSources = galleryPricingSourcesForMetaGallery(
        pricingGroup,
        photos,
        serverGalleryPricingSources,
    );
    const galleryLicensing = useGalleryLicensing(pricingSources);
    const isLicensingResolved = !galleryLicensing.isLoading;
    const isVolumeLicensing = isLicensingResolved && galleryLicensing.isVolumePricing;
    const [searchParams, setSearchParams] = useSearchParams();
    const activeTab: 'bilder' | 'coupons' =
        isVolumeLicensing && searchParams.get('tab') === 'coupons' ? 'coupons' : 'bilder';
    const setActiveTab = (tab: 'bilder' | 'coupons') => {
        setSearchParams(prev => {
            const updated = new URLSearchParams(prev);
            if (tab === 'bilder') {
                updated.delete('tab');
            } else {
                updated.set('tab', tab);
            }
            return updated;
        });
    };
    const [isGroupEditModalOpen, setGroupEditModalOpen] = useState(false);
    const galleryRef = useRef<HTMLDivElement>(null);

    usePhotoSwipe({ galleryRef, trigger: photos.length });

    if (isLoading && photos.length === 0) return <PageLayout>
        <div className="flex h-full items-center justify-center"><span className="loading loading-spinner"></span></div>
    </PageLayout>;
    if (isError || !group) return <PageLayout>
        <div className="p-8"><ErrorMessage message={t`Meta-Galerie nicht gefunden.`} /></div>
    </PageLayout>;

    const totalDownloads = downloadsCount || 0;
    const galleryNamesById = new Map(
        pricingSources.map(source => [source.galleryId, source.galleryName ?? source.galleryId]),
    );
    return (
        <PageLayout>
            <div className="container mx-auto p-4 md:p-8">
                <div className="mb-6 flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
                    <div>
                        <h1 className="text-3xl font-bold flex flex-wrap items-center gap-2">
                            <Trans>Meta-Galerie:</Trans> {group.name}
                            {isAdmin && (
                                <button onClick={() => setGroupEditModalOpen(true)}
                                        className="btn btn-ghost btn-sm btn-circle tooltip tooltip-bottom"
                                        data-tip="Meta-Galerie bearbeiten">
                                    <span className="iconify mdi--pencil text-xl"></span>
                                </button>
                            )}
                        </h1>
                        <p className="opacity-70 mt-2"><Trans>Sammelansicht aller Fotos. Uploads sind nur in den Untergalerien
                            möglich.</Trans></p>
                    </div>
                    <div className="flex items-center">
                        <span className="badge badge-ghost font-normal">
                            {t`${totalDownloads} Downloads gesamt`}
                        </span>
                    </div>
                </div>

                {isVolumeLicensing && galleryLicensing.groups.length > 0 && (
                    <section
                        className="mb-6 rounded-box border border-primary/20 bg-primary/5 p-4"
                        aria-labelledby="meta-gallery-pricing-heading"
                        data-testid="meta-gallery-licensing-groups"
                    >
                        <h2 id="meta-gallery-pricing-heading" className="font-bold text-lg mb-3"><Trans>Preise nach Untergalerie</Trans></h2>
                        <div className="space-y-2">
                            {galleryLicensing.groups.map(group => {
                                const galleryNames = group.galleryIds
                                    .map(galleryId => galleryNamesById.get(galleryId) ?? galleryId)
                                    .join(', ');
                                const pricePerItem = formatMoney(group.pricePerItemCents ?? 0);
                                const photoCount = group.photoCount;
                                const tierIndex = group.tierIndex;
                                const priceLabel = t`${pricePerItem} pro Bild (Tier ${tierIndex})`;
                                return (
                                    <article
                                        key={group.key}
                                        className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 rounded-box bg-base-100 p-3"
                                        aria-label={t`Preisgruppe ${galleryNames}`}
                                        data-testid={`meta-gallery-pricing-group-${group.presetId}`}
                                        data-gallery-ids={group.galleryIds.join(',')}
                                        data-gallery-group-ids={group.galleryGroupIds.join(',')}
                                    >
                                        <div className="min-w-0">
                                            <div className="font-semibold text-sm truncate">{galleryNames}</div>
                                            {group.presetName && <div className="text-xs opacity-70">{group.presetName}</div>}
                                        </div>
                                        {group.isVolumePricing ? (
                                            <div className="flex items-center gap-3 text-sm">
                                                <span>{t`${photoCount} Bilder`}</span>
                                                <span>{priceLabel}</span>
                                                <span className="font-mono font-bold" data-testid={`meta-gallery-group-total-${group.presetId}`}>
                                                    {formatMoney(group.totalCents ?? 0)}
                                                </span>
                                            </div>
                                        ) : (
                                            <span className="badge badge-ghost"><Trans>Scope-Lizenz</Trans></span>
                                        )}
                                    </article>
                                );
                            })}
                        </div>
                        <div className="mt-3 flex items-center justify-between border-t border-primary/20 pt-3 font-semibold">
                            <span><Trans>Volumen-Summe</Trans></span>
                            <span className="font-mono" data-testid="meta-gallery-volume-subtotal">
                                {formatMoney(galleryLicensing.volumeSubtotalCents)}
                            </span>
                        </div>
                    </section>
                )}

                {isVolumeLicensing && (
                    <div role="tablist" className="tabs tabs-boxed w-full md:w-auto bg-base-200 border border-base-300 p-1 flex-wrap shadow-sm mb-6">
                        <a
                            role="tab"
                            className={`tab ${activeTab === 'bilder' ? 'tab-active font-bold' : ''}`}
                            onClick={() => setActiveTab('bilder')}
                        >
                            <span className="iconify mdi--image-multiple-outline mr-1"></span>
                            <Trans>Bilder</Trans>
                        </a>
                        <a
                            role="tab"
                            className={`tab ${activeTab === 'coupons' ? 'tab-active font-bold' : ''}`}
                            onClick={() => setActiveTab('coupons')}
                        >
                            <span className="iconify mdi--ticket-percent-outline mr-1"></span>
                            <Trans>Coupons</Trans>
                        </a>
                    </div>
                )}

                {activeTab === 'bilder' && (
                    <>
                        {!isLoading && photos.length === 0 && (
                            <EmptyState icon="mdi--image-off-outline" title="Noch keine Bilder vorhanden" message="Es befinden sich noch keine Bilder in den untergeordneten Galerien." />
                        )}

                        <div className="grid grid-cols-2 md:grid-cols-4 xl:grid-cols-6 gap-4" ref={galleryRef}>
                            {photos.map(photo => (
                                <div key={photo.id} className="relative group">
                                    <a href={photo.url} data-pswp-width={photo.width || 2000}
                                       data-pswp-height={photo.height || 1333}
                                       data-title={photo.title}
                                       data-desc={photo.description}
                                       data-artist={photo.artist}
                                       data-photo-id={photo.id}
                                       className="pswp-item block relative aspect-square">
                                        <ResponsiveImage src={photo.thumb_url} srcSet={photo.srcset} containerClassName="absolute inset-0 w-full h-full" className="object-cover w-full h-full rounded shadow-sm hover:shadow-md transition-shadow" alt={photo.title || 'Bild'} />
                                    </a>
                                    <div className="absolute top-2 right-2 opacity-100 z-10">
                                        <button onClick={(e) => {
                                            e.preventDefault();
                                            e.stopPropagation();
                                            navigate('/photos/' + photo.id);
                                        }} className="btn btn-circle btn-sm btn-neutral shadow-lg" title="Details & Metadaten">
                                            <span className="iconify mdi--open-in-new text-lg"></span>
                                        </button>
                                    </div>
                                    <div
                                        className="absolute top-0 left-0 bg-black/60 text-white text-xs px-1.5 py-0.5 rounded-br truncate max-w-full">
                                        {photo.gallery?.name}
                                    </div>
                                </div>
                            ))}
                        </div>

                        {!isReachingEnd && photos.length > 0 && (
                            <div className="text-center mt-8">
                                <button className="btn btn-outline" onClick={() => setSize(size + 1)}><Trans>Mehr laden</Trans></button>
                            </div>
                        )}
                    </>
                )}

                {activeTab === 'coupons' && isVolumeLicensing && (
                    <GalleryGroupCouponsTab groupId={group.id} />
                )}

                <GalleryModals
                    availableGroups={flattenGroups(safeGroups)}
                    isGroupModalOpen={isGroupEditModalOpen} setGroupModalOpen={setGroupEditModalOpen}
                    isGalleryModalOpen={false} setGalleryModalOpen={() => {
                }}
                    editingGroup={group}
                    onCreateGroup={async () => {
                    }} onCreateGallery={async () => {
                }}
                    onUpdateGroup={async (id, name, slug, isPub, parentId, extraOpts) => {
                        // Keep the modal's org_id semantics intact on this route:
                        // omitted preserves assignments, explicit null clears them.
                        await updateGroup(id, name, slug, isPub, parentId, extraOpts);
                        mutate();
                    }}
                    onUpdateGallery={async () => {
                    }}
                    onDeleteGroup={async (id) => {
                        await deleteGroup(id);
                        navigate('/');
                    }}
                    onDeleteGallery={async () => {
                    }}
                />
            </div>
        </PageLayout>
    );
}
