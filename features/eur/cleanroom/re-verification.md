# Unabhängige Nachprüfung (Re-Verifikation) der Korrekturen

**Gegenstand:** `business-rules/domain-rules.json` — 131 Regeln (EUE-001 … EUE-131)
**Referenz:** `lokale Ausgangsmappe` (unverändert, SHA-256 `38fe51cd…19d763`, mtime 2026-11-21 12:44)
**Vorbericht:** `verification/domain-verification.md` (Endurteil: CHANGES REQUIRED)
**Datum:** 2026-11-21
**Prüfart:** unabhängige Zell-, Formel-, Cache- und Metadatenprüfung mit `openpyxl`
(`data_only=False` und `data_only=True`, Datenvalidierungen und bedingte Formatierungen aus dem XML-Modell)
**Ergebnis:** **APPROVED** — 24/24 betroffene Regeln korrekt, 0 Restbefunde

---

## 1. Umfang und Vorgehen

Der Auftrag nennt „25 betroffene Regeln“. Tatsächlich sind es **24 distinkte IDs**: die 4 vormals falschen
(EUE-072, EUE-102, EUE-109, EUE-115) + die 19 unpräzisen aus § 6 des Vorberichts + die neu aufgenommene
EUE-131. Alle 24 wurden vollständig gegen die Mappe geprüft, nicht nur die geänderten Textstellen.
Fünf nicht betroffene Regeln wurden stichprobenartig geprüft (§ 5).

In einer **zweiten Runde** wurden die vier in der ersten Runde verbliebenen Befunde (EUE-072, EUE-107,
EUE-108, EUE-115) nach der Korrektur erneut ausschließlich an den betroffenen Zellen geprüft (§ 4).
Für jede Regel wurden die im Katalog behaupteten Zellen im Formelmodus und im Cache-Modus gelesen;
Datenvalidierungen und bedingte Formatierungen wurden direkt ausgelesen, definierte Namen aufgelöst.
Jede Aussage ist mit `Blatt!Zelle` belegt.

**Keine Datei außer diesem Report wurde verändert.** Meine Hilfsskripte liegen ausschließlich unter
`/tmp/opencode/`. `tools/sync_registry.py` lief idempotent (Hashes vor/nach identisch).

---

## 2. Ergebnis in Zahlen

| Urteil | Anzahl |
|---|---:|
| jetzt korrekt | 24 |
| jetzt korrekt, aber unpräzise | 0 |
| weiterhin falsch (Formel/Logik) | 0 |
| nicht belegt | 0 |
| **Summe betroffener Regeln** | **24** |

Die vier vormals falschen **Formeln** (EUE-072, EUE-102, EUE-109, EUE-115) sind sachlich richtig, die
19 Präzisierungen aus § 6 des Vorberichts sind umgesetzt, und die vier Restbefunde der ersten Runde
sind in der Fix-Runde vollständig behoben (§ 4).

---

## 3. Tabelle der 24 betroffenen Regeln

Legende: ✅ korrekt · ⚠️ korrekt, aber unpräzise · ❌ falsch · ➕ fehlte

