# Settings: Validierung gehört zum Key, nicht zum Anfragetermin

## Status

SOLL-Zustand. Deckt den Befund vom 2026-09-27 ab, der beim Nachsehen der
API-Typen der Lizenzbegriffe auffiel, und den Einheitenvertrag vom 2026-09-28.

## Kernregel

**Die Validierungsregel eines Settings-Feldes gehört zum Key, nicht zum
Namen, unter dem es in einer Anfrage steht.** Ein Legacy-Name darf
ausschließlich eine andere Schreibweise desselben Feldes sein — nie eine
andere Regel.

## Der Befund

`SettingsController::updateLicenseTerms()` kennt beide Schreibweisen und
schreibt beide in denselben Key:

| Anfragetermin | Regel | schreibt nach |
|---|---|---|
| `base_price` (kanonisch) | `nullable|integer|min:500` | `base_price` |
| `srp_base_price` (Legacy) | `nullable|numeric|min:0` | `base_price` |

Die Zuordnung selbst ist korrekt und dokumentiert (Zeile 320):

```php
// Map legacy `srp_*` request keys to unprefixed brand-scoped settings keys.
$srpKeyMap = [
    'srp_base_price' => 'base_price',
    'srp_setup_fee' => 'setup_fee',
    …
];
```

Alle vier `srp_*`-Felder folgen derselben Regel, sie ist also eine bewusste
Rückwärtskompatibilität und **kein** Fehler. Das Mapping selbst zu
„korrigieren" wäre ein Fehler.

Der Fehler ist die **Regel dahinter**: Weil die Validierung am
Anfragetermin hängt statt am Key, haben zwei Namen für dieselbe Größe
zwei verschiedene Regeln. Ein `PUT` mit `srp_base_price: 1` wird
akzeptiert und schreibt `base_price = '1'` — der kanonische Name lehnt
dieselbe Eingabe ab (`integer`, `min:500`).

Damit umgeht der Legacy-Weg die Integer- und die Mindestwertprüfung, und
der gespeicherte Wert unterscheidet sich nicht von dem über den
kanonischen Weg erzeugten. Wer die Regel umgeht, sieht dieselben Daten
wie jemand, der sie eingehalten hat — nur eben mit einem Betrag, der nie
validiert wurde.

### Warum das nicht sofort auffiel

Die Regeldiskrepanz ist nur sichtbar, wenn man Key und Anfragetermin
getrennt liest. Im Response stehen beide Felder nebeneinander
(`base_price` und `srp_base_price`, beide aus demselben Key), was wie
zwei verschiedene Werte aussieht und die Kopplung verschleiert. Hätte man
das Mapping für einen Fehler gehalten und „behoben", wäre der Fehler
unbemerkt geblieben und die Regeldiskrepanz mit verdeckt.

## Verbindliche Folgerungen

1. **Regel am Key, nicht am Anfragetermin.** Jeder Schreibweg auf einen
   Key muss dieselbe Validierung durchlaufen. Zwei Regeln für eine Größe
   sind ein Loch, keine Flexibilität.
2. **Der Legacy-Name bekommt die kanonische Regel**, nicht eine eigene.
   Er ist derselbe Wert, also dieselbe Prüfung.
3. **Die Branche zwischen Anfrage und Key ist die Stelle, an der das
   passieren muss.** Eine Validierung *vor* dem Mapping kann den
   kanonischen Namen nicht kennen. Also gehört die Regel auf den Key
   (siehe `SettingResolver`) oder unmittelbar vor das Schreiben, nach der
   Zuordnung.
4. **`numeric` ist nur dort zu weit, wo der Key ein ganzzahliger
   Centbetrag ist.** Siehe unten: `calc_base_price` ist **Euro**, nicht Cent,
   und seine Dezimal-Annahme ist ein testgeschützter Vertrag. Eine pauschale
   Umstellung auf `integer` hätte diesen Vertrag gebrochen.

## Die Einheit steht nicht am Feldnamen — sie stand nirgends

