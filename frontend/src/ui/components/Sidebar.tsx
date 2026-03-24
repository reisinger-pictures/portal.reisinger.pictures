import {useState} from 'react';
import {Link, useNavigate} from 'react-router-dom';
import {useAuth} from '../../logic/useAuth';
import {Gallery, GalleryGroup, GalleryTreeResponse} from '../../logic/useGalleries';

interface SidebarProps {
    tree?: GalleryTreeResponse | null;
    isLoading?: boolean;
    isError?: unknown;
    onOpenGalleryModal?: () => void;
    onOpenGroupModal?: () => void;
    onEditGroup?: (group: GalleryGroup) => void;
    onEditGallery?: (gallery: Gallery) => void;
    currentView?: string;
    onCloseMobile?: () => void;
}

export default function Sidebar(props: SidebarProps) {
    const {user, login, logout} = useAuth();
    const navigate = useNavigate();

    const [loginEmail, setLoginEmail] = useState('');
    const [loginPassword, setLoginPassword] = useState('');
    const [authError, setAuthError] = useState('');
    const [isLoggingIn, setIsLoggingIn] = useState(false);

    const handleLogout = async () => {
        await logout();
        navigate('/');
    };

    const handleSidebarLogin = async (e: React.FormEvent) => {
        e.preventDefault();
        setIsLoggingIn(true);
        setAuthError('');
        try {
            await login(loginEmail, loginPassword);
        } catch {
            setAuthError('Login fehlgeschlagen.');
        }
        setIsLoggingIn(false);
    };

    const renderGroup = (group: GalleryGroup) => {
        const safeChildren = Array.isArray(group.children) ? [...group.children].sort((a, b) => a.name.localeCompare(b.name)) : [];
        const safeGalleries = Array.isArray(group.galleries) ? [...group.galleries].sort((a, b) => a.name.localeCompare(b.name)) : [];
        return (
            <li key={"group-" + group.id}>
                <details open>
                    <summary className="flex justify-between items-center pr-2 w-full whitespace-nowrap">
                        <span className="font-semibold text-base-content/80 flex-1">📁 {group.name}</span>
                        <div className="flex gap-1 ml-2 items-center shrink-0">
                            {props.onEditGroup && (
                                <div className="tooltip tooltip-left" data-tip="Meta-Galerie bearbeiten">
                                    <button onClick={(e) => {
                                        e.preventDefault();
                                        props.onEditGroup!(group);
                                    }} className="btn btn-ghost btn-xs text-base-content opacity-70 hover:opacity-100">
                                        <span className="iconify mdi--pencil text-base"></span>
                                    </button>
                                </div>
                            )}
                        </div>
                    </summary>
                    <ul>
                        {safeChildren.map(renderGroup)}
                        {safeGalleries.map(renderGallery)}
                    </ul>
                </details>
            </li>
        );
    };

    const renderGallery = (gallery: Gallery) => {
        const isExpired = gallery.expires_at && new Date(gallery.expires_at) < new Date();
        return (
            <li key={"gal-" + gallery.id} className="group">
                <div className="flex justify-between items-center w-full pr-2 whitespace-nowrap">
                    <Link
                        to={'/' + gallery.full_path}
                        className={`flex-1 flex items-center gap-2 ${isExpired ? 'line-through opacity-50' : ''}`}
                        title={gallery.name}
                        onClick={props.onCloseMobile}
                    >
                        <span className="iconify mdi--image-multiple-outline text-lg opacity-70 shrink-0"></span>
                        <span>{gallery.name}</span>
                    </Link>
                    <div className="flex gap-1 ml-2 items-center mr-[14px] shrink-0">
                        {props.onEditGallery && (
                            <div className="tooltip tooltip-left" data-tip="Galerie bearbeiten">
                                <button onClick={(e) => {
                                    e.preventDefault();
                                    props.onEditGallery!(gallery);
                                }} className="btn btn-ghost btn-xs text-base-content opacity-70 hover:opacity-100">
                                    <span className="iconify mdi--pencil text-base"></span>
                                </button>
                            </div>
                        )}
                    </div>
                </div>
            </li>
        );
    };

    const safeGroups = Array.isArray(props.tree?.groups) ? [...props.tree.groups].sort((a, b) => a.name.localeCompare(b.name)) : [];
    const safeRootGalleries = Array.isArray(props.tree?.root_galleries) ? [...props.tree.root_galleries].sort((a, b) => a.name.localeCompare(b.name)) : [];
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
                    <span className="iconify mdi--close text-xl"></span>
                </button>
            </div>

            {isGuest && (
                <div className="p-6 border-b border-base-300 bg-base-100">
                    <h3 className="font-bold mb-3 flex items-center gap-2"><span className="iconify mdi--login"></span> Anmelden</h3>
                    <form onSubmit={handleSidebarLogin} className="space-y-3">
                        <input type="email" required placeholder="E-Mail Adresse" value={loginEmail} onChange={e => setLoginEmail(e.target.value)} className="input input-sm input-bordered w-full"/>
                        <input type="password" required placeholder="Passwort" value={loginPassword} onChange={e => setLoginPassword(e.target.value)} className="input input-sm input-bordered w-full"/>
                        {authError && <p className="text-xs text-error font-semibold leading-tight">{authError}</p>}
                        <button type="submit" className="btn btn-sm btn-primary w-full mt-2" disabled={isLoggingIn}>
                            {isLoggingIn ? <span className="loading loading-spinner"></span> : 'Login'}
                        </button>
                    </form>
                </div>
            )}

            <ul className="menu bg-base-200 w-full p-2 border-b border-base-300 shrink-0">
                {isAdminOrPhotog && (
                    <>
                        <li><Link to="/" className={props.currentView === 'structure' ? 'active' : ''} onClick={props.onCloseMobile}><span className="iconify mdi--view-dashboard text-lg"></span> Dashboard</Link></li>
                        {user.is_admin && (
                            <>
                                <li><Link to="/users" className={props.currentView === 'users' ? 'active' : ''} onClick={props.onCloseMobile}><span className="iconify mdi--account-group text-lg"></span> Benutzer & Rechte</Link></li>
                                <li><Link to="/settings" className={props.currentView === 'settings' ? 'active' : ''} onClick={props.onCloseMobile}><span className="iconify mdi--cog text-lg"></span> Einstellungen</Link></li>
                            </>
                        )}
                    </>
                )}
            </ul>

            {isAdminOrPhotog && props.currentView === 'structure' && (
                <div className="p-4 flex gap-2 border-b border-base-300 shrink-0">
                    <button onClick={props.onOpenGalleryModal} className="btn btn-primary btn-sm flex-1 truncate">Galerie...</button>
                    <button onClick={props.onOpenGroupModal} className="btn btn-outline btn-sm flex-1 truncate">Meta-Galerie...</button>
                </div>
            )}

            {/* Hier ist der Container mit overflow-auto für vertikales UND horizontales Scrollen */}
            <div className="flex-1 overflow-auto p-4">
                {isAdminOrPhotog && props.tree && (
                    <ul className="menu bg-base-200 w-full rounded-box p-0">
                        {safeGroups.map(renderGroup)}
                        {safeRootGalleries.map(renderGallery)}
                    </ul>
                )}
            </div>

            <div className="mt-auto border-t border-base-300 bg-base-200 shrink-0">
                {user && user.roles && user.roles.length > 0 && (
                    <div className="p-4">
                        <button onClick={handleLogout} className="btn btn-outline btn-error w-full btn-sm">Abmelden</button>
                    </div>
                )}
            </div>
        </aside>
    );
}