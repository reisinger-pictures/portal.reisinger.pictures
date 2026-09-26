import { t } from "@lingui/core/macro";
import { Trans } from "@lingui/react/macro";
import { useState, type ReactElement } from 'react';
import {Link} from 'react-router-dom';
import {describeFtpConnection} from '../../logic/ftpConnection';
import {useFtp, type FtpAccountStatus} from '../../logic/useFtp';
import {useProtectedGalleries} from '../../logic/useGalleries';
import { useUI } from '../components/UIContext';
import {FtpConnectionRows, FtpConnectionUnconfigured} from './FtpConnectionRows';
import ShowOncePassword from './components/ShowOncePassword';

const brandLabels: Record<string, string> = {
    rp: 'Reisinger Pictures',
};

function BrandBadge({brand}: {brand?: string | null}) {
    if (!brand) return null;
    return <span className="badge badge-sm badge-outline ml-1">{brandLabels[brand] ?? brand}</span>;
}

/**
 * The one sentence about FTPS, built from the deployment instead of asserted.
 *
 * Ports and TLS mode were literals here once, and they were wrong once: the UI
 * said "explicit" while the SFTPGo binding could be configured differently, and
 * the resulting handshake failure surfaces in the camera as Error 48, which sends
 * the photographer to the wrong menu. Each mode therefore gets its own message
 * naming the concrete Canon choice, and an unknown mode says so instead of
 * picking one.
 */
function FtpsHint({sftpPort, ftpsPort, tlsMode}: {sftpPort: number | null; ftpsPort: number | null; tlsMode: string | null}) {
    const missing = t`nicht konfiguriert`;
    // Resolved to plain variables first: the Lingui rule requires identifiers in
    // the placeholder, and a `??` inside `${}` would make the message untranslatable
    // in any real editor.
    const sftp = sftpPort !== null ? String(sftpPort) : missing;
    const ftps = ftpsPort !== null ? String(ftpsPort) : missing;

    if (tlsMode === 'explicit') {
        return (
            <p className="text-sm opacity-70 mt-2">
                {t`SFTP läuft über Port ${sftp}, FTPS über Port ${ftps}. FTPS verwendet explizites TLS (AUTH TLS) — in der Kamera dafür „Explizites FTP über TLS" wählen. Der passive Bereich muss in der Firewall freigeschaltet sein.`}
            </p>
        );
    }

    if (tlsMode === 'implicit') {
        return (
            <p className="text-sm opacity-70 mt-2">
                {t`SFTP läuft über Port ${sftp}, FTPS über Port ${ftps}. FTPS verwendet implizites TLS — in der Kamera dafür „Implizites FTP über TLS" wählen. Der passive Bereich muss in der Firewall freigeschaltet sein.`}
            </p>
        );
    }

    return (
        <p className="text-sm opacity-70 mt-2">
            {t`SFTP läuft über Port ${sftp}, FTPS über Port ${ftps}. Für FTPS ist kein Verschlüsselungsmodus hinterlegt — bitte den Support kontaktieren, bevor du FTPS in der Kamera einrichtest.`}
        </p>
    );
}

/**
 * The provisioning state, and what it means for the camera.
 *
 * `pending` is called out separately from `error` because they fail differently
 * and the difference is invisible from the outside: a `pending` account makes
 * every camera login fail no matter which password is typed, which is the silent
 * failure that looks like a wrong slug.
 */
