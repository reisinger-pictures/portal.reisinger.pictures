import { Trans } from "@lingui/react/macro";
import ResponsiveImage from '../components/ResponsiveImage';
import {useState} from 'react';
import {Link, Navigate, useLocation} from 'react-router-dom';
import {Gallery, GalleryGroup} from '../../logic/useGalleries';
import {useAuth} from '../../logic/useAuth';
import {useBillingDetails} from '../../logic/useLicenseTerms';
import {useBrand} from '../../logic/useBrand';
import {usePermissions} from '../../logic/usePermissions';
import {useSearch} from '../../logic/useSearch';
import DashboardLayout from '../components/DashboardLayout';
import {useDashboard} from '../components/DashboardContext';
import ErrorBoundary from '../components/ErrorBoundary';
import SearchBarWithSuggestions from '../components/SearchBarWithSuggestions';
import ManagementUserView from './ManagementUserView';
import ManagementSettingsView from './ManagementSettingsView';
import ManagementStructureView from './ManagementStructureView';
import ManagementFtpInbox from './ManagementFtpInbox';
import ManagementStatsView from './ManagementStatsView';
import ManagementOrdersView from './ManagementOrdersView';
import ManagementManualInvoiceView from './ManagementManualInvoiceView';
import ManagementCustomersView from './ManagementCustomersView';
import ManagementProductsView from './ManagementProductsView';
import ManagementTextSnippetsView from './ManagementTextSnippetsView';
import ManagementPayoutsView from './ManagementPayoutsView';
import ManagementCouponsView from './ManagementCouponsView';
import ManagementContractView from './ManagementContractView';
import ManagementProjectsBoard from './ManagementProjectsBoard';
import ManagementBoardsView from './ManagementBoardsView';
import PhotographerPayoutsView from '../photographer/PhotographerPayoutsView';
import PhotographerTeamModal from './components/PhotographerTeamModal';

function DashboardView({
    currentView,
    teamModalNode,
    onTeamModalChange,
}: {
    currentView: string;
    teamModalNode: Gallery | GalleryGroup | null;
    onTeamModalChange: (node: Gallery | GalleryGroup | null) => void;
}) {
    const {tree, mutate, onOpenGalleryModal, onOpenGroupModal, onEditGroup, onEditGallery} = useDashboard();

    return (
        <>
            <ErrorBoundary>
                {currentView === 'galleries' && <ManagementStructureView tree={tree}
                                                                         onOpenPhotographerTeam={(node) => onTeamModalChange(node)}
                                                                         onOpenGroupModal={(id) => onOpenGroupModal(id)}
                                                                         onOpenGalleryModal={(id) => onOpenGalleryModal(id)}
                                                                         onEditGroup={g => onEditGroup(g)}
                                                                         onEditGallery={g => onEditGallery(g)}/>}
                {currentView === 'users' && <ManagementUserView/>}
                {currentView === 'settings' && <ManagementSettingsView/>}
                {currentView === 'stats' && <ManagementStatsView/>}
                {currentView === 'admin-orders' && <ManagementOrdersView/>}
                {currentView === 'admin-manual-invoice' && <ManagementManualInvoiceView type="invoice"/>}
                {currentView === 'admin-manual-offer' && <ManagementManualInvoiceView type="offer"/>}
                {currentView === 'admin-customers' && <ManagementCustomersView/>}
                {currentView === 'admin-products' && <ManagementProductsView/>}
                {currentView === 'admin-snippets' && <ManagementTextSnippetsView/>}
                {currentView === 'admin-payouts' && <ManagementPayoutsView/>}
                {currentView === 'admin-coupons' && <ManagementCouponsView/>}
                {currentView === 'admin-contracts' && <ManagementContractView/>}
                {currentView === 'my-payouts' && <PhotographerPayoutsView/>}
                {currentView === 'admin-projects' && <ManagementProjectsBoard/>}
                {currentView === 'boards' && <ManagementBoardsView/>}
            </ErrorBoundary>
            <PhotographerTeamModal isOpen={!!teamModalNode} onClose={() => onTeamModalChange(null)}
                                   item={teamModalNode}
                                   isGroup={teamModalNode && !('type' in teamModalNode) ? true : false}
                                   onUpdateState={() => {
                                       onTeamModalChange(null);
                                       mutate();
                                   }}/>
        </>
    );
}

