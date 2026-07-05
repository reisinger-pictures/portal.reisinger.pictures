import { useEffect, useState } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { t } from "@lingui/core/macro";
import { Trans } from "@lingui/react/macro";
import PageLayout from './components/PageLayout';
import ErrorMessage from './components/ErrorMessage';
import { fetchJoinContract, submitJoin, JoinContractResponse } from '../logic/useContractJoin';

export default function ContractJoinView() {
    const { token } = useParams<{ token: string }>();
    const navigate = useNavigate();

    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [contract, setContract] = useState<JoinContractResponse | null>(null);

    const [name, setName] = useState('');
    const [email, setEmail] = useState('');
    const [selectedRoles, setSelectedRoles] = useState<string[]>([]);
    const [acceptPrivacy, setAcceptPrivacy] = useState(false);
    const [isJoining, setIsJoining] = useState(false);

    useEffect(() => {
        if (!token) return;
        fetchJoinContract(token)
            .then(data => { setContract(data); setLoading(false); })
            .catch(err => { setError(err.message); setLoading(false); });
    }, [token]);

    const handleRoleToggle = (role: string) => {
        if (selectedRoles.includes(role)) {
            setSelectedRoles(selectedRoles.filter(r => r !== role));
        } else {
            if (!contract?.allow_multiple_roles && selectedRoles.length >= 1) {
                setSelectedRoles([role]);
            } else {
                setSelectedRoles([...selectedRoles, role]);
            }
        }
    };

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault();
        if (!token || selectedRoles.length === 0) return;
        setIsJoining(true);
        setError('');
        try {
            const result = await submitJoin(token, name, email, selectedRoles);
            navigate(`/contracts/sign/${result.personal_token}`, { replace: true });
        } catch (err: unknown) {
            setError(err instanceof Error ? err.message : String(err));
        } finally {
            setIsJoining(false);
        }
    };

    if (loading) return <PageLayout>
        <div className="flex h-full items-center justify-center"><span
            className="loading loading-spinner loading-lg text-primary"></span></div>
    </PageLayout>;

    if (error && !contract) return (
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
                        <h2 className="card-title text-2xl mb-1"><Trans>Vertrag beitreten</Trans></h2>
                        <p className="text-base-content/70 mb-6"><Trans>Bitte gib deine Daten an und wähle deine Rolle.</Trans></p>

                        {error && <ErrorMessage message={error} className="mb-4" />}

                        <form onSubmit={handleSubmit} className="space-y-4">
                            <div className="form-control">
                                <label className="label"><span className="label-text font-bold"><Trans>Dein Name</Trans></span></label>
                                <input type="text" required value={name} onChange={e => setName(e.target.value)} className="input input-bordered" placeholder={t`z.B. Maria Muster`} />
                            </div>
                            <div className="form-control">
                                <label className="label"><span className="label-text font-bold"><Trans>Deine E-Mail</Trans></span></label>
                                <input type="email" required value={email} onChange={e => setEmail(e.target.value)} className="input input-bordered" placeholder={t`maria@beispiel.de`} />
                            </div>

                            <div className="form-control">
                                <label className="label"><span className="label-text font-bold"><Trans>Deine Rolle</Trans></span></label>
                                <p className="text-xs text-base-content/50 mb-2">
                                    {contract?.allow_multiple_roles ? t`Mehrfachauswahl möglich` : t`Bitte wähle eine Rolle`}
                                </p>
                                <div className="flex flex-wrap gap-2">
                                    {contract?.available_roles.map(role => (
                                        <button type="button" key={role}
                                            onClick={() => handleRoleToggle(role)}
                                            className={`btn btn-sm ${selectedRoles.includes(role) ? 'btn-primary' : 'btn-outline'}`}>
                                            {role}
                                            {selectedRoles.includes(role) && <span className="iconify mdi--check ml-1"></span>}
                                        </button>
                                    ))}
                                </div>
                            </div>

                            <div className="form-control mt-4">
                            <label className="cursor-pointer label justify-start gap-3 p-3 rounded-box hover:bg-base-300/50 transition-colors">
                                <input type="checkbox" required className="checkbox checkbox-primary mt-0.5 shrink-0" checked={acceptPrivacy} onChange={e => setAcceptPrivacy(e.target.checked)} />
                                <span className="label-text text-sm leading-tight">
                                    <Trans>Ich habe die <a href="/privacy" target="_blank" className="link link-primary">Datenschutzerklärung</a> gelesen und akzeptiert.</Trans>
                                </span>
                            </label>
                            </div>

                            <div className="form-control mt-6">
                                <button type="submit" disabled={isJoining || selectedRoles.length === 0} className="btn btn-primary w-full text-lg">
                                    {isJoining ? <span className="loading loading-spinner"></span> : t`Vertraulich ansehen & unterschreiben`}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </PageLayout>
    );
}
