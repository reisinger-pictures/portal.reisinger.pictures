import PageLayout from './components/PageLayout';

export default function Impressum() {
    return (
        <PageLayout>
            <div className="container mx-auto p-8 max-w-4xl">
                <h1 className="text-4xl font-bold mb-8">Impressum</h1>

                <div className="prose prose-base max-w-none">
                    <h2 className="text-2xl font-bold mt-8 mb-4">Medieninhaber &amp; Herausgeber</h2>
                    <p>
                        [Name des Fotografen / Unternehmens]<br />
                        [Adresse]<br />
                        [PLZ, Ort]
                    </p>

                    <h2 className="text-2xl font-bold mt-8 mb-4">Kontakt</h2>
                    <p>
                        E-Mail: [E-Mail-Adresse]<br />
                        Telefon: [Telefonnummer]<br />
                        Website: [Domain]
                    </p>

                    <h2 className="text-2xl font-bold mt-8 mb-4">Unternehmensgegenstand</h2>
                    <p>
                        Professionelle Fotografie, Bildbearbeitung sowie der Vertrieb und die Lizenzierung
                        von fotografischen Werken über dieses Online-Portal.
                    </p>

                    <h2 className="text-2xl font-bold mt-8 mb-4">Haftungsausschluss</h2>
                    <p>
                        Der Medieninhaber übernimmt keine Haftung für die Richtigkeit, Vollständigkeit
                        und Aktualität der Inhalte dieser Website. Trotz sorgfältiger Kontrolle wird keine
                        Haftung für die Inhalte externer Links übernommen. Für den Inhalt verlinkter Seiten
                        sind ausschließlich deren Betreiber verantwortlich.
                    </p>

                    <h2 className="text-2xl font-bold mt-8 mb-4">Urheberrecht</h2>
                    <p>
                        Alle auf dieser Website veröffentlichten Bilder, Grafiken, Texte und sonstigen
                        Werke unterliegen dem Urheberrecht des jeweiligen Fotografen bzw. Medieninhabers.
                        Jede Verwendung, Vervielfältigung oder Weitergabe – auch auszugsweise – bedarf
                        der vorherigen schriftlichen Zustimmung des Rechteinhabers.
                    </p>

                    <h2 className="text-2xl font-bold mt-8 mb-4">Streitbeilegung</h2>
                    <p>
                        Die Europäische Kommission stellt eine Plattform zur Online-Streitbeilegung (OS)
                        bereit, die Sie unter <a href="https://ec.europa.eu/consumers/odr/" target="_blank" rel="noopener noreferrer">https://ec.europa.eu/consumers/odr/</a> finden.
                        Wir sind nicht bereit oder verpflichtet, an Streitbeilegungsverfahren vor einer
                        Verbraucherschlichtungsstelle teilzunehmen.
                    </p>
                </div>
            </div>
        </PageLayout>
    );
}
