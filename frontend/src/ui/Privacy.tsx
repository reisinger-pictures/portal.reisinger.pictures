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
                        <Trans>Stripe ist der eingesetzte Zahlungsdienstleister und Zahlungsprozessor für die sofortige Kartenzahlung. Die für die Zahlung erforderlichen Bestell- und Zahlungsdaten sowie von Stripe verarbeitete Geräte-, Browser-, Netzwerk- und Aktivitätssignale werden für die Zahlungsabwicklung, 3D-Secure und die Betrugs- und Risikoerkennung genutzt. Die weitere Verarbeitung durch Stripe richtet sich nach den Datenschutzhinweisen von Stripe.</Trans>
                    </p>
                    <p>
                        <Trans>Zur eindeutigen Zuordnung speichern wir die Stripe Customer-ID des Portalkontos und die PaymentIntent-ID des Zahlungsvorgangs. Diese Kennungen sind Zahlungs- und Kontrollinformationen, keine Kartendaten. Für die technische Absicherung wird außerdem der Checkout-Fingerprint-Hash aus dem serverseitig kanonisierten Checkout-Kontext verwendet; er ist kein Browser-Fingerprint und wird nicht für seitenübergreifendes Tracking verwendet.</Trans>
                    </p>
                    <p>
                        <Trans>Bei fehlgeschlagenen Zahlungen verarbeiten wir begrenzte PaymentIntent-Fehler-/Decline-Telemetrie: eine begrenzte Anzahl von Fehlversuchen, den Zeitpunkt des letzten Fehlers und einen bereinigten, gekürzten Decline-Code. Zur Vermeidung doppelter Verarbeitung wird anhand der Stripe-Event-ID eine kurzlebige technische Event-ID-Deduplizierung durchgeführt.</Trans>
                    </p>
                    <p>
                        <Trans>Im Portal speichern wir keine rohen Stripe-Fehlerobjekte, Kartendaten wie PAN oder CVC, Client-Secrets oder den vollständigen Zahlungsdatensatz. Die genannten Daten werden für die Zahlungsabwicklung, die Erkennung und Begrenzung automatisierter Kartentests und die Nachvollziehbarkeit von Transaktionen verarbeitet. Die jeweils zutreffende Rechtsgrundlage und die Aufbewahrung ergeben sich je nach Datenkategorie aus der geltenden Datenschutz- und Aufbewahrungsrichtlinie sowie den einschlägigen rechtlichen, steuerlichen und abrechnungsbezogenen Pflichten.</Trans>
                    </p>
                    <p>
                        <Trans>Für die Wiederaufnahme eines Checkout-Vorgangs speichert Ihr Browser bis zum Ende der Sitzung eine zufällige Checkout-Kennung und eine verkürzte Warenkorb-Markierung im Sitzungsspeicher. Diese Angaben enthalten weder Kartendaten noch ein Stripe Client Secret oder eine PaymentIntent-ID und werden nach Abschluss, Abmeldung oder Zurücksetzen des Warenkorbs gelöscht.</Trans>
                    </p>

                    <h2 className="text-2xl font-bold mt-8 mb-4"><Trans>5. Cloudflare Turnstile</Trans></h2>
                    <p>
                        <Trans>Wenn Cloudflare Turnstile für das Portal konfiguriert ist, kann bei einem erhöhten Risiko eine zusätzliche Sicherheitsprüfung angezeigt werden. Cloudflare ist dabei der eingesetzte Sicherheitsprozessor. Cloudflare verarbeitet technisch notwendige Geräte- und Aktivitätssignale; je nach Konfiguration können hierfür Cookies oder vergleichbare Technologien verwendet werden.</Trans>
                    </p>
                    <p>
                        <Trans>Das einmalig erzeugte Prüftoken wird zur serverseitigen Siteverify-Prüfung an Cloudflare übermittelt. Die Antwort wird auf die Action "checkout", den Nutzerbezug (cData) und den zugelassenen Hostname geprüft; die Remote-IP wird dabei, sofern verfügbar, als "remoteip" an Cloudflare übermittelt. Das Token ist nur einmal gültig; das Token und die vollständige Siteverify-Antwort werden von uns nicht gespeichert.</Trans>
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
                </div>
            </div>
        </PageLayout>
    );
}