| Regel | Vorurteil | Jetzt | Zellbeleg |
|---|---|---|---|
| EUE-072 | ❌ | ✅ | `FR!BA3 = IF(AND($S3="",$AC3=""),"",IF($K3="Privat",0,SUM($S3,$AC3)*IF($AX3="",1,$AX3)))` — exakt wie katalogisiert, `AR3` entfernt (steht in `FR!BC3`). `FR!AX3` = `IF($AW3="","",IF(ratio>=90%,100%,IF(ratio<10%,0%,ratio)))`; die neue `wirkung` gibt genau das wieder (unter 10 % → 0 %, ab 90 % → 100 %). BT/PT sind Tage: `FR!AT2 = "BT"`, `FR!AU2 = "PT"`, `FR!AT3`/`AU3` rechnen Datum/Zeit in Tage um — „Reisezeit“ ist korrekt. |
| EUE-102 | ❌ | ✅ | `E1A!C39 = IF(AND($E32<>"",$E32<>0),"",IF($E39="","","9216 "))`; `E1A!C32 = "9275"`; `E1A!D39` warnt bei `$E40>300` nur („Wert löschen (wegen 9217 große Pauschale) →“) und löscht `C39` nicht; `E1A!E39` DV `decimal 0.0–300.0`. Kein englischer Rest. |
| EUE-109 | ❌ | ✅ | `Ü!AA7 = ue_est_bmg` (Cache `-705,6`), `Ü!AA8 = IF(ue_est_modus="Nur andere Gewinne",0,N(e1a_9225))` (Cache `0`), `Ü!AA9 = MIN(SUM(AA7:AA8),set_sv_bgl_max*12)` (Cache `-705,6`) — 9225 wird **addiert**. Deckelung `Einstellungen!I57` 7.070/7.525/8.085 × 12 = 84.840/90.300/97.020. |
| EUE-115 | ❌ | ✅ | `Ü!AA8` addiert 9225 zur Basis; `Ü!F12` entfernt 9225 aus den Ausgaben (`… - SUMIFS(…,"9225*") * --(ue_sv_modus<>"Nur Unfallversicherung") …`, im „Nur Betrieb“- und im Else-Zweig); `E1A!E33` summiert `"9225*"`. Die neue `wirkung` trennt beide Richtungen und beide Modi korrekt (§ 4.4). |
| EUE-131 | ➕ | ✅ | `Ü!Y5` DV = `"Gewerbetreibende (GSVG),Neue Selbständige (GSVG),Freiberufliche (FSVG),Nur Unfallversicherung"`; `Ü!Z15`/`Ü!AA15` prüfen `"Freiberufliche (FSVG)"`; `Ü!I12` prüft `"Freiberufler (FSVG)"` → FSVG-Zweig unerreichbar, Fallback `set_sv_gsvg_pv_min_beitrag`. `Einstellungen!I62` 103,69/110,22 vs. `I60` 95,91/101,95 → 7,78 € (2024) / 8,27 € (2025+). Cache `Ü!I12 = 160,80075`. |
| EUE-013 | ⚠️ | ✅ | `Einstellungen!C58 = "Österreich"`, `C231 = "Zypern"`, `C232` leer → 174 Länderzeilen; `D58 = IF(j>=2025,30,26.4)`, `E58 = IF(j>=2025,17,15)`. |
| EUE-020 | ⚠️ | ✅ | `Einstellungen!I64` = 20 % für 2023–2026 (Cache `0,2`); `Ü!Z15` wählt bei FSVG `set_sv_fsvg_pv_satz`. Quellen `Einstellungen!I64` und `Ü!Z15`; Tippfehler `ŞZ!Z15` behoben. |
| EUE-023 | ⚠️ | ✅ | `Einstellungen!N3:N30` = 28 Zeilen, 27 unterschiedliche KZ (9135 doppelt: `N8` E1A, `N26` E1B); `E1A!C17:C38` = 22 KZ, `E1B!C10:C15` = 9500/9135/9510/9520/9521/9530. |
| EUE-029 | ⚠️ | ✅ | `Einstellungen!O3` → `❯` / `99+` / Zahl; `Y3` → `❯` / `999+` / Zahl; „Verwendungen“ (kein Englisch). |
| EUE-030 | ⚠️ | ✅ | DVs: `Einstellungen!R3:R50` (0–1), `E!G3:G1000`/`AV!H3:H50`/`WEB!G3:G100` (1–31), `AV!O3:O50` (2–99), `FR!O3:O102` (0–10), `E1A!E39` (0–300), `E1A!E40` (25–1200), `Einstellungen!D7` (11–990), `Monat!P5` (OFF + 12 Monate); `A!G` und `FR` ohne Tages-/Monats-DV; Parameter ohne rohe Zellreferenz. |
| EUE-035 | ⚠️ | ✅ | `E!D3 = IF(OR(M3="",P3=0),"",1)`; `E!D4` mit `IF(D3<>"",D3+1,COUNTIFS(M$3:M3,"<>",P$3:P3,"<>0")+1)`. |
| EUE-037 | ⚠️ | ✅ | Monats-DV `E!F3:F1000`, `A!F3:F1000`, `AV!G3:G50`, `WEB!F3:F100`; Tag-DV nur `E!G`, `AV!H`, `WEB!G` (1–31); `FR` ohne Monats-/Tages-DV; `E!B3`/`A!B3` mit „⚠ Ungültiger Monat.“, `A!B3` mit „• Tag fehlt“. |
| EUE-044 | ⚠️ | ✅ | `A!D3 = IF(OR(L3="",P3=0),"",1)`; `A!D4` mit `COUNTIFS(L$3:L3,"<>",P$3:P3,"<>0")`. |
| EUE-065 | ⚠️ | ✅ | `FR!Z3` 1.500/3.000 bzw. 30.000; `FR!Z4` SUMIF-Abzug; `FR!AA3 = IF($Z3="","",MIN($Z3,MAX(0,IF(AND($Q3="",$P3=""),$R3,N($Q3)-N($P3)))))`. |
| EUE-066 | ⚠️ | ✅ | `FR!AB3` `MIN($O3,6)` (PKW) / `MIN($O3,1)` (Motorrad); drei Mitfahrer-Warnungen in `FR!B3`. |
| EUE-067 | ⚠️ | ✅ | Beide Prüfungen in `FR!B3` („⚠ ANFANG-KM und END-KM oder alternativ DISTANZ (KM) angeben“, „⚠ Entweder Anfang-/Endestand ODER Distanz angeben“); `FR!AA3` nutzt `R3` bei leerem `P/Q`. |
| EUE-070 | ⚠️ | ✅ | `FR!AF3` = `FR!AG3` = `=SUM($AI3,N($AJ2>0))` (Cache je `1`); 35-Tage-Fenster in `FR!AF4`, ohne Fenster in `FR!AG4`; `FR!AL3` deckelt auf `5*12`/`15*12`. |
| EUE-082 | ⚠️ | ✅ | `UST!D5..D14` = 000/016/057/(D8,D9 leer)/070/072/073/008/125; Labels `UST!C10..C14` = Gesamt-ig.-BMG, 20 %, 10 %, 13 %, 4,9 %; Quellen inkl. `D11`–`D13`. |
| EUE-104 | ⚠️ | ✅ | `Ü!U5` DV `Ja,Nein`; `Ü!R5` DV `Gesamt,Nur Betrieb,Nur Vermietung`; `Ü!U8`/`U13` Prognose; Verteilung `Ü!J12`/`K12`; `Ü!I13` EST/SV-Basis, `I15` Prognose, `I16` Verlustbasis (Array). |
| EUE-106 | ⚠️ | ✅ | `Ü!Y17`, `R24`, `R25`, `U24`, `U25` verzweigen wie katalogisiert; `I13` auf `$U$5`, `N4` auf „Nur andere Gewinne“; „branchten“ entfernt. |
| EUE-107 | ⚠️ | ✅ | `Ü!Z13` SVS `"-"` bei FSVG/UV; `Ü!Z15` PV `"-"` nur bei UV, bei FSVG 20 % (`Einstellungen!I64`, Cache `0,2`); `Ü!Z14` KV bei Jungunternehmer/UV. Quelle jetzt `Einstellungen!I64` (§ 4.2). |
| EUE-108 | ⚠️ | ✅ | `Ü!AA13` MAX-basiert (Cache `101,169`), `Ü!AA14` = 449,64 (37,47×12), `Ü!Z13` ohne Jungunternehmer-Bedingung (1,53 %), `Ü!AA5` DV `Ja,Nein`; `Z15`/`AA15` ohne Jungunternehmer-Ausnahme. Schlusssatz jetzt korrekt (§ 4.3). |
| EUE-127 | ⚠️ | ✅ | CF `containsText NOT(ISERROR(SEARCH("⚠",B3)))` + `notContainsBlanks` auf `E!B3:B1000`, `A!B3:B1000`, `AV!B3:B50`, `FR!B3:B102`, `WEB!B3:B100`; `Monat!P7` `beginsWith "💡"`. |
| EUE-128 | ⚠️ | ✅ | `colorScale` auf `Ü!E7:E10/F7:F10/G7:G10/E12:E23/F12:F23/G12:G23/N7:N10/N12:N23`; `BE-ART!E3:P152/Q3:Q152/R3:R152`; `BA-ART!E3:P153/Q3:Q153/R3:R153`; `Projekte` zeilenweise `D3:Y3 … D79:X79`; `ZA!E14:P14/E23:P23/…/E95:P95` `containsText "✓"`. |

