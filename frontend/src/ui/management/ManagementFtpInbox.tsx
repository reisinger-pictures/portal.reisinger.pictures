import { t } from "@lingui/core/macro";
import { Trans } from "@lingui/react/macro";
import { useEffect, useId, useRef, useState, type ReactElement } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import {describeFtpConnection} from '../../logic/ftpConnection';
import {useFtp, type FtpAccountStatus} from '../../logic/useFtp';
import {useProtectedGalleries} from '../../logic/useGalleries';
import ModalShell from '../components/ModalShell';
import { useUI } from '../components/UIContext';
import {FtpConnectionRows, FtpConnectionUnconfigured} from './FtpConnectionRows';
import KameraEinrichtungContent from './KameraEinrichtungContent';
import ShowOncePassword from './components/ShowOncePassword';

const brandLabels: Record<string, string> = {
    rp: 'Reisinger Pictures',
};

/**
 * The confirmation field of the camera password change (D-51).
 *
 * A factory, not a constant: the schema carries a translated message, and the
 * Lingui rule forbids a macro at module scope — a schema built there would be
 * built while the module is evaluated, before any locale is loaded. The
 * component calls the factory, so the message is produced inside a render.
 *
 * The rule is deliberately only "not empty". The portal cannot judge a camera
 * password — it stores none, so it has nothing to compare the input with. The
 * only question the client can answer is whether something was typed at all;
 * the verdict on the password itself comes from the service.
 */