> **Die Regel selbst steht jetzt an genau einer Stelle:**
> [`../tech/02-backend-architecture.md` § 4](../tech/02-backend-architecture.md)
> (Owner-Entscheidung 2026-09-28). Dieses Dokument ist das
> **Felderinventar** dazu: pro Feld die gemessene Einheit und ihr Beleg. Es
> wiederholt die Regel nicht, weil eine zweite Fassung genau der Defekt war, den
> die Regel behebt.
>
> **Zu den Zeilennummern in `CalculatorSettingsCard.tsx` und
> `shootingCalculator.ts`:** sie beziehen sich auf `82e8d17`, den letzten Commit
> **vor** der Cent-Umstellung, und beschreiben deshalb den **alten** Zustand.
> Die Umstellung läuft parallel; im Arbeitsverzeichnis sind die Zeilen
> womöglich schon verschoben. Prüfbar mit
> `git show 82e8d17:frontend/src/logic/shootingCalculator.ts`. Belege aus
> `backend/` und `database/` sind nicht betroffen.

Bei der Umsetzung dieser Regel zeigte sich, dass die Typfrage größer ist als
zunächst angenommen: **dieselbe Antwort mischt drei Einheiten.**

| Einheit | Felder | Nachweis |
|---|---|---|
| **Cent** (Integer) | `base_price`, `setup_fee`, `privacy_fee`, `extra_image_fee` | Seeder `DatabaseSeeder.php:164-168`, unter dem Kommentar „Per-image license base prices are stored in cents": `'8000'`, `'5000'`, `'20000'`, `'1500'`. Karte `CalculatorSettingsCard.tsx:38-41` liest `/100`, `:52-55` schreibt `Math.round(x * 100)` — für **genau diese vier** |
| **Cent** (Integer) | `price_web`, `price_print`, `price_original` | Seeder `DatabaseSeeder.php:148-150` → `'7500'`, `'14500'`, `'45000'`; serverseitige Begründung `SettingsController.php:307-313`; verbraucht als Roh-Integer **ohne** `/100` in `pricingLogic.ts:55-58` über `getRequiredTerm` (`:14`, blankes `parseInt`) |
| **Euro** (Fließkomma) | `calc_base_price`, `calc_hourly_rate` | Seeder `DatabaseSeeder.php:159-160` → `'50'` / `'80'`; dieselbe Karte schreibt `data.calc_base_price` **ohne** `×100` (`CalculatorSettingsCard.tsx:47`) und liest **ohne** `/100` (`:33-34`); `ShootingCalculatorSettingsTest:209-210` pinnt `'49.99'` und `'99.5'` |
| **Faktor** (dimensionslos) | `mult_commercial`, `mult_unlimited`, `mult_international` | `DatabaseSeeder.php:151-153` → `'2.0'`, `'1.5'`, `'1.5'` |
| **Faktor** (dimensionslos) | `calc_flatrate_multiplier` | `DatabaseSeeder.php:163` → `'1.2'` |

> **Zwei Korrekturen an dieser Tabelle, die vorher falsch waren.** Sie stehen
> hier festgeschrieben, weil ein Leser, der die alte Zeile kennt, den neuen Wert
> sonst für einen Fehler hält.
>
> 1. **`calc_flatrate_multiplier` ist `'1.2'`, nicht `'2.0'`.** Die alte Zeile
>    schrieb „geeseedet als `'2.0'`" und belegte damit **vier** Felder mit einem
>    einzigen Beweis — und dieser Beweis gehörte `mult_commercial`
>    (`DatabaseSeeder.php:151`). Die Zeile ist in zwei Zeilen zerfallen, je mit
>    eigenem Beleg. `'2.0'` ist nie der Wert von `calc_flatrate_multiplier`
>    gewesen; die Zahl war von einem Nachbarfeld übernommen.
> 2. **`CalculatorSettingsCard` war kein Beleg für `price_web`, `price_print`
>    oder `price_original`.** Die Datei nennt diese drei Felder mit keinem
>    Zeichen — `grep price_web frontend/src` findet sie nur in `pricingLogic.ts`
>    und dessen Test. Die alte Zeile hängte sieben Felder unter eine Karte, die
>    vier davon erreicht. Für diese drei steht jetzt ein eigener Beleg: der
>    gespeicherte Wert plus die Rechnung, die nur in Cent aufgeht —
>    `pricingLogic.test.ts:108` erwartet `1500` für `print − web`
>    (`2500 − 1000`), `:112` erwartet `17000` für
>    `original · 2 · 3 · 2 − web`. In Euro wären das 15 € und 170 € Aufpreis
>    für **ein** Bild; in Cent sind es die Dimensionen, die der Key verspricht.

