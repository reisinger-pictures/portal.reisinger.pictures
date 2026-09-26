import { t } from "@lingui/core/macro";
import { Trans } from "@lingui/react/macro";
import { useState, type ReactElement } from 'react';
import {describeFtpConnection, type FtpConnectionField} from '../../logic/ftpConnection';
import {useFtp} from '../../logic/useFtp';
import {useProtectedGalleries} from '../../logic/useGalleries';
import { useUI } from '../components/UIContext';

const brandLabels: Record<string, string> = {
    rp: 'Reisinger Pictures',
};

/**
 * The wording for the camera rows, kept here and not in `ftpConnection`.
 *
 * The logic module returns stable field names instead of labels on purpose: it
 * has to stay testable without a Lingui catalogue, and a `t` at module scope is
 * forbidden anyway (the shell chunks are evaluated before the locale is
 * activated). The map is static, so a missing field is a TypeScript error rather
 * than an empty table cell.
 */
const connectionLabels: Record<FtpConnectionField, ReactElement> = {
    host: <Trans>Server</Trans>,
    username: <Trans>Benutzername</Trans>,
    sftp: <Trans>SFTP-Port</Trans>,
    ftps: <Trans>FTPS-Port</Trans>,
    passive_ports: <Trans>Passiver Bereich (Firewall)</Trans>,
    path: <Trans>Zielordner</Trans>,
};

function BrandBadge({brand}: {brand?: string | null}) {
    if (!brand) return null;
    return <span className="badge badge-sm badge-outline ml-1">{brandLabels[brand] ?? brand}</span>;
}

export default function ManagementFtpInbox() {
    const {status, isLoading, setTargetGallery, processInbox} = useFtp();
    const {tree} = useProtectedGalleries();
    const [selectedId, setSelectedId] = useState<string>('');
    const [processing, setProcessing] = useState(false);
    const { showToast } = useUI();

    if (isLoading || !status) return <div className="p-4"><span className="loading loading-spinner"></span></div>;

    const safeGroups = Array.isArray(tree?.groups) ? tree.groups : [];
    const safeRootGalleries = Array.isArray(tree?.root_galleries) ? tree.root_galleries : [];

    // Empty means the server has not declared its connection parameters (or has
    // nothing complete to declare) — the section then warns instead of showing a
    // table of blanks a photographer would try to copy into the camera.
    const connectionRows = describeFtpConnection(status.connection);

    const flatGalleries = [
        ...(safeGroups.flatMap(g => Array.isArray(g.galleries) ? g.galleries : [])),
        ...safeRootGalleries
    ];

    const handleSetTarget = async () => {
        await setTargetGallery(selectedId === '' ? null : selectedId);
        setSelectedId('');
    };

    const handleProcess = async () => {
        setProcessing(true);
        const data = await processInbox();
        const processedCount = data.processed;
        showToast('success', t`Import abgeschlossen. ${processedCount} Bilder wurden verschoben.`);
        setProcessing(false);
    };

    return (
        <div className="card bg-base-200 border border-base-300 mb-8">
            <div className="card-body">
                <h2 className="card-title text-2xl flex items-center gap-2">
                    <span className="iconify mdi--folder-download text-primary"></span> <Trans>FTP Inbox</Trans>
                </h2>

                <div className="flex gap-4 items-center bg-base-100 p-4 rounded-box mt-2">
                    <div className="flex-1">
                        <p className="text-sm opacity-70"><Trans>Dein Upload-Ordner</Trans></p>
                        <code className="font-bold font-mono text-lg">{status.ftp_folder}</code>
                    </div>
                    <div className="text-right">
                        <p className="text-sm opacity-70"><Trans>Bilder in der Warteschlange</Trans></p>
                        <p className={`text-2xl font-bold ${status.file_count > 0 ? 'text-warning' : 'text-success'}`}>
                            {status.file_count}
                        </p>
                    </div>
                </div>

                <div className="divider my-2"><Trans>Kamera-Verbindung</Trans></div>

                {connectionRows.length === 0 ? (
                    <div className="alert alert-warning shadow-sm" role="status">
                        <span className="iconify mdi--alert-circle-outline text-xl"></span>
                        <div>
                            <h3 className="font-bold"><Trans>Verbindungsdaten der Kamera nicht verfügbar</Trans></h3>
                            <p className="text-sm"><Trans>Die Server-Konfiguration ist unvollständig, daher lassen sich hier keine
                                Verbindungsdaten anzeigen. Bitte kontaktiere den Support, damit die Kamera
                                eingerichtet werden kann.</Trans></p>
                        </div>
                    </div>
                ) : (
                    <>
                        <div className="overflow-x-auto bg-base-100 rounded-box border border-base-300 shadow-sm">
                            <table className="table table-zebra w-full">
                                <thead>
                                    <tr>
                                        <th><Trans>Einstellung</Trans></th>
                                        <th><Trans>Wert</Trans></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {connectionRows.map(row => (
                                        <tr key={row.field}>
                                            <td className="whitespace-nowrap text-sm">{connectionLabels[row.field]}</td>
                                            <td><code className="font-mono text-sm">{row.value}</code></td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <p className="text-sm opacity-70 mt-2"><Trans>SFTP läuft über Port 2222, FTPS über Port 989.
                            FTPS verwendet explizites TLS (AUTH TLS) — in der Kamera muss dafür
                            „Explizites FTP über TLS" gewählt werden. Der passive Bereich muss in der
                            Firewall freigeschaltet sein.</Trans></p>
                    </>
                )}

                <div className="divider my-2"><Trans>Zuordnung</Trans></div>

                <div className="flex flex-col md:flex-row gap-4 items-end">
                    <div className="flex-1 w-full">
                        <label className="label"><span className="label-text"><Trans>Aktuelle Ziel-Galerie</Trans></span></label>
                        {status.current_target_gallery ? (
                            <div
                                className="flex items-center bg-base-100 border border-base-300 rounded-box p-3 shadow-sm">
                                <span>{status.current_target_gallery.name}</span>
                                <BrandBadge brand={status.current_target_gallery.brand} />
                                <button
                                    className="btn btn-xs btn-circle btn-ghost ml-auto text-base-content/70 hover:text-error"
                                    title={t`Zuordnung aufheben`} onClick={() => setTargetGallery(null)}><span
                                    className="iconify mdi--close text-lg"></span></button>
                            </div>
                        ) : (
                            <div className="flex gap-2">
                                <select value={selectedId}
                                        onChange={e => setSelectedId(e.target.value)}
                                        className="select select-bordered flex-1">
                                    <option value="">-- <Trans>Ziel-Galerie auswählen</Trans> --</option>
                                    {flatGalleries.map(g => <option key={g.id} value={g.id}>{g.name} [{g.brand ?? 'cross-brand'}]</option>)}
                                </select>
                                <button onClick={handleSetTarget} disabled={selectedId === ''}
                                        className="btn btn-outline btn-primary"><Trans>Setzen</Trans>
                                </button>
                            </div>
                        )}
                    </div>

                    <button
                        onClick={handleProcess}
                        disabled={status.file_count === 0 || !status.current_target_gallery || processing}
                        className="btn btn-primary"
                    >
                        {processing ? <span className="loading loading-spinner"></span> : <Trans>Bilder Importieren</Trans>}
                    </button>
                </div>

            </div>
        </div>
    );
}