---

## 4. Fix-Runde: Prüfung der vier Korrekturen

Alle vier Befunde der ersten Runde wurden ausschließlich an den genannten Zellen nachgeprüft.
Die Mappe selbst ist unverändert (gleiche SHA-256, gleiche mtime); geändert wurde nur der Katalog.

### 4.1 EUE-072 — `wirkung` jetzt 0 % / 100 % ✅

**Neue `wirkung`:**
> „… liegt er unter zehn Prozent, wird er mit **null Prozent** berücksichtigt, liegt er bei **mindestens
> neunzig Prozent**, mit einhundert Prozent.“

**Arbeitsmappe:**
```
FR!AX3 = IF($AW3="", "",
          IF(ratio >= 90%, 100%,
           IF(ratio < 10%, 0%,
            ratio)))          ratio = SUMIF(fr_bt) / (SUMIF(fr_bt)+MAX(0,SUMIF(fr_pt)))
FR!BA3 = … SUM($S3,$AC3) * IF($AX3="",1,$AX3)
```
**Befund:** Die Beschreibung stimmt jetzt in beiden Schwellen und beiden Werten mit `FR!AX3` überein
(`<10 % → 0 %`, `>=90 % → 100 %`, dazwischen exakter Anteil). Die Formulierung „Verhältnis der betrieblichen
zur gesamten Reisezeit“ ist belegt: `FR!AT2 = "BT"`, `FR!AU2 = "PT"`, und `FR!AT3`/`AU3` berechnen aus
Datum/Zeit **Tage**, nicht Beträge (`= … N($H3)+1 - …`). `FR!BA3` ist unverändert exakt.
**Ergebnis:** behoben.

