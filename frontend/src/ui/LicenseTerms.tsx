import { Trans } from "@lingui/react/macro";
import { Link } from 'react-router-dom';
import PageLayout from './components/PageLayout';

export default function LicenseTerms() {
    return (
        <PageLayout>
            <div className="container mx-auto p-8 max-w-4xl">
                <h1 className="text-4xl font-bold mb-8"><Trans>AGB &amp; Lizenzbedingungen</Trans></h1>

                <div className="prose prose-base max-w-none">
                    <p className="lead">
                        <Trans>Diese Allgemeinen Geschäftsbedingungen (AGB) und Lizenzbedingungen gelten für sämtliche über dieses Portal angebotenen Fotoprodukte und digitalen Inhalte (im Folgenden „Download-Dateien“) sowie für alle Verträge, die über dieses Portal geschlossen werden.</Trans>
                    </p>

                    <h2 className="text-2xl font-bold mt-8 mb-4"><Trans>1. Geltungsbereich</Trans></h2>
                    <p>
                        <Trans>Diese Bedingungen regeln das Vertragsverhältnis zwischen dem Betreiber dieses Portals (im Folgenden „Fotograf“) und dem Kunden bei der Bestellung von Bildern, Lizenzpaketen und sonstigen digitalen Inhalten. Entgegenstehende oder abweichende Bedingungen des Kunden werden nicht anerkannt, sofern der Fotograf ihrer Geltung nicht ausdrücklich schriftlich zugestimmt hat.</Trans>
                    </p>

                    <h2 className="text-2xl font-bold mt-8 mb-4"><Trans>2. Vertragsgegenstand</Trans></h2>
                    <p>
                        <Trans>Vertragsgegenstand ist die Übertragung einfacher, nicht ausschließlicher Nutzungsrechte an fotografischen Werken (Lizenzierung) sowie die Bereitstellung der bestellten digitalen Dateien zum Download. Anzahl, Auflösung und Lizenzumfang der gelieferten Dateien ergeben sich aus der jeweiligen Bestellung und den auf dem Portal angegebenen Lizenzbedingungen.</Trans>
                    </p>

                    <h2 className="text-2xl font-bold mt-8 mb-4"><Trans>3. Zustandekommen des Vertrages</Trans></h2>
                    <p>
                        <Trans>Die Darstellung der Produkte im Portal stellt kein bindendes Angebot dar. Mit dem Klicken auf „Zahlungspflichtig bestellen“ gibt der Kunde ein bindendes Angebot zum Abschluss des Vertrages ab. Der Vertrag kommt mit der Bestätigung der Bestellung durch den Fotografen bzw. mit der Aussendung der Bestellbestätigung zustande.</Trans>
                    </p>

                    <h2 className="text-2xl font-bold mt-8 mb-4"><Trans>4. Preise und Zahlungsbedingungen</Trans></h2>
                    <p>
                        <Trans>Es gelten die zum Zeitpunkt der Bestellung auf dem Portal ausgewiesenen Preise. Die Zahlung erfolgt wahlweise per Kreditkarte (über den Zahlungsdienstleister Stripe) oder auf Rechnung. Der Kaufpreis ist mit Bestellung fällig. Die Rechnung wird elektronisch übermittelt.</Trans>
                    </p>

                    <h2 className="text-2xl font-bold mt-8 mb-4"><Trans>5. Lieferung digitaler Inhalte</Trans></h2>
                    <p>
                        <Trans>Die Lieferung der bestellten digitalen Inhalte erfolgt ausschließlich in elektronischer Form. Unmittelbar nach Abschluss der Zahlung werden die bestellten Dateien für den Download freigeschaltet (sofortiger Download). Der Kunde erhält über sein Konto oder per E-Mail Zugriff auf die Download-Bereiche. Mangels körperlicher Übertragung sind die Regelungen über Versand und Gefahrübergang nicht anwendbar.</Trans>
                    </p>

                    <h2 className="text-2xl font-bold mt-8 mb-4"><Trans>6. Erlöschen des Rücktrittsrechts bei digitalen Inhalten</Trans></h2>
                    <p>
                        <Trans>Bei Verträgen über die Lieferung digitaler Inhalte im Sinne des § 18 Abs. 1 Z 11 des österreichischen Fern- und Auswärtsgeschäfte-Gesetzes (FAGG) erlischt das Rücktrittsrecht vorzeitig, wenn der Kunde bei der Bestellung ausdrücklich zugestimmt hat, dass die Ausführung des Vertrages – nämlich der sofortige Download der Fotos – vor Ablauf der Widerrufsfrist beginnt, und wenn der Kunde zur Kenntnis genommen hat, dass er durch diese Zustimmung sein Rücktrittsrecht verliert.</Trans>
                    </p>
                    <p>
                        <Trans>Mit der Bestätigung der Checkbox „Ich bin einverstanden, dass der Download meiner Fotos unmittelbar nach Zahlungsabschluss beginnt (sofortiger Download)“ erteilt der Kunde diese ausdrückliche Zustimmung. Ab dem Zeitpunkt der Freischaltung des Downloads gilt das Rücktrittsrecht als erloschen.</Trans>
                    </p>

                    <h2 className="text-2xl font-bold mt-8 mb-4"><Trans>7. Widerrufsrecht</Trans></h2>
                    <p>
                        <Trans>Verbrauchern steht ein gesetzliches Widerrufsrecht zu. Die Details, insbesondere die 14-tägige Widerrufsfrist sowie die Bedingungen für das vorzeitige Erlöschen des Rücktrittsrechts bei digitalen Inhalten, sind in der</Trans> <Link to="/widerruf" className="link link-primary"><Trans>Widerrufsbelehrung</Trans></Link> <Trans>dargestellt.</Trans>
                    </p>

                    <h2 className="text-2xl font-bold mt-8 mb-4"><Trans>8. Lizenzbedingungen und Nutzungsrechte</Trans></h2>
                    <p>
                        <Trans>Mit dem Erwerb einer Lizenz erhält der Kunde das einfache, nicht übertragbare Recht, die erworbenen Fotografien für den in der jeweiligen Lizenzvereinbarung festgelegten Zweck und Umfang zu nutzen. Eine darüber hinausgehende, insbesondere gewerbliche oder öffentliche Nutzung, die Weitergabe, der Weiterverkauf oder die Unterlizenzierung der Dateien bedarf der vorherigen schriftlichen Zustimmung des Fotografen.</Trans>
                    </p>

                    <h2 className="text-2xl font-bold mt-8 mb-4"><Trans>9. Urheberrecht</Trans></h2>
                    <p>
                        <Trans>Alle gelieferten Fotografien unterliegen dem Urheberrecht des Fotografen. Etwaige Metadaten (IPTC/EXIF), die Hinweise auf den Rechteinhaber und die Lizenzbedingungen enthalten, dürfen nicht entfernt oder verändert werden. Jede nicht ausdrücklich gestattete Verwendung stellt eine Urheberrechtsverletzung dar und berechtigt den Fotografen zu Schadenersatz und Unterlassung.</Trans>
                    </p>

                    <h2 className="text-2xl font-bold mt-8 mb-4"><Trans>10. Haftung</Trans></h2>
                    <p>
                        <Trans>Der Fotograf haftet für Schäden, die durch Vorsatz oder grobe Fahrlässigkeit verursacht werden, sowie nach den zwingenden gesetzlichen Bestimmungen. Die Haftung für leichte Fahrlässigkeit ist auf die bei Vertragsabschluss vorhersehbaren und vertragstypischen Schäden beschränkt, soweit keine zwingenden gesetzlichen Haftungsbestimmungen entgegenstehen.</Trans>
                    </p>

                    <h2 className="text-2xl font-bold mt-8 mb-4"><Trans>11. Schlussbestimmungen</Trans></h2>
                    <p>
                        <Trans>Es gilt österreichisches Recht unter Ausschluss des UN-Kaufrechts. Sollte eine Bestimmung dieser Bedingungen unwirksam sein, bleibt die Wirksamkeit der übrigen Bestimmungen unberührt. Gerichtsstand ist, soweit gesetzlich zulässig, der Sitz des Fotografen.</Trans>
                    </p>

                    <p className="mt-8">
                        <Trans>Weitere rechtliche Informationen:</Trans>{' '}
                        <Link to="/widerruf" className="link link-primary"><Trans>Widerrufsbelehrung</Trans></Link>
                        {' · '}
                        <Link to="/impressum" className="link link-primary"><Trans>Impressum</Trans></Link>
                        {' · '}
                        <Link to="/privacy" className="link link-primary"><Trans>Datenschutzerklärung</Trans></Link>
                    </p>
                </div>
            </div>
        </PageLayout>
    );
}