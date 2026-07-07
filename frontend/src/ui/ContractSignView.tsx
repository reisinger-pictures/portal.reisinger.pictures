import { useEffect, useRef, useState, useCallback } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { t } from "@lingui/core/macro";
import { Trans } from "@lingui/react/macro";
import PageLayout from './components/PageLayout';
import ErrorMessage from './components/ErrorMessage';
import { fetchSignContract, sendPageExit, submitSign, SignContractResponse } from '../logic/useContractJoin';
import { useContractHeartbeat } from '../logic/useContractHeartbeat';
import { calcAge } from '../logic/utils';

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
                <button onClick={() => navigate('/')} className="btn btn-ghost mt-4"><Trans>Zurück zur Startseite</Trans></button>
            </div>
        </PageLayout>
    );

    if (signed) {
        const signerName = data?.signer.name;
        return (
            <PageLayout>
                <div className="flex h-full items-center justify-center p-4">
                    <div className="card w-full max-w-md bg-base-100 shadow-2xl">
                        <div className="card-body text-center">
                            <span className="iconify mdi--check-circle text-success text-6xl mx-auto"></span>
                            <h2 className="card-title text-2xl justify-center mt-4"><Trans>Vertrag unterschrieben!</Trans></h2>
                            <p className="text-base-content/70"><Trans>Vielen Dank, {signerName}.</Trans></p>
                            <p className="text-base-content/70"><Trans>Das unterschriebene Vertragsdokument wird dir nach Vertragsschluss per E-Mail zugesendet.</Trans></p>
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
                        <h1 className="text-3xl font-bold"><Trans>Vertrag</Trans></h1>
                        <div className="divider"></div>
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                            <div>
                                <p className="font-bold text-base-content/60"><Trans>Unterzeichner</Trans></p>
                                <p>{data?.signer.name}</p>
                                <p>{data?.signer.email}</p>
                            </div>
                            <div>
                                <p className="font-bold text-base-content/60"><Trans>Rolle</Trans></p>
                                <p>{(data?.signer.roles ?? []).join(', ')}</p>
                            </div>
                        </div>
                    </div>
                </div>

                {data?.contract.terms_html && (
                    <div className="card bg-base-100 shadow-xl">
                        <div className="card-body">
                            <h2 className="text-xl font-bold mb-4"><Trans>Vertragsinhalt</Trans></h2>
                            <div className="editor-content prose max-w-none" dangerouslySetInnerHTML={{ __html: data.contract.terms_html }} />
                        </div>
                    </div>
                )}

                {items.length > 0 && (
                    <div className="card bg-base-100 shadow-xl">
                        <div className="card-body">
                            <h2 className="text-xl font-bold mb-4"><Trans>Leistungen / Positionen</Trans></h2>
                            <div className="overflow-x-auto">
                                <table className="table table-zebra w-full">
                                    <thead>
                                        <tr>
                                            <th><Trans>Position</Trans></th>
                                            <th className="text-right"><Trans>Menge</Trans></th>
                                            <th className="text-right"><Trans>Preis</Trans></th>
                                            <th className="text-right"><Trans>Gesamt</Trans></th>
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
                                            <tr><td colSpan={4} className="text-right font-bold pt-4"><Trans>Zwischensumme</Trans></td></tr>
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
                                            <td colSpan={3} className="text-right"><Trans>Gesamtbetrag</Trans></td>
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
                            <h2 className="text-xl font-bold mb-4"><Trans>Rechnungsempfänger</Trans></h2>
                            <div className="grid grid-cols-2 gap-2 text-sm">
                                {data.contract.billing_details.name && <><span className="font-bold"><Trans>Name:</Trans></span><span>{data.contract.billing_details.name}</span></>}
                                {data.contract.billing_details.company && <><span className="font-bold"><Trans>Firma:</Trans></span><span>{data.contract.billing_details.company}</span></>}
                                {data.contract.billing_details.street && <><span className="font-bold"><Trans>Straße:</Trans></span><span>{data.contract.billing_details.street}</span></>}
                                {data.contract.billing_details.zip && <><span className="font-bold"><Trans>PLZ:</Trans></span><span>{data.contract.billing_details.zip}</span></>}
                                {data.contract.billing_details.city && <><span className="font-bold"><Trans>Ort:</Trans></span><span>{data.contract.billing_details.city}</span></>}
                                {data.contract.billing_details.email && <><span className="font-bold"><Trans>E-Mail:</Trans></span><span>{data.contract.billing_details.email}</span></>}
                                {(() => {
                                    const bd = data.contract.billing_details.birthdate;
                                    if (!bd) return null;
                                    const birthDate = new Date(bd);
                                    const age = calcAge(birthDate);
                                    return <><span className="font-bold">Alter:</span><span>{age} Jahre (geb. {birthDate.toLocaleDateString('de-DE')})</span></>;
                                })()}
                            </div>
                        </div>
                    </div>
                )}

                {isStale && (
                    <div className="alert alert-warning shadow-xl">
                        <span className="iconify mdi--alert-circle text-xl"></span>
                        <div>
                            <h3 className="font-bold"><Trans>Vertrag wurde geändert</Trans></h3>
                            <p className="text-sm"><Trans>Dieser Vertrag wurde nach dem Öffnen bearbeitet. Bitte laden Sie die Seite neu, um die aktuelle Version zu lesen und zu unterschreiben.</Trans></p>
                            <button onClick={() => window.location.reload()} className="btn btn-warning btn-sm mt-2">
                                <Trans>Seite neu laden</Trans>
                            </button>
                        </div>
                    </div>
                )}

                <div className="card bg-base-100 shadow-xl border-2 border-primary/30">
                    <div className="card-body">
                        <h2 className="text-xl font-bold mb-4 text-primary"><Trans>Verbindliche Unterzeichnung</Trans></h2>

                        {error && <ErrorMessage message={error} className="mb-4" />}

                        <div className="alert alert-info mb-4">
                            <span className="iconify mdi--information-outline text-xl"></span>
                            <span><Trans>Mit deiner Unterschrift bestätigst du, den Vertrag gelesen zu haben und akzeptierst alle Bedingungen. Deine IP-Adresse und der Zeitpunkt werden im Audit-Trail protokolliert.</Trans></span>
                        </div>

                        <div className="form-control">
                            <label className="cursor-pointer label justify-start gap-3 p-3 rounded-box hover:bg-base-300/50 transition-colors">
                                <input type="checkbox" required className="checkbox checkbox-primary mt-0.5 shrink-0" checked={acceptContract} onChange={e => setAcceptContract(e.target.checked)} />
                                <span className="label-text font-medium">
                                    <Trans>Ich habe den Vertrag gelesen und stimme allen Bedingungen verbindlich zu.</Trans>
                                </span>
                            </label>
                        </div>

                        <div className="mt-6 flex flex-col items-end">
                            {isStale && <p className="text-warning text-sm text-right mb-2"><Trans>Bitte Seite neu laden, um den aktualisierten Vertrag zu unterschreiben.</Trans></p>}
                            <button onClick={handleSign} disabled={isSigning || !acceptContract || isStale} className="btn btn-primary btn-lg">
                                {isSigning ? <span className="loading loading-spinner"></span> : ''}
                                {(data?.contract.items?.length ?? 0) > 0 ? t`Zahlungspflichtig abschließen` : t`Vertrag verbindlich abschließen`}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </PageLayout>
    );
}
