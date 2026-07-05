import { useEffect, useRef, useState } from 'react';
import { t } from "@lingui/core/macro";
import { Trans } from "@lingui/react/macro";
import ErrorMessage from './components/ErrorMessage';
import { useSWRConfig } from 'swr';
import {useNavigate, useParams} from 'react-router-dom';
import PageLayout from './components/PageLayout';
import { useAuth } from '../logic/useAuth';
import { apiMutate } from '../api';
import { RedeemInviteResponse } from '../api';

export default function InviteView() {
    const {token} = useParams<{ token: string }>();
    const navigate = useNavigate();
    const { mutate } = useSWRConfig();
    const { user, isLoading: authLoading } = useAuth();
    const autoRedeemStartedRef = useRef(false);
    const [autoRedeeming, setAutoRedeeming] = useState(false);

    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [galleryName, setGalleryName] = useState('');
    const [requiresPassword, setRequiresPassword] = useState(false);
    const [inviteName, setInviteName] = useState('');
    const [regSuccess, setRegSuccess] = useState('');

    const [name, setName] = useState('');
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [acceptPrivacy, setAcceptPrivacy] = useState(false);

    useEffect(() => {
        fetch('/api/invites/' + token, {headers: {'Accept': 'application/json'}})
            .then(res => {
                if (!res.ok) throw new Error(t`Dieser Einladungslink ist ungültig oder abgelaufen.`);
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
                body: JSON.stringify({token, name: name || null, email: email || null, password: password || null, accept_privacy: acceptPrivacy})
            });

            const data = await res.json();
            if (!res.ok) throw new Error(data.error || data.message || JSON.stringify(data) || t`Fehler beim Beitritt.`);
            if (data.requires_mail_verification) {
                setRegSuccess(data.message);
                return;
            }

            // SWR anweisen, die User-Session frisch zu laden
            await mutate(() => true, undefined, { revalidate: true });
            
            navigate('/' + data.full_path, {replace: true});
        } catch (err: unknown) {
            setError(err instanceof Error ? err.message : String(err));
        }
    };

    
    useEffect(() => {
        // Auto-Redeem nur wenn: Nicht am Laden, User eingeloggt, Galerie bekannt, kein PW nötig
        if (!loading && !authLoading && user && galleryName && !requiresPassword && !error && !autoRedeemStartedRef.current) {
            autoRedeemStartedRef.current = true;
            // Nur senden, wenn der User existiert (und somit Datenschutzerklärung bei Registrierung akzeptiert hat)
            // setAutoRedeeming wird bewusst NICHT synchron im Effect-Body aufgerufen (react-hooks/set-state-in-effect),
            // sondern erst asynchron, sobald der Request unterwegs ist.
            Promise.resolve()
                .then(() => setAutoRedeeming(true))
                .then(() => apiMutate<RedeemInviteResponse>('/api/invites/redeem', 'POST', { token, accept_privacy: !!user }))
                .then(resData => {
                    if (resData.full_path) {
                        mutate(() => true, undefined, { revalidate: true });
                        navigate('/' + resData.full_path, {replace: true});
                    } else {
                        setAutoRedeeming(false);
                        autoRedeemStartedRef.current = false;
                    }
                })
                .catch((err) => {
                    console.error("Auto-redeem failed", err);
                    setError(t`Automatischer Beitritt fehlgeschlagen. Bitte manuell versuchen.`);
                    setAutoRedeeming(false);
                    autoRedeemStartedRef.current = false;
                });
        }
    }, [loading, authLoading, user, galleryName, requiresPassword, error, token, navigate, mutate]);

    if (loading || authLoading || autoRedeeming) return <PageLayout>
        <div className="flex h-full items-center justify-center"><span
            className="loading loading-spinner loading-lg text-primary"></span></div>
    </PageLayout>;

    if (error && !galleryName) return (
        <PageLayout>
            <div className="flex h-full items-center justify-center p-4">
                <ErrorMessage message={error} className="max-w-md shadow-lg mx-auto" />
            </div>
        </PageLayout>
    );

    return (
        <PageLayout>
            <div className="flex h-full items-center justify-center p-4">
                <div className="card w-full max-w-md bg-base-100 shadow-2xl">
                    <div className="card-body">
                        <h2 className="card-title text-2xl mb-1"><Trans>Willkommen zur Fotoauswahl</Trans></h2>
                        <p className="text-base-content/70 mb-6"><Trans>Galerie:</Trans><strong>{galleryName}</strong></p>

                        {error && <ErrorMessage message={error} className="mb-4" />}

                        {regSuccess && (
                        <div className="alert alert-success shadow-sm mb-4">
                            <span className="iconify mdi--check-circle text-xl"></span>
                            <span>{regSuccess}</span>
                        </div>
                    )}
                                    {!regSuccess && inviteName && !requiresPassword ? (
                            <form onSubmit={handleSubmit}>
                                <div className="form-control mt-4 mb-4">
                                <label className="cursor-pointer label justify-start gap-3 p-3 rounded-box hover:bg-base-300/50 transition-colors">
                                    <input type="checkbox" required className="checkbox checkbox-primary mt-0.5 shrink-0" checked={acceptPrivacy} onChange={e => setAcceptPrivacy(e.target.checked)} />
                                    <span className="label-text text-sm leading-tight">
                                        <Trans>Ich habe die <a href="/privacy" target="_blank" className="link link-primary">Datenschutzerklärung</a> gelesen und akzeptiert.</Trans>
                                    </span>
                                </label>
                            </div>
                            <div className="form-control">
                                <button type="submit" className="btn btn-primary w-full text-lg"><Trans>Weiter als {inviteName}</Trans></button>
                            </div>
                        </form>
                    ) : (
                        <form onSubmit={handleSubmit} className="space-y-4">
                            {!regSuccess && !inviteName && (
                                <>
                                    <div className="form-control">
                                        <label className="label">                                            <span
                                            className="label-text font-bold"><Trans>Dein Name</Trans></span></label>
                                        <input type="text" required value={name}
                                               onChange={e => setName(e.target.value)}
                                                className="input input-bordered" placeholder={t`z.B. Maria Muster`}/>
                                    </div>
                                    <div className="form-control">
                                        <label className="label">                                            <span
                                            className="label-text font-bold"><Trans>Deine E-Mail</Trans></span></label>
                                        <input type="email" required value={email}
                                               onChange={e => setEmail(e.target.value)}
                                                className="input input-bordered" placeholder={t`maria@beispiel.de`}/>
                                    </div>
                                </>
                            )}

                            {inviteName && requiresPassword && (
                                <p className="text-sm opacity-70"><Trans>Hallo <strong>{inviteName}</strong>, bitte gib das Galerie-Passwort ein.</Trans></p>
                            )}

                            {requiresPassword && (
                                <div className="form-control pt-2">
                                    <label className="label"><span
                                        className="label-text font-bold text-warning"><Trans>Galerie-Passwort</Trans></span></label>
                                    <input type="password" required value={password}
                                           onChange={e => setPassword(e.target.value)}
                                           className="input input-bordered input-warning"/>
                                </div>
                            )}

                            <div className="form-control mt-4 mb-2">
                                <label className="cursor-pointer label justify-start gap-3 p-3 rounded-box hover:bg-base-300/50 transition-colors">
                                    <input type="checkbox" required className="checkbox checkbox-primary mt-0.5 shrink-0" checked={acceptPrivacy} onChange={e => setAcceptPrivacy(e.target.checked)} />
                                    <span className="label-text text-sm leading-tight">
                                        <Trans>Ich habe die <a href="/privacy" target="_blank" className="link link-primary">Datenschutzerklärung</a> gelesen und akzeptiert.</Trans>
                                    </span>
                                </label>
                                </div>
                                <div className="form-control mt-6">
                                    <button type="submit" className="btn btn-primary w-full text-lg">
                                        {inviteName ? t`Weiter als ${inviteName}` : t`Galerie öffnen`}
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