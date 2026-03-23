import { useEffect, useState } from 'react';
import { useSWRConfig } from 'swr';
import {useNavigate, useParams} from 'react-router-dom';
import PageLayout from './components/PageLayout';
import { useAuth } from '../logic/useAuth';

export default function InviteView() {
    const {token} = useParams<{ token: string }>();
    const navigate = useNavigate();
    const { mutate } = useSWRConfig();
    const { user, isLoading: authLoading } = useAuth();
    const [autoRedeeming, setAutoRedeeming] = useState(false);

    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [galleryName, setGalleryName] = useState('');
    const [requiresPassword, setRequiresPassword] = useState(false);
    const [inviteName, setInviteName] = useState('');

    const [name, setName] = useState('');
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');

    useEffect(() => {
        fetch('/api/invites/' + token, {headers: {'Accept': 'application/json'}})
            .then(res => {
                if (!res.ok) throw new Error('Dieser Einladungslink ist ungültig oder abgelaufen.');
                return res.json();
            })
            .then(data => {
                setGalleryName(data.gallery_name);
                setRequiresPassword(data.requires_password);
                setInviteName(data.invite_name || '');
                setLoading(false);
            })
            .catch(err => {
                setError(err instanceof Error ? (err as Error).message : String(err));
                setLoading(false);
            });
    }, [token]);

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault();
        setError('');

        try {
            const res = await fetch('/api/invites/redeem', {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
                credentials: 'include', // CRITICAL FIX: Damit das JWT-Cookie nicht verworfen wird
                body: JSON.stringify({token, name, email, password})
            });

            const data = await res.json();
            if (!res.ok) throw new Error(data.error || 'Fehler beim Beitritt.');

            // SWR anweisen, die User-Session frisch zu laden
            await mutate(() => true, undefined, { revalidate: true });
            await mutate('/api/auth/me');
            
            navigate('/' + data.full_path, {replace: true});
        } catch (err: unknown) {
            setError(err instanceof Error ? err.message : String(err));
        }
    };

    
    useEffect(() => {
        if (!loading && !authLoading && user && galleryName && !requiresPassword && !error && !autoRedeeming) {
            setAutoRedeeming(true);
            fetch('/api/invites/redeem', {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
                credentials: 'include',
                body: JSON.stringify({ token })
            })
            .then(r => r.json())
            .then(resData => {
                if (resData.full_path) {
                    mutate('/api/auth/me');
                    navigate('/' + resData.full_path, {replace: true});
                } else {
                    setAutoRedeeming(false);
                }
            })
            .catch(() => setAutoRedeeming(false));
        }
    }, [loading, authLoading, user, galleryName, requiresPassword, error, token, autoRedeeming, navigate, mutate]);

    if (loading || authLoading || autoRedeeming) return <PageLayout>
        <div className="flex h-full items-center justify-center"><span
            className="loading loading-spinner loading-lg text-primary"></span></div>
    </PageLayout>;

    if (error && !galleryName) return (
        <PageLayout>
            <div className="flex h-full items-center justify-center p-4">
                <div className="alert alert-error shadow-lg max-w-md">
                    <span className="iconify mdi--alert-circle text-2xl"></span>
                    <span>{error}</span>
                </div>
            </div>
        </PageLayout>
    );

    return (
        <PageLayout>
            <div className="flex h-full items-center justify-center p-4">
                <div className="card w-full max-w-md bg-base-100 shadow-2xl">
                    <div className="card-body">
                        <h2 className="card-title text-2xl mb-1">Willkommen zur Fotoauswahl</h2>
                        <p className="text-base-content/70 mb-6">Galerie: <strong>{galleryName}</strong></p>

                        {error && <div className="alert alert-error text-sm py-2 mb-4">{error}</div>}

                        {inviteName && !requiresPassword ? (
                            <div className="form-control mt-4">
                                <button onClick={handleSubmit} className="btn btn-primary w-full text-lg">Weiter
                                    als {inviteName}</button>
                            </div>
                        ) : (
                            <form onSubmit={handleSubmit} className="space-y-4">
                                {!inviteName && (
                                    <>
                                        <div className="form-control">
                                            <label className="label"><span
                                                className="label-text font-bold">Dein Name</span></label>
                                            <input type="text" required value={name}
                                                   onChange={e => setName(e.target.value)}
                                                   className="input input-bordered" placeholder="z.B. Maria Muster"/>
                                        </div>
                                        <div className="form-control">
                                            <label className="label"><span
                                                className="label-text font-bold">Deine E-Mail</span></label>
                                            <input type="email" required value={email}
                                                   onChange={e => setEmail(e.target.value)}
                                                   className="input input-bordered" placeholder="maria@beispiel.de"/>
                                        </div>
                                    </>
                                )}

                                {inviteName && requiresPassword && (
                                    <p className="text-sm opacity-70">Hallo <strong>{inviteName}</strong>, bitte gib das
                                        Galerie-Passwort ein.</p>
                                )}

                                {requiresPassword && (
                                    <div className="form-control pt-2">
                                        <label className="label"><span
                                            className="label-text font-bold text-warning">Galerie-Passwort</span></label>
                                        <input type="password" required value={password}
                                               onChange={e => setPassword(e.target.value)}
                                               className="input input-bordered input-warning"/>
                                    </div>
                                )}

                                <div className="form-control mt-6">
                                    <button type="submit" className="btn btn-primary w-full text-lg">
                                        {inviteName ? `Weiter als ${inviteName}` : 'Galerie öffnen'}
                                    </button>
                                </div>
                            </form>
                        )}
                    </div>
                </div>
            </div>
        </PageLayout>
    );
}