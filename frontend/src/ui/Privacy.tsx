import { Trans } from "@lingui/react/macro";
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

                    <h2 className="text-2xl font-bold mt-8 mb-4"><Trans>1. Automatische Datenerfassung und IP-Adressen</Trans></h2>
                    <p>
                        <Trans>Aus Gründen der Datensparsamkeit und zur Einhaltung der DSGVO protokollieren wir</Trans> <strong><Trans>keine IP-Adressen</Trans></strong> <Trans>der Nutzer oder Gäste in unseren Audit- und Download-Logs. Webserver-Logs werden, sofern sie IP-Adressen enthalten, automatisch nach wenigen Tagen rotiert und gelöscht.</Trans>
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

                    <h2 className="text-2xl font-bold mt-8 mb-4"><Trans>4. Ihre Rechte</Trans></h2>
                    <p>
                        <Trans>Sie haben das Recht auf Auskunft, Berichtigung, Löschung oder Einschränkung der Verarbeitung Ihrer gespeicherten Daten. Bitte wenden Sie sich hierfür an den verantwortlichen Fotografen oder Administrator dieses Portals.</Trans>
                    </p>
                </div>
            </div>
        </PageLayout>
    );
}
