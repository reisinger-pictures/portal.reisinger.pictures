# Settings: Validierung gehört zum Key, nicht zum Anfragetermin

## Status

SOLL-Zustand. Deckt den Befund vom 2026-09-27 ab, der beim Nachsehen der
API-Typen der Lizenzbegriffe aufgetaucht ist.

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

## Die Einheit steht nicht am Feldnamen — sie steht nirgends

Bei der Umsetzung dieser Regel zeigte sich, dass die Typfrage größer ist als
zunächst angenommen: **dieselbe Antwort mischt drei Einheiten.**

| Einheit | Felder | Nachweis |
|---|---|---|
| **Cent** (Integer) | `base_price`, `setup_fee`, `privacy_fee`, `extra_image_fee`, `price_web`, `price_print`, `price_original` | `CalculatorSettingsCard` schreibt `Math.round(euros * 100)` und liest `/100` |
| **Euro** (Fließkomma) | `calc_base_price`, `calc_hourly_rate` | dieselbe Karte schreibt `data.calc_base_price` **ohne** `×100` und liest **ohne** `/100`; `ShootingCalculatorSettingsTest:209-210` pinnt `'49.99'` und `'99.5'` |
| **Faktor** (dimensionslos) | `mult_commercial`, `mult_unlimited`, `mult_international`, `calc_flatrate_multiplier` | geseedet als `'2.0'` |

Der Unterschied zwischen der ersten und der zweiten Zeile ist eine einzige
Zeile im Frontend — Zeile 47 schickt Euro, Zeile 52 schickt Cent, für zwei
Felder derselben Karte. Es gibt **kein** Feld, das die Einheit mitteilt, und
keinen Vertrag, der sie festlegt. Ein Client, der `calc_base_price` wie
`base_price` behandelt, liegt um den Faktor 100 daneben.

Das ist die eigentliche Beobachtung hinter der ursprünglichen Frage nach den
Typen, und sie ist schärfer als „die Beträge sind Strings": Strings sind ein
Darstellungsproblem, gemischte Einheiten sind ein **Rechen**problem.

Umgesetzt ist deshalb nur das, was belegt ist: `setup_fee`, `privacy_fee` und
`extra_image_fee` wurden von `numeric` auf `integer` gewechselt, weil sie auf
allen drei Wegen Cent sind (geseedet 5000/20000/1500, geschrieben
`Math.round(euros * 100)`, gelesen `Number(value)/100`). `numeric` akzeptierte
dort einen Bruchcent wie `'5000.5'` — ein Wert, den kein Client erzeugen und
kein Verbraucher darstellen kann. Die `calc_*`-Felder bleiben bewusst
`numeric`.

## Abgrenzung: Mandantenfähigkeit

Die Spalte `brand` existiert, der zusammengesetzte Primärschlüssel ist
`(key, brand)`, und `SettingResolver` schreibt nach
`BrandRegistry::currentOrDefault()` — es gibt aber nur eine Marke, `rp`.
Die Mechanik ist vorhanden und ungenutzt.

Für Mandantenfähigkeit ist die Regel oben eine **Vorbedingung**: Ein Key
pro Marke nützt nur, wenn die Bedeutung am Key hängt. Solange die
Bedeutung am Anfragetermin hängt, würde eine zweite Marke denselben Fehler
nur vervielfachen — dann eben pro Marke statt einmal.

## Nicht getroffene Entscheidung: der API-Typ

Ob die API Geldbeträge als Cent-Integer oder als Text ausgibt, ist eine
getrennte Frage und wird hier **nicht** entschieden. `settings.value` ist
`text`, deshalb steht in der Antwort `base_price` als `'19900'` und
`mult_commercial` als `'2.0'`.

Die Owner-Entscheidung vom 2026-09-27 ist die Richtung, aber noch kein
Umbau: **die API ist die Grenze, nicht die Datenbank.** Draußen sollen
Cent-Integer heraus, innen darf `settings` ein Key-Value-Paar mit
String-Werten bleiben — dort ist der Text nicht ein Makel, sondern das Format
eines Stores, der heterogene Werte hält.

Diese Entscheidung allein genügt aber nicht, weil sie das Problem nur
verschiebt: Solange `calc_base_price` in Euro und `base_price` in Cent
kommt, ist „die API gibt Cent-Integer aus" für die `calc_*`-Felder falsch.
**Vorher muss die Einheit am Feld oder im Vertrag stehen**, sonst typisiert
die API zwei verschiedene Größen in dasselbe Format. Die Einheitentabelle
oben ist der Kandidat dafür; die Entscheidung ist offen.
