import { t } from "@lingui/core/macro";
import { Trans } from "@lingui/react/macro";
import {Link} from 'react-router-dom';
import {useFtp} from '../logic/useFtp';
import {FtpConnectionBlock} from './management/FtpConnectionRows';

/**
 * The camera setup, in the order it has to be done.
 *
 * Every value a camera needs is rendered from `status.connection` — the same
 * response the inbox card reads — instead of being written out here. A page that
 * carried its own copy of host, ports and the TLS mode is a page that can be
 * right while the server is configured differently, and the photographer has no
 * way to tell which one is lying. The Canon menu labels below are the opposite
 * case: they are product strings from the manual, fixed for a given camera body,
 * so they are literal and do not vary with the deployment.
 *
 * The root-certificate procedure is deliberately absent. It is a fallback for
 * when `Vertrauenswürdige Zielserver` does not work, and it is only worth
 * freezing into a procedure once that has actually happened.
 */
export default function KameraEinrichtung() {
    const {status, isLoading} = useFtp();

    if (isLoading || !status) {
        return <div className="p-4"><span className="loading loading-spinner"></span></div>;
    }

    const {host, username, sftp_port, ftps_port, pasv_port_start, pasv_port_end, ftps_tls_mode, path} = status.connection;
    const missing = t`nicht konfiguriert`;
    const serverValue = host ?? missing;
    const userValue = username ?? missing;
    const sftpValue = sftp_port !== null ? String(sftp_port) : missing;
    const ftpsValue = ftps_port !== null ? String(ftps_port) : missing;
    const pasvValue = pasv_port_start !== null && pasv_port_end !== null ? `${pasv_port_start}\u2013${pasv_port_end}` : missing;

    // Canons Assistent bietet neben `Stammverzeichnis` ein eigenes `Ordner
    // wählen`. Der Zielordner kommt deshalb aus der Verbindung statt aus diesem
    // Text: eine Anleitung, die „Stammverzeichnis" sagt, während der Import
    // einen Unterordner liest, schickt den Upload an eine Stelle, die niemand
    // importiert — und das sieht wie ein leerer Posteingang aus.
    const targetFolder = path === null
        ? missing
        : path === '/'
            ? <Trans>Stammverzeichnis</Trans>
            : <><Trans>Ordner wählen</Trans>: <code className="font-mono">{path}</code></>;

    const menuSteps = [
        {step: '1\u20132', menu: 'Kommunikationsfunktionen → Bilder zum FTP-Server übertr. → OK', value: <Trans>Netzwerk ggf. erst aktivieren</Trans>},
        {step: '3\u20134', menu: 'Gerät für Verbindung hinzufügen', value: <Trans>Neue Einstellungen</Trans>},
        {step: '5\u20136', menu: 'Kommunikationsart', value: <Trans>Dein WLAN; bei einem Fehlschlag „Offline konfigurieren" wählen</Trans>},
        {step: '7', menu: 'SET', value: <Trans>Einstellungen speichern</Trans>},
        {step: '8', menu: 'FTP-Modus', value: <Trans>SFTP (empfohlen) oder FTPS</Trans>, emphasis: true},
        {step: '9', menu: 'Adressen-Einstellung', value: <code className="font-mono">{serverValue}</code>},
        {step: '9', menu: 'Portnummerneinstellung', value: <><code className="font-mono">{sftpValue}</code> / <code className="font-mono">{ftpsValue}</code></>},
        {step: '10', menu: 'Benutzername, Kennwort (nur SFTP)', value: <><code className="font-mono">{userValue}</code> + das einmal angezeigte Passwort</>},
        {step: '11', menu: 'Passiver Modus (nur FTP/FTPS)', value: <Trans>Aktivieren</Trans>, emphasis: true},
        {step: '13', menu: 'Anmeldekennwort (nur FTP/FTPS)', value: <Trans>das einmal angezeigte Passwort</Trans>},
        {step: '14', menu: 'Zielordner', value: targetFolder, emphasis: true},
        {step: '15', menu: 'SET', value: <Trans>speichern; bei Rückfrage OK → Zielserver vertrauen</Trans>},
    ];

    const errorRows = [
        {
            message: <><strong>Error 41</strong> \u2014 keine Verbindung zum FTP-Server</>,
            meaning: <Trans>TCP kommt nicht durch</Trans>,
            suspect: <Trans>Firewall für {ftpsValue} bzw. {sftpValue}; Passiver Modus auf Aktivieren</Trans>,
        },
        {
            message: <><strong>Error 48</strong></>,
            meaning: <Trans>TLS-Vertrauen</Trans>,
            suspect: <Trans>zuerst „Vertrauenswürdige Zielserver" auf Aktivieren</Trans>,
        },
        {
            message: <><strong>Error 48</strong> \u2014 bleibt bestehen</>,
            meaning: <Trans>die Kamera verlangt ein Stammzertifikat</Trans>,
            suspect: <Trans>siehe den Hinweis zum Stammzertifikat unten</Trans>,
        },
        {
            message: <Trans>Verbindung ok, aber 0 Bilder in der Warteschlange</Trans>,
            meaning: <Trans>Der Upload kam nicht an</Trans>,
            suspect: <Trans>der SFTP-Login schlägt still fehl: das Konto steht noch auf pending</Trans>,
        },
        {
            message: <Trans>Upload läuft, aber Import 0 Bilder</Trans>,
            meaning: <Trans>Die Datei liegt in einem Unterordner</Trans>,
            suspect: <Trans>„Verzeichnisstruktur" steht auf Kamera statt Standard</Trans>,
        },
        {
            message: <Trans>TLS-Handshake bricht mit „wrong version number" ab</Trans>,
            meaning: <Trans>Der Client erwartet implizites TLS</Trans>,
            suspect: ftps_tls_mode === 'implicit'
                ? <Trans>Der Server ist auf implizites TLS konfiguriert — in der Kamera FTPS wählen</Trans>
                : <Trans>Der Server ist auf explizites TLS konfiguriert — in der Kamera SFTP wählen oder einen Client mit AUTH TLS benutzen</Trans>,
        },
    ];

    return (
        // This route renders outside DashboardLayout, so the page has to own its
        // `main` landmark — without one there is no landmark to scope locators to
        // on the one page a photographer follows step by step.
        <main className="p-6 md:p-10 max-w-5xl mx-auto w-full">
            <div className="flex flex-wrap items-center gap-3 mb-6">
                <Link to="/" className="btn btn-sm btn-ghost">
                    <span className="iconify mdi--arrow-left text-lg"></span> <Trans>Zurück zum FTP Inbox</Trans>
                </Link>
            </div>

            <h1 className="text-3xl md:text-4xl font-bold mb-2 flex items-center gap-3">
                <span className="iconify mdi--camera text-primary"></span>
                <Trans>Kamera einrichten</Trans>
            </h1>
            <p className="opacity-70 mb-8">
                <Trans>Canon EOS R1 / R6 Mark II → Portal. Menüpfade und Nummerierung aus dem Canon-Handbuch,
                    Abschnitt „Übertragen von Bildern auf einen FTP-Server".</Trans>
            </p>

            <div className="card bg-base-200 border border-base-300 mb-6">
                <div className="card-body">
                    <h2 className="card-title text-xl"><Trans>1. Das Konto anlegen — es gibt noch keins</Trans></h2>
                    <p className="text-sm">
                        <Trans>Vor dem Test existiert bewusst kein FTP-Konto. Dein FTP-Ordner-Slug ist nur der
                            Name; in SFTPGo wird nichts angelegt, bis die Zugangsdaten angefordert werden. Ein Konto
                            vorab zu provisionieren hieße, Zugangsdaten zu erzeugen, die niemand kennt.</Trans>
                    </p>
                    <ol className="list-decimal list-inside space-y-2 text-sm">
                        <li><Trans>Prüfe deinen FTP-Ordner-Slug im Profil — er ist der Benutzername der Kamera.
                            Eine Änderung ist per Definition ein Reset: alter Account weg, neuer Account, neues
                            Passwort.</Trans></li>
                        <li><Trans>Fordere die Zugangsdaten an: im Dashboard unter
                            <strong> FTP Inbox → Kamera-Konto</strong> auf
                            <strong> „Neues Kamera-Passwort"</strong> klicken. Es sind drei Anforderungen pro Stunde
                            möglich.</Trans></li>
                        <li><Trans>Notiere das Passwort sofort. Es wird genau einmal angezeigt, nicht gespeichert
                            und nicht wiederherstellbar. Bei Verlust gibt es nur ein neues, und das alte entfällt.</Trans></li>
                    </ol>

                    <div className="divider my-2"><Trans>Die Werte für die Kamera</Trans></div>
                    <FtpConnectionBlock connection={status.connection} />
                    {status.connection.configured && (
                        <p className="text-sm opacity-70 mt-2"><Trans>Reihenfolge nicht umstellen: erst Konto und
                            Passwort, dann Kamera. Wer zuerst die Kamera konfiguriert, hat noch kein gültiges
                            Passwort und wundert sich über Error 41.</Trans></p>
                    )}
                </div>
            </div>

            <div className="card bg-base-200 border border-base-300 mb-6">
                <div className="card-body">
                    <h2 className="card-title text-xl"><Trans>2. Verbindung in der Kamera einrichten</Trans></h2>
                    <div className="overflow-x-auto bg-base-100 rounded-box border border-base-300 shadow-sm">
                        <table className="table table-zebra w-full">
                            <thead>
                                <tr>
                                    <th><Trans>Schritt</Trans></th>
                                    <th><Trans>Aktion</Trans></th>
                                    <th><Trans>Wert</Trans></th>
                                </tr>
                            </thead>
                            <tbody>
                                {menuSteps.map((row, index) => (
                                    <tr key={index}>
                                        <td className="whitespace-nowrap font-bold">{row.step}</td>
                                        <td className={row.emphasis ? 'font-bold' : undefined}><code className="font-mono">{row.menu}</code></td>
                                        <td className="text-sm">{row.value}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <div className="alert alert-info shadow-sm mt-4">
                        <span className="iconify mdi--shield-check-outline text-xl"></span>
                        <div>
                            <h3 className="font-bold"><Trans>FTPS: „Vertrauenswürdige Zielserver" aktivieren</Trans></h3>
                            <p className="text-sm"><Trans>Das Zertifikat des Servers ist selbstsigniert, die Kamera kann seine
                                Vertrauenswürdigkeit deshalb nicht prüfen. Der dafür vorgesehene Schalter liegt nicht
                                im FTP-Konfigurationsfluss, sondern eine Ebene tiefer: Gerät für Verbindung bearbeiten →
                                Gerät wählen → FTP-Server → Vertrauenswürdige Zielserver → Aktivieren.</Trans></p>
                            <p className="text-sm mt-1"><Trans>Das ist der erste Weg. Das Stammzertifikat ist der Fallback,
                                falls die Kamera mit dieser Einstellung keine Verbindung aufnimmt.</Trans></p>
                        </div>
                    </div>

                    <div className="alert alert-info shadow-sm mt-2">
                        <span className="iconify mdi--lock text-xl"></span>
                        <div>
                            <h3 className="font-bold"><Trans>Der FTPS-Verschlüsselungsmodus</Trans></h3>
                            <p className="text-sm"><Trans>Der passive Bereich <code
                                className="font-mono">{pasvValue}</code> muss in der Firewall freigeschaltet sein — er
                                wird nur von FTPS benötigt, SFTP kommt mit einem einzigen Port aus.</Trans></p>
                            {ftps_tls_mode === 'explicit' && (
                                <p className="text-sm"><Trans>Der Server ist auf explizites TLS konfiguriert (AUTH TLS). In der
                                    Kamera ist dafür nichts extra einzustellen — der Modus FTPS der Kamera macht genau
                                    das. Ein Klartext-Greeting beim Testen des Servers ist dieses Verfahren und kein
                                    Fehler.</Trans></p>
                            )}
                            {ftps_tls_mode === 'implicit' && (
                                <p className="text-sm"><Trans>Der Server ist auf implizites TLS konfiguriert. Wähle in der
                                    Kamera FTPS; ein Klartext-Greeting beim Testen des Servers ist in diesem Modus ein
                                    Fehlerzeichen.</Trans></p>
                            )}
                            {ftps_tls_mode === null && (
                                <p className="text-sm"><Trans>Für FTPS ist kein Verschlüsselungsmodus hinterlegt. Frage den
                                    Support nach der Einrichtung, bevor du FTPS verwendest.</Trans></p>
                            )}
                        </div>
                    </div>

                    <div className="alert alert-warning shadow-sm mt-2">
                        <span className="iconify mdi--folder-alert-outline text-xl"></span>
                        <div>
                            <h3 className="font-bold"><Trans>„Verzeichnisstruktur: Standard" ist zwingend</Trans></h3>
                            <p className="text-sm"><Trans>Ebenfalls unter Gerät für Verbindung bearbeiten → FTP-Server. Nimm
                                Standard. Der Import im Portal liest die Inbox nicht rekursiv; die Kamera legt auf dem
                                Server A/DCIM/100EOSR1/… an, und diese Dateien landen in einem Unterordner, den der
                                Import nicht sieht. Das Ergebnis ist: Upload erfolgreich, Import 0 Bilder.</Trans></p>
                        </div>
                    </div>
                </div>
            </div>

            <div className="card bg-base-200 border border-base-300 mb-6">
                <div className="card-body">
                    <h2 className="card-title text-xl"><Trans>3. Übertragen und importieren</Trans></h2>
                    <ol className="list-decimal list-inside space-y-2 text-sm">
                        <li><Trans>Bild auswählen: Bildauswahl/übertr. → Bild markieren → MENU → Übertrag. → OK.
                            Oder FTP-Übertragungseinstellungen → Autom. Übertragung → Aktivieren, dann geht jedes Bild
                            direkt nach der Aufnahme raus.</Trans></li>
                        <li><Trans>Prüfe, dass die Datei angekommen ist: die Anzahl unter „Bilder in der Warteschlange"
                            muss hochgehen. Sonst ist der Upload gescheitert — nicht der Import.</Trans></li>
                        <li><Trans>Ziel-Galerie setzen, dann „Bilder Importieren". Die Datei wird erst nach erfolgreicher
                            Verarbeitung und erst nach dem Datenbankeintrag gelöscht. Bricht der Import ab, bleibt sie
                            liegen und der nächste Versuch sieht sie wieder.</Trans></li>
                    </ol>
                </div>
            </div>

            <div className="card bg-base-200 border border-base-300 mb-6">
                <div className="card-body">
                    <h2 className="card-title text-xl"><Trans>4. Drei stille Fehler</Trans></h2>
                    <p className="text-sm opacity-70"><Trans>Diese drei Fälle erzeugen keine Fehlermeldung in der Kamera.
                        Sie sehen nur nach Erfolg aus und kosten trotzdem den ganzen Test.</Trans></p>

                    <div className="alert alert-warning shadow-sm mt-2">
                        <span className="iconify mdi--file-alert-outline text-xl"></span>
                        <div>
                            <h3 className="font-bold"><Trans>Stammzertifikat: Dateiname muss ROOT.CER, ROOT.CRT oder ROOT.PEM sein</Trans></h3>
                            <p className="text-sm"><Trans>Die Kamera importiert ausschließlich eine Stammzertifikatsdatei mit
                                genau diesen Dateinamen. Ein beliebig benanntes cert.pem wird übersprungen, ohne jede
                                Fehlermeldung. Es kann nur ein Zertifikat importiert werden, und die Karte muss vorher
                                eingesteckt sein.</Trans></p>
                        </div>
                    </div>

                    <div className="alert alert-warning shadow-sm mt-2">
                        <span className="iconify mdi--folder-alert-outline text-xl"></span>
                        <div>
                            <h3 className="font-bold"><Trans>Verzeichnisstruktur steht auf „Kamera" statt „Standard"</Trans></h3>
                            <p className="text-sm"><Trans>Der Upload funktioniert, aber der Import findet nichts, weil die
                                Datei in einem Unterordner liegt. Siehe Schritt 14.</Trans></p>
                        </div>
                    </div>

                    <div className="alert alert-warning shadow-sm mt-2">
                        <span className="iconify mdi--account-clock-outline text-xl"></span>
                        <div>
                            <h3 className="font-bold"><Trans>Das Konto steht noch auf „pending"</Trans></h3>
                            <p className="text-sm"><Trans>Dann scheitert jede Anmeldung der Kamera, unabhängig vom
                                Passwort. Warte, bis unter FTP Inbox → Kamera-Konto „Konto aktiv" steht.</Trans></p>
                        </div>
                    </div>
                </div>
            </div>

            <div className="card bg-base-200 border border-base-300 mb-6">
                <div className="card-body">
                    <h2 className="card-title text-xl"><Trans>5. Wenn es nicht klappt</Trans></h2>
                    <div className="overflow-x-auto bg-base-100 rounded-box border border-base-300 shadow-sm">
                        <table className="table table-zebra w-full">
                            <thead>
                                <tr>
                                    <th><Trans>Meldung</Trans></th>
                                    <th><Trans>Bedeutung</Trans></th>
                                    <th><Trans>Erste Verdachtsstelle</Trans></th>
                                </tr>
                            </thead>
                            <tbody>
                                {errorRows.map((row, index) => (
                                    <tr key={index}>
                                        <td className="text-sm">{row.message}</td>
                                        <td className="text-sm">{row.meaning}</td>
                                        <td className="text-sm">{row.suspect}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div className="card bg-base-200 border border-base-300 mb-6">
                <div className="card-body">
                    <h2 className="card-title text-xl"><Trans>6. SFTP zuerst probieren</Trans></h2>
                    <p className="text-sm"><Trans>Beide Protokolle sind eingerichtet. SFTP ist der einfachere Weg: ein
                        Port, kein Zertifikat, keine passive Port-Range. FTPS ist der Rückweg für Kameras, die SFTP
                        nicht sprechen — und der braucht „Vertrauenswürdige Zielserver" und den Passiven Modus.</Trans></p>
                    <p className="text-sm"><Trans>Beide Ports bleiben offen. Es kostet nichts, und ein nicht vorhersehbarer
                        Kameratyp soll den Umstieg nicht blockieren.</Trans></p>
                </div>
            </div>

            <div className="alert shadow-sm">
                <span className="iconify mdi--information-outline text-xl"></span>
                <div>
                    <h3 className="font-bold"><Trans>Zum Stammzertifikat</Trans></h3>
                    <p className="text-sm"><Trans>Es gibt einen Fallback über ein Stammzertifikat für Kameras, die mit
                        „Vertrauenswürdige Zielserver" keine Verbindung aufnehmen. Die Vorgehensweise dafür ist bewusst
                        noch nicht festgelegt und wird erst dokumentiert, wenn der einfache Weg nachweislich nicht
                        trägt — frag den Support danach.</Trans></p>
                </div>
            </div>
        </main>
    );
}