Der Unterschied zwischen der ersten Cent-Zeile und der Euro-Zeile ist eine
einzige Zeile im Frontend — `CalculatorSettingsCard.tsx:47` schickt Euro,
`:52` schickt Cent, für zwei Felder derselben Karte. Es gab **kein** Feld, das
die Einheit mitteilt, und keinen Vertrag, der sie festlegt. Ein Client, der
`calc_base_price` wie `base_price` behandelt, liegt um den Faktor 100 daneben.

Das ist die eigentliche Beobachtung hinter der ursprünglichen Frage nach den
Typen, und sie ist schärfer als „die Beträge sind Strings": Strings sind ein
Darstellungsproblem, gemischte Einheiten sind ein **Rechen**problem.

### Nicht in dieser Tabelle: die nicht-monetären Felder

`calc_flatrate_multiplier` und die `mult_*` standen in der alten Tabelle in einer
Reihe mit den Geldfeldern, nur weil sie eine eigene Einheit haben. Sie sind
keine Geldfelder. Ebenso wenig `calc_images_per_hour` (`'6'`,
`DatabaseSeeder.php:161`) und `calc_outdoor_images_per_hour` (`'8'`, `:162`) —
**Anzahlen**, verbraucht über `parseInt` in `shootingCalculator.ts:68,75`. Die
vollständige Ausschlussliste steht in
[`../tech/02-backend-architecture.md` § 4](../tech/02-backend-architecture.md)
Nummer 3.

> **Befund, kein Lückenfall:** `calc_images_per_hour` und
> `calc_outdoor_images_per_hour` sind in **keinem** Dokument unter `features/`
> genannt — geprüft mit
> `grep -rn 'calc_images_per_hour\|calc_outdoor_images_per_hour' features/`,
> Ergebnis: keine Treffer. Zwei von neun Feldern der Antwort
> `GET /api/settings/license-terms` waren damit an keiner Stelle beschrieben,
> weder mit Einheit noch ohne. Sie sind hiermit erstmals benannt. Wer eine
> Feldbeschreibung für sie schreibt, schreibt sie neu — es gibt nichts zu
> übernehmen und nichts zu ergänzen.

### Umgesetzt ist bisher nur das, was belegt ist

`setup_fee`, `privacy_fee` und `extra_image_fee` wurden von `numeric` auf
`integer` gewechselt, weil sie auf allen drei Wegen Cent sind (geeseedet
5000/20000/1500, geschrieben `Math.round(euros * 100)`, gelesen
`Number(value)/100`). `numeric` akzeptierte dort einen Bruchcent wie
`'5000.5'` — einen Wert, den kein Client erzeugen und kein Verbraucher
darstellen kann. Die `calc_*`-Felder bleiben bewusst `numeric`.

**In Arbeit, nicht erledigt:** die Umstellung von `calc_base_price` und
`calc_hourly_rate` auf Cent. Der Zielzustand ist entschieden
(`../tech/02-backend-architecture.md` § 4 Nummer 1), der Ist-Stand ist
gemessen, die Umsetzung liegt beim Code-Agenten. Bis dahin gilt: diese beiden
Felder sind **Geld in Euro** und damit ein offener Vertragsbruch, den dieses
Dokument nicht beschönigt.

## Warum das ein geschriebener Vertrag ist

Der Grund für die Regel ist kein Schemafehler. Es ist, dass die Einheit eines
Feldes **nirgends stand** und deshalb nur im Code existierte — als Teiler in
einem Eingabefeld, an genau einer Stelle im Repo. (Die
`CalculatorSettingsCard.tsx`-Zeilennummern in diesem Abschnitt folgen derselben
Ankerregel wie oben: Stand `82e8d17`.)

1. **Eine Einheit, die nur ein Teiler ist.**
   `CalculatorSettingsCard.tsx:47` schickt `calc_base_price` unverändert
   (Euro); dieselbe Datei schickt in `:52` `srp_base_price` als
   `Math.round(x * 100)` (Cent). Beides geht an denselben Endpunkt
   (`backend/routes/api.php:213`), beide Felder stehen in derselben Antwort
   (`:71`), und **nichts im Repository konnte dem widersprechen** — weder ein
   Schema, noch ein Vertrag, noch ein Test. Der Divisor an Zeile 52 *war* die
   Definition der Einheit, und eine Definition, die nur an einer Stelle im Code
   steht, ist keine Definition, sondern eine Behauptung.