export default function ManagementDashboard() {
    const location = useLocation();
    const pathView = location.pathname.replace('/', '');
    const currentView = pathView || 'structure';
    const {canAccessB2BFeatures, isSuperAdmin, isPhotographer} = usePermissions();
    const isB2BView = ['admin-orders', 'admin-manual-invoice', 'admin-manual-offer', 'admin-customers', 'admin-products', 'admin-snippets', 'admin-payouts', 'admin-contracts', 'admin-projects', 'boards'].includes(currentView);

    const [isSearchFocused, setIsSearchFocused] = useState(false);
    const {user} = useAuth();
    const {logoSrc, portalName} = useBrand();
    const {billingDetails, isLoading: termsLoading} = useBillingDetails();
    const isImpressumMissing = isSuperAdmin && !termsLoading && (!billingDetails?.bank_holder || !billingDetails?.company_street || !billingDetails?.company_zip || !billingDetails?.company_city || !billingDetails?.bank_iban);
    const {results: personalFeed, isLoading: feedLoading} = useSearch('', true);

    const [teamModalNode, setTeamModalNode] = useState<Gallery | GalleryGroup | null>(null);

    if (isB2BView && !canAccessB2BFeatures) {
        return <Navigate to="/" replace/>;
    }

    return (
        <DashboardLayout
            currentView={currentView}
            sidebarWrapper={(children) => (
                <ErrorBoundary
                    fallback={<div className="w-72 2xl:w-80 p-4 text-error border-r border-base-300"><Trans>Fehler beim
                        Laden der
                        Sidebar.</Trans></div>}>
                    {children}
                </ErrorBoundary>
            )}
            header={({onMenuClick}) => (
                <>
                    {user?.ai_is_unconfigured && currentView !== 'settings' && (
                        <div className="m-4 md:m-6 mb-0 alert alert-warning shadow-sm">
                            <span className="iconify mdi--robot-off-outline text-xl"></span>
                            <div>
                                <h3 className="font-bold"><Trans>KI-Bildbeschreibung nicht konfiguriert</Trans></h3>
                                <p className="text-sm"><Trans>Die KI-Funktion zur Metadaten-Generierung ist nicht aktiviert.
                                    Setze <code className="bg-base-300 px-1 rounded">AI_ENABLED=true</code> und <code
                                        className="bg-base-300 px-1 rounded">AI_API_KEY</code> als Umgebungsvariablen.</Trans>
                                </p>
                            </div>
                        </div>
                    )}
                    {isImpressumMissing && currentView !== 'settings' && (
                        <div className="m-4 md:m-6 mb-0 alert alert-error shadow-sm">
                            <span className="iconify mdi--alert-circle text-xl"></span>
                            <div>
                                <h3 className="font-bold"><Trans>Impressum & Bankdaten unvollständig!</Trans></h3>
                                <p className="text-sm"><Trans>Bitte hinterlege deine Firmendaten in den</Trans> <Link to="/settings"
                                                                                                        className="underline font-bold"><Trans>Einstellungen</Trans></Link>,
                                    <Trans>um den Rechnungs- und Bestellprozess zu aktivieren.</Trans></p>
                            </div>
                        </div>
                    )}
                    <header
                        className="p-4 md:p-6 bg-base-100 border-b border-base-300 sticky top-0 z-30 flex items-center gap-3">
                        <button type="button"
                                className={`btn btn-square btn-ghost md:hidden shrink-0 ${isSearchFocused ? 'hidden' : ''}`}
                                onClick={onMenuClick}>
                            <span className="iconify mdi--menu text-2xl"></span>
                        </button>
                        <Link to="/"
                              className={`md:hidden flex items-center gap-2 shrink-0 mr-1 ${isSearchFocused ? 'hidden' : ''}`}>
                            <img src={logoSrc} alt="Logo" className="w-8 h-8 rounded shadow-sm bg-base-100"/>
                            <span
                                className="font-bold text-sm truncate max-w-28 sm:max-w-48">{portalName}</span>
                        </Link>

                        <SearchBarWithSuggestions clearOnSubmit onFocusChange={setIsSearchFocused} />
                    </header>
                </>
            )}
        >
            <DashboardView
                currentView={currentView}
                teamModalNode={teamModalNode}
                onTeamModalChange={setTeamModalNode}
            />
            {currentView === 'structure' && (
                <div className="p-6 md:p-10">
                    {isPhotographer && <ManagementFtpInbox/>}
                    {isPhotographer && (
                        <div className="mt-12 border-t border-base-300 pt-8">
                            <h2 className="text-2xl font-bold mb-6 flex items-center gap-2">
                                <span className="iconify mdi--history text-primary"></span> <Trans>Deine neuesten
                                Uploads & Galerien</Trans>
                            </h2>
                            {feedLoading ? (
                                <span className="loading loading-spinner text-primary"></span>
                            ) : (
                                <div className="space-y-8">
                                    {personalFeed?.galleries && personalFeed.galleries.length > 0 && (
                                        <div
                                            className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-4">
                                            {personalFeed.galleries.slice(0, 3).map(g => (
                                                <Link key={g.id} to={'/' + g.full_path}
                                                      className="card bg-base-100 shadow-sm hover:shadow-xl transition-shadow transition-transform border border-base-300">
                                                    <div
                                                        className="card-body p-4 flex flex-row items-center">
                                                        <div className="text-2xl mr-2"><span className="iconify mdi--image-multiple-outline text-base-content/50"></span></div>
                                                        <h3 className="card-title text-base text-primary truncate flex-1">{g.name}</h3>
                                                    </div>
                                                </Link>
                                            ))}
                                        </div>
                                    )}
                                    {personalFeed?.photos && personalFeed.photos.length > 0 && (
                                        <div
                                            className="grid grid-cols-3 md:grid-cols-6 lg:grid-cols-8 gap-2">
                                            {personalFeed.photos.slice(0, 20).map(p => (
                                                <Link key={p.id} to={'/photos/' + p.id}
                                                      className="block relative aspect-square bg-base-300 rounded overflow-hidden group shadow-sm hover:shadow-md">
                                                    <ResponsiveImage src={p.thumb_url} srcSet={p.srcset}
                                                                     containerClassName="absolute inset-0 w-full h-full"
                                                                     className="object-cover w-full h-full group-hover:scale-105 transition-transform"
                                                                     alt={p.title || 'Bild'}/>
                                                </Link>
                                            ))}
                                        </div>
                                    )}
                                    {(!personalFeed?.galleries?.length && !personalFeed?.photos?.length) && (
                                        <p className="opacity-50"><Trans>Du hast noch keine eigenen Galerien oder
                                            Bilder.</Trans></p>
                                    )}
                                </div>
                            )}
                        </div>
                    )}
                </div>
            )}
        </DashboardLayout>
    );
}
