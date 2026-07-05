import { useEffect, useState } from 'react';
import { t } from "@lingui/core/macro";
import { Trans } from "@lingui/react/macro";
import ErrorMessage from './components/ErrorMessage';
import { useSWRConfig } from 'swr';
import { useNavigate, useParams } from 'react-router-dom';
import PageLayout from './components/PageLayout';
import { apiMutate } from '../api';
import { useAuth } from '../logic/useAuth';

export default function TenantInviteView() {
    const { token } = useParams<{ token: string }>();
    const navigate = useNavigate();
    const { mutate } = useSWRConfig();
    const { user, isLoading: authLoading } = useAuth();

    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [tenantName, setTenantName] = useState('');
    const [email, setEmail] = useState('');
    const [confirmed, setConfirmed] = useState(false);

    const [name, setName] = useState('');
    const [password, setPassword] = useState('');
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [acceptPrivacy, setAcceptPrivacy] = useState(false);

    useEffect(() => {
        fetch('/api/tenant-invites/' + token, { headers: { 'Accept': 'application/json' } })
            .then(res => {
                if (!res.ok) throw new Error(t`Dieser Einladungslink ist ungültig oder abgelaufen.`);
                return res.json();
            })
            .then(data => {
                setTenantName(data.tenant_name);
                setEmail(data.email);
                setLoading(false);
            })
            .catch(err => {
                setError(err instanceof Error ? err.message : String(err));
                setLoading(false);
            });
    }, [token]);

    const handleJoin = async () => {
        setError('');
        setIsSubmitting(true);
        try {
            await apiMutate('/api/tenant-invites/redeem', 'POST', {
                token, accept_privacy: true,
                ...(user ? {} : { name, password })
            });
            await mutate(() => true, undefined, { revalidate: true });
            navigate('/', { replace: true });
        } catch (err: unknown) {
            setError(err instanceof Error ? err.message : t`Fehler beim Beitreten.`);
            setIsSubmitting(false);
        }
    };

    const handleConfirm = () => {
        if (user) {
            handleJoin();
        } else {
            setConfirmed(true);
        }
    };

    if (loading || authLoading) {
        return (
            <PageLayout>
                <div className="flex h-full items-center justify-center">
                    <span className="loading loading-spinner loading-lg text-primary"></span>
                </div>
            </PageLayout>
        );
    }

    if (error && !tenantName) {
        return (
            <PageLayout>
                <div className="flex h-full items-center justify-center p-4">
                    <ErrorMessage message={error} className="max-w-md shadow-lg mx-auto" />
                </div>
            </PageLayout>
        );
    }

    if (confirmed && !user) {
        return (
            <PageLayout>
                <div className="flex h-full items-center justify-center p-4">
                    <div className="card w-full max-w-md bg-base-100 shadow-2xl">
                        <div className="card-body">
                            <h2 className="card-title text-2xl mb-1"><Trans>Account erstellen</Trans></h2>
                            <p className="text-base-content/70 mb-4">
                                <Trans>Du bist dabei, <strong>{tenantName}</strong> beizutreten. Erstelle deinen Account, um fortzufahren.</Trans>
                            </p>
                            {error && <ErrorMessage message={error} className="mb-4" />}
                            <form onSubmit={e => { e.preventDefault(); handleJoin(); }} className="space-y-4">
                                <div className="form-control">
                                    <label className="label"><span className="label-text font-bold"><Trans>Deine E-Mail</Trans></span></label>
                                    <input type="email" disabled value={email} className="input input-bordered opacity-70 cursor-not-allowed" />
                                </div>
                                <div className="form-control">
                                    <label className="label"><span className="label-text font-bold"><Trans>Dein Name</Trans></span></label>
                                    <input type="text" required value={name} onChange={e => setName(e.target.value)} className="input input-bordered" placeholder={t`z.B. Maria Muster`} autoFocus />
                                </div>
                                <div className="form-control pt-2">
                                    <label className="label"><span className="label-text font-bold"><Trans>Passwort festlegen</Trans></span></label>
                                    <input type="password" required minLength={8} value={password} onChange={e => setPassword(e.target.value)} className="input input-bordered" />
                                    <span className="label-text-alt opacity-70 mt-1">{t`Mindestens 8 Zeichen`}</span>
                                </div>
                                <div className="form-control mt-4 mb-2">
                                    <label className="cursor-pointer label justify-start gap-3 p-3 rounded-box hover:bg-base-300/50 transition-colors">
                                        <input type="checkbox" required className="checkbox checkbox-primary mt-0.5 shrink-0" checked={acceptPrivacy} onChange={e => setAcceptPrivacy(e.target.checked)} />
                                        <span className="label-text text-sm leading-tight">
                                            <Trans>Ich habe die <a href="/privacy" target="_blank" className="link link-primary">Datenschutzerklärung</a> gelesen und akzeptiert.</Trans>
                                        </span>
                                    </label>
                                </div>
                                <div className="form-control mt-6">
                                    <button type="submit" className="btn btn-primary w-full text-lg" disabled={isSubmitting}>
                                        {isSubmitting ? <span className="loading loading-spinner"></span> : <Trans>Account aktivieren & Beitreten</Trans>}
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </PageLayout>
        );
    }

    return (
        <PageLayout>
            <div className="flex h-full items-center justify-center p-4">
                <div className="card w-full max-w-md bg-base-100 shadow-2xl">
                    <div className="card-body">
                        <h2 className="card-title text-2xl mb-1"><Trans>Einladung zu Organisation</Trans></h2>

                        <div className="bg-base-200 rounded-box p-4 my-4 text-center">
                            <p className="text-lg font-bold">{tenantName}</p>
                        </div>

                        <p className="text-base-content/70 mb-4">
                            <Trans>Du wurdest eingeladen, der Organisation <strong>{tenantName}</strong> beizutreten.</Trans>
                        </p>

                        <ul className="list-disc list-inside text-sm text-base-content/70 space-y-1 mb-4">
                            <li><Trans>Du erhältst Zugriff auf freigeschaltete Galerien</Trans></li>
                            <li><Trans>Deine Bestellungen können über die Organisation abgerechnet werden</Trans></li>
                            <li><Trans>Org-Administratoren sehen deine Bestellungen</Trans></li>
                        </ul>

                        <div className="form-control mb-4">
                            <label className="label"><span className="label-text font-bold"><Trans>E-Mail</Trans></span></label>
                            <input type="email" disabled value={email} className="input input-bordered opacity-70 cursor-not-allowed" />
                        </div>

                        {error && <ErrorMessage message={error} className="mb-4" />}

                        <div className="flex gap-3">
                            <button className="btn btn-outline flex-1" onClick={() => navigate('/', { replace: true })}>
                                <Trans>Ablehnen</Trans>
                            </button>
                            <button className="btn btn-primary flex-1" onClick={handleConfirm} disabled={isSubmitting}>
                                {isSubmitting ? <span className="loading loading-spinner"></span> : <Trans>Beitreten & Fortfahren</Trans>}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </PageLayout>
    );
}
