import { Trans } from "@lingui/react/macro";
import { Link } from 'react-router-dom';
import PageLayout from './components/PageLayout';

export default function Privacy() {
    return (
        <PageLayout>
            <div className="container mx-auto p-8 max-w-4xl">
                <h1 className="text-4xl font-bold mb-8"><Trans>Datenschutzerklärung</Trans></h1>

                <div className="prose prose-base max-w-none">
                    <p className="lead">
                        <Trans>Der Schutz Ihrer Daten ist uns wichtig. Nachfolgend informieren wir Sie über die Verarbeitung personenbezogener Daten bei der Nutzung dieses Foto-Portals.</Trans>
                    </p>

                    <h2 className="text-2xl font-bold mt-8 mb-4"><Trans>1. IP-Adressen und technische Protokolle</Trans></h2>
                    <p>
                        <Trans>Beim Checkout speichern wir die zum Zeitpunkt der Bestellung ermittelte IP-Adresse zusammen mit der Bestellung. Sie wird für die Zahlungsabwicklung, die Betrugs- und Missbrauchsprävention sowie die Nachvollziehbarkeit der Transaktion verarbeitet. In den separaten Audit- und Download-Logs der Fotografen wird keine vollständige IP-Adresse gespeichert.</Trans>
                    </p>
                    <p>
                        <Trans>Für den Checkout-Missbrauchsschutz werden aus der IP-Adresse abgeleitete Risikoschlüssel (IP-Risikoschlüssel) zur Überwachung und Limitierung von Versuchen verwendet. Diese Schlüssel dienen nicht als seitenübergreifendes Browser-Fingerprinting. Webserver-Logs können technisch bedingt IP-Adressen enthalten.</Trans>
                    </p>
                    <p>
                        <Trans>Die IP-Risikoschlüssel werden als schlüsselabhängiger, nicht umkehrbarer Hash berechnet. In den Limitierungs- und Risikozählern wird ausschließlich der so abgeleitete Schlüssel geführt, nie die rohe IP-Adresse; der Schlüssel lässt sich nicht in eine IP-Adresse zurückrechnen.</Trans>
                    </p>
                    <p>
                        <Trans>Die Aufbewahrung der IP-bezogenen Bestell- und Sicherheitsdaten richtet sich je nach Datenkategorie nach der geltenden Aufbewahrungsrichtlinie sowie den einschlägigen rechtlichen, steuerlichen und abrechnungsbezogenen Pflichten. Die IP-Risikoschlüssel laufen mit dem jeweiligen konfigurierten Limitierungsfenster ab. Die konkrete Frist und die zugrunde liegende Zuordnung teilt der Betreiber auf Anfrage mit.</Trans>
                    </p>

                    <h2 className="text-2xl font-bold mt-8 mb-4"><Trans>2. Cookies und Authentifizierung</Trans></h2>
                    <p>
                        <Trans>Diese Plattform verwendet sogenannte "HttpOnly"-Cookies, um angemeldete Benutzer sicher zu authentifizieren. Diese Cookies speichern einen Authentifizierungs-Token (JWT) und sind für die technische Funktion des Portals (Zugriff auf private Galerien, Speichern von Bewertungen) zwingend erforderlich. Sie können nicht durch clientseitige Skripte (JavaScript) ausgelesen werden.</Trans>
                    </p>

                    <h2 className="text-2xl font-bold mt-8 mb-4"><Trans>3. Download-Tracking und Urheberrechtsschutz</Trans></h2>
                    <p>
                        <Trans>Wenn Sie Bilder aus unseren Galerien herunterladen, dokumentieren wir diesen Vorgang in einer internen Datenbank (Audit-Log), um den Zugriff auf unsere urheberrechtlich geschützten Werke nachvollziehen zu können. Dabei speichern wir Ihren Namen (sofern angegeben) und den Zeitpunkt des Downloads.</Trans>
                    </p>
                    <p>
                        <strong><Trans>Wichtiger Hinweis zu Metadaten:</Trans></strong> <Trans>Beim Download hochauflösender Bilder wird Ihr Name bzw. Ihre Kennung sowie ein Verweis auf unsere Nutzungsbedingungen technisch in die Metadaten (IPTC/EXIF) der Bilddatei eingebettet. Dies dient dem Schutz vor unautorisierter Weitergabe und Leaks.</Trans>
                    </p>

                    <h2 className="text-2xl font-bold mt-8 mb-4"><Trans>4. Zahlungsabwicklung und Betrugsprävention</Trans></h2>
                    <p>
                        <Trans>Für neue Online-Zahlungen ist die Mollie B.V. in Amsterdam (Niederlande) der eingesetzte Zahlungsdienstleister. Das Portal übermittelt an Mollie ausschließlich den Zahlungsbetrag, die Währung, eine Bestell- und Vorgangsreferenz, die gewählte Zahlungsart sowie die Rücksprung- und die Webhook-Adresse. Die Bestellreferenz ist die Kennung der Bestellung, die Vorgangsreferenz die laufende Nummer des Zahlungsversuchs; sie enthalten weder Name noch Adresse, Kartendaten oder Rechnungsdaten. Für die Zahlung wechselt der Kunde auf die von Mollie gehostete Checkout-Seite; Kartendaten und Rechnungsdaten werden dabei nicht über das Portal an Mollie übermittelt, sondern auf der Seite von Mollie eingegeben. Bei Zahlungsarten wie paybybank oder eps erfolgt die Freigabe bei der Bank des Kunden. Die weitere Verarbeitung durch Mollie richtet sich nach den Datenschutzhinweisen von Mollie.</Trans>
                    </p>
                    <p>
                        <Trans>Für jede über Mollie abgewickelte Zahlung speichern wir die von Mollie vergebene Zahlungs-ID (Payment-ID) des jeweiligen Zahlungsversuchs. Diese Kennung ist eine Zahlungs- und Kontrollinformation, keine Kartendaten; sie ordnet den Vorgang bei Mollie der Bestellung zu und dient der Nachvollziehbarkeit von Zahlung, Erstattung und Rückbuchung. Der geprüfte Zahlungsstatus wird erst übernommen, nachdem Betrag und Währung des bei Mollie abgerufenen Vorgangs mit der Bestellung übereinstimmen.</Trans>
                    </p>
                    <p>
                        <Trans>Für jeden Online-Zahlungsversuch speichern wir zusätzlich die laufende Nummer des Versuchs, den gewählten Zahlungsweg, den Betrag und die Währung des Vorgangs sowie den von Mollie gemeldeten Zahlungsstatus. Die Anzahl der Versuche je Bestellung ist begrenzt; einen bereits gemeldeten Status verarbeiten wir anhand der von Mollie vergebenen Zahlungs-ID nur einmal.</Trans>
                    </p>
                    <p>
                        <Trans>Bestellungen aus der Zeit vor der Umstellung auf Mollie wurden über den damaligen Zahlungsdienstleister abgewickelt; an ihn werden für neue Bestellungen keine Daten mehr übermittelt.</Trans>
                    </p>
                    <p>
                        <Trans>Für jede Bestellung speichern wir außerdem eine Zuordnung aus einem Checkout-Fingerprint-Hash und einer zufälligen Checkout-Kennung Ihres Browsers. Der Checkout-Fingerprint-Hash wird serverseitig aus dem kanonisierten Checkout-Kontext berechnet; er ist kein Browser-Fingerprint und wird nicht für seitenübergreifendes Tracking verwendet. Diese Zuordnung dient allein dazu, einen wiederholten Absendevorgang von einer bewusst neuen Bestellung zu unterscheiden.</Trans>
                    </p>
                    <p>
                        <Trans>Für eine sofortige Online-Zahlung prüft das Portal zusätzlich serverseitig das Erstellungsdatum Ihres Portalkontos sowie die Anzahl Ihrer Checkout-Versuche und der Versuche Ihrer IP-Adresse. Dafür werden Ihre Konto-ID, Ihr Kontoerstellungsdatum und der oben beschriebene IP-Risikoschlüssel verwendet; die Prüfung entscheidet, ob ein Zahlungsvorgang begonnen wird. Für Bestellungen per Rechnung, Lieferschein oder Angebot gilt diese Prüfung nicht.</Trans>
                    </p>
                    <p>
                        <Trans>Im Portal speichern wir keine Kartendaten wie PAN oder CVC und keinen vollständigen Zahlungsdatensatz des Anbieters. Kartendaten werden ausschließlich auf der Seite von Mollie eingegeben und erreichen das Portal nicht. Die genannten Daten werden für die Zahlungsabwicklung, die Erkennung und Begrenzung automatisierter Kartentests und die Nachvollziehbarkeit von Transaktionen verarbeitet. Die jeweils zutreffende Rechtsgrundlage und die Aufbewahrung ergeben sich je nach Datenkategorie aus der geltenden Datenschutz- und Aufbewahrungsrichtlinie sowie den einschlägigen rechtlichen, steuerlichen und abrechnungsbezogenen Pflichten.</Trans>
                    </p>
                    <p>
                        <Trans>Für die Wiederaufnahme eines Checkout-Vorgangs speichert Ihr Browser bis zum Ende der Sitzung eine zufällige Checkout-Kennung und eine verkürzte Warenkorb-Markierung im Sitzungsspeicher. Diese Angaben enthalten weder Kartendaten noch Zahlungsdaten des Zahlungsdienstleisters und werden nach Abschluss, Abmeldung oder Zurücksetzen des Warenkorbs gelöscht. Der Eintrag ist dem angemeldeten Portalkonto zugeordnet und wird ersetzt, sobald sich der Warenkorb ändert.</Trans>
                    </p>

                    <h2 className="text-2xl font-bold mt-8 mb-4"><Trans>5. Cloudflare Turnstile</Trans></h2>
                    <p>
                        <Trans>Wenn Cloudflare Turnstile für das Portal konfiguriert ist, kann bei einem erhöhten Risiko eine zusätzliche Sicherheitsprüfung angezeigt werden. Cloudflare ist dabei der eingesetzte Sicherheitsprozessor. Cloudflare verarbeitet technisch notwendige Geräte- und Aktivitätssignale; je nach Konfiguration können hierfür Cookies oder vergleichbare Technologien verwendet werden.</Trans>
                    </p>
                    <p>
                        <Trans>Das einmalig erzeugte Prüftoken wird zur serverseitigen Siteverify-Prüfung an Cloudflare übermittelt. Die Antwort wird auf die Action "checkout", den Nutzerbezug (cData) und den zugelassenen Hostname geprüft; die Remote-IP wird dabei, sofern verfügbar, als "remoteip" an Cloudflare übermittelt. Das Token ist nur einmal gültig; das Token und die vollständige Siteverify-Antwort werden von uns nicht gespeichert.</Trans>
                    </p>
                    <p>
                        <Trans>Weder das Prüftoken noch die Antwort der Prüfung und auch das Prüfergebnis selbst werden im Portal gespeichert. Ihre Warenkorb- und Bestelldaten werden dabei nicht an Cloudflare übermittelt; übermittelt werden das Token, ein serverseitiger Schlüssel und die IP-Adresse des Checkout-Vorgangs.</Trans>
                    </p>
                    <p>
                        <Trans>Ohne aktivierte Konfiguration wird Turnstile nicht geladen. Die Aufbewahrung von Turnstile-Signalen und technischen Protokolldaten richtet sich nach den beim Betreiber und bei Cloudflare geltenden Aufbewahrungsregeln; die konkreten Fristen teilt der Betreiber auf Anfrage mit.</Trans>
                    </p>

                    <h2 className="text-2xl font-bold mt-8 mb-4"><Trans>6. Ihre Rechte</Trans></h2>
                    <p>
                        <Trans>Sie haben das Recht auf Auskunft, Berichtigung, Löschung oder Einschränkung der Verarbeitung Ihrer gespeicherten Daten.</Trans>
                    </p>
                    <p>
                        <Trans>Fragen zu Zwecken, Rechtsgrundlagen, Aufbewahrung, Löschung oder Auskunft richten Sie bitte an den verantwortlichen Betreiber oder die zuständige Datenschutz-Kontaktperson.</Trans>{' '}
                        <Trans>Die Kontaktdaten finden Sie im</Trans>{' '}
                        <Link to="/impressum"><Trans>Impressum</Trans></Link>.
                    </p>
                    <p>
                        <Trans>Zu den in dieser Erklärung genannten Kennungen und Signalen sind die technischen Angaben vollständig beschrieben. Die zugehörigen Rechtsgrundlagen, die konkreten Aufbewahrungsfristen sowie die Regeln zu Löschung und Anonymisierung sind noch nicht abschließend festgelegt; der Betreiber teilt sie Ihnen auf Anfrage und mit der finalen Fassung dieser Erklärung mit.</Trans>
                    </p>
                </div>
            </div>
        </PageLayout>
    );
}
