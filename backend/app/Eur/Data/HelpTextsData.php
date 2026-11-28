<?php

declare(strict_types=1);

namespace App\Eur\Data;

/**
 * GENERIERT von `php artisan eur:export-data` aus dem gepinnten
 * Regelartefakt — nicht von Hand ändern. Die Quelldatei kennt nur der Generator.
 *
 * Die Ausfüllhilfe je Kennzahl, unverändert aus der Zellspalte des
 * Artefakts. Zelladressen werden nicht übernommen: die Tabelle ist
 * Ausfüllhilfe, keine zweite Regelquelle.
 */
final class HelpTextsData
{
    /**
     * @var list<array{kz: string, bezeichnung: string, hilfetext: string}>
     */
    public const ROWS = [
        [
            'kz' => '9040',
            'bezeichnung' => 'Betriebseinnahmen',
            'hilfetext' => 'Standardkennzahl und Sammelbecken. Erträge/Betriebseinnahmen (Waren-/Leistungserlöse und Eigenverbrauch)',
        ],
        [
            'kz' => '9050',
            'bezeichnung' => 'Freie Dienstverträge',
            'hilfetext' => 'Einnahmen aus selbständig erbrachten Leistungen im Rahmen von freien Dienstverträgen, Vorträgen, unterrichtender oder lehrender Tätigkeit, Funktionsgebühren – die gemäß §109a vom Dienstgeber gemeldet werden müssen',
        ],
        [
            'kz' => '9060',
            'bezeichnung' => 'Erlös abgeg. Anlagegüter',
            'hilfetext' => 'Erlös (Betriebseinnahme) abgegangenes (Verkauf oder Entnahme) Anlagegut aus dem Anlagevermögen',
        ],
        [
            'kz' => '9090',
            'bezeichnung' => 'Übrige Betriebseinnahmen',
            'hilfetext' => 'Sonstige Einnahmen, die nicht 9040, 9050 oder 9060 zuordenbar sind (z. B. Gutschriften der Sozialversicherung, Versicherungsentschädigungen, Förderungen, Subventionen etc.) auch gelegentliche und in geringem Umfang anfallende Erlöse.',
        ],
        [
            'kz' => '9100',
            'bezeichnung' => 'Waren',
            'hilfetext' => '// Waren, Rohstoffe, Hilfsstoffe Ausgaben für den Einkauf von Waren, Rohstoffe, Hilfsstoffe und Fertig- bzw. Einzelteile. Keine Büromaterialen.',
        ],
        [
            'kz' => '9110',
            'bezeichnung' => 'Fremdpersonal',
            'hilfetext' => '// Beigestelltes Personal (Fremdpersonal) und Fremdleistungen Ausgaben für Fremdpersonal (z.B. Freelancer). Nicht einzutragen sind jene Ausgaben für Fremdpersonal, die als Herstellungskosten von Anlagegütern zu „aktivieren" (Anlageverzeichnis) sind',
        ],
        [
            'kz' => '9120',
            'bezeichnung' => 'Eigenpersonal',
            'hilfetext' => 'Personalaufwand ("eigenes Personal"). Löhne, Gehälter und Lohnnebenkosten.',
        ],
        [
            'kz' => '9130',
            'bezeichnung' => 'Anlagegüter',
            'hilfetext' => 'Abschreibungen auf das Anlagevermögen (Dinge die länger als 1 Jahr dienen: Einrichtung, Computer, Drucker, Fahrzeuge, Maschine) und geringwertige Wirtschaftsgüter. Die Abschreibungen aus dem Anlagenverzeichnis werden unabhängig von der Ausgaben-Art hier zugeordnet',
        ],
        [
            'kz' => '9134',
            'bezeichnung' => 'Degressive AfA',
            'hilfetext' => '',
        ],
        [
            'kz' => '9135',
            'bezeichnung' => 'Beschleunigte Gebäude-AfA',
            'hilfetext' => '',
        ],
        [
            'kz' => '9150',
            'bezeichnung' => 'Gebäudeinstandhaltung',
            'hilfetext' => 'Instandhaltungen (Erhaltungsaufwand) für Gebäude. Reinigung durch Dritte, Entsorgung und Beleuchtung sind nicht hier einzutragen.',
        ],
        [
            'kz' => '9160',
            'bezeichnung' => 'Reisen, Fahrten und Diäten',
            'hilfetext' => 'Fahrten Wohnsitz - Arbeitsstätte: Reise- und Fahrtspesen (z.B Zugtickets) inkl. Kilometergeld und Diäten (ohne tatsächliche Kfz-Kosten) Alle Betrieblichen Ausgaben aus dem Fahrtenbuch werden automatisch hier zugeordnet',
        ],
        [
            'kz' => '9165',
            'bezeichnung' => '50% Pauschale Massenbeförderung',
            'hilfetext' => 'Wenn glaubhaft gemacht werden kann, dass die Karte im Wirtschaftsjahr auch für betriebliche Fahrten verwendet wurde, können 50% der Ausgaben für eine nicht übertragbare Wochen-, Monats- oder Jahreskarte für Massenbeförderungsmittel als Betriebsausgabe berücksichtigt werden',
        ],
        [
            'kz' => '9170',
            'bezeichnung' => 'Tatsächliche KFZ-Kosten',
            'hilfetext' => '"Tatsächliche Kfz-Kosten wenn nicht bereits Kilometergeld geltend gemacht wird. Instandhaltungsaufwendungen betreffend Kfz, Betriebsstoffe, Reparatur- und Servicekosten, Versicherungsprämien, Steuern"',
        ],
        [
            'kz' => '9180',
            'bezeichnung' => 'Miete, Pacht und Leasing',
            'hilfetext' => '"Miet- und Pachtaufwand, Leasing. Nicht hier einzutragen sind Aufwendungen/Ausgaben für Beheizung, Beleuchtung, für die Reinigung gemieteter Räume sowie Betriebskosten"',
        ],
        [
            'kz' => '9190',
            'bezeichnung' => 'Provisionen und Lizenzen',
            'hilfetext' => 'Provisionen an Dritte, Lizenzgebühren',
        ],
        [
            'kz' => '9200',
            'bezeichnung' => 'Werbung und Repräsentation',
            'hilfetext' => 'Werbe- und Repräsentationsaufwendungen, Bewirtungsspesen, nicht in den Kennzahlen 9243 bis 9246 zu erfassenden Spenden, Trinkgelder',
        ],
        [
            'kz' => '9210',
            'bezeichnung' => 'Buchwert abgeg. Anlagegüter',
            'hilfetext' => 'Buchwerte von im Wirtschaftsjahr abgegangenen (Verkauf oder Entnahme) Anlagen. Zerstörte AG sind 9130',
        ],
        [
            'kz' => '9220',
            'bezeichnung' => 'Zinsen',
            'hilfetext' => 'Zinsen und ähnliche Aufwendungen',
        ],
        [
            'kz' => '9275',
            'bezeichnung' => 'Arbeitszimmer',
            'hilfetext' => 'Abzugsfähige Ausgaben liegen nur dann vor, wenn das Arbeitszimmer (nahezu) ausschließlich betrieblich genutzt wird und den Mittelpunkt der gesamten betrieblichen Tätigkeit bildet. Das ist z. B. bei Schriftstellern, Malern, Komponisten oder Gutachtern der Fall. Als Betriebsausgaben durch ein Arbeitszimmer kommen insbesondere anteilige Mietkosten, Betriebskosten (Beheizung, Beleuchtung, Versicherung etc.), AfA, Finanzierungskosten in Betracht. Bitte tragen Sie die Aufwendungen für das Arbeitszimmer nur in Kennzahl 9275 ein, ohne Erfassung in anderen Kennzahlen. Werden Aufwendungen für ein Arbeitszimmer berücksichtigt, stehen keine Werbungskosten für ergonomisch geeignetes Mobiliar (Kennzahl 158 im Formular E 1) zu und es können in der Veranlagung auch keine Werbungskosten für ein Homeoffice-Pauschale berücksichtigt werden.',
        ],
        [
            'kz' => '9225',
            'bezeichnung' => 'Pflichtversicherungen',
            'hilfetext' => 'SVA-Beträge, Eigene Pflichtversicherungsbeiträge, Beiträge zu Versorgungs- und Unterstützungseinrichtungen und Beiträge zur Selbständigenvorsorge',
        ],
        [
            'kz' => '9243',
            'bezeichnung' => 'Spenden Lehreinrichtungen',
            'hilfetext' => 'Betriebliche Spenden an begünstigte Forschungs- und Lehreinrichtungen, Museen, das Bundesdenkmalamt, Behindertensport-Dachverbände, die Internationale Anti-Korruptions-Akademie',
        ],
        [
            'kz' => '9244',
            'bezeichnung' => 'Spenden Hilfsorganisationen',
            'hilfetext' => 'Betriebliche Spenden an mildtätige Organisationen, begünstigte Sendensammelvereine',
        ],
        [
            'kz' => '9245',
            'bezeichnung' => 'Spenden Umweltschutz',
            'hilfetext' => 'Betriebliche Spenden an Umweltschutzorganisationen und Tierheime',
        ],
        [
            'kz' => '9246',
            'bezeichnung' => 'Spenden Feuerwehr',
            'hilfetext' => 'Betriebliche Spenden an freiwillige Feuerwehren und Landesfeuerwehrverbände',
        ],
        [
            'kz' => '9230',
            'bezeichnung' => 'Übrige Betriebsausgaben',
            'hilfetext' => 'Standardkennzahl. Alle anderen nicht bereits erfassen Ausgaben. z.B Fachliteratur, Büromaterial,Telefon, Porto',
        ],
        [
            'kz' => '9216',
            'bezeichnung' => 'Ergonomisches Mobiliar',
            'hilfetext' => 'Ausgaben für ergonomisch geeignetes Mobiliar eines in der Wohnung eingerichteten Arbeitsplatzes können bis höchstens 300 Euro als Betriebsausgaben berücksichtigt werden. Es darf keine Eintragung in Kennzahl 9275, 9217 oder 159 (Formular E 1) erfolgen. Hier sind nur Ausgaben/Aufwendungen des Veranlagungsjahres und ein allfälliger Überhang aus einem Vorjahr anzugeben.',
        ],
        [
            'kz' => '9215',
            'bezeichnung' => 'Arbeitsplatzpauschale',
            'hilfetext' => 'Das Arbeitsplatzpauschale steht zu, wenn zur Ausübung der betrieblichen Tätigkeit kein anderer Raum zur Verfügung steht (E2 2024 Zahl 42, EStR 2000 RZ 1299). Es müssen Ihnen tatsächliche Kosten entstehen, es muss aber nicht der Hauptwohnsitz sein (EStR 2000 RZ 1298). Die Pauschale deckt wohnraumbezogene Aufwendungen wie Strom, Heizung, Beleuchtung oder die AfA ab. Arbeitsmittel wie Computer und Drucker dürfen jedoch getrennt als Betriebsausgaben geltend gemacht werden (EStR 2000 RZ 1298). Die Berücksichtigung eines Arbeitszimmers (Kennzahl 9275) schließt die Arbeitsplatzpauschale aus (siehe EStR 2000 RZ 1300). ▶ GROSSE ODER KLEINE? Große wenn, sonst die Kleine: a.) Werden keine anderen Einkünfte aus einer aktiven Erwerbstätigkeit (Vermietung und Vermöglich zählen hier nicht) erzielt, für die außerhalb der Wohnung ein anderer Raum zur Verfügung steht, oder b.) betragen diese höchstens 12.816 Euro (ändert sich jedes Jahr) (E2 2024 Zahl 44, EStR 2000 RZ 1301) ▶ PRO MONAT Kleine Pauschale: 25€ pro Monat = 300€ für das ganze Jahr Große Pauschale: 100€ pro Monat = 1200€ für das ganze Jahr Für jeden (vollen oder angefangenen) Monat, in dem die Tätigkeit ausgeübt wird, steht als Pauschale der jeweilige Zwölftelbetrag zu (EStR 2000 RZ 1300). ▶ EXTRA DER KLEINEN PAUSCHALE Neben dieser Pauschale sind Ausgaben für ergonomisch geeignetes Mobiliar (insbesondere Schreibtisch, Drehstuhl, Beleuchtung) in der Kennzahl 9216 nach Maßgabe des § 16 Abs. 1 Z 7a lit. a zweiter und dritter Satz EStG 1988 abzugsfähig. Dementsprechend ist für diese Betriebsausgaben die Deckelung von 300 Euro maßgeblich; die Berücksichtigung einer AfA kommt nicht in Betracht. (EStR 2000 RZ 1301)',
        ],
        [
            'kz' => '9217',
            'bezeichnung' => 'Arbeitsplatzpauschale',
            'hilfetext' => 'Das Arbeitsplatzpauschale steht zu, wenn zur Ausübung der betrieblichen Tätigkeit kein anderer Raum zur Verfügung steht (E2 2024 Zahl 42, EStR 2000 RZ 1299). Es müssen Ihnen tatsächliche Kosten entstehen, es muss aber nicht der Hauptwohnsitz sein (EStR 2000 RZ 1298). Die Pauschale deckt wohnraumbezogene Aufwendungen wie Strom, Heizung, Beleuchtung oder die AfA ab. Arbeitsmittel wie Computer und Drucker dürfen jedoch getrennt als Betriebsausgaben geltend gemacht werden (EStR 2000 RZ 1298). Die Berücksichtigung eines Arbeitszimmers (Kennzahl 9275) schließt die Arbeitsplatzpauschale aus (siehe EStR 2000 RZ 1300). ▶ GROSSE ODER KLEINE? Große wenn, sonst die Kleine: a.) Werden keine anderen Einkünfte aus einer aktiven Erwerbstätigkeit (Vermietung und Vermöglich zählen hier nicht) erzielt, für die außerhalb der Wohnung ein anderer Raum zur Verfügung steht, oder b.) betragen diese höchstens 12.816 Euro (ändert sich jedes Jahr) (E2 2024 Zahl 44, EStR 2000 RZ 1301) ▶ PRO MONAT Kleine Pauschale: 25€ pro Monat = 300€ für das ganze Jahr Große Pauschale: 100€ pro Monat = 1200€ für das ganze Jahr Für jeden (vollen oder angefangenen) Monat, in dem die Tätigkeit ausgeübt wird, steht als Pauschale der jeweilige Zwölftelbetrag zu (EStR 2000 RZ 1300). ▶ EXTRA DER KLEINEN PAUSCHALE Neben dieser Pauschale sind Ausgaben für ergonomisch geeignetes Mobiliar (insbesondere Schreibtisch, Drehstuhl, Beleuchtung) in der Kennzahl 9216 nach Maßgabe des § 16 Abs. 1 Z 7a lit. a zweiter und dritter Satz EStG 1988 abzugsfähig. Dementsprechend ist für diese Betriebsausgaben die Deckelung von 300 Euro maßgeblich; die Berücksichtigung einer AfA kommt nicht in Betracht. (EStR 2000 RZ 1301)',
        ],
    ];
}
