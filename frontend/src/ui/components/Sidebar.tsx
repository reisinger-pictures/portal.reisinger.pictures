import {Link, useNavigate} from 'react-router-dom';
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
    const {user, logout} = useAuth();
    const navigate = useNavigate();
    const { itemCount } = useCart();

    const handleLogout = async () => {
        await logout();
        navigate('/');
    };

    const isAdminOrPhotog = user?.is_admin || user?.is_photographer;
    const isGuest = !user;

    return (
        <aside className="w-full bg-base-200 flex flex-col h-full shadow-lg border-r border-base-300 z-20 relative shrink-0">
            <div className="p-6 border-b border-base-300 flex justify-between items-start relative">
                <div className="min-w-0 flex-1">
                    <Link to="/" className="flex items-center gap-3 text-xl font-bold text-base-content opacity-70 hover:opacity-100 mb-2 transition-opacity">
                        <img src="/android-chrome-192x192.png" alt="Logo" className="w-8 h-8 rounded shadow-sm bg-base-100 shrink-0"/>
                        <span className="whitespace-nowrap">Reisinger Foto Portal</span>
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

            <ul className="menu bg-base-200 w-full p-2 border-b border-base-300 shrink-0">
                {isAdminOrPhotog && (
                    <>
                        <li><Link to="/" className={props.currentView === 'structure' ? 'active' : ''} onClick={props.onCloseMobile}><span className="mdi--view-dashboard text-lg"></span> Dashboard</Link></li>
                        
                        {user?.is_photographer && (
                            <>
                                <li><Link to="/galleries" className={props.currentView === 'galleries' ? 'active' : ''} onClick={props.onCloseMobile}><span className="mdi--folder-multiple text-lg"></span> Galerien</Link></li>
                                {!user.is_admin && !user.is_customer_manager && <li><Link to="/stats" className={props.currentView === 'stats' ? 'active' : ''} onClick={props.onCloseMobile}><span className="mdi--chart-bar text-lg"></span> Auswertungen</Link></li>}
                            </>
                        )}
                        
                        {(user.is_admin || user.is_customer_manager) && (
                            <>
                                <li><Link to="/tenants" className={props.currentView?.startsWith('tenants') ? 'active' : ''} onClick={props.onCloseMobile}><span className="mdi--domain text-lg"></span> Mandanten</Link></li>
                                <li><Link to="/users" className={props.currentView === 'users' ? 'active' : ''} onClick={props.onCloseMobile}><span className="mdi--account-group text-lg"></span> {user.is_customer_manager && !user.is_admin ? 'Mein Team' : 'Benutzer & Rechte'}</Link></li>
                                <li><Link to="/stats" className={props.currentView === 'stats' ? 'active' : ''} onClick={props.onCloseMobile}><span className="mdi--chart-bar text-lg"></span> Auswertungen</Link></li>
                            </>
                        )}
                        
                        {user.is_admin && (
                            <>
                                <li><Link to="/admin-orders" className={props.currentView === 'admin-orders' ? 'active' : ''} onClick={props.onCloseMobile}><span className="mdi--receipt-text-check text-lg"></span> Bestellungen & Anfragen</Link></li>
                                <li><Link to="/settings" className={props.currentView === 'settings' ? 'active' : ''} onClick={props.onCloseMobile}><span className="mdi--cog text-lg"></span> Einstellungen</Link></li>
                            </>
                        )}
                        {user.is_super_admin && (
                            <li><Link to="/admin-manual-invoice" className={props.currentView === 'admin-manual-invoice' ? 'active' : ''} onClick={props.onCloseMobile}><span className="mdi--file-document-edit-outline text-lg"></span> Manuelle Rechnung</Link></li>
                        )}
                    </>
                )}
                
                {user && (
                    <>
                        {isAdminOrPhotog && <div className="divider my-1 text-xs opacity-50">Dein Account</div>}
                        <li><Link to="/search" className={props.currentView === 'search' ? 'active' : ''} onClick={props.onCloseMobile}><span className="mdi--magnify text-lg"></span> Suche & Entdecken</Link></li>
                        <li><Link to="/profile" className={props.currentView === 'profile' ? 'active' : ''} onClick={props.onCloseMobile}><span className="mdi--account-circle text-lg"></span> Mein Profil</Link></li>
                        <li><Link to="/orders" className={props.currentView === 'orders' ? 'active' : ''} onClick={props.onCloseMobile}><span className="mdi--license text-lg"></span> Einkäufe & Anfragen</Link></li>
                    </>
                )}
                
                <li>
                    <a onClick={() => { props.onCloseMobile?.(); navigate('/cart'); }} className="flex justify-between items-center">
                        <div className="flex items-center gap-2"><span className="mdi--cart text-lg"></span> Warenkorb</div>
                        {itemCount > 0 && <span className="badge badge-primary badge-sm">{itemCount}</span>}
                    </a>
                </li>
            </ul>

            {/* Spacer, um den Logout-Button nach unten zu drücken */}
            <div className="flex-1"></div>

            <div className="mt-auto border-t border-base-300 bg-base-200 shrink-0">
                <div className="p-3 text-center">
                    <a href="https://reisinger.pictures/impressum/" target="_blank" rel="noopener noreferrer" className="text-xs font-bold opacity-50 hover:opacity-100 transition-opacity flex items-center justify-center gap-1">
                        <span className="iconify mdi--open-in-new"></span> Impressum & Datenschutz
                    </a>
                </div>
                {user && (
                    <div className="p-4 pt-0">
                        <button onClick={handleLogout} className="btn btn-outline btn-error w-full btn-sm">Abmelden</button>
                    </div>
                )}
            </div>
        </aside>
    );
}
