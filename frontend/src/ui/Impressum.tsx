import { Trans } from "@lingui/react/macro";
import PageLayout from './components/PageLayout';

export default function Impressum() {
    return (
        <PageLayout>
            <div className="container mx-auto p-8 max-w-4xl">
                <h1 className="text-4xl font-bold mb-8"><Trans>Impressum</Trans></h1>

                <div className="prose prose-base max-w-none">
                    <h2 className="text-2xl font-bold mt-8 mb-4"><Trans>Medieninhaber &amp; Herausgeber</Trans></h2>
                    <p>
                        <Trans>[Name des Fotografen / Unternehmens]</Trans><br />
                        <Trans>[Adresse]</Trans><br />
                        <Trans>[PLZ, Ort]</Trans>
                    </p>

                    <h2 className="text-2xl font-bold mt-8 mb-4"><Trans>Kontakt</Trans></h2>
                    <p>
                        <Trans>E-Mail: [E-Mail-Adresse]</Trans><br />
                        <Trans>Telefon: [Telefonnummer]</Trans><br />
                        <Trans>Website: [Domain]</Trans>
                    </p>

                    <h2 className="text-2xl font-bold mt-8 mb-4"><Trans>Unternehmensgegenstand</Trans></h2>
                    <p>
                        <Trans>Professionelle Fotografie, Bildbearbeitung sowie der Vertrieb und die Lizenzierung von fotografischen Werken über dieses Online-Portal.</Trans>
                    </p>

                    <h2 className="text-2xl font-bold mt-8 mb-4"><Trans>Haftungsausschluss</Trans></h2>
                    <p>
                        <Trans>Der Medieninhaber übernimmt keine Haftung für die Richtigkeit, Vollständigkeit und Aktualität der Inhalte dieser Website. Trotz sorgfältiger Kontrolle wird keine Haftung für die Inhalte externer Links übernommen. Für den Inhalt verlinkter Seiten sind ausschließlich deren Betreiber verantwortlich.</Trans>
                    </p>

                    <h2 className="text-2xl font-bold mt-8 mb-4"><Trans>Urheberrecht</Trans></h2>
                    <p>
                        <Trans>Alle auf dieser Website veröffentlichten Bilder, Grafiken, Texte und sonstigen Werke unterliegen dem Urheberrecht des jeweiligen Fotografen bzw. Medieninhabers. Jede Verwendung, Vervielfältigung oder Weitergabe – auch auszugsweise – bedarf der vorherigen schriftlichen Zustimmung des Rechteinhabers.</Trans>
                    </p>

                    <h2 className="text-2xl font-bold mt-8 mb-4"><Trans>Streitbeilegung</Trans></h2>
                    <p>
                        <Trans>Die Europäische Kommission stellt eine Plattform zur Online-Streitbeilegung (OS) bereit, die Sie unter</Trans> <a href="https://ec.europa.eu/consumers/odr/" target="_blank" rel="noopener noreferrer">https://ec.europa.eu/consumers/odr/</a> <Trans>finden. Wir sind nicht bereit oder verpflichtet, an Streitbeilegungsverfahren vor einer Verbraucherschlichtungsstelle teilzunehmen.</Trans>
                    </p>
                </div>
            </div>
        </PageLayout>
    );
}
