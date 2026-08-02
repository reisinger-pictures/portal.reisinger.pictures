import { Trans } from "@lingui/react/macro";
import {Link, useNavigate} from 'react-router-dom';
import { useBrand } from '../../logic/useBrand';
import { usePermissions } from '../../logic/usePermissions';
import { useLicensingMode } from '../../logic/useLicensingMode';
import SidebarLoginForm from './SidebarLoginForm';
import {useAuth} from '../../logic/useAuth';
import {Gallery, GalleryGroup, GalleryTreeResponse} from '../../logic/useGalleries';
import { useCart } from '../../logic/CartContext';

interface SidebarProps {
    tree?: GalleryTreeResponse | null;
    isLoading?: boolean;
    isError?: unknown;
    onOpenGalleryModal?: (groupId?: string) => void;
    onOpenGroupModal?: (groupId?: string) => void;
    onEditGroup?: (group: GalleryGroup) => void;
    onEditGallery?: (gallery: Gallery) => void;
    currentView?: string;
    onCloseMobile?: () => void;
}

export default function Sidebar(props: SidebarProps) {
    const { logoSrc, portalName, impressumUrl, features } = useBrand();
    const {user, logout} = useAuth();
    const { isStaff, isAdmin, isSuperAdmin, isPhotographer, isOrgAdmin, showOrgsSection } = usePermissions();
    const licensingMode = useLicensingMode();
    const isVolumeLicensing = licensingMode === 'volume_licensing';
    const navigate = useNavigate();
    const { itemCount } = useCart();

    const handleLogout = async () => {
        await logout();
        navigate('/');
    };

    const isGuest = !user;

    return (
        <aside className="w-full bg-base-200 flex flex-col h-full shadow-lg border-r border-base-300 z-20 relative shrink-0">
            <div className="p-6 border-b border-base-300 flex justify-between items-start relative">
                <div className="min-w-0 flex-1">
                    <Link to="/" className="flex items-center gap-3 text-xl font-bold text-base-content opacity-70 hover:opacity-100 mb-2 transition-opacity">
                        <img src={logoSrc} alt="Logo" className="w-8 h-8 rounded shadow-sm bg-base-100 shrink-0"/>
                        <span className="whitespace-nowrap">{portalName}</span>
                    </Link>
                </div>
                {/* Mobile Close Button */}
                <button className="btn btn-sm btn-square btn-ghost md:hidden absolute top-4 right-4" onClick={props.onCloseMobile}>
                    <span className="mdi--close text-xl"></span>
                </button>
            </div>

            {isGuest && (
                <SidebarLoginForm />
            )}

            <div className="flex-1 overflow-y-auto w-full">
            <ul className="menu bg-base-200 w-full p-2 border-b border-base-300">
                {isStaff && (
                    <>
                        <li className="menu-title opacity-50 text-xs uppercase tracking-widest mt-2"><Trans>Übersicht</Trans></li>
                        <li><Link to="/" className={props.currentView === 'structure' ? 'active' : ''} onClick={props.onCloseMobile}><span className="mdi--view-dashboard text-lg"></span> <Trans>Dashboard</Trans></Link></li>
                        
                        <li className="menu-title opacity-50 text-xs uppercase tracking-widest mt-4"><Trans>Medien</Trans></li>
                        {isPhotographer && (
                            <li><Link to="/galleries" className={props.currentView === 'galleries' ? 'active' : ''} onClick={props.onCloseMobile}><span className="mdi--folder-multiple text-lg"></span> <Trans>Galerien & Ordner</Trans></Link></li>
                        )}
                        {isPhotographer && (
                            <li><Link to="/production" className={props.currentView === 'production' ? 'active' : ''} onClick={props.onCloseMobile}><span className="mdi--image-edit text-lg"></span> <Trans>Bildbearbeitung</Trans></Link></li>
                        )}
                        <li><Link to="/search" className={props.currentView === 'search' ? 'active' : ''} onClick={props.onCloseMobile}><span className="mdi--magnify text-lg"></span> <Trans>Suche & Entdecken</Trans></Link></li>

                        {isAdmin && (
                            <>
                                <li className="menu-title opacity-50 text-xs uppercase tracking-widest mt-4"><Trans>Büro & Dokumente</Trans></li>
                                <li><Link to="/admin-projects" className={props.currentView === 'admin-projects' ? 'active' : ''} onClick={props.onCloseMobile}><span className="mdi--view-column text-lg"></span> <Trans>Projekte</Trans></Link></li>
                                <li><Link to="/admin-orders" className={props.currentView === 'admin-orders' ? 'active' : ''} onClick={props.onCloseMobile}><span className="mdi--receipt-text-check text-lg"></span> <Trans>Shop-Bestellungen</Trans></Link></li>
                                <li><Link to="/admin-payouts" className={props.currentView === 'admin-payouts' ? 'active' : ''} onClick={props.onCloseMobile}><span className="mdi--cash-multiple text-lg"></span> <Trans>Payouts & Abrechnung</Trans></Link></li>
                                {isSuperAdmin && (
                                    <>
                                        <li><Link to="/admin-manual-offer" className={props.currentView === 'admin-manual-offer' ? 'active' : ''} onClick={props.onCloseMobile}><span className="mdi--file-chart-outline text-lg"></span> <Trans>Manuelles Angebot</Trans></Link></li>
                                        <li><Link to="/admin-manual-invoice" className={props.currentView === 'admin-manual-invoice' ? 'active' : ''} onClick={props.onCloseMobile}><span className="mdi--file-document-edit-outline text-lg"></span> <Trans>Manuelle Rechnung</Trans></Link></li>
                                        <li><Link to="/admin-customers" className={props.currentView === 'admin-customers' ? 'active' : ''} onClick={props.onCloseMobile}><span className="mdi--account-details text-lg"></span> <Trans>Kunden (CRM)</Trans></Link></li>
                                        <li><Link to="/admin-products" className={props.currentView === 'admin-products' ? 'active' : ''} onClick={props.onCloseMobile}><span className="mdi--package-variant-closed text-lg"></span> <Trans>Produkte & Leistungen</Trans></Link></li>
                                        <li><Link to="/admin-snippets" className={props.currentView === 'admin-snippets' ? 'active' : ''} onClick={props.onCloseMobile}><span className="mdi--text-box-multiple text-lg"></span> <Trans>Textbausteine</Trans></Link></li>
                                        <li><Link to="/admin-contracts" className={props.currentView === 'admin-contracts' ? 'active' : ''} onClick={props.onCloseMobile}><span className="mdi--file-sign text-lg"></span> <Trans>Verträge</Trans></Link></li>
                                    </>
                                )}
                            </>
                        )}

                        {isAdmin && isVolumeLicensing && (
                            <>
                                <li className="menu-title opacity-50 text-xs uppercase tracking-widest mt-4"><Trans>Marketing</Trans></li>
                                <li><Link to="/admin-coupons" className={props.currentView === 'admin-coupons' ? 'active' : ''} onClick={props.onCloseMobile}><span className="mdi--ticket-percent text-lg"></span> <Trans>Gutscheincode</Trans></Link></li>
                            </>
                        )}

                        <li className="menu-title opacity-50 text-xs uppercase tracking-widest mt-4"><Trans>Verwaltung</Trans></li>
                        {(isAdmin || isOrgAdmin) && showOrgsSection && features.orgs && (
                            <>
                                <li><Link to="/orgs" className={props.currentView?.startsWith('orgs') ? 'active' : ''} onClick={props.onCloseMobile}><span className="mdi--domain text-lg"></span> <Trans>Organisationen</Trans></Link></li>
                                <li><Link to="/users" className={props.currentView === 'users' ? 'active' : ''} onClick={props.onCloseMobile}><span className="mdi--account-group text-lg"></span> {isOrgAdmin && !isAdmin ? <Trans>Mein Team</Trans> : <Trans>Benutzer & Rechte</Trans>}</Link></li>
                            </>
                        )}
                        <li><Link to="/stats" className={props.currentView === 'stats' ? 'active' : ''} onClick={props.onCloseMobile}><span className="mdi--chart-bar text-lg"></span> <Trans>Auswertungen</Trans></Link></li>
                        {isAdmin && (
                            <li><Link to="/settings" className={props.currentView === 'settings' ? 'active' : ''} onClick={props.onCloseMobile}><span className="mdi--cog text-lg"></span> <Trans>Einstellungen</Trans></Link></li>
                        )}
                    </>
                )}
                
                {user && (
                    <>
                        {isStaff && <div className="divider my-1 text-sm opacity-50"><Trans>Dein Account</Trans></div>}
                        <li><Link to="/search" className={props.currentView === 'search' ? 'active' : ''} onClick={props.onCloseMobile}><span className="mdi--magnify text-lg"></span> <Trans>Suche & Entdecken</Trans></Link></li>
                        <li><Link to="/profile" className={props.currentView === 'profile' ? 'active' : ''} onClick={props.onCloseMobile}><span className="mdi--account-circle text-lg"></span> <Trans>Mein Profil</Trans></Link></li>
                        <li><Link to="/orders" className={props.currentView === 'orders' ? 'active' : ''} onClick={props.onCloseMobile}><span className="mdi--license text-lg"></span> <Trans>Einkäufe & Anfragen</Trans></Link></li>
                        {isPhotographer && <li><Link to="/my-payouts" className={props.currentView === 'payouts' ? 'active' : ''} onClick={props.onCloseMobile}><span className="mdi--cash-multiple text-lg"></span> <Trans>Meine Abrechnungen</Trans></Link></li>}
                    </>
                )}
                
                <li>
                    <a onClick={() => { props.onCloseMobile?.(); navigate('/cart'); }} className="flex justify-between items-center">
                        <div className="flex items-center gap-2"><span className="mdi--cart text-lg"></span> <Trans>Warenkorb</Trans></div>
                        {itemCount > 0 && <span className="badge badge-primary badge-sm">{itemCount}</span>}
                    </a>
                </li>
            </ul>
            </div>

            <div className="mt-auto border-t border-base-300 bg-base-200 shrink-0">
                <div className="p-3 text-center">
                    <a href={impressumUrl ?? undefined} target="_blank" rel="noopener noreferrer" className="text-sm font-bold opacity-50 hover:opacity-100 transition-opacity flex items-center justify-center gap-1">
                        <span className="iconify mdi--open-in-new"></span> <Trans>Impressum & Datenschutz</Trans>
                    </a>
                </div>
                {user && (
                    <div className="p-4 pt-0">
                        <button onClick={handleLogout} className="btn btn-outline btn-error w-full btn-sm"><Trans>Abmelden</Trans></button>
                    </div>
                )}
            </div>
        </aside>
    );
}