### 4.2 EUE-107 — Quelle jetzt `Einstellungen!I64` ✅

**Katalog (`quellen`):** letzter Eintrag `{"beleg":"FSVG-PV-Satz 20 %","blatt":"Einstellungen","zelle":"I64"}`.

**Arbeitsmappe:** `Einstellungen!I64 = IF(set_wirtschaftsjahr=2026,20%,IF(…2025…,20%,…))` (Cache `0,2`),
definierter Name `set_sv_fsvg_pv_satz = Einstellungen!$I$64`. Das Blatt `Ü` hat `dimensions = A1:AC33`;
`Ü!I64` ist außerhalb des belegten Bereichs und leer. Die übrigen Quellen (`Ü!Y5`, `Z13`, `Z15`) und der
`logik`-Text sind unverändert.
**Ergebnis:** behoben.

### 4.3 EUE-108 — „nur UV lässt KV/SVS/PV leer“ präzisiert ✅

**Neue `wirkung` (Schluss):**
> „Nur der Modus ‚Nur Unfallversicherung‘ lässt KV, SVS und PV **gemeinsam** leer; bei freiberuflicher
> Tätigkeit entfällt zusätzlich die SVS.“

**Arbeitsmappe:**
```
Ü!Z13  = IF(OR(ue_sv_modus="Freiberufliche (FSVG)", ue_sv_modus="Nur Unfallversicherung"), "-", set_sv_svs_satz)
Ü!AA13 = IF(ue_sv_svs_satz="-", "", …)
Ü!Z15  = IF(ue_sv_modus="Nur Unfallversicherung", "-", IF(…"Freiberufliche (FSVG)"…, set_sv_fsvg_pv_satz, …))
Ü!AA14 = IF(ue_sv_modus="Nur Unfallversicherung", "", …)   Cache 449,64
```
**Befund:** Bei „Nur Unfallversicherung“ sind `Z13`/`Z14`/`Z15` = `"-"` und `AA13`/`AA14`/`AA15` = `""`
(gemeinsam leer). Bei FSVG setzt `Z13` die SVS auf `"-"`, `AA13` wird `""`; KV/PV bleiben besetzt.
„SVS und PV sind von der Jungunternehmer-Regelung nicht betroffen“ ist belegt: `Z15`/`AA15` und
`Z13`/`AA13` referenzieren `AA5` nicht, `Z14`/`AA14` schon.
**Ergebnis:** behoben.

