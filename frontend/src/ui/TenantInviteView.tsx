import { useEffect, useState } from 'react';
import ErrorMessage from './components/ErrorMessage';
import { useSWRConfig } from 'swr';
import { useNavigate, useParams } from 'react-router-dom';
import PageLayout from './components/PageLayout';
import { apiMutate } from '../api';

export default function TenantInviteView() {
    const { token } = useParams<{ token: string }>();
    const navigate = useNavigate();
    const { mutate } = useSWRConfig();

    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [tenantName, setTenantName] = useState('');
    const [email, setEmail] = useState('');

    const [name, setName] = useState('');
    const [password, setPassword] = useState('');
    const [isSubmitting, setIsSubmitting] = useState(false);

    useEffect(() => {
        fetch('/api/tenant-invites/' + token, { headers: { 'Accept': 'application/json' } })
            .then(res => {
                if (!res.ok) throw new Error('Dieser Einladungslink ist ungültig oder abgelaufen.');
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

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault();
        setError('');
        setIsSubmitting(true);

        try {
            await apiMutate('/api/tenant-invites/redeem', 'POST', { token, name, password });
            
            // SWR Cache revalidieren, da nun ein JWT existiert
            await mutate(() => true, undefined, { revalidate: true });
            
            // Zurück zum Dashboard
            navigate('/', { replace: true });
        } catch (err: any) {
            setError(err.message || 'Fehler beim Aktivieren des Accounts.');
            setIsSubmitting(false);
        }
    };

    if (loading) return <PageLayout><div className="flex h-full items-center justify-center"><span className="loading loading-spinner loading-lg text-primary"></span></div></PageLayout>;

    if (error && !tenantName) return (
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
                        <h2 className="card-title text-2xl mb-1">Unternehmens-Account</h2>
                        <p className="text-base-content/70 mb-6">Einladung für: <strong>{tenantName}</strong></p>

                        {error && <ErrorMessage message={error} className="mb-4" />}

                        <form onSubmit={handleSubmit} className="space-y-4">
                            <div className="form-control">
                                <label className="label"><span className="label-text font-bold">Deine E-Mail</span></label>
                                <input type="email" disabled value={email} className="input input-bordered opacity-70 cursor-not-allowed" />
                            </div>
                            <div className="form-control">
                                <label className="label"><span className="label-text font-bold">Dein Name</span></label>
                                <input type="text" required value={name} onChange={e => setName(e.target.value)} className="input input-bordered" placeholder="z.B. Maria Muster" autoFocus />
                            </div>
                            <div className="form-control pt-2">
                                <label className="label"><span className="label-text font-bold">Passwort festlegen</span></label>
                                <input type="password" required minLength={8} value={password} onChange={e => setPassword(e.target.value)} className="input input-bordered" />
                                <span className="label-text-alt opacity-70 mt-1">Mindestens 8 Zeichen</span>
                            </div>

                            <div className="form-control mt-6">
                                <button type="submit" className="btn btn-primary w-full text-lg" disabled={isSubmitting}>
                                    {isSubmitting ? <span className="loading loading-spinner"></span> : 'Account aktivieren & Anmelden'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </PageLayout>
    );
}