2. **Das Feld hatte die Einheit schon einmal gewechselt.** `base_price` wurde
   in `V004__ecommerce_and_governance.php:131` als Euro angelegt
   (`'value' => '35.00'`), der Seeder schreibt denselben Key als Cent
   (`DatabaseSeeder.php:165` → `'8000'`). Dieselbe Zeile, dieselbe Spalte, zwei
   Einheiten — und über Jahre hinweg kein Widerspruch, weil niemand die Einheit
   von `base_price` je notiert hatte. Der Kommentar `DatabaseSeeder.php:164`
   („Per-image license base prices are stored in cents") ist die erste
   schriftliche Festlegung, und sie entstand erst, als jemand den Unterschied
   bemerkte.
3. **Ein Test hat den Euro-Zweig festgeschrieben, nicht den Fehler.** In
   `backend/tests/Feature/ShootingCalculatorSettingsTest.php` läuft
   `RefreshDatabase` (`:15`) — aber **ohne Seeder**. Die Settings-Zeile stammt
   dort also aus der Migration, also aus `'35.00'` und damit aus dem
   Euro-Zweig, und nicht aus dem Seederwert `'8000'`.
   `test_update_accepts_decimal_for_numeric_calc_fields` (`:202-213`) pinnt
   daraufhin `'49.99'` und `'99.5'` für die `calc_*`-Felder. Der Test ist grün
   und prüft die **falsche** Einheit, weil seine Fixture einen Zustand erzeugt,
   den es in Produktion nicht gibt. Die Folge ist die Lektion in
   [`../tech/04-testing-guidelines.md` § 6a](../tech/04-testing-guidelines.md):
   ein Round-Trip-Test beweist, dass ein Wert angekommen ist — nie, dass er in
   derselben Einheit angekommen ist.

Punkt 3 ist der Grund, warum Regel Nummer 5 in § 4 eine *Schreibpflicht* ist
und keine Dokumentationsempfehlung: Eine Einheit, die kein Test prüfen kann,
weil die Fixture die falsche erzeugt, muss wenigstens an einer Stelle
geschrieben stehen, die kein Test überschreiben kann.

## Abgrenzung: Mandantenfähigkeit

Die Spalte `brand` existiert, der zusammengesetzte Primärschlüssel ist
`(key, brand)`, und `SettingResolver` schreibt nach
`BrandRegistry::currentOrDefault()` — es gibt aber nur eine Marke, `rp`.
Die Mechanik ist vorhanden und ungenutzt.

Für Mandantenfähigkeit ist die Regel oben eine **Vorbedingung**: Ein Key
pro Marke nützt nur, wenn die Bedeutung am Key hängt. Solange die
Bedeutung am Anfragetermin hängt, würde eine zweite Marke denselben Fehler
nur vervielfachen — dann eben pro Marke statt einmal.

## Der API-Typ: entschieden, Umsetzung in Arbeit

`settings.value` ist `text` (`V001__initial_portal_schema.php:223`), deshalb
liefert MySQL **jeden** Wert als JSON-**String** zurück — `base_price` kommt als
`"8000"`, `mult_commercial` als `"2.0"`. Das ist der Ist-Stand und die
Formatgrenze eines Stores, der heterogene Werte hält.

**Die Entscheidung ist gefallen:** Die API ist die Grenze, nicht die
Datenbank. Draußen gehen **Geld als Integer** heraus (§ 4 Nummer 4), innen darf
`settings` ein Key-Value-Paar mit String-Werten bleiben. Die Umstellung der
Geldfelder auf Integer in der Antwort ist **in Arbeit** (Code-Seite, nicht in
diesem Commit).

Diese Entscheidung allein genügt aber nicht, weil sie das Problem nur
verschiebt: Solange `calc_base_price` in Euro und `base_price` in Cent kommt,
ist „die API gibt Cent-Integer aus" für die `calc_*`-Felder falsch. Genau
deshalb steht die Einheit jetzt am Feld und nicht nur in einem Umstellungs-
plan. Ein Client wendet heute `Number(value)` an; das ist auf der sicheren
Seite. Wer die Einheit aus dem JSON-Typ ableitet, hat sie nicht — der Typ sagt
sie nicht.
