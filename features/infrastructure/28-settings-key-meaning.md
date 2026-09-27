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
4. **`numeric` für ganzzahlige Cent-Beträge ist zu weit.** `base_price`
   wird mit `integer` geprüft, `calc_base_price` und die `srp_*`-Felder
   mit `numeric` — eine Fließkommazahl für einen ganzzahligen Betrag. Der
   Folgefehler ist derselbe wie oben: zwei Namen, zwei Regeln, und die
   schwächere gewinnt, wenn man sie wählt.

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
getrennte Frage und wird hier nicht entschieden. Beobachtung aus dem
Anlass: `settings.value` ist `text`, deshalb stehen in der Antwort
`base_price` als `'19900'` und `mult_commercial` als `'2.0'` — und nur am
Feldnamen erkennbar ist, ob durch 100 geteilt werden muss.