### 4.4 EUE-115 — Modus-Sätze getrennt ✅

**Neue `wirkung`:**
> „Bei der Versicherungsart ‚Nur Unfallversicherung‘ unterbleibt das Herausrechnen aus den Ausgaben; der
> Modus ‚Nur andere Gewinne‘ lässt lediglich den Zuschlag zur Beitragsgrundlage entfallen, das Herausrechnen
> bleibt bestehen.“

**Arbeitsmappe:**
```
Ü!F12 = … - SUMIFS(a_betriebsausgaben, a_monate, $C12, a_est_kennzahlen, "9225*")
             * --(ue_sv_modus<>"Nur Unfallversicherung") + …      („Nur Betrieb“- und Else-Zweig)
Ü!AA8 = IF(ue_est_modus="Nur andere Gewinne", 0, N(e1a_9225))      Cache 0
```
**Befund:** Das Herausrechnen hängt an `ue_sv_modus`; bei „Nur Unfallversicherung“ ist der Faktor `0`, die
Subtraktion unterbleibt. „Nur andere Gewinne“ ist ein `ue_est_modus` und fällt in `F12` in den Else-Zweig,
in dem 9225 weiterhin herausgerechnet wird; nur `AA8` wird `0`. Genau so beschreibt es die neue `wirkung`.
**Ergebnis:** behoben.

---

## 5. Stichprobe nicht betroffener Regeln (Regression)

Fünf Regeln, die in keiner Korrekturrunde genannt wurden:

| Regel | Urteil | Beleg |
|---|---|---|
| EUE-014 | ✅ | `Einstellungen!I57` = 8085/7525/7070; `Ü!AA9` = `MIN(SUM(AA7:AA8),set_sv_bgl_max*12)`; `Ü!Y10` = `IF(set_sv_bgl_max*12 = ue_sv_bmg,"Wow! Maximale Beitragsgrundlage erreicht.","")`. |
| EUE-049 | ✅ | `AV!S3` = `ROUND($N3/$O3*IF(ISNA(MATCH($G3,{Juli…Dezember},0)),1,0.5),2)` bei `$F3 = set_wirtschaftsjahr`; Folgejahreszweig prüft gegen `$Q3`. |
| EUE-090 | ✅ | `Ü!R18` = 20818/21617/21992; `Ü!S18` = 30 % für 2024–2026; `Ü!U18` = `(MAX(Einkommen-R18,0)-MAX(Einkommen-R19,0))*S18`. |
| EUE-101 | ✅ | `E1A!C40` = `"9217"` für `E40<=1200 AND E40>300`, `"9215"` für `E40<=300`, sonst leer, mit `E32`-Guard; `E1A!D40` = „Großes/Kleines Arbeitsplatzpauschale“/„Falscher Betrag“; DV `E1A!E40` 25–1200. |
| EUE-110 | ✅ | `Ü!AA14` Cache `449,64`; `Einstellungen!I58` = 6,8 %, `I59` = 37,47. |

**Keine Regression** in der Stichprobe.

---

## 6. Mechanischer Zustand

