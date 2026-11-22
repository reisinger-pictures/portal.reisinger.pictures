# Statusdokument Regelabdeckung (abgeleitetes Clean-Room-Artefakt)

**Zweck:** den aktuellen, aus den Nachweisen des Ausgangsprojekts messbaren
Abdeckungsstand festhalten und überholte Aussagen kenntlich machen.
**Grundlage:** `verification/rule-coverage.json`,
`verification/parity-report.md`, `verification/ods-rule-verification.md`,
`verification/re-verification.md`, Regelkatalog und DEG-Katalog.

## 1. Aktueller Stand

| Kenngröße | Wert |
|---|---:|
| Regeln im autoritativen Katalog | 131 |
| Fachbereiche | 11 |
| Jahresabhängige Regeln (kanonische Definition) | 26 |
| `covered` | 121 |
| `missing` | 0 |
| `not-applicable` | 10 |
| DEG-Regeln (Sekundärquelle, separat) | 15 |

Die 10 `not-applicable`-Fälle sind reine Arbeitsmittel-Artefakte
(Formatierung, Zellenwerte, Druckbereiche). Das Backlog nicht implementierter
Regeln ist leer.

## 2. Nachweise

- `verification/ods-rule-verification.md`: 131/131 Regeln mit Beleg in der
  Clean-Room-Basis, 0 Abweichungen, Belege in allen 16 Blättern (16/16).
  Der Regelkatalog selbst hat 11 Bereiche (B01 … B11).
- `verification/re-verification.md`: unabhängige Zweitprüfung derselben 131
  Regeln.
- `verification/parity-report.md`: Abdeckung nach Bereich mit Unit- und
  E2E-Referenzen je Regel.

## 3. Überholte Aussage

Die in einer früheren Planung zitierte Aussage
„7 covered / 15 partial / 99 missing / 10 not-applicable" beschreibt einen
**früheren** Zwischenstand und ist ausdrücklich **überholt**. Sie darf nicht
mehr als Grundlage für Umfangs- oder Aufwandschätzungen verwendet werden.
Maßgeblich ist ausschließlich der Stand 121 / 0 / 0 / 10 aus Abschnitt 1.

## 4. Abgrenzung

- DEG-001 … DEG-015 zählen **nicht** in den Floor von 131 und werden separat
  ausgewiesen.
- `covered` setzt Smoke- **und** Playwright-Referenz voraus; Unit-Nachweise
  allein genügen nicht.
- Die externe steuerliche Prüfung bleibt dokumentierter Vorbehalt, kein
  Blocker.
