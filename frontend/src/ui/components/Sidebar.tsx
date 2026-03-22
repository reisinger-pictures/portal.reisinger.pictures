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
    const {user, login, logout, register} = useAuth();
    const navigate = useNavigate();

    const [showRegister, setShowRegister] = useState(false);

    // Login State
    const [loginEmail, setLoginEmail] = useState('');
    const [loginPassword, setLoginPassword] = useState('');
    const [authError, setAuthError] = useState('');
    const [isLoggingIn, setIsLoggingIn] = useState(false);

    // Register State
    const [regName, setRegName] = useState('');
    const [regEmail, setRegEmail] = useState('');
    const [regSuccess, setRegSuccess] = useState('');

    const handleLogout = () => {
        logout();
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

    const handleSidebarRegister = async (e: React.FormEvent) => {
        e.preventDefault();
        setIsLoggingIn(true);
        setAuthError('');
        try {
            const msg = await register(regName, regEmail);
            setRegSuccess(msg);
        } catch(err: unknown) {
            setAuthError(err instanceof Error ? (err as Error).message : 'Registrierung fehlgeschlagen.');
        }
        setIsLoggingIn(false);
    };

    const renderGroup = (group: GalleryGroup) => {
        const safeChildren = Array.isArray(group.children) ? group.children : [];
        const safeGalleries = Array.isArray(group.galleries) ? group.galleries : [];
        return (
            <li key={"group-" + group.id}>
                <details open>
                    <summary className="flex justify-between items-center pr-2">
                        <span className="font-semibold text-base-content/80">📁 {group.name}</span>
                        <div className="flex gap-1 ml-auto items-center">
                            <div className="tooltip tooltip-left" data-tip="Meta-Galerie öffnen">
                                <button onClick={(e) => {
                                    e.preventDefault();
                                    navigate('/meta/' + group.id);
                                    props.onCloseMobile?.();
                                }} className="btn btn-ghost btn-xs text-base-content opacity-70 hover:opacity-100">
                                    <span className="iconify mdi--eye text-base"></span>
                                </button>
                            </div>
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
                <div className="flex justify-between items-center w-full pr-2">
                    <Link to={'/' + gallery.full_path}
                          className={`flex-1 truncate flex items-center gap-2 ${isExpired ? 'line-through opacity-50' : ''}`}
                          onClick={props.onCloseMobile}>
                        <span className="iconify mdi--image-multiple-outline text-lg opacity-70 shrink-0"></span>
                        <span className="truncate">{gallery.name}</span>
                        {gallery.is_live && <span className="badge badge-error badge-xs ml-2 animate-pulse">LIVE</span>}
                    </Link>
                    {/* mr-5 gleicht den Platz aus, den der Pfeil (<summary>) in Meta-Galerien verbraucht! */}
                    <div className="flex gap-1 ml-auto items-center mr-[14px]">
                        <div className="tooltip tooltip-left" data-tip="Galerie öffnen">
                            <button onClick={(e) => {
                                e.preventDefault();
                                navigate('/' + gallery.full_path);
                                props.onCloseMobile?.();
                            }} className="btn btn-ghost btn-xs text-base-content opacity-70 hover:opacity-100">
                                <span className="iconify mdi--eye text-base"></span>
                            </button>
                        </div>
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

    const safeGroups = Array.isArray(props.tree?.groups) ? props.tree?.groups : [];
    const safeRootGalleries = Array.isArray(props.tree?.root_galleries) ? props.tree?.root_galleries : [];

    const isAdminOrPhotog = user?.is_admin || user?.is_photographer;
    const isClient = !isAdminOrPhotog && user;
    const isGuest = !user;

    return (
        <aside
            className="w-80 bg-base-200 flex flex-col h-full shadow-lg border-r border-base-300 z-20 relative shrink-0">
            <div className="p-6 border-b border-base-300 flex justify-between items-start">
                <div>
                    <Link to="/"
                          className="flex items-center gap-3 text-xl font-bold text-base-content opacity-70 hover:opacity-100 truncate mb-2 hover:opacity-80 transition-opacity">
                        <img src="/android-chrome-192x192.png" alt="Logo"
                             className="w-8 h-8 rounded shadow-sm bg-base-100"/>
                        Reisinger Foto Portal
                    </Link>
                    {user && (
                        <div className="tooltip tooltip-right"
                             data-tip={`Rolle: ${user.roles?.join(', ') || 'Kunde/Gast'}`}>
                            <div className="flex gap-x-2 items-center justify-start">
                                <span
                                    className="iconify mdi--account-circle text-xl text-base-content/70 cursor-help"></span>
                                <span className="text-xs text-base-content/70 truncate">{user.email}</span>
                            </div>
                        </div>
                    )}
                </div>
                {props.onCloseMobile && (
                    <button className="btn btn-square btn-sm btn-ghost md:hidden" onClick={props.onCloseMobile}>
                        <span className="iconify mdi--close text-xl"></span>
                    </button>
                )}
            </div>

            {/* NEU: Login/Register Formular OBEN in der Sidebar */}
            {isGuest && (
                <div className="p-6 border-b border-base-300 bg-base-100">
                    {showRegister ? (
                        <>
                            <h3 className="font-bold mb-3 flex items-center gap-2"><span
                                className="iconify mdi--account-plus"></span> Registrieren</h3>
                            {regSuccess ? (
                                <div className="alert alert-success text-sm p-3 shadow-sm">{regSuccess}</div>
                            ) : (
                                <form onSubmit={handleSidebarRegister} className="space-y-3">
                                    <input type="text" required placeholder="Dein Name" value={regName}
                                           onChange={e => setRegName(e.target.value)}
                                           className="input input-sm input-bordered w-full"/>
                                    <input type="email" required placeholder="E-Mail Adresse" value={regEmail}
                                           onChange={e => setRegEmail(e.target.value)}
                                           className="input input-sm input-bordered w-full"/>
                                    {authError &&
                                        <p className="text-xs text-error font-semibold leading-tight">{authError}</p>}
                                    <button type="submit" className="btn btn-sm btn-primary w-full mt-2"
                                            disabled={isLoggingIn}>
                                        {isLoggingIn ?
                                            <span className="loading loading-spinner"></span> : 'Registrieren'}
                                    </button>
                                </form>
                            )}
                            <div className="text-center mt-3 text-xs">
                                <button onClick={() => {
                                    setShowRegister(false);
                                    setAuthError('');
                                }} className="link link-hover text-base-content/70">Zurück zum Login
                                </button>
                            </div>
                        </>
                    ) : (
                        <>
                            <h3 className="font-bold mb-3 flex items-center gap-2"><span
                                className="iconify mdi--login"></span> Anmelden</h3>
                            <form onSubmit={handleSidebarLogin} className="space-y-3">
                                <input type="email" required placeholder="E-Mail Adresse" value={loginEmail}
                                       onChange={e => setLoginEmail(e.target.value)}
                                       className="input input-sm input-bordered w-full"/>
                                <input type="password" required placeholder="Passwort" value={loginPassword}
                                       onChange={e => setLoginPassword(e.target.value)}
                                       className="input input-sm input-bordered w-full"/>
                                {authError &&
                                    <p className="text-xs text-error font-semibold leading-tight">{authError}</p>}
                                <button type="submit" className="btn btn-sm btn-primary w-full mt-2"
                                        disabled={isLoggingIn}>
                                    {isLoggingIn ? <span className="loading loading-spinner"></span> : 'Login'}
                                </button>
                            </form>
                            <div className="text-center mt-3 text-xs">
                                <button onClick={() => {
                                    setShowRegister(true);
                                    setAuthError('');
                                }} className="link link-hover text-base-content/70">Neu hier? Registrieren
                                </button>
                            </div>
                        </>
                    )}
                </div>
            )}

            <ul className="menu bg-base-200 w-full p-2 border-b border-base-300">
                {isAdminOrPhotog && (
                    <>
                        <li><Link to="/" className={props.currentView === 'structure' ? 'active' : ''}
                                  onClick={props.onCloseMobile}>
                            <span className="iconify mdi--folder-multiple text-lg"></span> Galerie-Struktur
                        </Link></li>
                        {user.is_admin && (
                            <>
                                <li><Link to="/users" className={props.currentView === 'users' ? 'active' : ''}
                                          onClick={props.onCloseMobile}>
                                    <span className="iconify mdi--account-group text-lg"></span> Benutzer & Rechte
                                </Link></li>
                                <li><Link to="/mail-templates"
                                          className={props.currentView === 'mail-templates' ? 'active' : ''}
                                          onClick={props.onCloseMobile}>
                                    <span className="iconify mdi--email-multiple text-lg"></span> E-Mail Vorlagen
                                </Link></li>
                                <li><Link to="/stats" className={props.currentView === 'stats' ? 'active' : ''}
                                          onClick={props.onCloseMobile}>
                                    <span className="iconify mdi--chart-box text-lg"></span> Statistiken & Logs
                                </Link></li>
                                <li><Link to="/settings" className={props.currentView === 'settings' ? 'active' : ''}
                                          onClick={props.onCloseMobile}>
                                    <span className="iconify mdi--cog text-lg"></span> Einstellungen
                                </Link></li>
                            </>
                        )}
                        <li><Link to="/search" className={props.currentView === 'search' ? 'active' : ''}
                                  onClick={props.onCloseMobile}>
                            <span className="iconify mdi--magnify text-lg"></span> Globale Suche
                        </Link></li>
                    </>
                )}

                {isClient && (
                    <>
                        <li><Link to="/" className={props.currentView !== 'search' ? 'active' : ''}
                                  onClick={props.onCloseMobile}>
                            <span className="iconify mdi--folder-account text-lg"></span> Meine Galerien
                        </Link></li>
                        <li><Link to="/search" className={props.currentView === 'search' ? 'active' : ''}
                                  onClick={props.onCloseMobile}>
                            <span className="iconify mdi--magnify text-lg"></span> Bilder durchsuchen
                        </Link></li>
                    </>
                )}

                {isGuest && (
                    <>
                        <li><Link to="/search" className={props.currentView === 'search' ? 'active' : ''}
                                  onClick={props.onCloseMobile}>
                            <span className="iconify mdi--magnify text-lg"></span> Öffentliche Suche
                        </Link></li>
                    </>
                )}
            </ul>

            {isAdminOrPhotog && props.currentView === 'structure' && (
                <div className="p-4 flex gap-2 border-b border-base-300">
                    {user?.is_photographer && (
                        <button onClick={props.onOpenGalleryModal} className="btn btn-primary btn-sm flex-1">
                            <span className="iconify mdi--image-plus"></span> Galerie...
                        </button>
                    )}
                    {user?.is_admin && (
                        <button onClick={props.onOpenGroupModal} className="btn btn-outline btn-sm flex-1">
                            <span className="iconify mdi--folder-plus"></span> Meta-Galerie...
                        </button>
                    )}
                </div>
            )}

            <div className="flex-1 overflow-y-auto p-4">
                {props.isLoading &&
                    <div className="text-center"><span className="loading loading-dots loading-md"></span></div>}
                {!!props.isError && <div className="text-error text-sm">Fehler beim Laden.</div>}

                {isAdminOrPhotog && props.tree && (
                    <ul className="menu bg-base-200 w-full rounded-box p-0">
                        {safeGroups.map(renderGroup)}
                        {safeRootGalleries.map(renderGallery)}
                        {(!safeGroups.length && !safeRootGalleries.length) && (
                            <li className="disabled"><span
                                className="text-base-content/50">Keine Galerien gefunden</span></li>
                        )}
                    </ul>
                )}

                {isClient && props.currentView !== 'search' && (
                    <ul className="menu bg-base-200 w-full rounded-box p-0">
                        <li className="menu-title"><span>Freigeschaltet</span></li>
                        {user.my_galleries?.map((g: Gallery) => (
                            <li key={g.id}>
                                <Link to={'/' + g.full_path} onClick={props.onCloseMobile}>
                                    {g.name}
                                </Link>
                            </li>
                        ))}
                        {!user.my_galleries?.length && (
                            <li className="disabled"><span className="opacity-50">Keine Galerien</span></li>
                        )}
                    </ul>
                )}
            </div>

            
            
            <div className="mt-auto border-t border-base-300 bg-base-200">
                {user && (
                    <div className="p-4">
                        <button onClick={handleLogout} className="btn btn-outline btn-error w-full btn-sm">Abmelden</button>
                    </div>
                )}
                <div className="p-4 pt-2 text-center text-xs opacity-50 flex flex-col gap-1">
                    <p>&copy; {new Date().getFullYear()} Florian Reisinger</p>
                    <div className="flex justify-center gap-3">
                        <a href="https://reisinger.pictures/impressum" target="_blank" rel="noopener noreferrer" className="hover:underline">Impressum</a>
                        <Link to="/privacy" className="hover:underline">Datenschutz</Link>
                        <a href="https://reisinger.pictures/agb" target="_blank" rel="noopener noreferrer" className="hover:underline">AGB</a>
                    </div>
                </div>
            </div>
        </aside>
    );
}