const createChangePasswordSchema = () => z.object({
    current_password: z.string().min(1, t`Bitte das aktuelle Kamera-Passwort eingeben.`),
});
type ChangePasswordFormValues = z.infer<ReturnType<typeof createChangePasswordSchema>>;

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
            {t`SFTP läuft über Port ${sftp}, FTPS über Port ${ftps}. Für FTPS ist kein Verschlüsselungsmodus hinterlegt. Ohne diesen Modus kann die Kamera FTPS nicht aushandeln — nutze daher SFTP.`}
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
    // The form below is the reason: its resolver is rebuilt on every render,
    // and the React Compiler must not memoise around a form. Every other form
    // component in this app opts out of the compiler the same way.
    "use no memo";
    const {status, isLoading, error, readSettled, setTargetGallery, processInbox, resetCredentials, changeCredentials, mutate} = useFtp();
    const {tree} = useProtectedGalleries();
    const [selectedId, setSelectedId] = useState<string>('');
    const [processing, setProcessing] = useState(false);
    const [resetting, setResetting] = useState(false);
    const [newPassword, setNewPassword] = useState<string | null>(null);
    const [passwordNotice, setPasswordNotice] = useState<string | null>(null);
    const [showGuide, setShowGuide] = useState(false);
    const [showConnection, setShowConnection] = useState(false);
    const [showChangeDialog, setShowChangeDialog] = useState(false);
    const { showToast } = useUI();
    const changePasswordFormId = useId();
    const currentPasswordInputId = `${changePasswordFormId}-current-password`;
    const changePasswordSchema = createChangePasswordSchema();
    const {
        register,
        handleSubmit,
        reset: resetChangeForm,
        formState: {errors: changeErrors, isSubmitting: isChanging},
    } = useForm<ChangePasswordFormValues>({
        resolver: zodResolver(changePasswordSchema),
        defaultValues: {current_password: ''},
    });

    /**
     * Import one batch, reload the queue, and report what happened.
     *
     * One code path for both the manual button and the one-shot catch-up run on
     * mount (D-36): if the two diverged, the automatic run would report — and
     * leave the card in — a state a click never produces. `processing` is the
     * flag that keeps them from overlapping: it is set before the request and
     * the button is disabled while it is true, so a click cannot start a second
     * import while the catch-up run is still moving files.
     *
     * The backend imports at most `ftp.import.max_files` files per call and
     * answers 200 even when more files remain (D-35), so a normal shooting is a
     * success. The two genuine rejections left are an oversized single file
     * (413) and a source that changed mid-import (422); both carry a German
     * message and neither may surface as an unhandled rejection.
     *
     * The status is reloaded on **both** outcomes of the *import*, not only on
     * success (N5): a 422 aborts the batch midway, so files have already moved,
     * and a reload that only ran after a resolved `processInbox` would leave the
     * counter showing the pre-attempt number exactly when it matters most. That
     * covers the import's two exits; it does not promise anything about the
     * reload itself. If the reload's own `GET /status` fails, the counter stays
     * on its old value — silently for a 4xx, because the global `onError` only
     * toasts 5xx and offline (see `useFtp`), and SWR keeps the previous value
     * until the next revalidation. The reload also keeps the button disabled
     * until it settles — the queue on the card and the enabled state then change
     * together, so a fast second click cannot race the reload.
     * `setProcessing(false)` sits in the inner `finally`, so it runs after an
     * import error, after a reload error and after a success; the reload's own
     * failure is swallowed because the import outcome was already reported.
     */
    const handleProcess = async () => {
        setProcessing(true);
        try {
            const data = await processInbox();
            const processedCount = data.processed;
            showToast('success', t`Import abgeschlossen. ${processedCount} Bilder wurden verschoben.`);
        } catch (error) {
            showToast('error', error instanceof Error ? error.message : t`Der Import konnte nicht abgeschlossen werden.`);
        } finally {
            try {
                await mutate();
            } catch {
                // A failed reload must not mask the import outcome above, and
                // must not leave the button spinning. SWR still holds the last
                // good value and retries on the next revalidation.
            } finally {
                setProcessing(false);
            }
        }
    };

    /**
     * The one-shot catch-up run (D-36).
     *
     * SFTPGo's upload events are fire-and-forget, so a lost event leaves files
     * sitting in the inbox until somebody looks. Opening this page is that look:
     * once a status this mount actually read reports a non-empty inbox *and* a
     * target gallery, exactly one import runs. No target gallery means the
     * destination is an undecided question, and an empty inbox means there is
     * nothing to catch up on — both stay silent.
     *
     * The decision rests on the read the *hook* started for this mount
     * (`useFtp`): `readSettled` turns true once that explicit `mutate()` has
     * settled, so the value below is one this page read, not whatever the
     * module-wide SWR cache happened to hold. That is the fix for the dedupe
     * window — a remount inside `dedupingInterval`, or during a previous
     * instance's in-flight request, used to hand the first render a complete but
     * stale status (`file_count: 0` after every import) and the revalidation
     * that carried the real files arrived after the one shot was gone.
     *
     * `autoRunStarted` makes "once" literal rather than a convention: the first
     * time this effect reaches a decision — import, or a deliberate no-op for an
     * empty inbox / missing gallery — it flips to `true` before the call, so
     * React 19 StrictMode's development double-invoke (setup → cleanup → setup
     * on the same instance, which preserves refs), a later SWR revalidation, a
     * window-focus refetch and a plain re-render all find it already set and do
     * nothing. A ref, not state, on purpose: flipping it must not itself
     * re-render, and its value is never rendered. It is per mount, so a later
     * mount in the same state decides again — which it must, because the cache
     * after an import legitimately reads `file_count: 0`.
     *
     * A read that *failed* does not decide: `error` is set, the effect returns
     * without spending the attempt, and the next successful revalidation (a
     * focus refetch) decides instead. Running against the leftover cache value
     * the read could not confirm would be a guess — and the one attempt is not
     * spent on it. The user is not left in the dark about it: the global
     * `onError` (`App.tsx` `GlobalSWRConfig`) toasts 5xx/offline, mirrored and
     * pinned in `ManagementFtpInbox.autoRunWarmCache.test.tsx`.
     */
    const autoRunStarted = useRef(false);
    // Ref-delegation: the catch-up effect must not depend on the identity of
    // `handleProcess` (the compiler recreates it every render), so it reads the
    // current handler through a ref. The sync effect has no dependency array and
    // is declared first, so the ref is current before the catch-up effect runs.
    const handleProcessRef = useRef(handleProcess);
    useEffect(() => {
        handleProcessRef.current = handleProcess;
    });
    useEffect(() => {
        if (autoRunStarted.current) return;
        if (!readSettled) return;
        // A failed read waits for the next successful revalidation instead of
        // deciding on the leftover cache value (see the docblock above).
        if (error || !status) return;
        autoRunStarted.current = true;
        if (status.file_count === 0 || !status.current_target_gallery) return;
        void handleProcessRef.current();
    }, [readSettled, error, status]);

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

    /**
     * Open and close the confirmation dialog for the camera password.
     *
     * The form is reset in both directions, and that is not tidiness: the field
     * holds the photographer's current camera password, and a value left in a
     * closed form is a secret that stays in the DOM until the next render
     * happens to drop it. Opening therefore starts from an empty field — a
     * photographer who opens the dialog a second time must not find the
     * previous attempt still there — and closing drops it again.
     */
    const openChangeDialog = () => {
        resetChangeForm();
        setShowChangeDialog(true);
    };

    const closeChangeDialog = () => {
        setShowChangeDialog(false);
        resetChangeForm();
    };

    /**
     * Change the camera password, confirmed by the current one (D-51).
     *
     * The new password is generated by the portal and shown exactly once, so
     * the success path is the same one-time box the reset uses — a second
     * implementation of that box would be a second copy of the "shown once"
     * warning, and the drift between the two is what the shared component
     * exists to prevent.
     *
     * A failure keeps the dialog open and shows the server's own message in the
     * toast. Two things follow from that, and both matter: a wrong current
     * password is a 422 and is corrected by typing a different one, so the
     * input has to survive the attempt; and no password may be shown after a
     * failure, because the server cannot promise that the old one is still
     * valid — a stale password in the DOM is what produces Error 41 in the
     * camera.
     */
    const handleChangePassword = async (values: ChangePasswordFormValues) => {
        try {
            const result = await changeCredentials(values.current_password);
            setNewPassword(result.password);
            setPasswordNotice(result.password_notice);
            closeChangeDialog();
        } catch (error) {
            setNewPassword(null);
            setPasswordNotice(null);
            showToast('error', error instanceof Error ? error.message : t`Das Kamera-Passwort konnte nicht geändert werden.`);
        }
    };

    /**
     * One reset per click, never a retry loop.
     *
     * The backend allows a fixed number of resets per hour and account
     * (`status.ftp_reset_limit_per_hour`, P1-M33). Retrying on a 429, or
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
            // The reset is also the recovery path inside the change dialog
            // (D-51). The one-time password belongs on the card, not behind a
            // dialog that would cover it — so the dialog closes with it.
            closeChangeDialog();
        } catch (error) {
            setNewPassword(null);
            setPasswordNotice(null);
            showToast('error', error instanceof Error ? error.message : t`Die Zugangsdaten konnten nicht erneuert werden.`);
        } finally {
            setResetting(false);
        }
    };

    /**
     * The one button on the account card — and the two things it can mean.
     *
     * An active account has a password, and that password is the confirmation
     * for changing it (D-51): the click opens the dialog instead of rotating
     * the credential on the spot. A click that silently invalidated a working
     * camera mid-shooting is exactly what the confirmation exists to prevent.
     *
     * Every other state keeps the old behaviour, because none of them has a
     * current password that could be confirmed: a `pending` account was never
     * provisioned (the click provisions it), and a `revoked` or `error` account
     * may not be re-issued at all — the reset refuses those two as well, and
     * refusing them here would only move the message from the server's wording
     * to a guess of our own.
     */
    const handleCredentialsButton = async () => {
        if (status.ftp_account_status === 'active') {
            openChangeDialog();
            return;
        }
        await handleResetCredentials();
    };

    const quarantineCount = status.quarantine_count;
    const uploadPath = status.connection.path;
    const inboxPath = uploadPath === null || uploadPath === '/'
        ? status.ftp_folder
        : `${status.ftp_folder}${uploadPath}`;
    const quarantinePath = `${inboxPath}/_quarantine`;
    const quarantineTitle = quarantineCount === 1
        ? t`1 Datei liegt im Quarantäne-Ordner`
        : t`${quarantineCount} Dateien liegen im Quarantäne-Ordner`;

    return (
        <div className="card bg-base-200 border border-base-300 mb-8">
            <div className="card-body">
                <h2 className="card-title text-2xl flex items-center gap-2">
                    <span className="iconify mdi--folder-download text-primary"></span> <Trans>FTP Inbox</Trans>
                </h2>

                <div className="flex gap-4 items-center bg-base-100 p-4 rounded-box mt-2">
                    <div className="flex-1 min-w-0">
                        <p className="text-sm opacity-70"><Trans>Dein Upload-Ordner</Trans></p>
                        {/* Der Ordner ist ein ohne Leerzeichen path, der nur mitten im
                            Token umbrechen kann. `break-all` macht das vorhersagbar —
                            sonst stapelt ein langer Slug die Karte auf Mobile. */}
                        <code className="font-bold font-mono text-base md:text-lg break-all">{status.ftp_folder}</code>
                    </div>
                    <div className="text-right">
                        <p className="text-sm opacity-70"><Trans>Bilder in der Warteschlange</Trans></p>
                        <p className={`text-2xl font-bold ${status.file_count > 0 ? 'text-warning' : 'text-success'}`} data-testid="ftp-queue-count">
                            {status.file_count}
                        </p>
                    </div>
                </div>

                {quarantineCount > 0 && (
                    <div className="alert alert-warning shadow-sm mt-2" role="alert">
                        <span className="iconify mdi--alert-outline text-xl" aria-hidden="true"></span>
                        <div>
                            <h3 className="font-bold">{quarantineTitle}</h3>
                            <p className="text-sm">
                                <Trans>Per SFTP findest du die Dateien hier:</Trans>{' '}
                                <code className="break-all">{quarantinePath}</code>.{' '}
                                <Trans>Sie bleiben erhalten und werden nicht gelöscht.</Trans>
                            </p>
                        </div>
                    </div>
                )}

                <div className="divider my-2"><Trans>Kamera-Verbindung</Trans></div>

                {/* The connection values are reference material, not landing-page
                    content: the photographer opens them when the camera asks for
                    them, and the card stays scannable for the queue, the account
                    and the target gallery. Both actions stay on the card — the
                    unconfigured notice names „Anleitung öffnen" as the way to
                    the guide, so that button has to be reachable without opening
                    a dialog first. `flex-wrap` keeps the two long German labels
                    from overflowing a phone (D-9). */}
                <div className="flex flex-wrap gap-2 mt-3">
                    <button
                        type="button"
                        onClick={() => setShowConnection(true)}
                        data-testid="ftp-connection-button"
                        className="btn btn-sm btn-outline"
                    >
                        <span className="iconify mdi--information-outline text-lg"></span> <Trans>Verbindungsinformationen anzeigen</Trans>
                    </button>
                    <button
                        type="button"
                        onClick={() => setShowGuide(true)}
                        className="btn btn-sm btn-outline"
                    >
                        <span className="iconify mdi--book-open-variant text-lg"></span> <Trans>Anleitung öffnen</Trans>
                    </button>
                </div>

                {showConnection && (
                    // Same data source and same branch as the inline block this
                    // replaced — only the place changed. `testId` addresses the
                    // box the shell owns (D-18). `maxWidth="2xl"` is the wide end
                    // of the two-state width prop (D-13), and the value column
                    // needs it: host, port range and path are single unbreakable
                    // mono tokens. No `autoFocus` inside — a dialog that only
                    // shows values starts on the shell's labelled close button
                    // (D-12).
                    <ModalShell
                        title={<Trans>Kamera-Verbindung</Trans>}
                        icon="mdi--server-network-outline"
                        onClose={() => setShowConnection(false)}
                        maxWidth="2xl"
                        testId="ftp-connection-dialog"
                    >
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
                    </ModalShell>
                )}

                {showGuide && (
                    // Largest available dialog with a scrollable body: the guide is
                    // long (six sections plus tables) and must not push its close
                    // button off screen. The content is rendered from the status the
                    // inbox already holds, so the hook stays in this one component.
                    <ModalShell
                        title={<Trans>Kamera einrichten</Trans>}
                        icon="mdi--camera"
                        onClose={() => setShowGuide(false)}
                        boxClassName="w-11/12 max-w-5xl"
                        // `scroll-fade-bottom` fades the last 2rem so the cut of the
                        // scroll region reads as scrollable instead of broken, and
                        // `pb-8` (exactly 2rem) keeps that band clear of the closing
                        // alert. Both travel with the scroll port: the region that
                        // scrolls is the one the shell builds, so the mask has to sit
                        // there and not on the box that merely bounds it — on the box
                        // it would fade nothing. This dialog has no footer, so the
                        // band can sit directly at the end without hiding anything
                        // below.
                        bodyClassName="scroll-fade-bottom pb-8"
                        scrollableBody
                    >
                        <KameraEinrichtungContent connection={status.connection} resetLimitPerHour={status.ftp_reset_limit_per_hour} />
                    </ModalShell>
                )}

                <div className="divider my-2"><Trans>Kamera-Konto</Trans></div>

                <div>
                    {/* Label and action share one line: the label reads as the row's
                        title, so the button belongs on that line. `items-center`
                        keeps them aligned, and only the status block sits below —
                        rendering the action against the tall warning instead is what
                        floated it vertically in the UI review. */}
                    <div className="flex flex-wrap gap-3 items-center justify-between">
                        <p className="text-sm opacity-70"><Trans>Zugangsdaten für die Kamera</Trans></p>
                        <button onClick={handleCredentialsButton} disabled={resetting} data-testid="ftp-credentials-button" className="btn btn-primary">
                            {resetting
                                ? <span className="loading loading-spinner loading-sm"></span>
                                : <span className="iconify mdi--key-outline text-lg"></span>}
                            {/* Bei `pending` existiert noch kein Konto — dort ist die
                                Aktion eine Anlage, keine Rotation. Der Text muss das
                                sagen, sonst suggeriert er einen Reset, den es nicht
                                gibt, und der Fotograf sucht vergeblich nach dem
                                Passwort, das noch nie erzeugt wurde. */}
                            {status.ftp_account_status === 'pending'
                                ? <Trans>Kamera-Zugang einrichten</Trans>
                                : <Trans>Neues Kamera-Passwort</Trans>}
                        </button>
                    </div>
                    <AccountStatus status={status.ftp_account_status} error={status.ftp_account_error} />
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

                {showChangeDialog && (
                    // The confirmation for the camera password (D-51). The shell
                    // owns the landmark, the accessible name and the close
                    // button; `testId` addresses the box for the E2E assertions.
                    // `noValidate` hands the empty-field case to the Zod schema,
                    // so the photographer gets the translated message instead of
                    // a browser tooltip in the browser's language — the
                    // `required` attribute still marks the field as mandatory
                    // and carries the star in the label.
                    <ModalShell
                        title={<Trans>Kamera-Passwort ändern</Trans>}
                        icon="mdi--key-outline"
                        onClose={closeChangeDialog}
                        testId="ftp-change-password-dialog"
                        onFormSubmit={handleSubmit(handleChangePassword)}
                        noValidate
                        footer={
                            <div className="mt-6 flex flex-col sm:flex-row justify-end gap-3 w-full">
                                <button type="button" className="btn btn-ghost w-full sm:w-auto" onClick={closeChangeDialog}><Trans>Abbrechen</Trans></button>
                                <button
                                    type="submit"
                                    className="btn btn-primary w-full sm:w-auto"
                                    disabled={isChanging || resetting}
                                    data-testid="ftp-change-submit"
                                >
                                    {isChanging ? <span className="loading loading-spinner"></span> : <Trans>Passwort ändern</Trans>}
                                </button>
                            </div>
                        }
                    >
                        <p className="text-sm opacity-70 mb-4">
                            <Trans>Das aktuelle Kamera-Passwort bestätigt die Änderung. Danach wird ein neues
                                Kamera-Passwort erzeugt und genau einmal angezeigt — die Kamera muss mit dem neuen
                                Passwort neu konfiguriert werden.</Trans>
                        </p>

                        <div className="form-control mb-4">
                            <label className="label" htmlFor={currentPasswordInputId}>
                                <span className="label-text font-bold"><Trans>Aktuelles Kamera-Passwort</Trans></span>
                            </label>
                            <input
                                id={currentPasswordInputId}
                                type="password"
                                required
                                autoComplete="current-password"
                                data-testid="ftp-change-current-password"
                                {...register('current_password')}
                                className={`input input-bordered w-full ${changeErrors.current_password ? 'input-error' : ''}`}
                            />
                            {changeErrors.current_password && (
                                <p className="text-sm text-error mt-1">{changeErrors.current_password.message}</p>
                            )}
                        </div>

                        <div className="divider my-2"><Trans>Passwort nicht mehr bekannt?</Trans></div>

                        <p className="text-sm opacity-70 mb-2">
                            <Trans>Ohne das aktuelle Passwort kannst du das Kamera-Passwort zurücksetzen. Das alte
                                Passwort wird dabei sofort ungültig — die Kamera muss danach neu konfiguriert
                                werden.</Trans>
                        </p>

                        {/* The recovery path, and deliberately the weaker one: a
                            link, not a button of its own, and below the
                            confirmation instead of beside it. It is the way out
                            for a password that is lost — which the show-once
                            contract makes a normal case — and it kills the old
                            password on the spot, so it is never the default. */}
                        <button
                            type="button"
                            className="btn btn-sm btn-ghost underline px-0"
                            onClick={handleResetCredentials}
                            disabled={resetting}
                            data-testid="ftp-change-recovery"
                        >
                            {resetting
                                ? <span className="loading loading-spinner loading-sm"></span>
                                : <span className="iconify mdi--restart text-lg"></span>}
                            <Trans>Passwort zurücksetzen</Trans>
                        </button>
                    </ModalShell>
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
