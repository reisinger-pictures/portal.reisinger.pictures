import { Trans } from "@lingui/react/macro";
import { Link } from 'react-router-dom';
import PageLayout from './components/PageLayout';

export default function Widerrufsbelehrung() {
    return (
        <PageLayout>
            <div className="container mx-auto p-8 max-w-4xl">
                <h1 className="text-4xl font-bold mb-8"><Trans>Widerrufsbelehrung</Trans></h1>

                <div className="prose prose-base max-w-none">
                    <p className="lead">
                        <Trans>Verbraucher haben bei Verträgen über digitale Inhalte und Fotoprodukte das folgende gesetzliche Widerrufsrecht.</Trans>
                    </p>

                    <h2 className="text-2xl font-bold mt-8 mb-4"><Trans>Widerrufsrecht</Trans></h2>
                    <p>
                        <Trans>Sie haben das Recht, binnen vierzehn Tagen ohne Angabe von Gründen diesen Vertrag zu widerrufen. Die Widerrufsfrist beträgt vierzehn Tage ab dem Tag des Vertragsabschlusses.</Trans>
                    </p>

                    <h2 className="text-2xl font-bold mt-8 mb-4"><Trans>Ausübung des Widerrufs</Trans></h2>
                    <p>
                        <Trans>Um Ihr Widerrufsrecht auszuüben, müssen Sie uns (den Betreiber dieses Portals) mittels einer eindeutigen Erklärung über Ihren Entschluss, diesen Vertrag zu widerrufen, informieren. Sie können dafür das unten beschriebene Muster-Widerrufsformular verwenden, das jedoch nicht vorgeschrieben ist. Zur Wahrung der Widerrufsfrist genügt es, dass Sie die Mitteilung über die Ausübung des Widerrufsrechts vor Ablauf der Widerrufsfrist absenden.</Trans>
                    </p>

                    <h2 className="text-2xl font-bold mt-8 mb-4"><Trans>Folgen des Widerrufs</Trans></h2>
                    <p>
                        <Trans>Wenn Sie diesen Vertrag widerrufen, haben wir Ihnen alle Zahlungen, die wir von Ihnen erhalten haben, einschließlich der Lieferkosten, unverzüglich und spätestens binnen vierzehn Tagen ab dem Tag zurückzuzahlen, an dem die Mitteilung über Ihren Widerruf bei uns eingegangen ist. Für die Rückzahlung verwenden wir dasselbe Zahlungsmittel, das Sie bei der ursprünglichen Transaktion eingesetzt haben, es sei denn, mit Ihnen wurde ausdrücklich etwas anderes vereinbart.</Trans>
                    </p>

                    <h2 className="text-2xl font-bold mt-8 mb-4"><Trans>Erlöschen des Rücktrittsrechts bei digitalen Inhalten</Trans></h2>
                    <p>
                        <Trans>Das Widerrufsrecht erlischt bei Verträgen über die Lieferung digitaler Inhalte, wenn die Ausführung des Vertrages mit Ihrer ausdrücklichen Zustimmung begonnen hat, bevor die Widerrufsfrist abgelaufen ist, und wenn Sie zur Kenntnis genommen haben, dass Sie durch die Zustimmung Ihr Widerrufsrecht verlieren.</Trans>
                    </p>
                    <p>
                        <Trans>Konkret bedeutet dies für Bestellungen in diesem Portal: Wenn Sie beim Kauf Ihrer Fotos der sofortigen Ausführung des Vertrages zustimmen (sofortiger Download unmittelbar nach Zahlungsabschluss) und dies ausdrücklich per Checkbox bestätigen, haben Sie zur Kenntnis genommen, dass Ihr Rücktritts- bzw. Widerrufsrecht mit Beginn des Downloads vorzeitig erlischt.</Trans>
                    </p>

                    <h2 className="text-2xl font-bold mt-8 mb-4"><Trans>Muster-Widerrufsformular</Trans></h2>
                    <p>
                        <Trans>Der österreichische Gesetzgeber stellt ein Muster-Widerrufsformular bereit (Anhang I B zum Fern- und Auswärtsgeschäfte-Gesetz, FAGG). Sie können den Widerruf formlos erklären – etwa per E-Mail an den auf dem Portal angegebenen Kontakt. Das Muster-Widerrufsformular verlangt lediglich die Angabe des Vertrags, die Mitteilung über Ihren Entschluss zum Widerruf sowie Ihren Namen und Ihre Anschrift. Zur Wahrung der Widerrufsfrist genügt die rechtzeitige Absendung der Erklärung.</Trans>
                    </p>

                    <p className="mt-8">
                        <Trans>Weitere rechtliche Informationen:</Trans>{' '}
                        <Link to="/license-terms" className="link link-primary"><Trans>AGB &amp; Lizenzbedingungen</Trans></Link>
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