function AccountStatus({status, error}: {status: FtpAccountStatus; error: string | null}) {
    // A record rather than an if-chain with a fallback. The old shape ended in
    // "anything else is active", so a status the UI had never been taught
    // rendered as *Konto aktiv* — the one thing a photographer must never be told
    // about an account that does not work. Exhaustive by construction: a new
    // member of `FtpAccountStatus` fails the build here instead of reaching a
    // fallback.
    const states: Record<FtpAccountStatus, ReactElement> = {
        pending: (
            <div className="alert alert-warning shadow-sm mt-2" role="status">
                <span className="iconify mdi--account-clock-outline text-xl"></span>
                <div>
                    <h3 className="font-bold"><Trans>Kamera-Konto noch nicht angelegt</Trans></h3>
                    <p className="text-sm"><Trans>Die Kamera kann sich noch nicht anmelden — unabhängig vom Passwort. Fordere
                        zuerst die Zugangsdaten an.</Trans></p>
                </div>
            </div>
        ),
        revoked: (
            <div className="alert alert-warning shadow-sm mt-2" role="status">
                <span className="iconify mdi--account-off-outline text-xl"></span>
                <div>
                    <h3 className="font-bold"><Trans>Kamera-Konto entzogen</Trans></h3>
                    <p className="text-sm"><Trans>Das Konto wurde entfernt, als die Fotografen-Rolle wegfiel. Die Kamera
                        kann sich nicht mehr anmelden, bis das Konto erneut angelegt wird.</Trans></p>
                </div>
            </div>
        ),
        error: (
            <div className="alert alert-error shadow-sm mt-2" role="status">
                <span className="iconify mdi--alert-circle-outline text-xl"></span>
                <div>
                    <h3 className="font-bold"><Trans>Kamera-Konto fehlerhaft</Trans></h3>
                    <p className="text-sm">{error ?? <Trans>Der Server nennt keinen Grund.</Trans>}</p>
                </div>
            </div>
        ),
        active: (
            <div className="flex items-center gap-2 mt-1">
                <span className="badge badge-success badge-sm"><Trans>Konto aktiv</Trans></span>
            </div>
        ),
    };

    return states[status];
}

export default function ManagementFtpInbox() {
    const {status, isLoading, setTargetGallery, processInbox, resetCredentials} = useFtp();
    const {tree} = useProtectedGalleries();
    const [selectedId, setSelectedId] = useState<string>('');
    const [processing, setProcessing] = useState(false);
    const [resetting, setResetting] = useState(false);
    const [newPassword, setNewPassword] = useState<string | null>(null);
    const [passwordNotice, setPasswordNotice] = useState<string | null>(null);
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

    /**
     * One reset per click, never a retry loop.
     *
     * The backend allows three resets per hour and account. Retrying on a 429, or
     * re-enabling the button on a timer, would spend the photographer's remaining
     * quota on guesses; the honest behaviour is to surface the server's message
     * and let them come back later. A failed reset also drops any password still
     * on screen: the server cannot promise the old one is unchanged, and a stale
     * password in the DOM is what produces Error 41 in the camera.
     */
    const handleResetCredentials = async () => {
        setResetting(true);
        try {
            const result = await resetCredentials();
            setNewPassword(result.password);
            setPasswordNotice(result.password_notice);
        } catch (error) {
            setNewPassword(null);
            setPasswordNotice(null);
            showToast('error', error instanceof Error ? error.message : t`Die Zugangsdaten konnten nicht erneuert werden.`);
        } finally {
            setResetting(false);
        }
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
                    <FtpConnectionUnconfigured />
                ) : (
                    <>
                        <FtpConnectionRows rows={connectionRows} />
                        <FtpsHint
                            sftpPort={status.connection.sftp_port}
                            ftpsPort={status.connection.ftps_port}
                            tlsMode={status.connection.ftps_tls_mode}
                        />
                    </>
                )}

                <div className="mt-3">
                    <Link to="/kamera-einrichtung" className="btn btn-sm btn-outline">
                        <span className="iconify mdi--book-open-variant text-lg"></span> <Trans>Anleitung öffnen</Trans>
                    </Link>
                </div>

                <div className="divider my-2"><Trans>Kamera-Konto</Trans></div>

                <div className="flex flex-wrap gap-3 items-center justify-between">
                    <div className="flex-1 min-w-56">
                        <p className="text-sm opacity-70"><Trans>Zugangsdaten für die Kamera</Trans></p>
                        <AccountStatus status={status.ftp_account_status} error={status.ftp_account_error} />
                    </div>
                    <button onClick={handleResetCredentials} disabled={resetting} className="btn btn-primary">
                        {resetting
                            ? <span className="loading loading-spinner loading-sm"></span>
                            : <span className="iconify mdi--key-outline text-lg"></span>}
                        <Trans>Neues Kamera-Passwort</Trans>
                    </button>
                </div>

                {newPassword !== null && passwordNotice !== null && (
                    <ShowOncePassword
                        password={newPassword}
                        notice={passwordNotice}
                        onDismiss={() => {
                            setNewPassword(null);
                            setPasswordNotice(null);
                        }}
                    />
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