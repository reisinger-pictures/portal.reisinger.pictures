import { useEffect, useRef, useState, useCallback } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import PageLayout from './components/PageLayout';
import ErrorMessage from './components/ErrorMessage';
import { fetchSignContract, sendPageExit, submitSign, SignContractResponse } from '../logic/useContractJoin';
import { useContractHeartbeat } from '../logic/useContractHeartbeat';

function formatMoney(cents: number): string {
    return (cents / 100).toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
}

export default function ContractSignView() {
    const { token } = useParams<{ token: string }>();
    const navigate = useNavigate();

    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [data, setData] = useState<SignContractResponse | null>(null);
    const [acceptContract, setAcceptContract] = useState(false);
    const [isSigning, setIsSigning] = useState(false);
    const [signed, setSigned] = useState(false);
    const [contentVersion, setContentVersion] = useState<number | null>(null);
    const [isStale, setIsStale] = useState(false);
    const pageExitSent = useRef(false);

    useEffect(() => {
        if (!token) return;
        fetchSignContract(token)
            .then(result => {
                setData(result);
                setContentVersion(result.contract.content_version);
                setLoading(false);
            })
            .catch(err => { setError(err.message); setLoading(false); });
    }, [token]);

    const handleStale = useCallback(() => setIsStale(true), []);
    useContractHeartbeat(token, contentVersion, signed, handleStale);

    useEffect(() => {
        if (!token) return;
        pageExitSent.current = false;
        const onPageExit = () => {
            if (pageExitSent.current) return;
            if (document.visibilityState !== 'hidden') return;
            pageExitSent.current = true;
            sendPageExit(token);
        };
        document.addEventListener('visibilitychange', onPageExit);
        return () => {
            document.removeEventListener('visibilitychange', onPageExit);
        };
    }, [token]);

    const handleSign = async () => {
        if (!token || contentVersion === null) return;
        setIsSigning(true);
        setError('');
        try {
            await submitSign(token, contentVersion);
            setSigned(true);
        } catch (err: unknown) {
            setError(err instanceof Error ? err.message : String(err));
        } finally {
            setIsSigning(false);
        }
    };

    if (loading) return <PageLayout>
        <div className="flex h-full items-center justify-center"><span
            className="loading loading-spinner loading-lg text-primary"></span></div>
    </PageLayout>;

    if (error && !data) return (
        <PageLayout>
            <div className="flex h-full items-center justify-center p-4 flex-col">
                <ErrorMessage message={error} className="max-w-md shadow-lg mx-auto" />
                <button onClick={() => navigate('/')} className="btn btn-ghost mt-4">Zurück zur Startseite</button>
            </div>
        </PageLayout>
    );

    if (signed) {
        return (
            <PageLayout>
                <div className="flex h-full items-center justify-center p-4">
                    <div className="card w-full max-w-md bg-base-100 shadow-2xl">
                        <div className="card-body text-center">
                            <span className="iconify mdi--check-circle text-success text-6xl mx-auto"></span>
                            <h2 className="card-title text-2xl justify-center mt-4">Vertrag unterschrieben!</h2>
                            <p className="text-base-content/70">Vielen Dank, {data?.signer.name}.</p>
                            <p className="text-base-content/70">Das unterschriebene Vertragsdokument wird dir nach Vertragsschluss per E-Mail zugesendet.</p>
                        </div>
                    </div>
                </div>
            </PageLayout>
        );
    }

    const items = data?.contract.items ?? [];
    const discounts = data?.contract.discounts ?? [];
    const itemsTotal = items.reduce((sum, i) => sum + (i.row_total ?? i.price * i.qty), 0);
    const discountFixedTotal = discounts.filter(d => d.type === 'discount_fixed').reduce((sum, d) => sum + d.price, 0);

    return (
        <PageLayout>
            <div className="max-w-4xl mx-auto p-4 md:p-10 space-y-8">
                <div className="card bg-base-100 shadow-xl">
                    <div className="card-body">
                        <h1 className="text-3xl font-bold">Vertrag</h1>
                        <div className="divider"></div>
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                            <div>
                                <p className="font-bold text-base-content/60">Unterzeichner</p>
                                <p>{data?.signer.name}</p>
                                <p>{data?.signer.email}</p>
                            </div>
                            <div>
                                <p className="font-bold text-base-content/60">Rolle</p>
                                <p>{(data?.signer.roles ?? []).join(', ')}</p>
                            </div>
                        </div>
                    </div>
                </div>

                {data?.contract.terms_html && (
                    <div className="card bg-base-100 shadow-xl">
                        <div className="card-body">
                            <h2 className="text-xl font-bold mb-4">Vertragsinhalt</h2>
                            <div className="editor-content prose max-w-none" dangerouslySetInnerHTML={{ __html: data.contract.terms_html }} />
                        </div>
                    </div>
                )}

                {items.length > 0 && (
                    <div className="card bg-base-100 shadow-xl">
                        <div className="card-body">
                            <h2 className="text-xl font-bold mb-4">Leistungen / Positionen</h2>
                            <div className="overflow-x-auto">
                                <table className="table table-zebra w-full">
                                    <thead>
                                        <tr>
                                            <th>Position</th>
                                            <th className="text-right">Menge</th>
                                            <th className="text-right">Preis</th>
                                            <th className="text-right">Gesamt</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {items.map((item, i) => (
                                            <tr key={i}>
                                                <td>
                                                    <strong>{item.description}</strong>
                                                    {item.notes && <><br /><small className="text-base-content/50">{item.notes}</small></>}
                                                </td>
                                                <td className="text-right">{item.qty}</td>
                                                <td className="text-right">{formatMoney(item.price)}</td>
                                                <td className="text-right">{formatMoney(item.row_total ?? (item.price * item.qty))}</td>
                                            </tr>
                                        ))}
                                        {discounts.length > 0 && (
                                            <tr><td colSpan={4} className="text-right font-bold pt-4">Zwischensumme</td></tr>
                                        )}
                                        {discounts.map((d, i) => (
                                            <tr key={`d-${i}`}>
                                                <td colSpan={2}>{d.description}</td>
                                                <td className="text-right text-error">
                                                    {d.type === 'discount_percent' ? `${d.price / 100}%` : formatMoney(d.price)}
                                                </td>
                                                <td className="text-right text-error">-</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                    <tfoot>
                                        <tr className="text-lg font-bold">
                                            <td colSpan={3} className="text-right">Gesamtbetrag</td>
                                            <td className="text-right">{formatMoney(itemsTotal - discountFixedTotal)}</td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    </div>
                )}

                {data?.contract.billing_details && Object.values(data.contract.billing_details).some(v => v) && (
                    <div className="card bg-base-100 shadow-xl">
                        <div className="card-body">
                            <h2 className="text-xl font-bold mb-4">Rechnungsempfänger</h2>
                            <div className="grid grid-cols-2 gap-2 text-sm">
                                {data.contract.billing_details.name && <><span className="font-bold">Name:</span><span>{data.contract.billing_details.name}</span></>}
                                {data.contract.billing_details.company && <><span className="font-bold">Firma:</span><span>{data.contract.billing_details.company}</span></>}
                                {data.contract.billing_details.street && <><span className="font-bold">Straße:</span><span>{data.contract.billing_details.street}</span></>}
                                {data.contract.billing_details.zip && <><span className="font-bold">PLZ:</span><span>{data.contract.billing_details.zip}</span></>}
                                {data.contract.billing_details.city && <><span className="font-bold">Ort:</span><span>{data.contract.billing_details.city}</span></>}
                                {data.contract.billing_details.email && <><span className="font-bold">E-Mail:</span><span>{data.contract.billing_details.email}</span></>}
                            </div>
                        </div>
                    </div>
                )}

                {isStale && (
                    <div className="alert alert-warning shadow-xl">
                        <span className="iconify mdi--alert-circle text-xl"></span>
                        <div>
                            <h3 className="font-bold">Vertrag wurde geändert</h3>
                            <p className="text-sm">Dieser Vertrag wurde nach dem Öffnen bearbeitet. Bitte laden Sie die Seite neu, um die aktuelle Version zu lesen und zu unterschreiben.</p>
                            <button onClick={() => window.location.reload()} className="btn btn-warning btn-sm mt-2">
                                Seite neu laden
                            </button>
                        </div>
                    </div>
                )}

                <div className="card bg-base-100 shadow-xl border-2 border-primary/30">
                    <div className="card-body">
                        <h2 className="text-xl font-bold mb-4 text-primary">Verbindliche Unterzeichnung</h2>

                        {error && <ErrorMessage message={error} className="mb-4" />}

                        <div className="alert alert-info mb-4">
                            <span className="iconify mdi--information-outline text-xl"></span>
                            <span>Mit deiner Unterschrift bestätigst du, den Vertrag gelesen zu haben und akzeptierst alle Bedingungen. Deine IP-Adresse und der Zeitpunkt werden im Audit-Trail protokolliert.</span>
                        </div>

                        <div className="form-control">
                            <label className="cursor-pointer label justify-start gap-3">
                                <input type="checkbox" required className="checkbox checkbox-primary checkbox-sm mt-0.5" checked={acceptContract} onChange={e => setAcceptContract(e.target.checked)} />
                                <span className="label-text font-medium">
                                    Ich habe den Vertrag gelesen und stimme allen Bedingungen verbindlich zu.
                                </span>
                            </label>
                        </div>

                        <div className="mt-6 flex flex-col items-end">
                            {isStale && <p className="text-warning text-sm text-right mb-2">Bitte Seite neu laden, um den aktualisierten Vertrag zu unterschreiben.</p>}
                            <button onClick={handleSign} disabled={isSigning || !acceptContract || isStale} className="btn btn-primary btn-lg">
                                {isSigning ? <span className="loading loading-spinner"></span> : ''}
                                {(data?.contract.items?.length ?? 0) > 0 ? 'Zahlungspflichtig abschließen' : 'Vertrag verbindlich abschließen'}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </PageLayout>
    );
}