```
$ python3 tools/validate_domain_rules.py
SCHEMA VALID — 131 rules, 26 year-specific
exit=0
```
```
$ python3 tools/sync_registry.py
synced 131 rules → rules.json, README.md, DOMAIN.md
exit=0
```
Idempotent: SHA-256 von `business-rules/rules.json`, `business-rules/DOMAIN.md`,
`business-rules/README.md`, `README.md` und diesem Report vor/nach dem Lauf identisch (`diff` leer).
```
$ python3 tools/verify_rules.py
[1] SCHEMA VALID — 131 rules, 26 year-specific
[2] flat registry in sync: 131 rules
[3] generated artifacts reproducible: OK
[4] natural-language fields: 131 rules checked, 0 violations
[5] year validity: 26 year-specific rules, 0 invalid
[6] ratchet — 1 rule, 0 failed | PASS business-rules-extracted 131 ≥ 91
VERIFICATION PASSED — all automated checks green
exit=0
```
```
$ /projects/ratchet-cli/target/release/ratchet check .
ratchet — 1 rule, 0 failed
  PASS  business-rules-extracted        131 ≥ 91  (extrahierte Business Rules)
COHERENT — 1 of 1 gates passed
exit=0
```
**Hinweis:** Die Meldung „monotonicity skipped: ratchet.baseline.json is not committed yet“ ist ein Hinweis,
kein Fehler. (In der ersten Runde war ein `ratchet`-Lauf versehentlich parallel zu `sync_registry.py`
gestartet und las `rules.json` mitten im Schreiben; sequenzielle Läufe sind grün.)

**Registry:** 131 Regeln, IDs `EUE-001` … `EUE-131` lückenlos und eindeutig, `rules.json` deckungsgleich
mit `domain-rules.json`, 26 year-specific (Definition aus `tools/validate_domain_rules.py`).

---

## 7. Sprache und Formeltrennung in den 24 Regeln

Geprüft wurden `titel`, `ausloeser`, `wirkung`, `rechtsgrundlage`, `gueltigkeit.hinweis`,
`parameter[].name`, `parameter[].hinweis`.

- **Excel-Syntax:** keine rohen Zellreferenzen und keine Formeln in fachlichen Feldern; Zellreferenzen
  stehen nur in `logik`/`quellen`. Die frühere Beanstandung „Arbeitsplatzpauschale E1A!E40“ ist behoben.
- **Englisch/Denglisch:** keine Treffer, auch nicht in den vier überarbeiteten `wirkung`-Texten.
  „however“, „Zahl = Usage“ und „branchten“ sind entfernt. `tools/verify_rules.py` meldet für alle
  131 Regeln `0 violations`.

---

## 8. Endurteil

**APPROVED.**

Die vier Restbefunde der ersten Runde sind vollständig und exakt behoben:

1. **EUE-072** — `wirkung` beschreibt jetzt `<10 % → 0 %` und `>=90 % → 100 %` wie `FR!AX3`; die
   Formulierung „Reisezeit“ ist durch `FR!AT3`/`AU3` (Tage) belegt. ✅
2. **EUE-107** — Quelle zeigt jetzt auf `Einstellungen!I64` (20 %, `set_sv_fsvg_pv_satz`); `Ü!I64`
   existiert nicht mehr als Quelle. ✅
3. **EUE-108** — „nur UV lässt KV, SVS und PV **gemeinsam** leer; bei FSVG entfällt zusätzlich die SVS“
   entspricht `Ü!Z13`/`AA13` und `Ü!Z15`/`AA15`. ✅
4. **EUE-115** — die `wirkung` trennt jetzt korrekt: UV → kein Herausrechnen aus den Ausgaben
   (`ue_sv_modus`-Faktor in `Ü!F12`), „Nur andere Gewinne“ → nur `Ü!AA8` = 0, Herausrechnen bleibt. ✅

Alle 24 betroffenen Regeln sind damit korrekt, die 19 Präzisierungen und EUE-131 sind belegt, die fünf
Stichproben zeigen keine Regression. Schema, Registry-Sync, Reproduzierbarkeit, Sprachprüfung und
Ratchet-Gate sind grün. **Der Katalog ist freigabefähig.**
