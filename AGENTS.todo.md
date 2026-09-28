# Task Board — Portal Reisinger Pictures

> Stand: 2026-09-27. Architekturentscheidungen in `features/`.
>
> Test-Regel (DoD): Backend → PHPUnit, Frontend-Logik → Vitest, UI/Formulare → Playwright-E2E.
>
> **Struktur-Hinweis (2026-09-27, nach der Board-Bereinigung):** Dieses Board
> enthält **142 offene Positionen** über 1841 Zeilen,
> **0 erledigte** — erledigte Einträge werden entfernt, nicht abgehakt
> (`AGENTS.md` §3 Board-Hygiene). Jede offene Position trägt einen der drei
> Gründe, warum sie noch steht: **32× `manuell prüfen:`** (der Owner
> sieht es sich nach einem Deploy an), **16× `Entscheidung offen:`**
> (der Owner muss entscheiden), **45× `wartet auf`** (Bedingung oder
> Folgetask fehlt noch). Die restlichen **49** sind **gewöhnliche,
> sofort umsetzbare Arbeit** und tragen deshalb keinen Präfix — ein Präfix
> ohne Grund wäre schlechter als keiner.
> Die Positionen liegen in **11 von 22 `##`-Abschnitten**
> mit offenen Positionen. Diese Zahl ändert sich mit jeder Runde, deshalb steht sie
> hier **nur** als Orientierung und ist nicht festgeschrieben.
> Wer hier eine Position sucht, nutzt `grep -n '^- \['
> AGENTS.todo.md` und nicht die Reihenfolge im Dokument.

---

## 🚀 PRODUKTIONS-DEPLOY 2026-09-27 (`002e9eb`) — manuell prüfen

Deploy ausgeführt: `./sync.sh` → `docker restart portal_backend` → Gegenprobe.
**Maschinell verifiziert:** 4 Container healthy, der neue PHP-Code ist im laufenden
Prozess vorhanden (OPcache/Compiler-Zustand nach Neustart), Frontend-Bundle-Hash
unverändert gegenüber dem vorigen Deploy, Homepage **200**.
**Noch manuell durch den Owner zu prüfen** (nicht automatisierbar, kein Test deckt sie):

- [ ] manuell prüfen: FTP-Inbox im Browser — anmelden, Fotografen-Dashboard
  (`/management/ftp`), den Kamera-Status prüfen: Konto-Status, Zielordner-Pfad und
  der Inhalt der Inbox-Tabelle müssen dem tatsächlichen SFTPGo-Account auf dem Host
  entsprechen. Worauf es ankommt: **kein** 500er auf `GET /api/management/ftp/status`
  und der angezeigte `ftp_folder` muss der Name sein, den die Kamera als Ziel bekommt.
  **Erst die Migration prüfen, dann den Browser:** `sync.sh` führt keine Migration aus
  (§13), und `FtpController::status()` liest eine Spalte aus V041. Steht V041 auf dem
  Host auf `pending`, ist der 500er erklärt und der Browser-Test gegenstandslos —
  siehe den Eintrag „`GET /api/management/ftp/status` liefert 500, wenn die Spalte fehlt".
- [ ] manuell prüfen: Lizenz-Dialog auf Escape und Backdrop-Klick — Coupon-Formular
  (`CouponFormDrawer`) im Warenkorb öffnen, ein Feld ändern, dann **Escape** drücken
  und danach über den **Backdrop** schließen. Beide Wege müssen jetzt die
  Ungespeichert-Warnung auslösen — vorher taten das nur „Schließen" und „Abbrechen",
  und getippte Eingabe konnte still verworfen werden. Worauf es ankommt: beide
  Abbruchwege fragen nach, keiner verwirft mehr.
- [ ] manuell prüfen: Tastatur-Fokusreihenfolge in den elf migrierten Dialogen —
  die elf Dialoge der Welle nacheinander per Tastatur öffnen (Tab / Shift-Tab) und
  prüfen, dass der Fokus **in der Dialog-Box bleibt** und auf dem beschrifteten
  Schließen-Element landet (nicht auf dem Seiten-Hintergrund, nicht in der
  Adressleiste). **Kein Test deckt die Reihenfolge ab** — `DialogAccessibilityContract.test.tsx`
  prüft Namen, `aria-modal` und Escape, nicht die Tab-Reihenfolge.

---

## 📌 OFFENE ARBEIT — Einstieg (Stand 2026-09-26)

Die Einstiegs-Tabellen unten sind ein **Snapshot vom 2026-09-26** und
zählen nicht die 142 offenen Positionen von heute — sie ordnen nur die
Zuordnung nach Abhängigkeit. Maßgeblich ist die Liste selbst, nicht diese
Einstiegs-Reihenfolge. Sortiert nach dem, was die Position **für den Start**
braucht, nicht nach Schwere oder Familie.

### A. Jetzt umsetzbar (keine Entscheidung, kein Dienst nötig)

| Position | Was | Quelle |
|---|---|---|
| ~~FE-8~~ | **Erledigt** — `ModalShell`-Migration vollständig: 28/28 Nutzungsdateien (inkl. `UIProvider`; Beleg + Kommando in DOC-13 (c)) | unten |
| ~~P1-M21~~ | **Erledigt** — `FtpSlug` + Validierung | FTP-Block |
| ~~P1-M22~~ | **Erledigt** — `SftpGoClient` + `FtpCredentialService` | FTP-Block |
| ~~P1-M23~~ | **Erledigt** — Passwort-Fluss | FTP-Block |
| ~~P1-M25~~ | **Erledigt** — Regression lokale Disk | FTP-Block |
| ~~P1-M28~~ | **Erledigt** — Concurrency-Guard | FTP-Block |
| ~~P1-M30~~ | **Erledigt** — Migration V041 | FTP-Block |
| ~~P1-M27~~ | **Erledigt** — Kamera-Spec + Harness | FTP-Block |
| FE-2 (halb) | i18n-AST-Regel gebaut, meldet 246 Treffer, warnt nur | unten |
| P1-M24 | Ordner-Anlage und UID-Modell auf dem Host | FTP-Block |
| ~~P1-M26~~ | **Erledigt 2026-09-26** — `status()` zeigt den Provisionierungsstatus | FTP-Block |
| ~~P1-M33~~ | **Erledigt 2026-09-26** — Reset mit Rate-Limit und Audit-Trail | FTP-Block |
| TST-* (4) | Testqualitäts-Lücken, siehe Test-Audit | Test-Audit |

**Priorität 2026-09-26: Live-Deployment.** Ziel ist SFTPGo live zu schalten,
pure-ftpd abzuschalten, und dann mit echten Kameras zu testen und zu korrigieren.
M26 und M33 sind inzwischen abgeschlossen.

### B. Braucht eine Entscheidung oder Betriebs-Evidenz

| Position | Blockiert durch | Wer entscheidet |
|---|---|---|
| P1-M27 / M35 | Kamera-Protokoll ungeklärt; Messung steht aus | Fotograf + laufende Instanz |
| P1-M31 | `ftp_slug` vs. SFTPGo-Store: wer führt? | Owner |
| P1-M29 | Brand-Scope des Folder-Namespaces (bewusst YAGNI) | Owner, bei 2. Brand |
| P1-M36 | Cutover gegen **laufenden** `pure-ftpd` | Owner + Runbook 7.12 |
| P1-I9 | `portal_backend` läuft als root, Repo fordert `1000:1000` | Owner, vor Cutover |
| Auth-* (5) | Auth-Befunde, Verifikation ausstehend | Owner |
| P1-M24 | Ordner-Anlage und UID-Modell auf dem Host | Owner, Host-Zugriff |

### C. Ungeprüft — nicht als offen oder erledigt lesen

Diese Familien sind **weder** verifiziert **noch** abgehakt. Sie stehen in
alten Abschnitten und sehen je nach Abschnitt erledigt oder offen aus. Erst
prüfen, dann anfassen:

| Familie | Anzahl | Wo |
|---|---|---|
| `AIS-*` | 8 | verstreut, Abschnitt „P1 — AI / Mail / Jobs" |
| `AUTH-*` | 5 | dito |
| `TST-*` | 4 | Test-Audit, dort als Lücken geführt |
| P1-M32 | 1 | Kameraneukonfiguration — operativer Schritt, kein Code |

### Reihenfolge, die sich daraus ergibt

1. **Kamera prüfen** (P1-M32, ~20 Min, nur beim Fotograf). Klärt, ob SFTP oder
   FTPS überhaupt der Weg ist. Danach ist die Grundsatzentscheidung belegt.
2. **Harness bauen** (P1-M35) — beantwortet die Cipher-Frage und ist die
   Grundlage für den Integrationstest (P1-M38).
3. **A-Gruppe abarbeiten** — die M-Positionen in der Reihenfolge
   M21 → M22 → M23 → M30.
4. **Cutover** (P1-M36) erst, wenn 1–3 stehen und M27 beantwortet ist.

> **ID-Nennräume, am 2026-09-28 nachgemessen:** Die unter Position **DOC-13**
> behaupteten Kollisionen sind **größtenteils erledigt**. Die `P1-M`-Kollision ist
> aufgelöst — die dort formulierte Invariante ist leer, und `P1-M9a`/`P1-M9b` zeigen,
> dass umnummeriert wurde. Von den fünf `DOC`-IDs ist keine doppelt, `DOC-7` kommt
> gar nicht vor. `FE-2` ist keine Dublette (Index und Definition sagen beide „halb").
> **Aufgelöst am 2026-09-28:** Die letzte offene Doppelnennung ist beseitigt —
> `FE-8` stand in der Übersicht als *erledigt* und im Arbeitsplan als offener
> Brocken; der Arbeitsplan führt es nicht mehr als offene Arbeit, und die
> Übersichtszeile trägt den nachgemessenen Stand (28/28, Beleg in DOC-13 (c)).

---

## 🚦 DEPLOY-STATUS (2026-09-26) — hier zuerst schauen

`main` ist grün: `CI` auf `be2977a` mit allen 11 Jobs (Backend inkl. Pint-Gate,
Frontend, Security-Contract, 7× E2E, CI-Gate). Offene PRs: 0. Offene
Security-Alerts: 0.

**Bevor dieses Board als Auftragsliste gelesen wird, eine Warnung:** Am
2026-09-26 wurden alle 81 offenen Positionen systematisch gegen den Code
verifiziert (5 Verifikationsläufe über P1-A/I/F/M/L, INFRA, DOC, FE). Ergebnis:

| Familie | Geprüft | Already done | Wirklich offen |
|---|---|---|---|
| P1-A (Auth/AI/Jobs) | 7 | 5 | 0 Code, 2 Evidenz |
| P1-I (Infra/CI) | 8 | 6 | 1 Code, 1 Einstellung |
| P1-F (Frontend) | 10 | 9 | 0 |
| P1-M (Model/Services) | 13 | 12 | 0 (behoben in `43e8943`) |
| P1-L (Lua-Plugin) | 7 | 5 | 0 (behoben in `43e8943`) |
| INFRA-3…12 | 10 | 10 | 0 |
| FE-2…8 | 7 | 5 | 2 |
| DOC-2…12 | 11 | 3 | 8 (Doku-Wahrheit) |

**Das Board war zu ~85 % veraltet.** Wer hier blind eine Position abhakt oder
abarbeitet, fasst überwiegend bereits korrigierten Code an. Jede Position
unten ist gegen den Code geprüft; Belege stehen bei der jeweiligen Zeile.

### Offene Code-Arbeit

- [ ] Entscheidung offen: **i18n-Rückstand — erst entscheiden, ob die Regel auf Satzebene zusammenführt, dann abarbeiten.**
  `CHECK_I18N_UNLOCALIZED_STRICT=1 node scripts/check-i18n.mjs` meldet **246**
  Treffer in **33** Dateien: `jsx-text` 175, `jsx-attribute` 44,
  `helper-argument` 27 (Scan über 331 Quellen:
  `find src -type f \( -name '*.ts' -o -name '*.tsx' \) | wc -l`).
  **Warn-only, Exit 0** — ein Gate, das an 246 Altbefunden scheitert, benutzt
  niemand. `CHECK_I18N_UNLOCALIZED_STRICT=1` schaltet auf harten Fehlschlag
  (verifiziert: Exit 1), sobald der Bestand auf 0 ist.
  **Nicht abgearbeitet, bleibt als Altlast im Repo:** die 246. Die größten Brocken
  (`findUnlocalizedStringsInTree()`, Treffer pro Datei): `LicenseCatalogSettings.tsx` (62),
  `BrandSettingsCard.tsx` (25), `ShootingCalculatorModal.tsx` (24),
  `BillingDetailsCard.tsx` (21), `CalculatorSettingsCard.tsx` (18).
  **Zwei Befund-Arten, die keine echten Rückstände sind.** Rund 31 sind Nicht-Prosa:
  17 Tier-Bezeichner (`Web`/`Print`/`Original`), 3 Kameracodes (`Error 41`), 11
  Produkt- und Markennamen (`Logo`, `Cloudflare Turnstile`). Bewusst **nicht** über
  eine Allowlist unterdrückt — eine Liste, die niemand pflegt, wird zur nächsten
  stillen Ausnahme. Dazu eine vierte Art, die die Regel nicht unterscheidet:
  **an Interpolationsstellen zerrissene Sätze.** `Jahre (geb. {x})` ergibt zwei
  JsxText-Knoten (`Jahre (geb.` und `)`); einzeln zu beheben ist sinnlos.
  **Das ist die offene Entscheidung (nicht agentenseitig): Führt die Regel ihre
  Meldungen auf Satzebene zusammen — oder nicht?**
  - **Option A — Regel erweitern:** benachbarte JsxText-Knoten um ein
    Interpolationsloch zu einem Satz zusammenführen und Nicht-Prosa erkennen — die
    246 sinken auf eine echte Zahl, das Rauschen verschwindet.
  - **Option B — Regel unverändert lassen:** die 246 manuell nach Kategorien
    abarbeiten und die zerrissenen Sätze als bekanntes, akzeptiertes Rauschen führen.
  Erst diese Entscheidung macht die Abarbeitung sinnvoll.
  **Reihenfolge (Plan, keine Entscheidung):** `helper-argument` (27) zuerst, dann die
  `management/`-Konzentration. Umschalten auf `CHECK_I18N_UNLOCALIZED_STRICT=1` erst bei 0.
<!-- FTP-Konten: Ansatz am 2026-09-26 von pure-pw/pure-ftpd auf SFTPGo
     umgestellt. P1-M17..P1-M20 sind durch P1-M21..P1-M29 abgeloest.
     Begruendung: (a) pure-ftpd kann AES128-SHA nicht anbieten, damit
     erreicht die Kamera den Server nicht; (b) pure-ftpd liest die puredb
     nur beim Start, PHP-verwaltete Passwoerter erfordern also Neustarts.
     Der wichtigste Gewinn ist aber P1-M23: mit SFTPGo erzeugt PHP das
     Passwort, zeigt es einmal an und speichert es gar nicht. Damit
     entfaellt die urspruenglich geplante verschluesselte
     ftp_credentials-Tabelle samt FILE_ENCRYPTION_KEY vollstaendig. -->

- [~] wartet auf die Produktentscheidung, ob Fotografen ihr Passwort selbst ändern dürfen (Confirm-Flow mit aktuellem Passwort) oder nur der Admin. **P1-M23 (P0) — Passwort-Erzeugung und Show-once, statt Verschlüsselung
  at rest.** Das ist die **entscheidende Entlastung gegenüber P1-M18** und
  der eigentliche Grund für den Wechsel: SFTPGo hält das Passwort, das
  Portal muss es **nicht** speichern. Damit entfallen `ftp_credentials` als
  verschlüsselte Tabelle, `FILE_ENCRYPTION_KEY` und der
  `FILE_ENCRYPTION_PREVIOUS_KEYS`-Rotationspfad vollständig.
  **Ablauf:** PHP erzeugt ein kamerataugliches Passwort
  (`^[a-z0-9]{16,24}$`, keine Sonderzeichen — Kameras können sie nicht
  eingeben), übergibt es per HTTPS an SFTPGo, **zeigt es einmal** an und
  verwirft es. Verloren → `resetPassword()`, nicht wiederherstellbar.
  **Offen (Produktentscheidung, nicht Implementierung):** dürfen Fotografen ihr
  Passwort selbst ändern (dann Confirm-Flow mit dem aktuellen Passwort) oder
  nur der Admin? Nicht als Code-Task vergeben, bis das geklärt ist.
  **Der Provisionierungsstatus selbst ist in P1-M30 festgelegt** (Spalte auf
  `users`, kein Live-Query) — die frühere Unklarheit ist aufgelöst.
  **Tests:** PHPUnit für Erzeugung (Länge, Charset, keine Kollision nach
  `Str::random` + Prüfschleife) und dafür, dass im Request-Log/Response
  nach dem Show-once **kein** Klartext mehr auftaucht; Playwright-E2E für
  Anzeige und Reset.
- [ ] Entscheidung offen: Wer legt den Ordner an — Host-Script oder Admin-Schritt? (Niemals `chown -R` durch einen Entrypoint auf `/home/webadmin/websites`, siehe 19-ftp 7.9.) **P1-M24 (P1) — Ordner-Anlage und UID-Modell klären.** Aus der
  SFTPGo-Doku: *"Virtual folder auto creation on user add/update … you have
  to create the folder on disk yourself"* — SFTPGo legt nichts an. Der Ordner
  `ftp/<ftp_slug>` muss auf dem Host existieren, mit `1002:webgroup` und
  `2777`, **bevor** oder im selben Schritt wie der User.
  **Designprinzip (2026-09-26):** Jeder User bekommt einen eigenen physischen
  Ordner auf dem Host, auf den er eingeschränkt ist. SFTPGo konfiguriert das
  als `home_dir` — der User sieht nur sein eigenes Verzeichnis, nicht die
  anderer User. Kein gemeinsamer `r1`-User mehr. Die Kamera konfiguriert `/`
  als Subpath und lädt direkt in das Home-Verzeichnis. **Die GUI-Auswahl des
  Subfolders entfällt.**
  **Zu entscheiden:** Wer macht das? Ein kleines Script auf dem Host, das die
  Applikation aufruft, oder ein Admin-Schritt. **Wichtig:** Das ist exakt
  die Stelle, an der der Ownership-Vorfall vom 2026-09-26 wieder passieren
  kann (38.969 Dateien umgeschrieben, weil ein Entrypoint `chown -R` auf den
  gemappten Website-Baum lief). **Verboten:** `chown -R` durch
  Container-Entrypoints auf `/home/webadmin/websites`, und `adduser -h` auf
  bestehende Pfade.
  **Tests:** Shell-Test für das Script (existierender Ordner, falscher Owner,
  setgid-Bit) plus PHPUnit, dass ein nicht zugänglicher Ordner zu einem
  verständlichen Fehler führt und der User-Status im Portal als
  „wartet auf Ordner" geführt wird.
- [~] wartet auf die Host-Bindings 2222/989/50000-50100 — ohne sie antwortet im Container nichts, und jedes Messergebnis ist ein Fehlschluss (5 Ursachen, siehe M46). **P1-M35 (P0, neu 2026-09-26) — Testinfrastruktur für den
  Datei-Transport aufbauen; Config-Weg und Cipher-Frage fallen dabei ab.**
  **Neu gefasst 2026-09-26:** Das ist **kein Messskript-Problem**, sondern ein
  Testinfrastruktur-Problem. Messung und Integrationstest (M38) haben
  ** dieselbe** Voraussetzung: eine gesund konfigurierte SFTPGo-Instanz. Wer
  das Harness baut, beantwortet die Cipher-Frage nebenbei — sie zu messen,
  ohne das Harness zu bauen, war in drei Versuchen gescheitert.
  **Konfigurationsfragen, die das Harness beantworten muss:**
  (a) **Config-Verzeichnis**: das Image meldet `config file used:
  "/etc/sftpgo/sftpgo.json"`, `--config-dir` defaultet aber auf `.`; was gewinnt
  in der Portainer-Stack-Umgebung?
  (b) **Datenprovider**: der Default ist `sqlite` mit `Name:sftpgo.db`
  **relativ zum Config-Verzeichnis**; ein nicht persistiertes oder falsch
  gemountetes Verzeichnis ergibt `no such table: schema_version` und **keinen**
  Admin-User (`CreateDefaultAdmin:false`), damit 401 auf `/api/v2/token` — die
  Provisionierung aus M22 kann dann nicht laufen.
  (c) **Admin-Bootstrap**: `SFTPGO_DEFAULT_ADMIN_USERNAME`/`_PASSWORD` müssen
  greifen, sonst gibt es keinen API-Zugang.
  **Konsequenz für M22:** die Client-Implementierung darf **keine** Annahme
  über Erreichbarkeit oder Seed-Zustand treffen; ein nicht erreichbarer Dienst
  muss ein sauberer Fehlerpfad sein, kein 500er (vgl. 7.5 im Feature-Doc).
  **Tests:** PHPUnit mit gemocktem HTTP deckt den 401-Fall ab; den Rest macht
  das Harness aus M38.
  **Messbefunde aus drei gescheiterten Sondenversuchen (2026-09-26)** — die
  Ursache war jedes Mal die Konfiguration, nie SFTPGo selbst:
  SFTPGo 2.7.6-62ae9ba3. (1) Der erste Versand brachte das falsche Ergebnis
  „Cipher nicht angeboten", weil FTPS nie gestartet war: konfiguriert war Port
  9021, gelauscht wurde auf 2022 (Default), also wurde die Config gar nicht
  gelesen. (2) Der Mount nach `/var/lib/sftpgo` blieb ebenfalls wirkungslos,
  weil das Image intern `/etc/sftpgo` meldet. (3) FTPS-Bindings liegen unter
  `ftpserver.bindings[].port` und **nicht** unter `ftpserver.port`;
  `ftpserver.port` wird stillschweigend ignoriert, ohne Fehlermeldung.
  **Die eigentliche Lehre:** Solange die Bindung nicht steht, liefert
  `openssl s_client` **0 gelesene Bytes** und eine leere Cipher-Liste.
  **Ein leeres Ergebnis als „Cipher nicht angeboten" zu protokollieren ist ein
  Fehlschluss** — genau dieser Fehler ist entstanden, und er hätte die
  Grundsatzentscheidung aus einem falschen Grund gekippt. Jede künftige Messung
  muss deshalb **vor** dem Auslesen der Cipher-Liste prüfen, dass der
  TLS-Handshake überhaupt abgeschlossen wurde (gelesene Bytes > 0, Protokoll
  und Cipher gesetzt), und das Ergebnis als Fixture wegschreiben.
- [~] wartet auf das Harness aus P1-M35 — beide setzen dieselbe gesund konfigurierte SFTPGo-Instanz voraus. **P1-M38 (P0, neu 2026-09-26) — Integrationstest des Datei-Transports
  über **beide** Protokolle, mit echtem Client gegen echten Dienst.** Ergänzt
  M35 und M27: das Harness, auf das sich beide stützen.
  **Was dieser Test beweist — und was nicht.** Ein Client-Protokolltest prüft
  die **Server-Seite**: Erreichbarkeit, TLS-Handshake, Login, Upload,
  Ownership-Kette, Passwort-Durchreichung. Er beweist **nicht**, dass die
  Kamera es kann — die Kamera ist kein `curl`, sie hat eine eigene
  TLS-Bibliothek, eine eigene Cipher-Liste und ein Passwortfeld, das
  ausschließlich alphanumerisch akzeptiert. Diese Grenze ist der Grund, warum
  P1-M32 (Kamera bestätigt) ein **operativer** Schritt bleibt und durch keinen
  Test ersetzt wird. Wer diesen Test als Kamera-Nachweis zitiert, hat ihn
  falsch gelesen.
  **Abgedeckte Lücken** (das ist der eigentliche Wert, alles andere wäre
  getestete Fremdsoftware):
  (a) **Passive Port-Range.** Ein FTPS-Client, der 50000–50100 nicht erreicht,
  baut den TLS-Handshake erfolgreich auf und bricht erst beim Transfer ab. Ein
  `curl` gegen Port 989 allein findet diesen Fehler **nicht** — der passive
  Kanal ist ein zweiter TCP-Verbindungspfad. Das ist der häufigste
  Stillschweizgrund (7.13).
  (b) **Ownership-Kette.** Datei hochladen und prüfen, dass sie mit
  `1002:webgroup` ankommt und der `setgid`-Bit auf `ftp/<slug>` gehalten hat.
  Sonst scheitert der Import nach dem ersten echten Foto, beim Fotografen.
  (c) **Show-once-Passwort** (M23): ein alphanumerisches 16–24-Zeichen-Passwort
  muss ohne Sonderzeichen durch Provisionierung und Login kommen. Bisher ist das
  eine Behauptung, kein Beleg.
  (d) **Cipher-Fixture**: `openssl s_client` gegen die laufende Instanz, mit
  **Handshake-Vollständigkeitsprüfung** vor dem Auslesen (siehe M35). Ergebnis
  als Fixture wegschreiben, damit M27 nicht erneut von einer defekten Sonde
  ausgeht.
  **Struktur — drei strikt getrennte Ebenen:**
  1. *Unit/Feature* (bestehend, `Storage::fake()`): `FtpImportTest` prüft die
     Pipeline ohne jeden Dienst und bleibt grün. Für `SftpGoClient` (M22) kommt
     ein gemockter HTTP-Client dazu: jeder Fehlerpfad (Dienst nicht erreichbar,
     Benutzer existiert, 4xx/5xx) und ein Test, der sicherstellt, dass **kein**
     Passwort in Log oder Exception landet.
  2. *Integration*: eigener Job, eigener Compose-Stack mit echtem SFTPGo,
     provisioniertem Test-User und echtem Client (`curl` für FTPS, `sftp`/`ssh`
     für SFTP). **Nicht** Teil von `php artisan test` — sonst wird der Test
     bei der ersten Timing-Abweichung zum Flake und wird abgeschaltet.
  3. *Manuell*: echte Kamera, echtes Foto. P1-M32, nicht automatisierbar.
  **Tests:** PHPUnit für `SftpGoClient` (Ebene 1, gemockt). Der Integrationstest
  ist der Test — er ist **kein** PHPUnit-Fall, sondern ein Skript mit
  Playwright-Tag `@feature:ftp-transport`, damit er getrennt ausführbar ist und
  nicht den Smoke-Lauf blockiert.
- [~] wartet auf den Abschluss von DOC-13: die Einstiegs-Tabelle oben führt P1-M30 als erledigt (Migration V041), dieser Eintrag als offen. Erst den Doppel-ID-Konflikt auflösen, dann entscheiden. **P1-M30 (P0) — Spalte für den Provisionierungsstatus auf `users`.**
  **Festgelegt am 2026-09-26:** die Source of Truth ist eine Spalte, **kein**
  Live-Query gegen SFTPGo. Begründung: `FtpController::status()` (`:21-39`)
  ist ein Endpoint für den Fotografen; ein Live-Query würde die UI vom Dienst
  abhängig machen, während P1-M26 verlangt, dass der Import ohne SFTPGo
  weiterläuft. Beides zugleich ist nicht möglich. Zusätzlich würde ein
  Live-Query im Lesepfad ein Secret auch für Read-Operationen erfordern —
  das widerspricht dem Gewinn aus P1-M23.
  **Schema (V041+, Reihe endet bei V040):** `ftp_account_status` enum
  (`pending` / `active` / `error`), `ftp_provisioned_at` timestamp nullable,
  `ftp_account_error` text nullable. **Backfill:** alle bestehenden
  Fotografen auf `pending`, weil der Zustand unbekannt ist — sie wurden nie
  über SFTPGo provisioniert. **Rollback:** reines Spalten-Drop, kein
  Datenverlust, weil kein Passwort gespeichert wird (P1-M23).
  **Behandlung als Cache:** löscht jemand den User in SFTPGo von Hand, ist die
  Spalte veraltet. Deshalb ein expliziter `reconcileAccount()`-Pfad statt
  einem stillen Live-Query im Lesepfad.
  **Tests:** PHPUnit für Default `pending` bei Neuanlage, Übergänge
  pending→active→error, und dass `status()` bei SFTPGo-Ausfall weiter den
  gecachten Status liefert statt eines 500ers.
- [~] wartet auf den Owner-Eintrag von `SFTPGO_BASE_URL`/`SFTPGO_API_KEY` in die Portainer-Stack-Env; das versionierte Compose bleibt bei Platzhaltern. **P1-M31 (P0) — API-Key und Endpoint in den Stack, nicht ins Repo.**
  `deployment/docker-compose.yml` ist **versioniert**; dort ausschließlich
  `${SFTPGO_BASE_URL}` und `${SFTPGO_API_KEY}` als Platzhalter. Der Wert selbst
  gehört in die **Portainer-Stack-Env**. Commit nur die Platzhalter. Das ist
  exakt die Fehlerklasse aus C1–C4 (hartkodierte Secrets), die das Repo
  historisch schon einmal getroffen hat.
  **AGPL:** SFTPGo ist AGPL-3.0. Wir betreiben das offizielle, unveränderte
  Image, damit haften keine Offenlegungspflichten für das Portal. **Daher
  ausdrücklich kein selbstgebautes/patchtes Image** — das wäre eine andere
  Rechtslage. Rechtlich ist das keine Anwaltsberatung, nur die technische
  Konsequenz aus der Lizenz.
  **API-Schema nicht raten:** Feldnamen sind nicht verifiziert. Quelle in
  dieser Reihenfolge: `GET /openapi` an der laufenden Instanz (Swagger UI,
  im Community-Build aktiv), dann `openapi.yaml` im Repo `drakkan/sftpgo`.
  `enable_rest_api` im Compose **explizit setzen**, damit es eine bewusste
  Entscheidung ist und nicht der Default.
  **Tests:** PHPUnit, dass die Disk-/Config-Definition ohne Secret auskommt
  (kein Literal in der versionierten Compose-Datei), plus ein
  Konfigurations-Test auf die gesetzten Platzhalter.
- [ ] Entscheidung offen: wer die Canon neu konfiguriert und ab wann das gegen P1-M27 geprüft wird — ohne benannten Owner steht nach dem Umbau alles gleichzeitig still. **P1-M32 (P2) — Kameraneukonfiguration hat einen Owner.** Wenn SFTPGo
  pure-ftpd ablöst, muss die Kamera neu konfiguriert werden: Host/Port, neues
  Passwort, ggf. SFTP statt FTPS. Das ist **kein Code-Task** und gehört in
  keinem Board-Eintrag, weil es bisher niemandem zugewiesen war. Vor dem
  Umschalten muss benannt sein, wer das macht und ab wann gegen P1-M27
  geprüft wird. Ohne diesen Schritt steht nach dem Umbau alles gleichzeitig
  still.

### Offene Dokumentations-Wahrheit (kein Code, aber irreführend)

- [ ] **Kanban-Flakiness — die Board-Angabe zur Ursache ist widerlegt, die echte
  Ursache bleibt unbekannt.** Das Board schrieb sie Wartezeiten in
  `frontend/tests/e2e/helpers/KanbanHelper.ts:135,143,196,231` zu. Das hält nicht:
  Die Datei hat 209 Zeilen, `:196` ist leer, `:135` ist ein Assertion-Timeout, und
  `:85` sagt ausdrücklich „There are no retries or fixed dwell delays". Welche
  Ursache die Flakes tatsächlich haben, ist offen und nicht gemessen.
- [~] wartet auf einen Durchgang nach der Board-Bereinigung vom 2026-09-27 — die Widersprüche lagen an `[x]`-Einträgen, die entfernt wurden. **DOC-4 / DOC-5 / DOC-11** — das Board widerspricht sich selbst: Positionen
  sind an einer Stelle `[x] abgeschlossen` und an anderer als `offen` /
  `in Arbeit` geführt. Betroffen: CR-DATA-018/CR-BE-018 (Z. 284/341 vs. 543/545),
  CR-CRM-008 (Z. 282/283/338 vs. 618), CR-DOC-001 (Z. 410, behauptet „V038").

### Braucht eine Entscheidung oder Betriebs-Evidenz (kein Code)

- [ ] Entscheidung offen: läuft SFTPGo als `1002:82` (Host-Konvention) oder bleibt `1000:1000` mit dokumentierter Ausnahme für genau dieses Verzeichnis? Vor dem Stack-Recreate zu entscheiden. **P1-I9 (P0) — Deployment-Drift: `portal_backend` läuft als root, das
  versionierte Compose fordert `user: "1000:1000"`.** Verifiziert 2026-09-26.
  **Befund:** `deployment/docker-compose.yml:58` deklariert
  `user: "1000:1000"` für `backend`. Der laufende Container hat
  `Config.User` = **leer**, `docker exec portal_backend id` →
  `uid=0(root)`. Der Stack ist seit der `user:`-Zeile nie neu erstellt worden.
  **Warum das dringend ist, nicht nur unsauber:** beim nächsten
  `docker compose up -d` fällt der Backend **und** der neue `sftpgo`-Service
  (`:299`, ebenfalls `user: "1000:1000"`) auf UID 1000. Alles, was heute
  implizit über root funktioniert, bricht dann. Konkret verifiziert: mit
  `2755` auf `ftp/<slug>` konnte UID 1000 **weder anlegen noch `unlink`en** —
  und `FtpController::process()` macht genau das (`unlink($file)` nach dem
  Import). Ein solcher Ordner ist mit `2777` entschärft (passiert am
  2026-09-26), die Fehlerklasse bleibt aber: **jeder neue Pfad mit `2755` unter
  `/home/webadmin/websites` bricht beim UID-Wechsel.**
  **Zweite, verwandte Diskrepanz:** `sftpgo` läuft als `1000:1000`, der
  Host-Pfad gehört `1002:webgroup`. Dateien, die der Dienst anlegt, werden
  dadurch `1000`-owned statt `1002`. Funktional auffällig wird es nicht
  (2777 + setgid ⇒ Gruppe erbt sich, Lesen/Löschen gelingt), aber es
  mischt die Ownership-Modelle im Website-Baum — dieselbe Art Unordnung wie
  der `r1`-Vorfall vom 2026-09-26, nur leiser. **Zu entscheiden:** läuft
  SFTPGo als `1002:82`, oder bleibt `1000` und die Site-Konvention wird für
  dieses eine Verzeichnis offiziell ausgenommen?
  **Warum das CI-Gate nicht greift:** `tests/infrastructure/verify-image-nonroot.sh`
  prüft `Config.User` des **Image-Artefakts** (abgefangen in der früheren
  C-Historie), nicht den **effektiven Benutzer des laufenden Containers**. Ein
  korrektes Image mit überschriebenem `user:` im Compose ist für dieses Gate
  unsichtbar.
  **Tests:** (a) PHPUnit/Shell-Test, der `Config.User` des laufenden Containers
  gegen die `user:`-Deklaration im Compose stellt und bei Abweichung
  fehlschlägt — als eigener Vertrag, nicht im Image-Test; (b) Test, dass jede
  unter `/home/webadmin/websites` angelegte Inbox `2777` und `1002:webgroup`
  **oder** die dokumentierte Ausnahme ist; (c) `verify-image-nonroot.sh` um
  einen Hinweis ergänzen, dass es `user:`-Overrides im Compose **nicht**
  abdeckt.
  **Nicht im Scope:** die Reparatur selbst ist ein Deployment (Stack
  recreated), kein Code. Vorher die UID-Fragen oben entscheiden, sonst wird der
  Restart zum Ausfalltag.

- [ ] Entscheidung offen: Branch-Protection setzen? Repo-/Org-Einstellung mit Trust-Fragen, bewusst nicht vom Agenten gesetzt. Gewichtet schwerer als jeder einzelne P1-Befund. **Branch-Protection für `main` fehlt** — `GET /branches/main/protection`
  antwortet `404 Branch not protected`. Der `CI gate (push)` existiert und läuft
  grün, aber **nichts erzwingt ihn**. Das ist der gewichtigste offene Punkt für
  „prod deploy ready" und wiegt schwerer als jeder einzelne P1-Befund. **Bewusst
  nicht vom Agenten gesetzt:** Branch-Protection ist eine Repo-/Org-Einstellung
  mit Trust-Fragen und gehört dem Owner.
- [~] wartet auf einen echten Scheduler-Lauf (`app:import-locations`) plus einen Importer-Lauf. Kein Code offen. **P1-A5** — nur noch Live-Nachweis: ein echter Scheduler-Lauf
  (`app:import-locations`, wöchentlich mit `withoutOverlapping()->onOneServer()`)
  und ein Importer-Lauf. Kein Code offen.
- [~] wartet auf einen Queue-/Mail-/Worker-/Scheduler-Lauf. Die Code-Subblöcke R1–R7 sind verifiziert geschlossen. **P1-A7** — nur noch Operations-Evidenz (Queue/Mail/Worker/Scheduler) und
  eine **Entscheidung zur SMTP-Duplikat-Policy**. Die Code-Subblöcke R1–R7 sind
  verifiziert geschlossen.

### Bewusst NICHT geändert

- [ ] Entscheidung offen: Produktions-Secret-Handling: Rotation und/oder Secret-Manager. Kein Repo-Risiko, nur lokale Hygiene — deshalb ausdrücklich kein Code-Fix. **P1-I1** — `.env.production` liegt mit Live-Secrets auf der Platte, ist
  aber korrekt gitignored (`.gitignore:54:.env*`) und war **nie** committet
  (`git log --all -- .env.production` ist leer). Kein Repo-Risiko, nur
  lokale Hygiene. **Kein Code-Fix**, sonst würde das Secret nur in den falschen
  Ort kopiert.

---

## 🧪 TEST-QUALITÄTS-AUDIT (2026-09-26) — zwei unabhängige Audits

Scope: 84 E2E-Specs (191 `test()`, 278 Testinstanzen, 9.769 Zeilen) und 129
Vitest-Dateien (1.051 Tests) plus 2.440 PHPUnit-Tests. Zwei read-only Audits
auf getrennten Ebenen, beide mit der Auflage, Testnamen nicht für bare Münze zu
nehmen.

**Ergebnis vorweg: die Suite ist gesünder, als die Fragestellung nahelegte.**
77 % der E2E-Specs testen echte Use-Cases, 19 % Frontend-Features, 4 % gemischt.
Die Tag-Policy wird **ohne eine einzige Verletzung** eingehalten (190 von 191
`test()`-Aufrufen explizit getaggt; die eine Ausnahme nutzt eine berechnete
Variable, die regelkonform auflöst). Das Problem ist nicht die Ebene, sondern
**Tiefe und Redundanz**.

### Lücken, die Geld betreffen — höchste Priorität

- [~] wartet auf die 9 neuen Tests in `src/logic/__tests__/usePayouts.test.ts`, die
  `useAdminPayouts` und `useMyPayouts` abdecken. **Der Einwand war berechtigt:**
  `ManagementPayoutsView.test.tsx:6` mockt `../../logic/usePayouts` komplett weg und
  testet nur den Monat/Jahr-Fallback der View — die Zustandsübergaben des Hooks waren
  tatsächlich ungetestet.
  **Was der Hook wirklich tut, gegen die Vermutung:** die Monat/Jahr-Auswahl liegt in
  `ManagementPayoutsView.tsx:12-14` mit Klemmung beim Leeren (`:70-83`), **nicht** im
  Hook. Es gibt **keinen** Fehlerzustand — kein Hook destrukturiert `error`; die View
  leitet ihren Fehlerzweig aus `!data` ab. Und **kein** Refetch: die Hooks nehmen keine
  Argumente, die einzige Revalidierung ist das explizite `mutate()` nach erfolgreicher
  Mutation. Diese drei Wege wurden deshalb **nicht** getestet, statt sie zu erfinden.
  Abgedeckt sind der SWR-Schlüssel je Hook, der Loading-Zustand, die beiden Mutatoren
  mit exaktem Pfad und Body, und die **Reihenfolge** — ein fehlgeschlagener POST
  revalidiert nicht. Die Reihenfolge ist als Zusicherung getestet und beißt: mit
  absichtlich gebrochenem `await`-Verhältnis wurde `does not revalidate when
  calculateMonth rejects` rot.
- [~] wartet auf die 4 neuen Specs, die alle vier Dialoge über die echte UI öffnen:
  `admin/gallery-access-modal.spec.ts`, `admin/rating-status-modal.spec.ts`,
  `admin/text-snippets-modal.spec.ts` und `AT-03-E5` in
  `photographer/ai-gallery-defaults.spec.ts`. 10 passed (Desktop + Mobile).
  **Die Board-Angabe „admin-only" für `GalleryAccessModal` war falsch, und sie wäre in
  einem gescheiterten Test aufgefallen:** die Aktionszeile hängt an `isPhotographer`
  (`ManagementGalleryActions.tsx:23`) *und* bekommt `onOpenAccess` nur für Admins
  (`ManagementGalleryView.tsx:95`) — es braucht **beide** Rollen. Gelöst über
  `createIsolatedUser('photographer', { additionalRoles: ['admin'] })` statt die Spec zu
  schwächen, und ohne den gesäten Bootstrap-Admin zu benutzen.
  **Die drei Modal-Bedingungen:** `RatingStatusModal` braucht eine Galerie mit
  `type === 'selection'` (`GalleryHelper.createAndOpenSelectionGallery`, neu);
  `AIGalleryDefaultsModal` liegt **verschachtelt** in `GalleryMetadataDefaultsModal`, und
  der Spec prüft, dass das Kind aufgeht **während das Eltern-Modal offen bleibt**; die
  KI-Generierung selbst wird **nicht** ausgelöst, weil sie einen Live-AI-Dienst braucht
  und das Stubben von `/api/ai/*` verboten ist.
  **Der lügende Kommentar ist korrigiert.** Er behauptete, `AT-03-E4` sei entfernt
  worden, weil es `/api/ai/status`, `/api/auth/me` und
  `/api/ai/generate-metadata-text` mocke. Falsch auf beiden Zählern: der Test
  existiert, und diese drei Pfade kamen **nur innerhalb der Notiz** vor — ein
  `page.route`/`route.fulfill` existiert in der Datei nicht. Die neue Notiz sagt das
  ausdrücklich, statt die alte Behauptung zu tilgen.

### Strukturbefunde, nicht jetzt umgesetzt

- [ ] Entscheidung offen: **`admin/` ist ein Sammelbecken — Ziel-Taxonomie festlegen, bevor umgebaut wird.**
  **34** von **89** Specs (**38 %**) liegen flach in `frontend/tests/e2e/admin/`
  (`find frontend/tests/e2e -name '*.spec.ts' | wc -l` → 89;
  `ls frontend/tests/e2e/admin/*.spec.ts | wc -l` → 34) und das Verzeichnis mischt
  Pricing, CRM-Dokumente, Tenant-Administration, Galerie-Konfiguration und
  Projekt-Boards, während `client/`, `photographer/`, `crm/`, `delivery/` und
  `selection/` sauber actor- bzw. domänenskaliert sind. Zusätzlich drei fast
  identische Specs mit identischem 85-Zeilen-Setup (Zeilen 1–85 byte-gleich, nur
  Zeile 8 und ab Zeile 86 verschieden): `no-b2b-label.spec.ts` (107),
  `no-create-org.spec.ts` (102), `org-edit.spec.ts` (111) — die E2/E5/E6-Familie,
  wobei E5/E6 Negativ-Berechtigungen sind und E2 der Positiv-Fall auf demselben
  Gerüst. **Eigene Aufgabe, keine Nebenwirkung eines Cleanup.**
  **Entscheidung: nach welcher Taxonomie wird geschnitten — Domäne (`preise`, `crm`,
  `organisation`, `galerie`, `board`) oder Akteur — und wird die E2/E5/E6-Familie
  dabei in ein gemeinsames Setup gezogen?** Ohne Zielstruktur erzeugt jede
  Verschiebung nur einen zweiten Zwischenstand.
- [~] wartet auf den CSP-Policy-Text an lesbarer Stelle im Repo. **E2E ist strukturell
  blind für CSP-Verstöße — und lässt sich das nicht vollständig remedieren.**
  Befund aus der Wasserzeichen-Reparatur: `playwright.config.ts` hat **keinen**
  `webServer`-Block (`grep -n webServer frontend/playwright.config.ts` → kein Treffer),
  der E2E-Server ist ein separat gestarteter `pnpm dev` auf 4321
  (`scripts/e2e-up.sh:200` → `log "Frontend (separat): … pnpm dev"`), und
  `vite.config.ts:43-55` setzt im `server`-Block **keinen** `headers`-Schlüssel
  (`grep -n headers frontend/vite.config.ts` → kein Treffer). Die Suite läuft also
  **ohne jeden CSP-Header** — `tests/e2e/admin/watermark.spec.ts:37` →
  `const preview = page.locator('main img[alt="Watermark Preview"]')` ist seit Juni
  grün, während in Produktion jedes Rendern fehlschlug. **Warum trotzdem kein Header
  nachziehen:** Die Produktions-CSP steht in **keinem File dieses Repositories**; sie
  lebt nur im Caddy-Volume von `proxy-stack` (`proxy-stack_caddy_file/_data`). Eine
  E2E-Kopie müsste `script-src`, `style-src` und `connect-src` raten, und ein
  Fehlversuch erzeugt über 1000 Fehlschläge, die über das Produkt nichts aussagen.
  **Was stattdessen getan wurde, und das ist der bessere Weg:** Der Renderer-Test
  erzwingt die Allowlist `['data:', 'https:']` in einem `Image`-Mock und verweigert
  alles andere mit `error` — die Suite prüft also die konkrete Invariante, ohne die
  Umgebung nachzubauen. Zwei Zusatztests sichern den Mock selbst ab.
  **Bleibende Lücke:** Andere künftige CSP-Blockaden (Skripte, Fonts, `connect-src`)
  bleiben für E2E unsichtbar. Das ist eine Eigenschaft der Testumgebung, die man
  kennen muss — **nicht** durch Raten behoben. **Bedingung für eine Remediation:**
  der Policy-Text liegt zuerst lesbar im Repo; das ist eine Owner-Entscheidung und
  eine Frage an den `proxy-stack`. **Bis dahin kein offener Bug, sondern eine
  dokumentierte Blindstelle.**
- [ ] manuell prüfen: **Auszahlungsdaten nach dem automatischen Seed** — Stand ist
  gemessen und **korrekt**: `bank_holder` = `Florian Reisinger`, `bank_iban` =
  `DE96100110012179986174`, `bank_bic` = `NTSBDEB1XXX`, `company_*` = Linz,
  `Robert-Stolz-Straße 8`, 4020, Österreich, `admin@example.com`. Alle acht decken
  sich mit `DatabaseSeeder.php:174-181` und damit mit Ihren echten Daten. **Was
  sich nicht belegen lässt:** ob die Werte schon vor dem Seed so standen — ich habe
  sie vorher nicht erfasst. Falls der Seed etwas überschrieben hat, dann etwas auf
  denselben Wert. **Worauf es ankommt:** es steht keine Platzhalter-Bankverbindung
  mehr drin (auf einer frischen DB wäre es `Max Mustermann` / `ATXX XXXX XXXX`),
  also gehen Auszahlungen an das richtige Konto. Ein kurzer Blick in die
  Systemeinstellungen genügt, um es abzuhaken.
- [ ] **`wysiwyg-editor.spec.ts` — **lokal** fehlschlagend, in CI grün.** Gemessen am
  2026-09-28, CI-Run `36389471967`: `wysiwyg-editor.spec.ts:19` **✓** und `:101` **✓**.
  S1 hatte lokal einen Fehlschlag gemeldet (Zählung der Listenelemente 1 statt 2,
  Tiptap-Listenbehandlung); in CI tritt er **nicht** auf. Der Eintrag war zunächst als
  „reproduzierbar fehlend" notiert — das ist durch die Messung **widerlegt** und hiermit
  korrigiert. Das letzte Anfassen liegt am **2026-09-26** (`069df63`), also vor der
  Welle. **Befund, kein Task:** der Fehlschlag ist ein **Lokationsphänomen**, und die
  Zero-Pre-existing-Failures-Policy ist hier nicht einschlägig, weil kein CI-Run rot ist.
  **Nachgemessen 2026-09-28:** `npx playwright test tests/e2e/admin/wysiwyg-editor.spec.ts`
  → **4/4 grün**. Die Datei enthält **2 Testdefinitionen × 2 Projekte** (Desktop Chrome,
  Mobile Chrome); `--list` zählt `Total: 4 tests in 1 file`. Die frühere Angabe
  „18/18" in diesem Board war falsch und ist hiermit korrigiert. Keine Regression: das
  letzte Anfassen von `WysiwygEditor.tsx` liegt am **2026-08-18** (`b84f87f`), und die
  geprüfte Zählung (`ol li`) hat der Commit `069df63` nicht angefasst.
  **Die latente Fragilität ist seit 2026-09-28 behoben.** Der Spec prüft jetzt zwischen
  Toolbar-Klick und dem Tippen, dass der Listen-Befehl gegriffen hat — über die
  `btn-neutral`-Klasse des Buttons, die aus `toolbarUi.orderedList` stammt, also aus
  `ed.isActive('orderedList')` (`WysiwygEditor.tsx:231`, verwendet in `:303`). Das ist
  der Zustand des Editors selbst und keine DOM-Sonde; `toHaveClass` wiederholt ohne
  festen Wartezeit-Befehl. Beweis, dass es beißt: ohne den Klick meldet die neue Zeile
  `Received string: "btn btn-sm btn-ghost"` — **vor** der `ol li`-Zählung, die vorher
  allein „1 statt 2" hätte sagen müssen.
  **Der Mechanismus des ursprünglichen Auftretens bleibt `unbelegt`.** Die Trennung ist
  trotzdem echt: ein Fehlschlag in der `ol li`-Zählung bedeutet jetzt „der Befehl griff
  und der Inhalt stimmt nicht", nicht mehr „der Befehl griff vielleicht nicht".
- [ ] **Gegenprobe vor jedem Deploy, der `base_price` berührt:** Der Wert der
  `base_price`-Zeile ist **nicht** aus dem Repository ableitbar, weil `insertOrIgnore`
  (jetzt `upsert`) den Seederwert nur beim Anlegen der Zeile schreibt und eine bestehende
  Zeile unangetastet lässt. Am **2026-09-28** auf Produktion gemessen: `base_price = 8000`,
  `price_web = 7500`, `price_print = 14500`, `price_original = 45000` — also plausibel, und
  `min:500` greift nicht. **Damit ist der Auslöser des CI-Fehlers an
  `package-calculator-config.spec.ts` auf frischen Datenbanken begrenzt** (dort stand
  `35.00`, 35 Cent, weil `V004:131` die Zeile vorbelegt). Produktion war nie betroffen,
  weil dort über die Oberfläche gesetzte Werte stehen. **Vor dem nächsten Deploy aber
  erneut messen**, und zwar lesend über die öffentliche Route
  `GET https://portal.reisinger.pictures/api/settings/license-terms` (liefert
  `base_price` und `srp_base_price`) — steigt die Antwort mit 301 auf
  `reisinger.pictures`, ist die falsche Domain erwischt: `APP_URL` ist
  `portal.reisinger.pictures`. Steht der Wert unter 500, ist es ein Datenwert und kein
  Code-Fehler.

### Abweichungen bei der Umsetzung (2026-09-26) — zwei Audits, nicht blind übernommen

- **`CouponInput.test.tsx:148` war ein Fehlalarm.** `removeCoupon()` wird mit
  **keinen Argumenten** aufgerufen (`CouponInput.tsx:38`), die blanke Assertion
  ist dort also korrekt. Argumente zu ergänzen wäre falsch gewesen. Von fünf
  gemeldeten Stellen sind damit **vier** echte.
- **Zwei der vier E2E-Löschungen wurden zu Verschiebungen.** `guest.spec.ts` und
  `public-gallery.spec.ts` tragen `@smoke`; ein blindes Löschen hätte
  Smoke-Abdeckung entfernt, was die Bewertung des Audits nicht abwog. Deshalb:
  `@smoke` auf `search.spec.ts:4` gehoben (das den Test bereits duplizierte) und
  `guest.spec.ts` gelöscht; `public-gallery.spec.ts` **umbenannt** statt gelöscht,
  weil der Testkörper die Login-Formular-Eindeutigkeit prüft und nur der Name
  log. Der Test hieß „can view public galleries", öffnete aber keine Galerie.
- **Die verschachtelte-Dialog-Reparatur war zunächst falsch.** Der erste
  Fix prüfte „zuletzt registriert gewinnt" — aber React führt Effects
  **kind-zuerst** aus, ein verschachtelter Dialog registriert sich also *vor*
  seinem Elternteil und der äußere Dialog konsumierte das Escape. Die
  Korrektur nutzt DOM-Reihenfolge, die die Verschachtelung tatsächlich
  ausdrückt.
- **Eine von mir verschärfte Assertion schlug fehl und erwies meine eigene
  Annahme als falsch:** `handleSave(index)` speichert **eine** Zeile, ich hatte
  drei erwartet. Der Code war richtig, die Assertion wurde korrigiert — und
  bleibt schärfer, weil sie jetzt Foto-ID und Payload-Form prüft.
- **Einheitenregel im Vertrags-Pricing:** Editor-Positionen sind in **Euro**,
  Snapshot-Positionen in **Cent** (`toSnapshotValue` multipliziert mit
  `CONTRACT_SNAPSHOT_SCALE`). Ein um Faktor 100 falscher Testwert wäre ein
  Geldfehler im Test gewesen; der Kommentar pinnt die Regel jetzt explizit.

### Sauber, kein Handlungsbedarf

- Kein `expect(true)` in der gesamten Suite, **keine Snapshot-Tests** (0 Dateien).
- `tests/Unit/Models/GalleryOrgIdsTest.php:27` sieht wie ein reiner
  Getter-Test aus, ist aber gerechtfertigt: dokumentierter Regressionstext,
  prüft die Precondition im *ungeladenen* Zustand.
- `shootingCalculator.ts calculateCustomStudioPrice` wirkt ungetestet, ist es
  aber nicht — der Test greift über den Alias `calculateShootingPrice`, eine
  Einzeiler-Delegation. **Korrekt als Fehlalarm abgetan.**
- In der gesamten E2E-Suite nur zwei `page.route`-Mocks, beide legitim (einer
  verzögert und `continue()`t, einer mockt Stripe). **Kein Test mockt weg, was er
  zu prüfen vorgibt.**

## 🔍 VOLLSTÄNDIGER HEAD-AUDIT (2026-09-24)

> Scope: vollständiger tracked Codebestand bei `HEAD=72f55da` (1.127 Dateien; 531 PHP, 376 TS/TSX, 78 E2E-Specs), nicht nur der letzte Diff. Der Working Tree war zu Auditbeginn sauber. Die Prüfung läuft als unabhängiger Full-Repository-Audit; Befunde werden erst nach Verifikation gegen Implementierung, Call-Path und bestehende Tests als Probleme dokumentiert.

**Review- und Remediation-Tracking**
**Post-Audit-Folgeentscheidungen (2026-09-24; in den Backlog-Wellen umzusetzen)**
- **CR-PAY-010:** vollständige serverseitige Idempotency-Key-Unterstützung für Invoice/Free/Quote ist als nächster Umsetzungsschritt ausgewählt.
- **CR-FE-030:** Meta-Gallery-Lizenzierung soll pro Child/Gallery-Gruppe aufgelöst werden; Tests und API/UI-Vertrag folgen in einem Folge-Change.
- **CR-CRM-008:** dauerhaft retrybarer Dispatch-/Outbox-Fallback für CRM-Dateisystem-/Scout-Cleanup ist ausgewählt; bestehende Jobs/Tabellen werden bevorzugt, eine neue V039+-Migration nur bei unvermeidbarem Schema-Defizit.
- **CR-DATA-018/CR-BE-018:** Contract-E-Mail-Uniqueness wird zuerst mit bestehenden Mechanismen/Schema geprüft; V039+ nur bei unvermeidbarem Bedarf.

**Backlog-Entscheidungen (2026-09-24; User)**
- **V035 bleibt unverändert** (Produktion). Neuere Migrationen dürfen nur fachlich passend konsolidiert werden; bestehende Migrationen/Strukturen sind bevorzugt. Eine neue V039+-Migration ist nur zulässig, wenn die Anforderung mit bestehenden Tabellen/Indizes/Job-Verträgen nicht sicher erfüllbar ist.
- **CR-CRM-008:** Durable Outbox-/Dispatch-Retry mit bestehenden Tabellen/Jobs bevorzugen; nur bei unvermeidbarem Schema-Defizit V039+.
- **CR-DATA-018/CR-BE-018:** Contract-E-Mail-Uniqueness zuerst mit bestehenden Mechanismen/Schema lösen; V039+ nur als letztes Mittel.
- **CR-PAY-013/CR-CODE-002:** Purchase-time-Organisationszuordnung ist beschlossen; Snapshot-/Migrationsauswirkungen vor Implementierung spezifizieren.

- [~] wartet auf die Betriebsnachweise selbst (E2E-Harness, Live-Smoke, GHCR-/Dependency-/Deployment-/Live-Runtime-Nachweise, Secret-/Artifact-Hygiene). **Welle 4 — Betrieb/Release:** E2E-Harness, Live-Smoke, GHCR-/Dependency-/Deployment-/Live-Runtime-Nachweise und Secret-/Artifact-Hygiene abschließen.
**Akzeptierte Risiken aus dieser Welle (User-Entscheidungen, 2026-09-25/26):**

- **Cross-Brand-Zugriff für Super-Admin** (`ContractController` u. a.): gewollt. Nur der `brand=null`-Super-Admin ist cross-brand; brand-gebundene Admins bleiben isoliert.
- **Gebührenverteilung Payouts:** „Alles mit Abzug der Payment-Gebühren wird verteilt" ist das gewollte Modell — die Gebühr wird über die eligible Items verteilt.
- **Migrations-Policy:** Produktion hat **V035**; alles darüber (V036–V040) ist die nicht-produktive Frontier und fachlich konsolidierbar. Eine neue Migration nur, wenn eine Konsolidation technisch nicht geht. **Keine weitergehenden Restriktionen erfinden, die nicht vom User stammen.**
- **Vertrags-Join-Identität (FINAL-9/CTR-7):** Der geheime `personal_token` **ist** das Authentifizierungsmittel für die Unterzeichnung, und der Join-Link geht an die beteiligte Person — der Link ist die delegierte Autorisierung. Die beim Join angegebene E-Mail ist Anzeigeidentität, **kein Grant**; `sign/{personal_token}` hängt ausschließlich am Token. Siehe Detail-Eintrag im Review-Abschnitt.

## 🚀 EXECUTION PLAN — CURRENT SESSION (2026-09-25)

> Ziel: alle **actionable** TODOs in Abhängigkeitsreihenfolge abarbeiten. Historische `[x]`-Einträge werden nicht automatisch neu geprüft; stale/duplicate Findings werden durch einen aktuellen Reproduktionstest geschlossen oder als offen markiert. Der aktuelle Working Tree ist dirty; kein Checkbox-Status und kein Diff allein gilt als Verifikationsnachweis.

### 🆕 Validierung der Remediations-Welle `e5d20f0..2bfaed8` — 7 DeepSeek-v4.1-Reviewer

> **Methode:** 7 unabhängige read-only DeepSeek-v4.1-Reviewer über die gesamte Welle (664 Dateien, ~76k Zeilen). Ergebnis: **65 Befunde, 0× P0, 9× P1, 56× P2.** Authorization/Brand/Gallery-Tree/Media-Delivery als **READY** beurteilt (0 P0/P1; 6 Verdachtsmomente aktiv verworfen). Umsetzung in 5 nicht-überlappenden Workstreams, je mit separatem Verifier.

#### P1


#### P2 (Details je Workstream)

<details><summary>INFRA (10)</summary>

</details>

<details><summary>AI/Media/Storage/Payouts (8)</summary>


</details>

<details><summary>Contracts/Pricing (6)</summary>

</details>

<details><summary>Frontend (7)</summary>

</details>

<details><summary>Checkout/Payments (5)</summary>

</details>

<details><summary>Authorization/Brand (5, alle P2 — Bereich READY)</summary>


</details>

<details><summary>Tests/Dokumentation (15)</summary>

- [~] wartet auf einen MySQL-/MariaDB-Testlauf — die beiden Tests sind driver-gated und laufen auf CI-SQLite `:memory:` immer skipped, die V039-Uniqueness-Invariante hat so keinen automatisierten Nachweis. **TST-3:** `ContractSignerIdentityRaceTest.php:26,30` + `ContractSignerIdentityTest.php:217,223` sind driver-gated und laufen auf CI-SQLite `:memory:` immer skipped → V039-Uniqueness-Invariante ohne automatisierten Nachweis.
</details>

### ✅ Finaler unabhängiger Review (DeepSeek-v4.1, 2026-09-26) — Blocker behoben

> Review von `git diff 1b3448d` (gesamtes Changeset, 120 Dateien). Urteil: **NOT_READY** wegen 1× P1. Nach Behebung: **READY_TO_COMMIT**. Der Reviewer verifizierte 15 Fixes als korrekt (PAY-1-Kernlogik, CTR-2/3/5/9, PAY-3, AUTH-1, AIS-2, AIS-5, INFRA-2, FE-4, V036-Idempotenz, AUTH-3/5, APP_DEBUG-Policy, `ContractSigner::$hidden`, `stripe_customer_id`).

**Nachtrag 2026-09-26 (P2-Umsetzung, Commits `fca95c9` / `6a35e07` / `52b8331`):**

**Dependabot-Stand (2026-09-26, Ende der Welle):** **0 offene Security-Alerts** (von 3 high + 2 medium), **0 offene PRs** — alle 3 PRs sind erledigt, aber **nicht auf dem ursprünglichen Weg.**

- **#14 `browserslist` 4.28.4 → 4.29.0: gemergt** (Squash, `8632ab6`). Reiner transitiver Bump, kein Quellcode betroffen, frischer Lauf grün.
- **#15 `baseline-browser-mapping` 2.11.25: geschlossen als obsolet.** `baseline-browser-mapping` ist **keine direkte Abhängigkeit** (kein Eintrag in `frontend/package.json`), sie kommt transitiv über `browserslist`. #14 hat `baseline-browser-mapping@2.11.25` bereits ins Lockfile gezogen — exakt die Version, die #13/#15 anpeilen. Ein Merge wäre ein No-op und kollidierte zudem, weil beide PRs denselben Lockfile-Eintrag schreiben.
- **#13 `@tiptap/core` 3.30.2 → 3.30.5 — GEMERGT als `db9f512` mit Lockstep-Fix (s.u.).** Root-Cause war ein Versions-Skew, KEIN Breaking Change. Der rote Frontend-Check meldete `Property 'setParagraph' / 'toggleBold' / 'toggleHeading' / 'toggleItalic' / 'toggleBulletList' / 'toggleOrderedList' does not exist on type 'ChainedCommands'`. Das Changelog widerlegt die Breaking-Change-Annahme: 3.30.3/3.30.4/3.30.5 sind drei Patch-Fixes (Nested-Sibling-JSX, Prototype-Pollution in `mergeAttributes`, Markdown-Attribut-DoS) und ändern die Chain-Typen nicht. **Ursache:** `ChainedCommands`/`Commands`/`RawCommands` werden von den Extension-Paketen per TypeScript-Decoration-Merging auf `@tiptap/core` erweitert. Liest `core` eine andere Version als die Extensions, landen die Augmentationen auf einer anderen Modul-Instanz, der Merge geht verloren und die Kommandos verschwinden aus dem Typ. **Fix:** alle neun Pakete in Lockstep, Range `^3.30.2` → `~3.30.5`, Lockfile pinnt **3.30.6**.
  - **Warum `~` und nicht `^`:** `^3.30.5` löst **3.31.x** auf, das **keinen Changelog-Eintrag** upstream hat (neueste dokumentierte Version ist 3.30.5). Ein undokumentiertes Minor bei so gekoppelter Typ-Oberfläche nicht blind zu landen, ist keine Option; die Tilde hält die Range in der dokumentierten 3.30-Linie und nimmt weiter Patches an. Die Security-Fixes aus 3.30.4/3.30.5 sind in 3.30.6 kumuliert enthalten.
  - **Wiederholungssicherung:** dependabot-Gruppe `"@tiptap/*"` in `.github/dependabot.yml`, damit künftige Updates als ein Lockstep-PR ankommen und kein Ein-Paket-Bump den Skew erneut erzeugen kann.
  - Verifiziert auf 3.30.6: `tsc -p tsconfig.tests.json` exit 0 (der zuvor rote Check), `pnpm lint:fix`/`lint:e2e` exit 0, Vitest 128 Files/1040 Tests, `pnpm build` grün, `wysiwyg-editor.spec.ts` E2E 4/4 in beiden Viewports gegen einen mit geleertem Optimizer-Cache neu gestarteten Vite.

**Beobachtung ohne Bezug zum Tiptap-Bump:** `Backend (PHPUnit)` schlug auf dem Lauf von #13 mit `ImportLocationsNonDestructiveTest` fehl — `FileNotFoundException` auf `storage/app/private/temp/AT_postal.txt`, einem **parallelen Workern geteilten Temp-Pfad**. Der Backend-Lauf auf `main` ist grün. Passt zum bereits bekannten Paratest-/Worker-Konkurrenz-Thema in `backend/AGENTS.md`; als eigener Punkt zu verfolgen, **nicht** als Folge des Dependency-Bumps zu behandeln.
**Abgeschlossen / widerlegt:** FINAL-2, FINAL-3, FINAL-4, FINAL-5, FINAL-6 und FINAL-7 sind im Nachtrag oben umgesetzt und verifiziert; **FINAL-7 hat sich als gegenstandslos erwiesen** (`orders.total_amount` ist `NOT NULL`, V004). Details und Evidenz dort — dieser Abschnitt listet die erledigten P2 nicht mehr doppelt auf, damit das Board nicht in sich widerspricht.

**Ausdrücklich sauber (nicht regressieren):** Action-/Digest-Pinning, Secret-Hygiene, `pull_request_target`-Gating, rclone-Filter, Non-root in Production, Lua-Timeouts, Checkout-Idempotenz (Fingerprint bindet Brand/Items/Billing/Coupon/server-computed Betrag/Actor), Webhook-Signatur fail-closed auf allen vier Handlern, keine Raw-SQL mit User-Input, `$fillable`-Disziplin, `GalleryGroupSubtree` cycle-safe/depth- und node-bounded, V037-Owner-Trigger auf INSERT **und** UPDATE, V038-Actor-Key server-abgeleitet, AI-Prompt-Injection-Delimiter geschlossen, AI-Logging nur `status`+`body_length`, AI-Budget **vor** Decode, keine neuen E2E-`/api/*`-Mocks, kein localStorage-`addInitScript`, alle neuen E2E-Specs getaggt, `Mail::fake()` nur für Enqueue/Fault-Injection.

## 🛑 SESSION-HANDOVER (2026-09-25, Ende der Arbeitssession)

**Ergebnis in einem Satz:** 13 verifizierte Commits sind auf `main`; die verbleibenden Arbeitsstränge sind mit **unterschiedlichem Nachweisgrad** committed (2× fertig, 3× halb fertig), und die CI ist aus **genau einem** externen Grund rot.

### Commit-Stand
| Commit | Inhalt | Nachweisgrad |
|---|---|---|
| `f659de3`…`aa6dafd` | CI/Plugin, Frontend, Backend/Contract/V039, AI, Cleanup/Scout, Quote-Mail, Payout/V040 | **verifiziert** (unabhängige Verifier + grüne Suite 2349–2365 Tests) |
| `d7f3596` | Image-Namespace `reisinger-pictures` | verifiziert, **löst aber den GHCR-Blocker aus** |
| `aac83b4`, `70185eb` | GHCR-Blocker + P1-I5-Korrektur | Doku |
| `cf5e8fc`, `2bfaed8` | Gallery-Traversal/Status-Guard, Brand-Invariante (fail-closed) | **verifiziert** |
| `8cc3fcb` | Non-Root-Image-Gate | verifiziert (Fail-the-Guard belegt) |
| P1-M11 (`id` raus aus `$fillable`) | `Photo::createWithId()` | **FERTIG** — 275 Tests/996 Assertions + 46 Proben, PASS |
| Flake A+C (`attempts 0!==1`, `ai_img_*`-Glob) | Test-Härtung + injizierbares Temp-Prefix | **FERTIG** — serial 10/10, parallel 5/5, Ballast 25/25, 2× Revert-to-red |
| exiftool-Testbudget | explizites Budget + 1 Retry | **HALB** — Testmitigation fertig, **Produktionspfad ungefixt** |
| `preset_id`-Vertrag + GalleryModal-Zod-Bug | Integer-Kontrakt beidseitig | **HALB** — Frontend belegt, **Backend und 4 E2E-Specs unbelegt** |
| `SidebarHelper` + `E2E_SKIP_DOCKER_SERVICES` | kein Mobile-only-Gate mehr | **HALB** — Unit 4/4, **kein E2E-Lauf** |

### ⚠️ Umgebung während der Session verloren — nicht reproduzierbar
`/usr/local/bin/meilisearch`, `/usr/local/bin/mailpit`, `/usr/bin/exiftool`, `/usr/bin/pgrep`, `/usr/bin/ss` sowie die aus PHP-8.5.10-Quellen gebaute `gd.so` sind **alle weg**; `/tmp` wurde geleert. Das native Setup hat **nicht persistiert**. Konsequenz: **im aktuellen Zustand ist keine Testausführung möglich**; alle nach dem Verlust gemessenen Zahlen sind entsprechend zu behandeln. Vor jedem Resume müssen GD, exiftool sowie ein host-erreichbares Meilisearch + Mailpit wiederhergestellt werden.

### ⛔ Einzelner CI-Blocker
Beide Org-Pakete sind `private`; `backend` und alle 7 E2E-Jobs scheitern mit `unauthorized` beim Image-Pull, das neue Non-Root-Gate zusätzlich mit HTTP 401. `Frontend (Lint, Build, Vitest)` bleibt grün. **Owner-Aktion:** `portal-base` und `portal-e2e` auf `public` — https://github.com/orgs/reisinger-pictures/packages/container/package/portal-base (analog `portal-e2e`).

### Offene Defekte (bewusst nicht gefixt)
- **`CrmCleanupDispatchFallbackTest`** (6 `queue:work`-Aufrufe: 215/299/370/384/416/552) teilt exakt den Defekt, der bei A behoben wurde: unguarded `--memory=128` bei In-Process-Worker. Dieselbe 2-Zeilen-Fix + Exit-Code-Assertion fehlt dort.
- **Produktions-Exiftool-Timeout**: `PhotoDownloadController.php:225/228`, `ImageProcessor.php:254/401`, `PhotoProcessingService.php:85` rufen `Process::run()` ungeschützt mit 60 s Default. Auf einem überlasteten Host wird ein Download zum 500.
- **P1-M11 D1**: die bewusst erhaltene `captured_at`-Divergenz (FTP persistiert, HTTP nicht) hat **keinen Repo-Test** — ein künftiges `captured_at` in `$fillable` würde stillschweigend greifen.
- **P1-M11 D2**: `createWithId()` koppelt `PhotoProcessingService` per Ausnahmeliste; ein neues EXIF-Feld wirft `InvalidPhotoIdentifierException`, `FtpController` behält die Datei → der Import-Poll klemmt dauerhaft.
- **P1-I5**: Non-Root-Eigenschaft des gepinnten `d762d47c` ist **unverifiziert** (Pull 401). Das Gate erzwingt den Nachweis, sobald die Pakete public sind.
- **`preset_id`-Backend**: 3 neue PHPUnit-Tests wurden **nie ausgeführt**. `features/infrastructure/27-volume-licensing-presets.md:55` dokumentiert den numerischen Vertrag noch nicht.

### Umgebungsfallen
1. Nach jedem `gh auth refresh` muss `gh auth setup-git` laufen, sonst scheitert `git push` an `could not read Username`.
2. `concurrency: cancel-in-progress: true` in `ci.yml` **vernichtet den Log eines laufenden Laufs**, sobald gepusht wird. Beim Debuggen nicht währenddessen pushen.
3. Die Platte war zweimal über 90 % voll (fremde Projekte: `LuminaRust` 163 GB, Docker-Volumes 82 GB). Volle Testläufe brechen dann mit `No space left on device` ab.
4. `backend/tests/Fixtures/sample.jpg` wurde von einer parallelen Session **gelöscht** und aus dem Git-Objekt wiederhergestellt; `git status` ist für die Datei clean.

### Abbau dieser Session (durchgeführt)
Docker-Container `e2e-head`, `e2e-ci`, `e2e-mariadb`, `e2e-meili`, `e2e-mailpit`, `e2e-pf`, `e2e-fix`, `pf-mailpit`, `pf-meili`, `pf-mariadb`, `pf-meili-local`, `seed-ws-pf` sowie Volumes `ws-head`, `ws-ci-base`, `ws-pf`, `ws-fix` und die Netze `e2e-net`, `pfnet` entfernt; Host-Dienste Meilisearch/Mailpit gestoppt; Worktree `portal-staged-verify` geprüft (`git diff --cached main --diff-filter=A` leer ⇒ kein Unique-Work) und entfernt; Scratch unter `/tmp` bereinigt.

### Gate 0 — Baseline und Änderungsdisziplin
- [~] wartet auf erreichbare Host-Ports 127.0.0.1:7701/1025/8025 sowie PHP-GD und ExifTool auf dem Host; der gepinnte CI-Container ist der gültige GD-Testpfad. **Backend-Testumgebung (2026-09-25):** Meilisearch- und Mailpit-Container sind gestartet und intern gesund (`docker exec` Health/Message-API bestätigt), aber die Host-Ports `127.0.0.1:7701/1025/8025` sind aus der Shell-Namespace nicht erreichbar; Compose-Plugin fehlt. 28 root:root-Testartefakte unter `backend/storage/framework/testing` sind gitignored, aber für den Host nicht bereinigbar. PHP-GD/ExifTool fehlen im Host; der gepinnte CI-Container ist der gültige GD-Testpfad, muss aber auf Image-UID/Version geprüft werden.
- [~] wartet auf die reproduzierten GalleryTreeService-/Invoice-/Frontend-Fehler aus Welle 0 — solange sind die Feature-Wellen nicht sinnvoll startbar. **Gate 0 — Code-Fixes vor Feature-Wellen:** GalleryTreeService-/Invoice-Testfehler und Frontend-Meta-Gallery/Lint-Fehler zuerst reproduzieren, beheben und fokussiert verifizieren; anschließend Backend-Umgebung (Meilisearch/Mailpit/GD/Storage) reproduzierbar herstellen oder als offenen Infrastrukturblocker dokumentieren.
- [~] wartet auf den Rückgang der parallelen Agentenlast; erst dann die fehlgeschlagenen Delegationen neu starten. Subagent-Infrastruktur-Blocker (`invalid_request_error`, Datenbank-Lock) von Code-Regressions trennen und fehlgeschlagene Delegationen nach Rückgang der parallelen Last erneut starten.

### Workstream A — Payment/Cart/Media
- [~] wartet auf einen aktuellen Reproduktionstest pro Befund — ohne ihn ist unklar, was noch offen ist. **P1-F/P1-M-Reste:** nur reproduzierte Befunde priorisieren; jede UI-/Routing-Änderung mit Vitest plus Playwright-Functional-Tag, jede Backend-Änderung mit PHPUnit.
- [~] wartet auf die gebauten Suites — fokussierte Suites zuerst, danach die Gates. **Payment-/Media-Gates:** fokussierte Suites zuerst, danach Full-PHPUnit, Full-Vitest, `pnpm lint:fix`, `pnpm build`, `@smoke` und feature-getaggte E2E-Läufe.

### Workstream B — CRM/Contract/Infra
- [~] wartet auf Betriebs-/CI-Evidenz: GHCR-Pullability, Deployment-, Secret-, Scheduler-, Storage-Nachweise und die Plugin-Live-Runtime. E2E-Harness, Smoke, parallele Shards, GHCR-Digests/Pullability, Deployment-/Secret-/Scheduler-/Storage-Nachweise und Plugin-Live-Runtime als eigenständige Evidence-Tasks behandeln.
- [~] wartet auf den Abschluss der Review- und Checklistenpunkte; die Betriebsnachweise (Monitoring/Runbook, Rollback, unabhängige Verifikation) stehen parallel aus. Vor Live-GO: Contract-/Card-Testing-Review, Radar/3DS/Webhook/Privacy-Checkliste, Monitoring/Runbook, Rollback und unabhängige Verifikation abschließen.

**Ausführungsregel:** Jeder Workstream erhält einen separaten Implementierungs-Subagenten und einen separaten Verifier. Tests werden im Task Board mit exaktem Kommando, Ergebnis und Umgebungsstatus fortgeschrieben; ohne diesen Nachweis bleiben die Checkboxen offen.

**Zusätzlich gestartete aktive Teilaufgaben:**
**Read-only Implementierungs-Audit (2026-09-25; Testquellen vorhanden, Ausführung/Verifier offen):**
- [~] wartet auf den Abschluss der A7-Subblöcke — R1–R7 sind verifiziert, übrig sind Operations-Evidenz und Product Decisions. **P1-A7 Audit-Split (2026-09-25):** P1-A6 bleibt separat verifiziert. A7 ist in aktive Bugs (malformed provider response, image byte/pixel budget, session-prefix/header validation, manual delete ordering, scheduled cleanup durability, unchecked temp deletion, quote mail-loss), Operations-Evidence (queue/mail/worker/scheduler), Product Decisions (Prompt-Injection, SMTP duplicate policy) und stale/fixed Source-Punkte aufgeteilt. Keine pauschale A7-Schließung.
- [ ] Entscheidung offen: Prompt-Injection-Policy und SMTP-Duplikat-Policy; die Queue-/Mail-/Worker-/Scheduler-Evidenz steht ohnehin aus. **A7 Operations/Decisions:** Queue-/Mail-/Worker-/Scheduler-Evidence sowie Prompt-Injection- und SMTP-Duplikat-Policy separat entscheiden/dokumentieren.
- [ ] manuell prüfen: in `https://github.com/orgs/reisinger-pictures/packages/container/package/portal-base` → Settings → Change visibility → **Public** (analog `portal-e2e`). Abnahme: anonymer Manifest-Fetch liefert **200** statt 401, bei unveränderten Digests (`portal-base:8.5@sha256:d762d47c…`). Der E2E-Job zieht sein Image auf Job-Ebene, ein Registry-Login kommt dort zu spät — es bleibt nur `public`. **GHCR-Pakete public schalten (Owner-Aktion, 2026-09-25):** `portal-base` und `portal-e2e` im Org-Namespace sind `private`. Der Namespace-Fix ist committed, aber **die Sichtbarkeit kann nicht per CLI geändert werden** — `PATCH /orgs/reisinger-pictures/packages/container/<pkg>` liefert mit Token-Scopes `read:packages,write:packages,admin:org` ein generisches `404 Not Found` ohne Docs-Anker, während `GET` das Paket liefert und ein nicht existierender Name `{"message":"Package not found."}` ergibt ⇒ die Update-Route fehlt für diesen Token, es liegt nicht an den Scopes.
  - **Manuell als Org-Admin:** `https://github.com/orgs/reisinger-pictures/packages/container/package/portal-base` → Settings → Change visibility → Public; analog `portal-e2e`.
  - **Abnahme:** anonymes Manifest-Fetch liefert `200` (aktuell `401`) **und** die gepinnten Digests bleiben unverändert — `portal-base:8.5@sha256:d762d47c…`, `portal-e2e@sha256:<neu nach Rebuild>`.
  - **Nicht blockierend:** beide Pakete sind mit `reisinger-pictures/portal.reisinger.pictures` verknüpft, die CI zieht sie mit ihrem `GITHUB_TOKEN`. Public erspart nur dem Deployment-Host Credentials. Vor `docker compose pull` also kein Showstopper.
  - **⚠️ KORREKTUR 2026-09-25 — ist DOCH blockierend, für die gesamte CI:** Der obige Satz war falsch. Die CI hat **keinen** Registry-Login und läuft bewusst ohne `packages`-Scope; `ci.yml:17-24` dokumentiert das als Least-Privilege-Design („pulled anonymously from public GHCR“). Nach dem Namespace-Umstieg auf `reisinger-pictures/*` in `d7f3596` sind beide Pakete privat, damit bricht der Pull ab. Beleg aus CI-Lauf `36168928448` (Job `108183657407`, ~17 s, dann Abbruch): `Unable to find image … locally` + `docker: … /v2/reisinger-pictures/portal-base/manifests/sha256:d762d47c…: unauthorized` + `##[error]Process completed with exit code 125.` Die Steps „Prepare environment“, „Install dependencies“, „Generate app key“ und „Run PHPUnit“ fehlen im Log vollständig — PHPUnit startete nie.
  - **Warum kein Login-Step als Alternative:** Der `backend`-Job zieht sein Image in einem Step, dort würde ein `docker/login-action` + `packages: read` helfen. Der `e2e`-Job nutzt aber `container:` auf **Job-Ebene** (`ci.yml:226`); dieses Image pullt der Runner, **bevor** der erste Step läuft. Ein Login kommt dort zu spät — es bleibt nur `public` oder eine strukturelle Umbau-Umstellung des E2E-Jobs.
  - **Reihenfolge:** Pakete public schalten → neuer Push → E2E-Jobs müssen wieder laufen. Der Backend-Job ist erst danach verwertbar prüfbar. Vorher ist jeder weitere Push ein bekannter roter Lauf, deshalb ist der Namespace-Fix bewusst **noch nicht** erneut gepusht.
- [ ] Entscheidung offen: Freigabe des plattenfüllenden Fremd-Bestands (`/projects/LuminaRust` 152 GB, Docker-Volumes 82,75 GB). Unser eigener Fußabdruck ist mit ~1 GB vernachlässigbar; nichts davon wurde angefasst. **Host-Speicherwarnung (2026-09-25, nicht unser Repo):** `/` ist zu 97 % belegt (12 GB frei). Ursache sind **fremde** Projekte auf demselben Host — `/projects/LuminaRust` 152 GB, `lumina-denoise-closeout-target-final` 13 GB, `lumina-r5-target` 12 GB, Docker-Volumes 82.75 GB (u. a. `lumina-g09-cull-rustup`). Unser eigener Fußabdruck: Worktree `/projects/e2e-baseline` 541 MB, `/tmp/native-verify` 538 MB. Nichts davon angefasst — Freigabe ist eine Betreiber-Entscheidung, aber Composer-/Playwright-Läufe können bei 12 GB Rest unschlagbar werden.
**Checkout-Verifier-Blocker (Implementierung 2026-09-25; unabhängige Verifikation folgt):**
- Immediate-Stripe-Replay verlangt jetzt eine explizite, passende aktive Brand; die Null-/Fingerprint-Legacy-Lookups wurden aus dem positiven Claim-Pfad entfernt. Regressionen für Null-Brand/PI-Exact-Key, Lock-Timeout und Unique-Constraint-Race sind deterministisch und ohne Sleeps umgesetzt.
- Invoice-Mail nutzt den bestehenden `invoice_snapshots`-JSON-Vertrag als nicht ablaufenden Enqueue-Claim (`InvoiceSnapshot::MAIL_DISPATCH_KEY`) und eine transaktionale Queue-Claim-Schicht. Das ist ausdrücklich **at-most-once durable enqueue**, nicht exactly-once SMTP delivery. Queue-/Marker-Fehler vor dem Commit rollieren den Claim zurück und sind retrybar; Worker-/SMTP-Fehler können mehrfach versucht werden, ein terminaler Job bleibt in `failed_jobs` und verhindert eine automatische zweite Enqueue. Production mit nicht-transaktionaler Queue wird fail-closed abgewiesen. Keine V039+-Migration; der Marker wird aus öffentlichen Snapshot-Resources entfernt.
- **Fokusnachweis 2026-09-25:** `CheckoutIdempotencyServiceTest` **24 Tests/116 Assertions**, `CheckoutNonImmediateIdempotencyTest` **22/153**, `CheckoutPersistenceIntegrationTest` **7/47** (mit `SCOUT_DRIVER=null`), `CheckoutKillSwitchTest` **6/20**, `FreeOrderCheckoutTest` **1/10**, `CheckoutStripeErrorTest` **19/123**, `WebhookInvoiceMailAtomicDedupeTest` **3/14**, `WebhookReplayMailTest` **1/4** und `StripeWebhookTest` **32/116** grün (mit bekannten `.env`-Warnungen; Scouting-Tests mit `SCOUT_DRIVER=null`). Frontend `CartProvider.test.tsx` + `ClientCartView.test.tsx` + `checkoutSession.test.ts` **47/47** grün. PHP-Syntax (10 geänderte PHP-Dateien), Pint (10 Dateien) und `git diff --check` grün. Der Default-Lauf von `CheckoutPersistenceIntegrationTest` scheitert an Meilisearch `127.0.0.1:7701`; `CheckoutServiceTest` (5 Mail-Pfade) und `MailDeliveryTest` inklusive neuem Invoice-Claim/Mailpit-Test scheitern an SMTP `127.0.0.1:1025` (die Mailpit-API `127.0.0.1:8025` ist ebenfalls nicht erreichbar). Kein Mailpit-Zustellungs-PASS behauptet. Gezieltes ESLint ignoriert die Testdatei standardmäßig; `--no-ignore` meldet nur den bereits vorhandenen unbenutzten `_init`-Parameter in `ClientCartView.test.tsx:462`; mit diesem bestehenden Rule-Ausschluss ist die Datei lint-clean. Kein Playwright ausgeführt.

**Integration & Commit-Ownership (2026-09-25):**
**Working-Tree-Verifikation (Code committed; Task-Board-Update ausstehend):**
- Frontend-Final-Gate 2026-09-25: `pnpm test:run` **126/126 Testdateien / 1005/1005 Tests**, `pnpm lint:fix`, `pnpm lint:e2e`, `pnpm build` und Playwright-Collection **396 Tests/84 Dateien** grün; Browserlauf bleibt CI-/Stack-Follow-up.
- Plugin-Harness `bash admin.lrplugin/tests/run.sh`: grün; echte Lightroom-Runtime bleibt nicht verfügbar.
- V037: MariaDB-11.4-Migration inklusive Seed und Owner-Trigger-Replay grün; partielle DDL-Retry-Guards, SQLite-Trigger-Cleanup und PostgreSQL-Catalog-Guard implementiert; fokussierte PHPUnit-Regression (53 Assertions), PHP-Syntax/Pint und Disposable-MariaDB-Replay grün.
- Contract-/Magic-Link-Fokus: PHPUnit 59 Tests/242 Assertions plus `MagicLinkAuthTest` 5/18 grün; Datenbankuhr-Alias auf MariaDB 11.4 direkt verifiziert.
- Backend-Full-Suite-Lauf 2026-09-24: **umgebungsbedingt blockiert** (Meilisearch `127.0.0.1:7701`, Mailpit `1025/8025` und untracked `backend/.env` fehlten; 813 Aggregatfehler vor Assertions). Kein Produktfehler daraus; offizielle CI-Service-/Container-Baseline bleibt die maßgebliche Referenz.
- **Verifier-/Commit-Status 2026-09-25:** Die sechs Audit-/A7-Commits sind erstellt; unabhängige Verifier für Frontend, Checkout, Meta-Gallery, V039, AI, Cleanup, Quote und Storage liegen vor. Offen bleiben nur Live-Browser-/Betriebs-Evidence, V037-PostgreSQL und die expliziten A7-Betriebs-/Produktentscheidungen.
- **Pricing-Verifier-Follow-up (2026-09-25; Pricing-Implementierung + fokussierte Nachweise):** Contract store/update now preflight normalized candidate pricing before model writes; product, running-total, percentage, and persisted-ceiling overflow return 422 without contract/order/snapshot mutation. The shared `PersistedMoney::MAX_CENTS` ceiling is enforced by contract lifecycle/template-copy paths, checkout/collective-invoice guards, and Eloquent `Order`/`InvoiceSnapshot` write events. Legacy template copies preserve order when canonical partition order is unchanged and fail closed with a clear validation error when a discount would move before an item. PDF cents use exact integer formatting. Editor scaling validates at most two decimals and uses bounded `Math.round`; max-safe item/fixed/percent/manual-quantity roundtrips are green. Focused PHPUnit (22 relevant cases in the final pricing slice; warnings only from missing `.env`), focused/full Vitest (992/992), TypeScript tests, lint:fix, build, Pint, PHP syntax, and both diff checks pass. ContractClose/InvoiceService Mailpit paths remain environment-blocked at `127.0.0.1:1025`; no Playwright was run and no files were staged/committed/reset/stashed by this follow-up.
- **V037-Review (2026-09-25; historical statement reconciled):** Der aktuelle Second-Partial-Retry-Test deckt nun beide Owner-Trigger (Insert/Update) sowie Index-/Unique-Wiederherstellung ab; die frühere Aussage, dass nur Index/Unique geprüft würden, ist stale. Offen bleibt die unabhängige Cross-Driver-Ausführung unter SQLite, MariaDB/MySQL und PostgreSQL.
- [~] wartet auf einen Live-PostgreSQL-Dienst bzw. `psql` — der PG-Branch ist statisch geprüft, aber nie gelaufen. **V037 Cross-Driver-Retry-Verifikation:** SQLite/MariaDB unabhängig PASS (13/98, beide Owner-Trigger, Partial-Retry-Restore, Index/Unique/Catalog, V001–V039 Seed); PostgreSQL-Branch statisch geprüft, aber kein Live-PostgreSQL-Service/`psql` verfügbar. PG-Live-/Cross-Instance-Evidenz bleibt offen.
- **V037 Verifikationsanalyse (2026-09-24):** Der breitere `GuestOrderIsolationTest`-Lauf scheiterte an fehlender Meilisearch-Verbindung und nicht beschreibbarer `Storage::fake`-Cleanup-Pfade, nicht an V037; der isolierte Owner/Index-Test ist grün. `RatingUniquenessTest` ist mit `SCOUT_DRIVER=null` (4 Tests/25 Assertions) grün, während der Default-Lauf ausschließlich an Meilisearch `127.0.0.1:7701` scheitert.
- CI-Fix-Commit `8904c10` ist gepusht; Run `36035927250` auf diesem SHA ist **abgeschlossen rot**. Security-Contract, Backend und Frontend sind grün; Serial-E2E ist grün, sechs parallele E2E-Shards sind rot. Bestätigte Restursachen: Contract-Reset-Assertion, Search-History-State, Rating-PhotoLink, FTP-Navigation, Magic-Link, Cart-Ort/Detached-Link, Cart-Pricing-Sidebar und Coupon-Revalidation.
- E2E-Laufzeit Run `36035927250`: Frontend 4m25s, Serial-E2E 6m51s, parallele Shards 5m36s–17m09s. Der langsamste parallele Shard erreichte den bisherigen `globalTimeout: 900000` und ließ 26 Tests nicht ausführen; deshalb ist der neue, begrenzte Whole-Suite-Budget `globalTimeout: 1500000` (25 Minuten), der gegenüber dem Maximalwert rund 7m51s CI-Puffer lässt. Per-Test bleibt `timeout: 120000` (120s) unverändert.
- **E2E-Timeout-Entscheidung (User-Freigabe, 2026-09-25; auditbar):** Die gemessene Maximum-Baseline bleibt der langsamste parallele Shard aus Run `36035927250` mit **17m09s**; der serielle Lauf benötigte 6m51s. Die generische Doppelregel würde daraus 34m18s ergeben, aber der ausdrücklich freigegebene harte Cap bleibt bei **25 Minuten / 1500000 ms** (7m51s über dem gemessenen Maximum), mit **120000 ms / 120s pro Test**. Das ist eine bewusste Ausnahme von der Doppelregel, keine stillschweigende Timeout-Änderung; nach E2E-Änderungen sind neue Messung und explizite User-Freigabe erforderlich. In dieser Session wurde **kein Playwright-Lauf** ausgeführt; der Nachweis bleibt CI-gebunden.
- [~] wartet auf den Push und den echten Browserlauf im CI-Job; lokal ausdrücklich kein Playwright-Lauf. **CI/E2E-Verifier-Blocker (Implementierung 2026-09-25; Browser-Nachweis folgt nach Push):** Dependabot wartet jetzt ausschließlich auf `CI gate (push)` mit einem 65-Minuten-Job-Gesamtlimit; der Security-Contract prüft Event-Suffix, exakten Filter, Actor/Event-Skip, Fail-closed-Dependencies und Timeout. CustomerModal behandelt `PLZ & Stadt` als benannte Gruppe mit individuellen `aria-label`-Comboboxen; E2E-Regressions decken CustomerModal/AutocompleteInput, ProfileSettingsCard, SearchBar und den privaten Rating-Route-Guard ab (Dateien: `frontend/tests/e2e/admin/management-save-regression.spec.ts`, `frontend/tests/e2e/photographer/photographer.spec.ts`, `frontend/tests/e2e/guest/guest-search-header.spec.ts`, `frontend/tests/e2e/selection/rating-regressions.spec.ts`). **TODO:** Nach Push den echten Browserlauf in CI abwarten und dort die sichtbaren Ergebnisse der getaggten Tests dokumentieren; lokal ausdrücklich kein Playwright-Lauf.
- Timeout-Policy-Verifikation (2026-09-24): `pnpm exec tsc --noEmit -p tsconfig.node.json`, `pnpm lint:e2e`, `bash tests/infrastructure/ci-security-contract.sh` und `git diff --check` grün; gemäß Auftrag kein Browserlauf.
- **Cart-Pricing-Sidebar (2026-09-24; Fix umgesetzt, Browser-Verifikation ausstehend):** Run `36035927250` scheiterte auf Desktop und Mobile bereits an den Käufer-Navigationen. `power_user` ist ein Non-Staff-Client; dessen `ClientDashboard` rendert absichtlich `Suche & Entdecken`, aber nicht den staff-only Eintrag `Galerien & Ordner`. `SidebarHelper.navigateToClientGalleries()` verwendet den deutschen Discovery-Eintrag; alle drei Cart-/Coupon-Tests und ihre Tags bleiben erhalten. `SidebarHelper.test.ts` deckt den Client-Link ab (2 fokussierte Vitest-Tests grün), fokussiertes ESLint und `git diff --check` sind grün. Playwright wurde gemäß Auftrag nicht lokal erneut ausgeführt.
- **Coupon-Revalidation Branch-Readiness (2026-09-24; fokussierter Fix):** Der CI-Fehler war kein Overlay- oder Disabled-Klick: Nach `/photos/...` war der asynchrone `useLicensingMode`-Fallback zunächst die Scope-Karte, während der Fixture explizit Volume-Lizenzierung gesetzt hatte. `coupon-checkout-revalidation.spec.ts` navigiert den Nicht-Staff-Käufer jetzt über die deutsche Discovery-Seite, wartet semantisch auf die Galerieüberschrift und anschließend auf `volume-pricing-card` plus den aktiven deutschen Button `In den Warenkorb`; Invalid-/Expired-/Valid-Coupon-Semantik und `@feature:client:coupon` bleiben unverändert. Test-IDs und ein PhotoDetailView-Branch-Übergang sind durch fokussierte Vitest-Abdeckung geschützt. Lokales Playwright wurde nicht ausgeführt.

**Welle-0-Triage-Ergebnis (Read-only gegen `HEAD=59f9ec6`; 2026-09-24):**
- **Kanonisch aktiv:** CR-PAY-010, CR-FE-030, CR-CRM-008, CR-TEST-012, CR-FE-041, P1-F7 sowie die verbleibenden Reste aus P1-F10/F11, P1-A6/A7, P1-M9/M14 und das Model-Person-Count-Limit.
- **Bereits gefixt/stale oder duplicate:** P1-F1–F6/F8/F9/F12, P1-A1–A4, P1-L1–L6 (nur echte Lightroom-Runtime bleibt als Evidence offen), P1-M1/M2/M10–M13/M15, CR-FE-011/019/023/024/034/039, CR-TEST-005, CR-INF-005/006/019, P0-A13/P0-B7/CR-BE-010, CR-CODE-001 sowie die Age-Proof-Positivfallzeile. Die historischen Checkboxen dürfen nicht als neue Bugs double-countet werden.
  - **Korrigiert 2026-09-26 (DOC-8):** `P0-A13`, `P0-B7` und `CR-BE-010` standen in dieser Pauschalliste als gefixt, waehrend `CR-BE-010` (Z. 619) und die Einzelposition `P0-A13 (MEDIUM; reopened)` (Z. 815) sie ausdruecklich als NICHT gefixt fuehren. Die Einzeleintraege sind massgeblich, die Pauschalliste war falsch. **P0-A13 und P0-B7 sind OFFEN.**
- **Nur Verification/Environment:** Tag-Playwright-Ausführung, MariaDB-Migration/E2E, GHCR-/Deployment-/Secret-/Scheduler-/Storage-Nachweise, Plugin-Live-Runtime und Card-Testing-Betriebschecklisten. Diese Blöcke benötigen keinen erfundenen lokalen PASS.
- **Schema-Entscheidung:** CR-BE-018/CR-DATA-005/CR-DATA-018 bleiben bis zur realen Multi-Connection-/Template-Scope-Entscheidung offen; bestehende V036–V038 dürfen fachlich konsolidiert werden, V039+ nur bei nachgewiesenem unvermeidbarem Defizit. V037 hat zusätzlich einen MariaDB-CHECK-Kompatibilitätsblocker.
- **CI-Status 2026-09-24:** Der historische Run `36013526580` auf `59f9ec6` bleibt rot (V037/MariaDB-Fehler 1901; Frontend/Backend ansonsten grün). `e1f397d` beseitigte den Migrationsblocker; der darauffolgende Run `36024267904` war rot und deckte die nachgelagerten E2E-/Harness-Befunde auf. Der fokussierte Reparaturcommit `8904c10` ist gepusht; Run `36035927250` ist abgeschlossen rot. Image-Build `36024267953` ist grün; Registry-Namespace/Pullability bleibt separat offen.
- **E2E-Image-Namespace (2026-09-24):** Build-Run `36024267953` publiziert `ghcr.io/reisinger-pictures/portal-e2e@sha256:127543e4...`; `ci.yml` konsumiert weiterhin `ghcr.io/reisi007/portal-e2e@sha256:d542ae69...`. Ein lokaler `docker manifest inspect` des neuen Manifests endete mit `unauthorized`; Digest-/Namespace-Umstellung ist eine separate Registry-/Pullability-Entscheidung und darf nicht als bereits verifiziert gelten.
- **CI-E2E-Fehleranalyse Run `36024267904`:** Security/Backend/Frontend grün; alle sieben E2E-Shards erreichen die Tests, diverse API-/Auth-Flows scheitern jedoch. Bestätigte Ursachen sind der rohe `Set-Cookie`-Header, der MariaDB-reservierte Contract-Alias `CURRENT_TIMESTAMP AS current_time`, fehlende Label-/Pflichtfeld-Associierungen, der nicht erlaubte User-Endpoint für Photographers sowie zu unscharfe E2E-Locators. Die entsprechenden Fixes und Regressionen laufen getrennt; keine weiteren lokalen Browser-Retries.
- [~] wartet auf die unabhängige Verifikation; der Fix ist implementiert, aber nie von einem zweiten Agenten abgenommen. **Invite redemption regression (implemented; independent verification pending, 2026-09-24):** The logged-in-user E2E failure at `magic-link.spec.ts:70` was deterministic, not a consumed invite: `InviteView` returned from its auto-redeem effect with `!loading` after the lookup completed, so no second POST was sent. `GalleryInvite` is a revocable access grant (no `used_at`/expiry); the same token was verified for anonymous-then-registered redemption in PHPUnit. Fixed the loading guard, limited auto-redeem to registered users (`guest_id === null`), and made auth identity dependencies primitive so SWR revalidation cannot cancel navigation. Regression evidence: `InviteView.test.tsx` (4/4), `MagicLinkAuthTest` (same-token flow), PHP syntax, frontend build and targeted lint. Playwright was intentionally not run per task instruction.
- **Nicht als Fix erlaubt:** laufende Working-Tree-Änderungen erst nach unabhängiger Verifikation, fokussierten Tests, `git diff --check` und separatem Commit als erledig markieren.

**Test-Coverage-Audit der neuen Änderungen (2026-09-24; angefordert)**
- **Frontend-Full-Gate 2026-09-25 (finaler Verifier):** `pnpm test:run` **126/126 Testdateien / 1005/1005 Tests**, `pnpm lint:fix`, `pnpm lint:e2e`, `pnpm build` inkl. TypeScript/i18n/Vite und Playwright-Collection **396 Tests/84 Dateien** grün; Diff-Checks grün. Browserlauf bleibt CI-/Stack-Follow-up.
- **Review-Range:** `72f55da...HEAD` (Basis vor diesem Remediation-Commit; nach dem Push durch den späteren Reviewer auflösen)
- **Backend-Full-Gate 2026-09-24:** Der offizielle Containerlauf mit dem
  `portal-base:8.5`-Image (Registry `ghcr.io`, Namespace `reisi007` — **vor** der
  Migration auf `reisinger-pictures`) mit `--network host` und erreichbaren
  Meilisearch-/Mailpit-Diensten ist **PASS: 1.969 Tests, 0 Fehler, 6.276 Assertions**
  (1.962 Warnungen nur wegen fehlender lokaler `backend/.env`). Ein vorheriger
  Hostlauf war wegen der nicht erreichbaren Port-Forwardings umgebungsbedingt
  fehlgeschlagen und ist kein Code-Failure.
- **Backend-Integration-Final 2026-09-25:** Focused SQLite-/Container-/MariaDB-Gates für GalleryTree/MetaGallery, Checkout, CRM, Invoice, AI und Storage grün; offizielles PHP-Container-GD/ExifTool und Live Meilisearch/Mailpit-Slices grün. Der Host-Full-Suite-Lauf bleibt wegen `.env`/GD/Meilisearch/Mailpit und der bestehenden V001-PostgreSQL-Einschränkung umgebungsbedingt; kein vollständiger Backend-PASS wird behauptet.

**Bestätigte Findings aus dem unabhängigen Coverage-Audit (umgesetzt/verified; Umgebungs- und Folgeblocker separat)**
- **CR-FE-040 (P1, implemented/verified):** Das Entfernen eines einzelnen Items aus einem signierten Multi-Photo-Quote invalidiert den Token; der verbleibende Warenkorb wird ohne Quote-Metadaten persistiert und nicht mit veraltetem Token remounted. Regression: `CartProvider.test.tsx` (Multi-Photo-Quote, Persistenz/Remount), fokussiert 45 Tests grün; unabhängiger Verifier bestätigt den ursprünglichen Trigger.
- **CR-PAY-012 (P1, implemented/verified):** `POST /api/coupons/validate` returns the public `max_items` contract; the percentage preview now honors the server's cheapest-item limit. Regression: `CouponCheckoutControllerTest` (6 tests/19 assertions) plus `useCoupon` max-items Vitest.
- **CR-FE-047 (P1/P2, implemented/verified):** `useCoupon` resets atomically on eligibility, quote and complete-cart identity changes, ignores stale in-flight responses (including A→B→A), and the keyed `CouponInput` clears its local draft. Regression: 113 focused frontend tests across CartProvider/useCoupon/ClientCartView/cartLogic/CouponInput/LicenseSelector/VolumeLicensing, including non-eligible-item mutation.
- **CR-FE-048 (P2, implemented/verified):** Mixed carts retain `galleryGroupId` and send bounded, complete gallery/meta-gallery scope arrays to validation. Regression: mixed-scope controller contract plus ClientCartView/useCoupon request tests.
- **CR-TEST-013 (P2, implemented/verified):** Internal `/api/*` intercepts were removed from coupon/cart, delivery-metadata and AI-config E2E specs; real API fixtures/flows replace them. `pnpm lint:e2e`, targeted Playwright collection (26 tests across five specs) and the moved Vitest coverage pass; the only remaining route interception is the permitted external Stripe.js provider boundary.
- **CR-TEST-014 (P2, implemented/verified):** `admin.lrplugin/tests/run.sh` registriert `manager_upload_regression.lua`; der Harness-Contract-Test prüft die Lua-Dispatch-Reihenfolge, und der Static-Fallback weist ausdrücklich aus, dass ohne Lua-/Lightroom-Laufzeit keine Live-Ausführung erfolgt. `bash admin.lrplugin/tests/run.sh` ist grün.

**Befund CR-DOC-001 (P2, behoben)**
- Die Modul-Anweisungen nannten V027/V029 als letzte deploy-bereite Migration, obwohl zum damaligen Auditzeitpunkt V036 die Repository-Frontier war (V035 war laut Deployment-Status zuletzt deployed). Das konnte Agenten dazu verleiten, die falsche Migration zu erweitern und Deployment-/Seed-Anweisungen zu übernehmen. Die damalige Korrektur auf V036 ist inzwischen durch die aktuelle Repository-Frontier V038 (V037 Guest-Ownership, V036 Card-Testing) überholt; `AGENTS.md`/`backend/AGENTS.md` wurden entsprechend auf V038 mit V035 als zuletzt aufgezeichnetem Deployment-Stand und V039+ für neue Migrationen aktualisiert. Keine Produktionsmigration geändert.

**Weitere verifizierte Dokumentationsbefunde (umgesetzt 2026-09-24; Dokumentations-only)**

**Abgrenzung:** Für diese reine Dokumentations-Remediation werden keine
neuen Test- oder Verifikationsaussagen erstellt. Die allgemeine
Review-/Baseline-Tracking-Liste bleibt von den offenen Code-/Infra-Befunden
getrennt.

**Historischer Setup-Recovery-Verifikationslauf (2026-09-24, separater Verifikations-Subagent)**
- `frontend/pnpm run test:run`: **PASS**, 84 Dateien / 759 Tests; `pnpm lint:fix`: **PASS** ohne tracked Änderungen; `pnpm build`: **PASS**; PHP-Syntax: **PASS** (556/556); `git diff --check`: **PASS**.
- **Historischer Recovery-Lauf:** `backend/php artisan test`: **PASS nach Setup-Recovery**, 1.718 Warnungen / 7 passed / 4.936 Assertions, 66,49 s. Zuvor **FAIL**, 695 failed / 1.023 Warnungen / 7 passed (2.876 Assertions). Debugging-Analyse: Die ursprünglichen Logs zeigten `Connection refused` zu Meilisearch `127.0.0.1:7701` und Mailpit `127.0.0.1:1025`; nach lokaler CI-Image-/Fallback-Bereitstellung und Installation der fehlenden Host-Pakete `php8.5-gd` sowie ExifTool lief die Suite vollständig grün. Dies war ein Setup-/Umgebungsfehler, keine Code-Regression; die geänderte Umgebung ist nicht tracked und ist nicht der Status des aktuellen Checkouts.
- **Aktueller Full-Suite-Lauf (2026-09-24, final):** Im GD/ExifTool-Image
  `portal-base:8.5` (Registry `ghcr.io`, Namespace `reisi007` — **vor** der Migration
  auf `reisinger-pictures`) mit frischem `TEST_TOKEN=finalcontract`, Meilisearch und
  Mailpit im gemeinsamen Docker-Netz: **1.964 Tests / 6.241 Assertions, 0 Fehler**
  (81,98 s). PHP-Syntax, gezieltes Pint und `git diff --check` sind ebenfalls grün.

**Weitere verifizierte Dokumentations-/Infra-Befunde (009–016 Dokumentations-/Config-Konsistenz umgesetzt; 017–025 Dokumentations-only abgeschlossen)**
**Provisorisches Code-Finding (unabhängige Verifikation abgeschlossen)**
- **CR-CODE-001 (bestätigt als CR-BE-003):** Der Scratch-Probe wurde unabhängig bestätigt: Quote-Link signiert beliebige IDs und der Checkout/ZIP-Pfad kann ein privates Ziel ausliefern. Keine separate Implementierung; Remediation und Regression laufen unter CR-BE-003.
- **CR-CODE-002 (P1, purchase-time decision implemented):** `InvoiceService` and the organization summary prefer the purchase-time `invoice_snapshots.customer_details.org_id`; current `users.org_id` membership is used only for legacy snapshots without the key, while present-but-invalid keys fail closed. The existing JSON snapshot column remains sufficient (no migration/V035 change). The per-model Eloquent `InvoiceSnapshot::updating` boundary rejects replacement/removal of a present key; raw Query Builder/DB-facade, bulk Eloquent, and event-disabled writes are explicitly trusted maintenance escape hatches, so this is **application-level**, not hard database-level, immutability.

**Verifizierte Frontend-Befunde (READ-ONLY-Audit, Fix pending)**
- **CR-FE-001 (P1, implemented/verified):** Quote-Token und validierte Cart-Persistenz werden über `CartProvider`/`cartLogic`版本iert gespeichert; CartProvider- und CartView-Regressionen decke Reload/Navigation, Token-Bindung und stale-token Übergänge ab. Full-Vitest grün.
- **CR-FE-002 (P1, implemented/verified):** `ClientOrdersView` bietet ZIP nur für download-eligible Status an; Regression für `pending_payment` ist grün.
- **CR-FE-003 (P1, implemented/verified):** Management-Order-Statusoptionen entsprechen dem Backend-Vertrag; ManagementOrdersView-Regressionen sind grün.
- **CR-FE-004 (P1, implemented/verified):** Rating-Optimistic-State wird bei Fehlern zurückgerollt, Gast-Tastaturrating ist gesperrt und der Auth-Flow nutzt den vorgesehenen Root-Redirect;useGallery/Selection-Regressionen sind grün.
- **CR-FE-005 (P2, implemented/verified):** Auth-Revalidierung überschreibt dirty Billing-/Consent-Felder nicht; CartView-Regressionen und Full-Vitest sind grün.
- **CR-FE-006 (P2, implemented/verified):** `ProtectedRoute` normalizes trailing-slash management routes before rendering; direct `/galleries/` and `/admin-orders/` regression tests are green.
- **CR-FE-007 (P2, implemented/verified):** Cart and mobile close controls use real links/buttons with accessible names; keyboard/ARIA regressions are green.
- **CR-FE-008 (P2, implemented/partial):** `ModalDialogShell` now provides a named dialog, Escape/cancel handling and shared focus-trap behavior; broader legacy modal/nested-focus coverage remains partial.
- **CR-FE-009 (P2, implemented/verified):** Checkout/login/notification controls expose programmatic labels/names and the notifications route is linked from the sidebar; semantic-locator regressions are green.
- **CR-FE-010 (P2, implemented/verified):** Network helpers now preserve/assert response failures, rating flows check `response.ok()`, and cart persistence has a post-reload checkout regression.
- **CR-TEST-002 (shared-worktree verification, final snapshot):** Backend `php artisan test` **1.964/1.964 (6.241 Assertions)**; Full-Vitest **109 Dateien/876 Tests**; `pnpm lint:fix`, `pnpm lint:e2e`, `pnpm check:i18n`, `pnpm build`, PHP-Syntax, Pint, `git diff --check` und der Lightroom-Static-Harness (`admin.lrplugin/tests/run.sh`) sind grün. Lua/Lightroom-Live-Runtime bleibt nicht verfügbar.
- **CR-FE-011 (P1):** Access-cookie expiry prevents `/api/auth/refresh` because the refresh route is behind `auth:api`; idle sessions cannot recover. Separate refresh credential/handler and add PHPUnit/Playwright idle-session regression.
- **CR-FE-012 (P1, implemented/verified):** Frontend gruppiert Mixed Carts nach effektivem `(mode, preset)` und summiert serverseitig konsistente Gruppen; Pricing-/Cart-Regressionen sind grün.
- **CR-FE-013 (P1, implemented/verified):** Angezeigte Galerie-ID wird in PhotoDetail-/Management-Lizenz-UI durchgereicht; Komponenten- und Override-Regressionen sind grün.
- **CR-FE-014 (P1, implemented/verified):** Coupon-UI/Preview/Cart-Totals nutzen den tatsächlichen Server-Pricing-Pfad; Mode-/Percentage-/Fixed-/100%-Regressionen sind grün.
- **CR-FE-015 (P1, implemented/verified):** Stripe-/Coupon-Polling läuft über den zentralen Refresh/Retry-Pfad; 401→Refresh→Success-Regressionen sind grün.
- **CR-FE-016 (P1, implemented/verified):** Contract sign/join token boundaries remount by token and guard stale async responses; mutable-token Vitest regressions are green.
- **CR-FE-017 (P1, implemented/verified):** Customer/product/snippet/permissions modals retain entered state on rejected saves and expose loading protection; focused modal regressions are green.
- **CR-FE-018 (P1, implemented/verified):** Project/board form transformations now send explicit nulls for cleared assignee/price/count fields; regression coverage is green.
- **CR-FE-019 (P2):** Stripe loader failure silently disables payment with no retry/error/invoice fallback. Add loader-failure component and tagged checkout tests.
- **CR-FE-020 (P2, implemented; E2E pending):** Search URL synchronization now has a same-route back/forward regression with a mounted-input marker; tagged execution remains blocked by the E2E harness.
- **CR-FE-021 (P2, implemented/verified):** Create-user and license-catalog editors reset/version their drafts on reopen and SWR snapshot changes; rerender/reopen unit regressions are green.
- **CR-FE-022 (P2, implemented/verified):** Brand primary/secondary colors are applied through `BrandRegistry` CSS variables and revalidated after settings writes; unit coverage is green and tagged reload coverage is present but blocked with E2E.
- **CR-FE-023 (P2, implemented/partial):** Management/global menu openers now expose `aria-expanded`/`aria-controls` and unit coverage is green; the remaining gallery opt-in mobile-tag coverage is still open.
- **CR-FE-024 (P2, implemented/partial):** Modal shell focus handling is centralized and covered by `ModalDialogShell` tests; nested GalleryModal/document-listener race coverage remains a follow-up.
- **CR-FE-025 (P2, implemented/verified):** Login/auth E2E helpers use semantic role/name locators and accessible controls; lint/list checks pass, browser execution remains environment-blocked.
- **CR-FE-026 (P1, implemented/verified):** `CartProvider` groups effective `(mode,preset)` pricing, excludes quote items from tiers, persists quote state and uses server-consistent totals; mixed-cart unit/pricing regressions are green.
- **CR-FE-027 (P1, verifier caveat):** Separate access/refresh cookie names currently carry the same JWT value; if refresh credential independence is required, issue distinct signed tokens/claims and test rotation/replay. This needs an explicit auth-contract decision.
- **CR-FE-028 (P1, implemented/verified):** Authenticated raw-fetch paths now use the centralized refresh/retry/error pipeline; external LM Studio remains an explicit provider boundary. Focused API/AI/upload regressions are green.
- **CR-FE-029 (P2, implemented/verified):** `useAuth` now fetches/propagates `AuthMeUser` through SWR while retaining the broader `User` re-export only for non-auth consumers; type fixtures and tests are green.
- **CR-FE-030 (P2, decision selected 2026-09-24):** Meta-gallery licensing must resolve per child/gallery group rather than the first child; implement grouped descriptors/UI totals and mixed-child Vitest plus tagged E2E coverage.
- **CR-FE-031 (P2, implemented/verified):** Volume summaries count only non-quote items for tier thresholds and display totals; mixed quote/non-quote unit regressions are green.
- **CR-FE-032 (P1, implemented/verified):** Logout clears protected SWR state without revalidation and `useAuth.isLoading` no longer remains true after user/error are absent; hook/UI regressions are green.
- **CR-FE-033 (P2, implemented/verified):** The SWR auth fetcher is typed as `AuthMeUser`; fixtures and hook tests enforce the `/api/auth/me` contract.
- **CR-FE-034 (P2, E2E policy):** `management-save-regression.spec.ts` mocks the internal customers CRUD endpoint despite the repository rule against internal-route mocks; move rejection coverage to component/integration tests or implement real backend setup.
- **CR-FE-035 (P2, implemented/verified):** `pnpm lint:e2e` successfully parses the tagged E2E suite with the TypeScript-aware configuration.
- **CR-FE-036 (P2, implemented; E2E pending):** Same-route back/forward search coverage with a mounted-input marker is present; execution is blocked by the E2E harness.
- **CR-FE-037 (P2, implemented/verified):** License-catalog modifier editor has an active-edit SWR rerender regression; draft versioning prevents stale values.
- **CR-FE-038 (P2, implemented; E2E pending):** Brand-settings E2E is serial, resets global settings, skips the mobile mutation and uses semantic locators; browser execution remains blocked.
- **CR-FE-039 (P2, implemented/verified):** The shared-worktree `GalleryModal` reference race is resolved; repeated full Vitest runs are green (109 files/876 tests).
- **CR-TEST-005 (P2, E2E fixture):** Quote cart E2E specs submit fake `mocked-photo-*` IDs, but current quote validation requires real photos; replace with API-created real fixtures before treating tagged E2E as evidence.
- **CR-TEST-006 (P1, behoben):** `pnpm lint:fix`, `pnpm lint:e2e` und der Full-Vitest-Lauf sind grün; die previous Lingui/Coupon-Erwartungsfehler sind reconciled.
- **CR-TEST-010 (P1, behoben):** Full-Vitest (109 Dateien/876 Tests) ist grün; Pricing-/Coupon-Erwartungen und Mixed-Cart-Totale sind konsistent.
- **CR-TEST-011 (P2, i18n blocker, behoben):** `Rabatt` and registration-success messages are extracted/compiled; `check:i18n`, focused lint and build pass.
- **CR-FE-041 (P2, adjacent i18n gap):** CartItemList still contains raw user-facing German strings for group image/tier summary and discount explanation that the Lingui guard cannot detect. Wrap/translate them and rerun catalog checks.
- **CR-FE-042 (P1, implemented/verified):** `RatingStatusModal`, `useGallery`-Mutationen und Upload-/AI-Portalpfade nutzen den zentralen Refresh/Retry- und Response-Fehlerpfad; externe LM-Studio-Aufrufe bleiben als explizite Provider-Grenze ohne Portal-Cookie. Fokussierte 96 Vitest-Tests grün; Full-Vitest aktuell 109 Dateien/876 Tests grün.
- **CR-FE-043 (P2, implemented/verified):** `AbortError` bleibt in `fetcher`/`apiMutate`/`apiUpload`/`apiDownload` erhalten und `ImageHelper` reicht `AbortSignal` bis zum Download durch; Abort-Regressionen sind grün.
- **CR-FE-044 (P2, exact auth type):** `AuthMeUser` still marks several backend-guaranteed fields optional; decide whether the frontend contract should be an exact mirror and tighten fixtures/types.
- **CR-FE-045 (P1, implemented/verified):** Central API retry paths now normalize network failures/callbacks and preserve AbortError; canceled callers stop before/after shared refresh. Quote/invite/org lookup effects have stale-response/abort guards; reset logout checks non-OK responses. Focused fetch/invite/cart/reset tests (96) and full Vitest (109/876) are green.
- **CR-FE-046 (P1, implemented/verified):** LM Studio model discovery rejects non-2xx JSON responses, keeping external provider fallback fail-closed; signal-aware AI regressions are green.
- **CR-MEDIA-001 (P1, implemented/verified):** Stale unwatermarked file-delivery/ZIP derivatives are deterministically rebuilt and provenance-checked; `MediaVerifierFollowUpsTest` is green.
- **CR-MEDIA-002 (P1, implemented/verified):** Management tree, group show, settings and authorization paths enforce the complete gallery/group brand tree; focused parent-brand tests are green.
- **CR-MEDIA-003 (P2, implemented/verified):** Parent-group brand mismatch coverage now includes invite/finish-rating, sitemap and legacy ZIP/selection boundaries; focused tests are green.
- **CR-MEDIA-004 (P1, implemented/verified):** Selection stale ZIP, legacy order ZIP, quote-decode and watermarked-preview regressions are present and green.
- **CR-MEDIA-005 (P2, implemented/verified):** `MediaVerifierFollowUpsTest.php`, `InviteRevocationTest.php`, and `PublicMediaBrandIsolationTest.php` are present in the staged change set; release hygiene is closed.
- **CR-MEDIA-006 (P1, implemented/verified):** `MediaVisibilityService` reloads current photo/gallery/group state and filters generic ZIP/single delivery plus public gallery/search projections; hidden/inherited-hidden regressions pass (15 tests/79 assertions in the focused public-media suite) with no bytes or DownloadLog records.
- **CR-MEDIA-007 (P1, implemented/verified):** `sendQuote()` atomically claims `pending -> cancelled` before mail; status-race and sequential duplicate regressions pass in `QuoteControllerSecurityTest` (10 tests/27 assertions), with queued-mail semantics asserted correctly.
- **CR-MEDIA-008 (P1, implemented/verified):** PhotoDownload/FileDelivery reauthorize current order, ownership, brand tree, expiry, access, entitlement and photo state after derivative preparation and immediately before ZIP/single/direct bytes. Late refund/dispute/access-revocation regressions cover ZIP, single and direct media.
- **CR-MEDIA-009 (P2, implemented/verified):** Late single-download failures remove prepared `base_scale_*` and `dl_*.jpg` derivatives without deleting the persistent source; `MediaLateAuthorizationTest` asserts no temp residue, response bytes or successful log.
- **CR-BE-020 (P2, implemented/verified):** Explizite `null`-Photo-Job-Counts werden auf Request- und Modellebene abgewiesen; Store/Update/Move/Handoff/Cleanup-Regressionen sind grün.
- **CR-TEST-003 (P2, coverage gap):** No real idle-session Playwright test expires the access cookie while retaining the refresh cookie; add a tagged browser regression when the local stack is available.
- **CR-TEST-004 (P2, environment/setup blocker):** Drei tagged-Smoke-Versuche wurden nach dem Frontend-Fix durchgeführt: (1) Host `pnpm test:e2e:smoke` — Chromium fehlt und `::1:4321` ist nicht erreichbar; (2) `portal-e2e`-Image mit pnpm — Modulinstallation brach wegen No-TTY/Store-Mismatch ab; (3) Browser-Image mit direktem Vite/Playwright und konfigurierbarem Mailpit — 2 passed, 10 failed, 50 did-not-run, 1 Setup-Error. Die Admin-Login-Hilfe schlug im frisch geseedeten E2E-Backend weiterhin mit `Ungültige Zugangsdaten` fehl; ein direkter Curl-Login war zwischenzeitlich 200, die Testdaten wurden während des Laufs inkonsistent. Nach drei Versuchen gemäß E2E-Regel keine weitere Retry-Schleife; tagged Smoke in CI mit dem offiziellen `scripts/e2e-up.sh`/Compose-Harness wiederholen.

**Verifizierte Backend-Security-Befunde (READ-ONLY-Audit, Fix pending)**
- **CR-BE-001 (P1, implemented; verified):** V037 guest ownership and centralized actor scoping are implemented and independently verified: 229 focused tests / 948 assertions plus the corrected real-photo ZIP regression (8/45) passed, V036 untouched, no backfill/sentinel, legacy rows inaccessible, guests denied model/account access and unsupported checkout fails closed. Pint for the new regression passes; remaining baseline style/test blockers are tracked separately.
- **Migrationsentscheidung (2026-09-24; aktualisiert nach V038-Fortschritt):** V035 ist der zuletzt aufgezeichnete deployte Stand; V036 bleibt die Card-Testing-Migration, V037 die Gast-Ownership-Migration und V038 die aktuelle Repository-Frontier. Neue Änderungen müssen als separate V039+-Migrationen erfolgen; bestehende Migrationen werden nicht nachträglich geändert.
- **CR-BE-002 (P1, implemented/verified):** Repeated unauthenticated contract joins now fail closed with no personal_token/name/role disclosure; focused contract tests and independent verification pass.
- **CR-BE-003 (P1, implemented/verified):** Quote issuance, brand/existence/access checks, exact token/item binding, server price, revoked-access rejection and settled order ZIP reauthorization (including hidden/expiry/private access) are implemented. Focused quote/order fulfillment tests pass; public-media and generic delivery visibility are covered under CR-MEDIA-006.
- **CR-TEST-001 (behoben):** `resolveQuoteToken()` is defined, legacy fake-ID fixtures were replaced, and focused quote tests pass; the independent quote verifier found only the fulfillment gap above.
- **CR-BE-004 (P1, implemented/verified):** Fresh missing/invalid watermark assets fail closed, provenance markers reject stale derivatives, and file/ZIP/parent-brand follow-up regressions pass in the GD/ExifTool container.
- **CR-BE-005 (P2, implemented/verified):** Contract personal-token paths enforce status/expires_at/closes_at consistently with DB-time predicates; the `page-exit` route is telemetry-only and parent legacy deadline behaviors pass focused tests.
- **CR-BE-006 (P2, implemented/verified):** Foreign-brand registration rolls back inserted user/reset state; auth/me contract also passes independently.
- **CR-BE-007 (P1, implemented/verified for audited surfaces):** Public media/download/context/rating, dual-role upload and JWT host binding enforce current brand/reserved-null rules; two-brand/null-brand regressions are green. A second brand still requires deployment-level live verification.
- **CR-BE-008 (P1, implemented/verified):** `ContractCloseService` resolves billing users only within the contract brand; foreign/brandless users leave the generated order unowned, while order/invoice brand and ownership remain scoped. `ContractCloseTest`: 4 tests/56 assertions.
- **CR-BE-009 (P2, implemented/verified):** Org controller/invite and project/photo-job relationship paths enforce brand/access scope; 49 focused org/board relationship tests (114 assertions) are green.
- **CR-BE-010 (P2, review integrity):** Existing P0-B7 contract-token and P0-A13 watermark entries are not actually fixed; prior “FIXED & VERIFIED” labels and the unsafe existing test must be corrected rather than treated as closed.
- **CR-BE-011 (P1, implemented/verified):** Reserved-null trust boundaries now fail closed for role-only promotion, existing null-brand non-super-admins, login/reset/refresh, management/gallery authorization and brandless org invites; transient guests retain only active current-host invite access. `ReservedNullBrandTrustBoundaryTest`: 9 tests/25 assertions.
- **CR-BE-012 (P2, implemented/verified):** `OrgController::show` and all write/sync/invoice guards reject brandless organizations for brand-bound actors; focused org suites are green.
- **CR-BE-015 (P1, implemented/verified):** Role-only promotion to `super_admin` normalizes an existing brand to null atomically; trust-boundary regression is green.
- **CR-BE-021 (P1, implemented/verified):** `ImageProcessor::isSafeWatermarkedOutput()` requires watermark provenance/effective derivative proof; stale re-encoded cache regression and file/ZIP rebuild tests are green.
- **CR-BE-022 (P1, implemented/verified):** Parent/group brand validation is centralized across media/download/rating/management paths; current-brand-under-foreign/null-parent regressions are green.
- **CR-BE-023 (P1, implemented/verified):** `MailController` invite/rating paths enforce host/resource brand before side effects; focused regressions are green.
- **CR-CRM-010 (P1, implemented/verified):** Selection no-public/no-original rules are enforced through checkout, quote validation, ZIP delivery, flat-rate media and sitemap; focused selection regressions are green.
- **CR-TEST-012 (P2, implemented/static-verified 2026-09-24):** `scripts/wait-for-meilisearch.sh` now enforces validated total/request/retry bounds and caps curl/sleep to the remaining deadline; `scripts/e2e-up.sh`, backend PHPUnit CI and E2E CI invoke it before Scout access. `E2ELocationFixturePolicyTest` protects the local/CI ordering and bound contract; Bash syntax, the CI security contract, `git diff --check` and a fake-curl cold-start harness (retry, request cap, 1s deadline, invalid-config fail-closed) pass. No local Playwright run was performed; the live service-container replay remains CI-owned.
- **CR-TEST-008 (P2, V037 DoD, behoben):** `GuestOrderIsolationTest.php` import/FQCN Pint violations were corrected; targeted Pint, syntax and `php artisan test --filter GuestOrderIsolationTest` pass.
- **CR-TEST-009 (P1, V037 follow-up, behoben):** Der Gast-ZIP-404 wurde reproduziert und auf eine ungültige Test-Fixture mit synthetischer, nicht persistierter Photo-ID zurückgeführt; Ownership/Brand/Snapshot waren korrekt. Testfixture auf reales `rp`-Gallery/Photo/Sample-Image umgestellt, Cross-Guest-Denial auf null Download-Logs verschärft; `GuestOrderIsolationTest` nun 8 Tests/45 Assertions und verwandte ZIP-Suites 27/83 grün, Pint/Syntax/Diff grün.
- **CR-BE-016 (P1, implemented/verified):** Existing/otherwise-created `brand = null` non-Super-Admins are rejected by shared authorization/login/reset/refresh boundaries; guest invite semantics remain scoped.
- **CR-BE-017 (P1, implemented/verified):** Brandless organization invite redemption is rejected without creating a null-brand non-Super-Admin; regression is green.
- **CR-BE-018 (P1, contract verifier follow-up, partial):** Direct and template join paths now share one stable normalized-email lock identity, lock the scope row, and keep duplicate check/insert plus instance creation in one transaction. The sequential transaction regression proves a mixed-case retry cannot create a second signer/instance, but this is not a single SQL conditional INSERT or a real multi-connection race; keep open pending a durable DB strategy.
- **CR-BE-019 (P1, contract verifier follow-up, implemented):** Parent legacy deadlines use the earlier effective deadline; the `page-exit` route is explicitly telemetry-only (valid personal token, no contract data/signature mutation, accepted after closure), with audit action `page_exit`. Focused contract tests pass.
- **CR-DATA-018 (P1, contract verifier follow-up, open):** The current V021 schema has no normalized-email column or durable unique `(contract_id/template, normalized email)` constraint. Application cache/row locks and `LOWER(TRIM(email))` protect supported writers, and the real sequential/transaction regression covers normalized duplicates, but bypass writers or a different cache domain can still insert duplicates. A separate migration decision (backfill, legacy cleanup, normalized column, unique index) remains required; no migration is added here and this finding is not closed.
- [~] wartet auf einen echten Multi-Connection-Race. Die Testumgebung ist SQLite `:memory:` und kann ihn prinzipiell nicht liefern. **Contract-join verification TODO:** Run a real multi-connection race and make the separate normalized-email/legacy-cleanup/unique-index decision before closing the remaining findings. The current SQLite `:memory:` environment cannot provide that race.
- **Contract suite environment note (2026-09-24):** An earlier broader contract run reached 75 warnings but two unrelated `ContractCloseTest` cases failed because the configured Mailpit SMTP endpoint `127.0.0.1:1025` refused connections; the join/template/availability tests pass independently. No mail or rating/V036/V037/V038 files were changed for this task.
- **CI-36024267904 / Contract join (P1, root cause fixed; focused verification):** The initial UI contract-creation test passed, and management create/open requests using the same `E2ESessionHelper` cookie passed; the failing public payloads contained valid names, emails, and `Model`/`Fotograf` roles. A MariaDB 11.4 API reproduction returned `500` before join validation/persistence with `SQLSTATE[42000] ... syntax error ... near 'current_time'` from `Contract::databaseNow()`. `CURRENT_TIME` is a reserved MariaDB word used as an unquoted result alias. The fix uses the portable alias `contract_database_now`; no auth-cookie or E2E harness correction is warranted. `ContractAvailabilityTest::test_database_clock_read_uses_a_mariadb_safe_alias` is the focused regression; the existing direct/template join regressions remain green (47 tests, 156 assertions), and a disposable MariaDB API check now returns `201` for join and `410` for the expired-template check. No Playwright retry was run after the three-attempt limit; the existing contract-uniqueness changes remain untouched, and no migration was changed for this fix.

**Verifizierte Infrastruktur-/Plugin-Befunde (READ-ONLY-Audit, Fix pending)**
- **CR-INF-001 (P0/P1; implemented/verified):** `sync.sh`/`rclone-backend-filter.txt` protect private storage and Stripe secrets, propagate failures, and real/fake rclone regressions verify ordinary sync plus private/root-SQLite preservation.
- **CR-INF-002 (P1, implemented/verified):** Production `db:seed` preserves existing settings via `insertOrIgnore`; custom-value regression passes.
- **CR-INF-003 (P1; historischer Befund, Verifikation offen)** Ein fehlendes `ADMIN_PASSWORD` fiel zuvor auf `admin` zurück, und `admin:update` rotierte ein bestehendes Passwort nicht. Die aktuelle Config/Seeder/Command-Kette ist env-only, fail-closed und rotiert das konfigurierte Passwort; ein aktueller Deployment-Nachweis bleibt offen.
- **CR-INF-005 (P2):** Empty gallery expiry in `GalleryDialog.lua` is omitted and cannot clear an existing backend expiry; distinguish omitted vs explicit null.
- **CR-INF-006 (P2):** `/api/auth/me` omits `can_edit_metadata` and saved billing fields required by frontend prefill/permissions; add backend contract and frontend regression tests.
- **CR-INF-007 (P2; historischer Befund, Verifikation offen)** Frisch geseedete Gallery-Gruppen hatten zuvor `brand = NULL`. Der aktuelle `DatabaseSeeder` setzt die aufgelöste Brand und repariert Legacy-NULL-Gruppen; ein aktueller Fresh-Seed-/Tree-Nachweis bleibt offen.
- **CR-INF-008 (P2; historischer Befund, Verifikation offen)** Der Location-Import lief zuvor synchron im Seed-Gate und zusätzlich als Hintergrundcommand. Das aktuelle Working Tree ruft `app:import-locations` nicht im Backend-Boot auf; `routes/console.php` plant ihn wöchentlich, mit Lock und transaktionalem Refresh. Live-Scheduler-/Failure-Isolation-Nachweis bleibt offen.
- **CR-INF-009 (P2; historischer Befund, Verifikation offen)** Ein leeres `PHOTO_STORAGE_PATH` konnte zuvor den Filesystem-Default überschreiben. Der aktuelle Compose-Guard verlangt einen nicht leeren absoluten Pfad und prüft das Mount-Ownership; der Deployment-Nachweis bleibt offen.
- **CR-INF-012 (P2; historischer Befund, Verifikation offen)** Privileged CLI-/Queue-/Scheduler-Container liefen zuvor als root mit schreibbaren Bind-Mounts. Das aktuelle Working Tree setzt `USER www-data`/UID:GID `1000:1000` und prüft Ownership; der Live-Runtime-Nachweis bleibt offen.
- **CR-INF-013 (P2; historischer Befund, Verifikation offen)** CI-/Runtime-Images und Actions verwendeten zuvor mutable Tags. Das aktuelle Working Tree enthält Digest-/SHA-Pins; ein aktueller Supply-Chain-Policy-Check bleibt offen.
- **CR-INF-014 (P2, implemented/verified):** Root and nested SQLite exclusions are ordered before catch-all; real/fake rclone tests verify root files are neither uploaded nor deleted and private storage remains protected.
- **CR-INF-015 (P1, implemented/verified):** Fresh E2E databases load the checked-in `backend/database/fixtures/e2e-locations.json` through explicit `E2ELocationSeeder`; CI and `scripts/e2e-up.sh` flush/sync/import the Location Scout index afterward. `DatabaseSeeder` remains network-free, production startup contains no location import, and weekly lock/scheduler behavior remains. Focused/live disposable Meilisearch tests, policy checks and scripts pass.
- **CR-INF-016 (P2, implemented/verified):** Direct Laravel config rejects empty/relative `PHOTO_STORAGE_PATH`; the runtime policy test checks the pinned container's stdout/stderr behavior and passes (6 tests/107 assertions). Local-template portability is covered under CR-INF-020.
- **CR-INF-017 (P1 release follow-up, open):** Pinned GHCR `portal-base`/`portal-e2e` images are stale and still run as root/UID 33 despite source Dockerfiles using UID 1000; rebuild/re-publish and update digests after verifying image metadata.
- **CR-INF-018 (P2, source-fixed/release-partial):** Local/test Compose images and E2E Dockerfile Node download are digest/version/checksum pinned and policy-covered, but the published GHCR digests are stale and the frontend CI setup-node job still uses mutable `node-version: 26`. Rebuild/update images and pin that job before closure.
- **CR-INF-019 (P2 release hygiene, implemented/verified):** The previously deferred hardening/regression paths are included in the final staged change set; the final review must verify the complete `git diff --cached --name-status` before push.
**Verifizierte Backend-Datenintegritäts-Befunde (READ-ONLY-Audit, Fix pending)**
- **CR-DATA-001 (P1, implemented/verified):** Coupon group scope now uses `galleries.gallery_group_id` and recursively expands assigned parent/child groups consistently with AuthorizationService; 66 coupon tests/166 assertions, nested/direct/brand checks, targeted Pint/syntax/diff pass.
- **CR-DATA-002 (P1, implemented/verified):** `ImageController` replacement closure now captures `$originalName`; the null-title/wrong-replacement regression is green in `ImageUploadTest`.
- **CR-DATA-003 (P1, implemented/verified):** FTP import reads/writes/processes before source cleanup, checks storage/processing failures and removes the inbox file only after DB success; `FtpImportTest` is green.
- **CR-DATA-004 (P1, implemented/verified):** Payout attribution uses actual bounded photo IDs and handles mixed galleries/photographers; `PayoutCalculationServiceTest` plus order fulfillment are green.
- **CR-DATA-005 (P1, application-locked/DB invariant open):** Cache/row locks make known repeated/concurrent join/sign writers produce one signer/instance/audit transition, but no durable normalized-email unique constraint or real multi-connection race regression exists; see CR-DATA-018.
- **CR-DATA-006 (P1, implemented/verified):** Gemeinsamer `ModelPhotoPrimaryService`-Pfad deckt Admin-/Owner-Promotion, Registrierungs-Resubmission, Cleanup und Löschabbruch mit Lock/Transaktion ab. Unabhängige Verifikation: 11/11 Primary-Tests (57 Assertions), 42/42 Owner-Suites, 8/8 Cleanup-Tests; targeted Pint/syntax/diff grün. SQLite-Sequenztests und 30-s-Cache-Lease bleiben als Anwendungs-/Race-Limit dokumentiert.
- **CR-DATA-007 (P1, implemented/verified):** `GalleryService::updateGroup` synchronizes `org_id` only when supplied, preserving omitted assignments and clearing explicit null; `GalleryServiceTest` is green.
- **CR-DATA-008 (P1, implemented/verified):** Metadata revert records the pre-revert state and acting user in `PhotoMetadataVersion`; `PhotoMetadataTest` is green.
- **CR-DATA-009 (P1, implemented/verified):** Public rating mutation enforces current host/brand tree before any rating write; two-brand/null-brand no-mutation regressions are green.
- **CR-DATA-010 (P2, implemented/verified V038):** Gast-Bewertungen besitzen nun den portablen `actor_key` mit DB-Unique-Invariante, Upsert-Lock und Retry; V038 ist die separate autorisierte Migration (V036/V037 unverändert). `RatingUniquenessTest` deckt Schema/Ownerless-Legacy, deterministische Legacy-Normalisierung, Gast-Deduplizierung und User/Guest-Isolation ab (4 Tests/25 Assertions, `SCOUT_DRIVER=null`); SQLite `migrate:fresh --seed`, V038-`up()`-Idempotenz, Pint, Syntax und Diff-Check sind grün. MariaDB 11.4 bestätigt nullable-Unique-Semantik und V038-Rerun/Deduplizierung; der vollständige MariaDB-Migrationslauf bleibt durch den bestehenden V037-Check-Constraint-Fehler (`user_id` in CHECK, Error 1901) blockiert, ohne V037 zu ändern.
- **CR-DATA-011 (P2, implemented/verified):** Order/single/gallery ZIP audit records persist bounded actual `photo_ids`/`gallery_ids` (and order linkage where applicable); `OrderDownloadFulfillmentTest` passes.
- **CR-DATA-012 (P2, implemented/verified):** `CleanupDerivatives` verifies adapter deletion and reports failure/nonzero exit when a derivative remains; `StorageCommandsTest` is green.
- **CR-DATA-013 (P2, implemented/verified):** Board-Mutationen verwenden Brand-/Owner-sichtbare Cache- und DB-Locks, dichte Reindexierung und owner-scoped Cleanup; 124 Board-Tests/463 Assertions sind grün. Cleanup reindexiert exakt gelöschte Owner/Status-Paare (inkl. Null-Brand); direkte SQL-Writers/echte Multi-Process-Race bleiben als Anwendungsgrenze dokumentiert.
- **CR-DATA-014 (P1, implemented/verified):** Recursive descendant-group coupon scope is fixed and independently verified (parent→child regression, 66 coupon tests/166 assertions, nested/direct/brand checks, targeted Pint/syntax/diff).
- **CR-DATA-015 (P1, implemented/verified):** Real single/gallery/order ZIP logs now persist bounded actual photo IDs; `PayoutCalculationServiceTest` and order fulfillment suite pass (42 tests/109 assertions combined), including multi-photographer attribution.
- **CR-DATA-016 (P2, implemented/verified):** `GalleryService::updateGroup` distinguishes omitted/null `org_id`, and `GalleryGroupModal` forwards `extraOpts`/prefills from `orgs`; focused modal/data-contract regressions are green.
- **CR-DATA-017 (P2, implemented/verified):** Mixed-gallery order logs carry bounded actual photo/gallery ID payloads and payout attribution; mixed-order regression is green in the 42-test/109-assertion focused fulfillment/payout run.
- **CR-DATA-019 (P2, implemented/verified):** `GalleryGroupResource` serializes minimal loaded `orgs` (`id`,`name`) and management queries eager-load the relation; `GalleryGroupDataContractTest` passes without exposing domain/brand.

**Verifizierte Checkout-/Payment-Befunde (READ-ONLY-Audit; Status inline)**
- **CR-PAY-001 (P1, implemented/verified):** Generic coupon CRUD autorisiert effektive Gallery-/Group-Scopes; No-Write-403-Tests decken Foreign/Assigned/Update ab.
- **CR-PAY-002 (P1, implemented/verified 2026-09-24):** Volume pricing now persists the supported `original` entitlement tier; the focused original-resolution order-ZIP regression passes.
- **CR-PAY-003 (P1, implemented/verified 2026-09-24):** Effective gallery mode wins over the injected brand-default strategy; explicit `scope_licensing` remains scope-priced and does not consume coupons.
- **CR-PAY-004 (P1, implemented/verified):** `used_count` ist clientseitig unveränderlich; Max-Use-/Delete-Guard-Regressionen sind grün.
- **CR-PAY-005 (P1, implemented/verified 2026-09-24):** Mixed volume groups are priced first, scoped validation considers all non-quote volume items, and one coupon is applied once to the combined effective volume subtotal. Fixed, percentage-with-`max_items`, percentage-without-max, scoped-second-gallery, photo-package and mixed scope/volume regressions are green; coupon `max_items`/`photo_package` use effective qualifying-tier prices while invoice lines retain base prices plus the tier breakdown.
- **CR-PAY-006 (P1, implemented/verified):** Manuelle Order-Statuswechsel löschen Owner/Photo/Tier-Purchase-Caches; Regression und bestehende OrderController-Suite (15/29) sind grün.
- Verification: `PaymentPricingRegressionTest` **11/11 (53 assertions)**; focused pricing/checkout/coupon run including `PaymentFindingsRegressionTest` **141/141 (374 assertions)**; targeted Pint, PHP syntax, and diff checks pass. No migration files were changed. The broader checkout run remains environment/shared-worktree dependent (Mailpit/Meilisearch and the concurrent brand-isolation changes), so no full-suite green claim is made here.
- **CR-PAY-007 (P2, implemented/verified):** Cart pricing groups carry the effective custom preset/tier and server-consistent item prices; component/cart regressions are green.
- **CR-PAY-008 (P2, implemented/verified):** Coupon UI uses the current server-priced cart discount (with an explicit legacy fallback only when no server amount exists); `CouponInput` regression is green.
- **CR-PAY-009 (P2, implemented/verified):** Zero-value invoice orders are conditionally settled to `paid`; delivery-note flow remains unchanged; regression is grün.
- **CR-PAY-010 (P1/P2, decision selected 2026-09-24):** Non-immediate checkout paths (Invoice/Free/Quote) must fully honor browser `Idempotency-Key`; implement server-side request claims/idempotent responses and PHPUnit regressions for duplicate success, replay-after-failure and terminal-order conflicts. Immediate Stripe idempotency remains covered.
- **CR-PAY-011 (P1, implemented/verified):** Signed dispute/refund events use row-locked conditional terminal transitions; refunded/pending/cancelled states cannot regress, disputed→refunded remains allowed, and signed webhook regressions are grün.
- **CR-PAY-013/CR-CODE-002 (P1, implemented; focused backend verification 2026-09-25):** Collective invoice selection, the organization summary count, and generated collective snapshots use purchase-time organization attribution; current membership remains only the explicit legacy fallback. Candidate selection loads snapshot-bearing delivery notes and filters in PHP, so malformed JSON is excluded without SQLite JSON extraction. `PurchaseTimeOrganizationInvoiceTest` passes **13 cases / 53 assertions** (including Eloquent replacement/removal rejection and malformed JSON after reassignment/non-membership); `CollectiveInvoiceOrganizationAttributionTest` passes **4 cases / 24 assertions**. `OrgControllerTest` passes **10 / 18**; the non-mail `OrgOrganizationCoreTest` subset passes **10 / 19**, while its one user-creation mail path is Mailpit-blocked; the non-mail `InvoiceServiceTest` subset passes **4 / 21** with `MAIL_MAILER=array`. The full `InvoiceServiceTest` is environment-blocked only by Mailpit SMTP `127.0.0.1:1025` (8 transport failures); PHP syntax, Pint, and `git diff --check` pass. No migration was added and V035 remains untouched. Boundary: per-model Eloquent events protect supported `customer_details` writes; raw Query Builder/DB-facade, bulk Eloquent, and event-disabled maintenance writes remain documented bypasses, not a hard database immutability claim.
- **InvoiceServiceTest failure analysis (2026-09-25):** The exact full file reports 8 failures, all with the same `TransportException` at `InvoiceService.php:149` because the synchronous queue attempts SMTP at `127.0.0.1:1025`; the logs show connection refused before any mail assertion. Replacing only the mail transport with `MAIL_MAILER=array` and selecting the four non-mail cases yields 4/21 green, so the candidate/archiving logic is not the failure source. Host Mailpit remains an environment-only limitation; no mail-path workaround was added to this change.
- **CR-PAY-014 (P2, follow-up):** `PurchaseService` intentionally keeps legacy purchase-cache writes/invalidation for compatibility but no longer trusts positive cache values for final authorization; remove the write-only cache in a separate cleanup if no external consumer remains.

**Verifizierte CRM-/Model-Flow-Befunde (READ-ONLY-Audit, Fix pending)**
- **CR-CRM-001 (P1, implemented/verified):** Invite revocation clears transient claims/blacklists across registered-user refresh/redeem paths; `InviteRevocationTest` and related auth tests are green.
- **CR-CRM-002 (P1, implemented/verified):** Selection galleries are forced non-public/non-free at create/update/model boundaries; inherited-group and delivery-boundary regressions are green.
- **CR-CRM-003 (P2, implemented/verified):** Model email updates synchronize canonical `customers.email` and encrypted profile snapshot; owner/admin/rollback regressions are green.
- **CR-CRM-004 (P2, implemented/verified):** Hard erase/expiry removes linked and customerless memberless-act registration invites in the same transaction; regression is green.
- **CR-CRM-005 (P1, implemented/verified):** Public media/download/context/rating, invite and contract-closure brand boundaries have two-brand/null-brand regressions; purchase-time organization attribution remains the separate CR-PAY-013 product decision.
- **CR-CRM-006 (P1, implemented/verified):** Scout indexing/removal is deferred until commit; rollback and post-save failure regressions are green.
- **CR-CRM-007 (P1, implemented/verified):** File manifests are captured at the locked delete boundary and cleanup is queued after commit; authoritative-path regression is green.
- **CR-CRM-008 (P1, decision selected 2026-09-24; implementation in progress):** File/Scout cleanup must remain retryable even when dispatch itself fails; use existing jobs/tables first and add durable dispatch/outbox fallback tests. A V039+ migration is only permissible if a schema gap is proven.
- **CR-CRM-009 (P2, implemented/verified):** Independent customerless memberless-act invite cleanup regression is green.

---

## Model-Registrierung (Magic-Link) + Profile-Iteration (2026-09-18/19 deployiert) — OFFENE Restarbeiten

> Plan: `~/.opencode/plan/model-registrierung.md` · SOLL: `features/crm/05-model-registration.md` + `features/crm/06-model-profile-iteration.md`.
> Admin lädt eine Managerperson per kopierbarem Magic-Link ein (Mail optional); diese registriert login-frei einen **Act** mit 1..n Personen (je Person = CRM-Customer + ModelProfile). Altersnachweis **immer Pflicht**. Fragenkatalog **Code-first + Answers-Snapshot**.
> **Status:** deployed (Commit `3db7437`, push + Redeploy done). Backend **1577** PHPUnit, Frontend **706** Vitest, Lint/Build grün, **12** Feature-E2E.
> **Nachtrag 2026-09-19 (committed in `6137d3a`):** Profil-Update-Mail + Contact-Sheet-Export (Backend+Frontend) + E2E-Ausbau. Backend **1587** PHPUnit, Frontend **722** Vitest, E2E-Grep (`model-registration|model-access|model-export`) **22** passed — alles READY-verifiziert.
> **Nachtrag 2 (2026-09-19, committed in `e5d20f0`):** N1 (Manager-Transfer + Nachfolge + V035) + N2 (Inaktive nur Super-Admin, 403 fail-closed). Backend **1596**, Vitest **726**, E2E-Grep **28/28** — READY-verifiziert.

**Backend (PHPUnit) — implementiert & verifiziert**
**Frontend (Vitest + Playwright) — implementiert & verifiziert**
**Offen (User-Entscheidungen / Nachträge)**
- [ ] Lokale E2E-Flakiness `database is locked` (SQLite `busy_timeout=null`) → Workaround `--workers=1`; Fix wäre `busy_timeout`/WAL (Backend)
- Hinweis (Setup): lokale `backend/.env` braucht `MODEL_REGISTRATION_THROTTLE_LIMIT=1000` (Parität zu `.env.ci`), sonst 429-Flakes im E2E-Grep-Lauf. `.env` ist gitignored.

**Future (nur TODO, nicht umsetzen)**
- [ ] Contact Sheet Phase 3 (Bulk/Ergebnisliste) + Phase 4 (signierter Extern-Link) — Plan: `~/.opencode/plan/pdf-contact-sheet-export.md`
- [ ] Entscheidung offen: die Aufbewahrungsfrist für Altersnachweise und das zugehörige Löschkonzept; die beiden UI-Punkte (Kategorien-Admin, Personen-Bestätigungslink) sind davon unabhängig und umsetzbar. Kategorien-Admin-UI; Personen-Bestätigungslink; Löschkonzept für Altersnachweise (Aufbewahrungsfrist)

**Model-Zugang (User-Anforderung 2026-09-19) — umgesetzt**
---

## E2E-Ausbau Runde 2 (Model-Registrierung/-Zugang, 2026-09-19) — Verifikationslauf erledigt, Restarbeit OFFEN

> Auftrag: Lücken der Runde-2-Features mit **echten Nutzer-Interaktionen** schließen (semantische, gescopte Locators, Tags, kein `page.goto`-SPA-Missbrauch, keine localStorage-Injektion).
> **Historischer Verifikationsstand:** `pnpm vitest run` **706 passed**, `pnpm lint:fix` 0, `pnpm build` grün; `npx playwright test --grep "@feature:model-registration|@feature:model-access" --workers=1` **20 passed** (Desktop + Mobile). Dieser Lauf betraf die E2E-Erweiterung; Backend-Code blieb unverändert.

**Neue/geänderte Tests**
**Gefundener & gefixter Frontend-Bug (durch den neuen E2E-Test aufgedeckt)**
**Bewusst ausgelassen (begründet)**
- **Age-Proof-Positivfall (Re-Upload ohne vorhandenen Proof):** nicht ohne Backend-Eingriff erzeugbar. `ProfileEditForm` blendet das Feld nur bei `profile.age_proof_required && !profile.age_proof_uploaded_at` ein; das öffentliche `POST /api/model-registration/{token}` erzwingt den Nachweis (`required`, v2), und der Owner-`POST /api/model-profil/{token}` setzt `age_proof_required=true` + die Datei (beim Bestehen bleibt `age_proof_uploaded_at` gesetzt). `age_proof_path === null` bei `age_proof_required === true` ist damit nur über Altbestände/einen direkten DB-Reset erreichbar — ein solcher Zustand existiert produktionsseitig nicht regulär.

**Umgebungs-Hinweise (kein Code-Delta)**
- Lokal scheiterte der Lauf zunächst an `429 Too Many Attempts`: das (gitignored) `backend/.env` hat **keinen** `MODEL_REGISTRATION_THROTTLE_LIMIT` → Limiter-Default 10/min. CI setzt in `backend/.env.ci` `MODEL_REGISTRATION_THROTTLE_LIMIT=1000`. Für die Verifikation wurde `.env` temporär auf 1000 gesetzt und danach **byte-identisch wiederhergestellt** (md5-geprüft).
- `pnpm test:e2e:smoke` (ohne `--workers=1`) verzeichnet die bekannte lokale SQLite-Flakiness `database is locked` (siehe Offen N3) — unabhängig von dieser Änderung.

---

## CODE REVIEW (2026-09-12) — Full-Main-Audit (9 Subareas) — umgesetzt; zwei Befunde 2026-09-24 wiedereröffnet

> Methodik: 9 read-only Subagenten über Backend (Auth/Security, Checkout/Payments, Controllers/Requests, Modelle/Data, AI/Mail/Jobs), Frontend (Logic, UI), Infra/CI, Lua/Tests. Fixes durch **separate** Implementer-Subagenten, nie der Reviewer.
> **Status (2026-09-12):** Alle P0- und die meisten P1-Findings umgesetzt + getestet. **Verifikation:** Backend `php artisan test` **1392 passed / 0 failed (3452 Assertions)**; Frontend `pnpm test:run` **628 passed**, `pnpm lint:fix` 0, `pnpm build` grün.
> **Entscheidungen (User):** Brand-Isolation **strikt** (nur `brand=null` = cross-brand, z. B. erster/Super-Admin; brand-gebundene Admins isoliert); **100%-Coupon → freie Orders erlaubt** (kein Stripe-Call bei 0, Status `paid`, Download frei); **Teil-Refund behält Zugriff** (nur Voll-Refund entzieht); **kein VAT** (Kleinunternehmer/§6 UStG bzw. Reverse-Charge → aktuelles Verhalten korrekt); `.env.production` **bleibt machine-local/untracked** (`APP_DEBUG=false` gesetzt); **keine Playwright-Trace-/Report-Uploads auf PRs**; **Lua-Passwort verschlüsselt** via `LrPasswords` (OS-Keychain, Klartext migriert + entfernt); **Contract-Signer-Magic-Link = by design** (Signer haben kein Konto).
> **Offen (Rest):** P2-Tests/Doku (E2E-Tags/serielle Suite-Isolation/Kanban-Flakiness), SHA-Pinning von Actions/Images, `Photo::$fillable 'id'` (bewusst **nicht** gefixt: `ImageController` schreibt Datei unter vorab generierter ID → Kopplung), `PricingService::calculateItemPriceCents` (bewusst behalten, öffentliche Preview-API + 20 Tests), gleich-brand Group-Ownership (Produktentscheidung), Frontend-Low-Hygiene (F10–F12 teils).
> **Regeln:** Jeder Fix braucht einen Regressionstest (DoD, Bugfix = mind. 1 Test). Backend-Fix gilt nur mit grünem `php artisan test`; Frontend mit `pnpm test:run` + `lint:fix` + `build`.
> Priorität: **P0** = Security/Geld (kritisch/hoch), **P1** = funktionale Bugs, **P2** = Härtung/Hygiene.

### P0-A — Brand-Isolation (Kernursache, Backend) — geschlossen 2026-09-28 (P0-A13 als Nicht-Leak nachgewiesen)

> **Was hier noch steht — und was nicht:** Die behobenen Findings des
> 2026-09-12-Audits sind am 2026-09-28 aus dieser Liste entfernt worden; sie stehen in
> **Was hier noch steht — und was nicht:** Die behobenen Findings des
> 2026-09-12-Audits sind am 2026-09-28 entfernt worden (§3); sie stehen in den
> Commits, die sie geschlossen haben. Der wiedereröffnete Befund `P0-A13` ist am
> 2026-09-28 **als Nicht-Leak nachgewiesen und geschlossen** — dieser Block ist damit
> leer. Es gibt hier **keine `[x]`-Einträge**;
> die frühere Konvention, mit der ein Kästchen hier Erledigung signalisierte, wird
> nicht mehr verwendet.

> **P0-A13 — kein Leak, geschlossen (2026-09-28).** Der historische Leak war real:
> ein Fotograf **ohne Zuweisung** auf einer öffentlichen `restricted_photographers`-Galerie
> bekam das Original, weil der alte Zweig rein über die Rolle entschied
> (`$user && ($svc->isAdmin($user) || $svc->isPhotographer($user))`). Behoben in
> `f21d78d` durch den Weg über `canManageGallery`, das an
> `canPhotographerAccessGallery` delegiert und damit `restricted_photographers`
> durchsetzt. **Nachträglich mit einem Test festgenagelt**, den es vorher nicht gab:
> `FileDeliveryControllerTest::test_unassigned_photographer_on_public_restricted_gallery_gets_watermark_not_original`
> — mit dem historischen Code rot (403 erwartet, 200 erhalten), mit dem heutigen grün.
> **Der verbleibende Weg, auf dem ein Gast ohne Auth das unmarkierte Original bekommt,
> ist `effective_is_free_download` — und der ist gewolltes Produktverhalten**, nicht
> Leck: `GalleryModal.tsx:324` („Deaktiviert Wasserzeichen … Direkter Download für
> Gäste") und `features/delivery/03-file-delivery-controller.md:60`. Der dritte Test
> grenzt das sauber ab: free_download umgeht die Wasserzeichen-Pflicht, **nicht** die
> Authentifizierungspflicht einer privaten Galerie.
> **Die Zeilenanker `:35,54-72` im alten Eintrag waren verrottet** — sie passten weder
> zur heutigen noch zur historischen Datei. Künftig nach Symbol benennen
> (`$logicalNeedsWatermark`, `canManageGallery`), nicht nach Zeile.

### P0-B — Checkout/Payments (Geld) — geschlossen 2026-09-28 (P0-B7 als Nicht-Leak nachgewiesen)

> **Was hier noch steht — und was nicht:** Die behobenen Findings des
> 2026-09-12-Audits sind am 2026-09-28 entfernt worden (§3); B16 ist über die
> dokumentierte Entscheidung „kein VAT" fachlich geprüft. Der wiedereröffnete
> Befund `P0-B7` ist am 2026-09-28 **als Nicht-Leak nachgewiesen und geschlossen** —
> dieser Block ist damit leer. Es gibt hier **keine
> `[x]`-Einträge**.

> **P0-B7 — nicht reproduzierbar, geschlossen (2026-09-28).** Die Antwort kann keinen
> fremden `personal_token` tragen: der 201-Body liefert ausschließlich den Token des
> **in diesem Request erzeugten** Signers. Eine Kollision auf
> `(join_scope_key, normalized_email)` scheitert geschlossen mit generischem 409
> **ohne** Token, Namen oder Rollen — in beiden Schreibpfaden
> (`ContractJoinController` und `ContractTemplateService`) und zusätzlich über den
> V039-Unique-Index. `ContractSigner::$hidden` lässt `personal_token` außerhalb der
> Join-Antwort gar nicht erst serialisieren. Festgenagelt in
> `ContractJoinTest::test_standard_join_never_returns_a_pre_existing_signers_personal_token`
> und der Template-Variante.
> **Der einzige verbleibende Spalt ist die bewusst ungeprüfte E-Mail-Zugehörigkeit** —
> eine akzeptierte Produktentscheidung (CTR-7, `ContractJoinTest.php:121`): wer einen
> gültigen Join-Link hält, darf unter einer noch nicht vertraglich gebundenen Adresse
> beitreten und bekommt den Token inline. Das war nie der gemeldete Befund, und es ist
> dokumentiert entschieden, nicht übersehen.

### P1 — AI / Mail / Jobs / Console — 🟡 OFFENE FOLLOW-UPS (Live-Nachweis + Policy-Entscheidung)

- [~] wartet auf den Live-Scheduler-/Importnachweis; der Code ist verifiziert. **P1-A5 (MEDIUM; historischer Befund, Verifikation offen)** `import-locations` lief im früheren Boot-Flow über HTTP mit `truncate()`. Im aktuellen Working Tree ruft `deployment/docker-compose.yml` den Import beim Boot nicht mehr auf; `routes/console.php` plant ihn wöchentlich und der Command nutzt einen Lock/transactionalen Refresh. Live-Scheduler-/Importnachweis bleibt offen.
- [ ] Entscheidung offen: siehe A7 Operations/Decisions — Prompt-Injection- und SMTP-Duplikat-Policy. **P1-A7 (historical umbrella):** aktive Code-Subblöcke R1–R7 sind verifiziert; Operations-Evidence und Product Decisions bleiben als separate Tasks.

### P1 — Infra / CI / Deploy — 🟡 OFFEN: Produktions-Secret-Handling (Entscheidung) + Auto-Merge/Branch-Protection (Live-Nachweis)

- [ ] Entscheidung offen: siehe P1-I1 oben — Produktions-Secret-Handling/Rotation für `.env.production`. **P1-I1 (HIGH; historischer Befund, Verifikation offen)** `.env.production` liegt mit Live-Secrets (Stripe live, whsec, SMTP, Make, AI-Key, APP_KEY, JWT_SECRET, DB) unverschlüsselt auf Platte (nicht getrackt, aber Risiko) → Secrets rotieren/Secret-Manager. Die aktuellen Config-Defaults sind dokumentiert (`APP_DEBUG=false`); Produktions-Secret-Handling und Rotation bleiben offen.
- [~] wartet auf einen echten Auto-Merge-Lauf und die Branch-Protection für `main`; die statische Verifikation des Gates ist grün, die Durchsetzung ist es nicht. **P1-I3 (HALB OFFEN — Static-Verifikation grün, Live-Durchsetzung fehlt) (MEDIUM; Aggregate-Gate statisch verifiziert, Branch-Protection/Live-Merge offen):** `automerge.yml` übergibt Dependabot-Metadaten sicher per `env` und wartet ausschließlich auf den exakten Push-Check `CI gate (push)`. Das dynamisch benannte CI-Gate hängt von Security, Backend, Frontend und der vollständigen E2E-Matrix ab und prüft deren Resultate explizit; `allowed-conclusions` bleibt `success`, `fail-on-no-checks` ist fail-closed und `checks-discovery-timeout: 2100` deckt die Check-Entdeckung ab. Dependabot-PR-seitige secret-dependent E2E- und Aggregate-Jobs werden per Actor/Event-Bedingung absichtlich übersprungen; der Push-Lauf desselben Head-SHA bleibt allein autoritativ, normale Same-Repo-PRs bleiben fail-closed. Der job-level `timeout-minutes: 65` ist die echte Gesamtgrenze (25m CI + 35m Discovery + 5m Checkout/Merge-Puffer). `actionlint`, Security-Contract, Shell-Syntax und Diff-Check werden vor Commit erneut geprüft; ein echter Auto-Merge-Lauf und Branch-Protection werden daraus nicht abgeleitet.

### P1 — Modelle / Services / Data-Integrity — ✅ überwiegend FIXED (M3/M12 teilw.)

> **Wichtiger Kontext:** `Brand`-Enum enthält aktuell nur `rp` (SRP in V025/V031 entfernt) → viele Brand-Isolation-Lücken (P0-A*) sind **latent**, nicht live ausnutzbar. Sie werden dennoch gefixt, weil ein zweiter Brand sie sofort scharf macht.

### 🟡 Verifikationsstand (historischer Snapshot 2026-09-12; aktueller Audit 2026-09-24)

- [~] wartet auf einen vollständigen Backend-Lauf für den aktuellen Working Tree (Meilisearch, Mailpit, `.env`). **Gesamt-Baseline:** Der historische Frontend-/PHP-Syntax-/Diff-Check wurde
  als Setup-Recovery-Datensatz dokumentiert; der aktuelle Checkout hat weitere
  uncommitted Änderungen. Ein vollständiger Backend-Lauf für den aktuellen
  Working Tree ist in diesem Docs-only-Pass nicht als grün zu behaupten; der
  lokale historische Lauf blieb wegen fehlender Meilisearch-/Mailpit-Services
  und `.env` umgebungsbedingt rot. Der separate Recovery-Verlauf oben bleibt
  historisch.

---

- [ ] manuell prüfen: **V046 ausdeployen** — `gallery_groups.slug` wird von global
  unique auf unique per `(brand, slug)` umgestellt (Migration
  `V046__gallery_group_slug_unique_per_brand.php`, Frontier und Zustand ausschließlich
  in `features/tech/07-architectural-decisions.md` AD-2). **Aus drei Gründen Handarbeit
  und nicht automatisiert:** (1) `sync.sh` führt keine Migration aus (§13), der Deploy
  überträgt nur Code; (2) die Migration ist **nie auf MySQL/MariaDB gelaufen** — lokal
  war kein Server verfügbar, verifiziert wurden Index-Form, `up`, `down`, erneutes
  Anwenden und beide Preflight-Fehler ausschließlich auf SQLite gegen eine befüllte
  Kopie; (3) sie verändert die Eindeutigkeitsbedingung einer Live-Tabelle, und
  `down()` verweigert das Zurückrollen, sobald zwei Marken einen Slug teilen — dieser
  Zustand ist mit `UNIQUE(slug)` nicht darstellbar. **Vor dem Deploy:** `docker exec
  portal_backend php artisan migrate:status` (Soll aktuell 45 `Ran`, 0 `Pending`, V046
  `Pending`) und ein Backup, weil `up()` bei Kollisionen abweist statt aufzulösen.
  **Danach:** `brand`-Spalte auf NULL prüfen — `(brand, slug)` erfasst NULL-Marken nicht,
  und die alte globale Eindeutigkeit entfällt mit; die Migration lässt solche Zeilen
  bewusst unangetastet.

## 🟡 OFFEN (Future) — pricing_strategy als Brand-Setting

- [ ] `pricing_strategy` je Brand im Admin-UI editierbar machen (DB-Overlay am Choke-Point `BrandRegistry::buildFromArray()`). Langfristige Arbeit, kein Blocker: laut `features/infrastructure/17-pricing-strategy-pattern.md` §5 („Presets and legacy settings") ist der Editor heute **nicht** Teil der Brand-Settings-Overlay-Whitelist — bis dahin bleiben DB-Setting und Galerie-Felder die autoritativen Stellschrauben.

## 🚫 Blockiert — Dependency-Migration TypeScript 6→7

- [~] wartet auf TypeScript-7-Support im Tooling. **Nachzuziehen, sobald das Tooling
  TS7 deklariert.** TS 7.0 ist zu frisch: kein Support durch die
  Vite/Rolldown-Babel-Pipeline, den ESLint-Typescript-Stack oder das
  React-Compiler-Preset.
  **Verifiziert 2026-09-28, unverändert seit 2026-08-25:** `frontend/package.json`
  deklariert `"typescript": "^6.0.3"`, installiert ist `6.0.3`
  (`node -p "require('./frontend/node_modules/typescript/package.json').version"`).

---

## 🟡 Card-Testing-Schutz (SOLL: V036, 2026-09-24) — implementiert; BETRIEB, ROLLOUT & FINAL REVIEW OFFEN

> Approved architecture: `features/security/card-testing-protection.md`. V036 und die zugehörige Implementierung sind im aktuellen Working Tree vorhanden. Der CI-Lauf `35917265654` auf Commit `e73d5cf` ist ein **historischer Verifikationsdatensatz**; er liegt vor den aktuellen uncommitted Änderungen und ist kein Nachweis für diesen vollständigen Working Tree. Eine lokale E2E-Ausführung wurde in diesem Docs-only-Pass nicht gestartet. Betriebs-, Rollout- und Final-Review-Gates bleiben ausdrücklich offen; diese Dokumentationskorrektur behauptet keine neuen Application-Tests.

**Architektur & Backend**
**Frontend / Stripe.js**
**Betrieb, Stripe Dashboard & Privacy**
- [ ] manuell prüfen: im Stripe-Dashboard getrennte Test-/Live-Keys bzw. RAKs, least privilege, Webhook-Signing-Secrets und Endpoint-Subscriptions für Success/Failed/Dispute/Refund prüfen; zusätzlich Radar-/Card-Testing-/High-Risk-Regeln, Review-Queue, False-Positive-Rollback und Alerts dokumentieren. Getrennte Test-/Live-Keys bzw. RAKs, least privilege, Webhook-Signing-Secrets und Endpoint-Subscription für Success/Failed/Dispute/Refund prüfen; **Stripe Dashboard/Radar**: Velocity-/Card-Testing-/High-Risk-Regeln, Review-Queue, False-Positive-Rollback und Alerts dokumentieren.
- [ ] manuell prüfen: den 3DS-Strom live durchspielen: SCA, frictionless, challenge, failure, timeout, mobile und return. Radar darf die lokalen Limits nicht ersetzen; Payment-Method-Settings und Testkarten mitverifizieren. **3DS-Betriebscheckliste**: SCA/frictionless/challenge/failure/timeout/mobile/return testen; Radar nicht als Ersatz für lokale Limits verwenden, Payment-Method-Settings und Testkarten verifizieren.
- [ ] Monitoring/Runbook für PI-Rate, Replays, User/IP-429, Failure-Velocity, Identity-Mismatch/Quarantäne, Cleanup, Account-Age-Rejections und Turnstile anlegen; Logs ohne PAN/CVC/Secret/Raw-Turnstile-Token.
- [ ] Entscheidung offen: Zweck, Legal Ground, konkrete Retention und Lösch-/Anonymisierungsregeln der Stripe-Identifikatoren — fachliche DPO-/Rechtsfreigabe. Datenschutzhinweise/ROPA/Prozessor-/DPA- und Cookie-Dokumentation für Stripe-Customer-/PI-IDs, IP(+Hash), Fingerprint, Failure-Codes und Turnstile finalisieren. `Privacy.tsx` enthält bereits einen technischen Teilabschnitt; Zweck/Legal-Ground, konkrete Retention, Lösch-/Anonymisierungsregeln und DPO-/Rechtsfreigabe sind noch nicht nachgewiesen.

**Tests**

- [ ] **E2E-Lücke Card-Testing** (Owner-Entscheidung 2026-09-28, aus der leeren
  DoD-Marke an dieser Stelle entstanden): Automatisierte Tests existieren für Schema,
  Rate-Limit und Turnstile — 28 Fälle in `CardTestingSchemaTest.php` (6),
  `CheckoutRateLimitTest.php` (7), `CheckoutRiskTurnstileTest.php` (14) und
  `TurnstileWidget.test.tsx` (1) —, aber **kein einziges Playwright-E2E**:
  `grep -rlniE "card.?test|turnstile|3ds" tests/e2e` liefert 0 Treffer. Der
  Widget-Test deckt die Turnstile-Komponente ab, nicht den Checkout-Pfad im Browser.
  **Abgrenzung:** Der 3DS-Live-Strom bleibt `manuell prüfen` (Eintrag oben) und ist
  ausdrücklich *nicht* Teil dieser Lücke — er ist nicht automatisierbar.

## Produktionsdeploy SFTPGo — Vorfall vom 2026-09-26 (abgeschlossen)

Der Cutover auf SFTPGo hat die Produktion am 2026-09-26 mehrfach lahmgelegt.
Ursache war nicht SFTPGo, sondern Compose v5.0.2. Drei davon gefundene Fehler,
alle mit Regressionstest:

**Weiterhin offen (bewusst):**

- [ ] manuell prüfen: erstes Kamera-Foto über FTPS/SFTP hochladen und prüfen, dass `FtpController::process()` es der Galerie zuordnet. Der Integrationstest P1-M38 beweist die Server-Seite, **nicht** die Kamera. **P1-M32 — echter Kamera-Test.** Heute ausdrücklich offengelassen. Der
  Integrationstest P1-M38 beweist die Server-Seite, nicht die Kamera.
- [ ] manuell prüfen: die Firewall-Regeln 2222, 989 und 50000-50100 auf dem Host setzen (8080 bleibt zu). Ohne sie erreicht die Kamera SFTPGo nicht. **Firewall 2222/989/50000-50100** ist weiterhin Handarbeit und noch
  nicht gesetzt. Ohne diese Ports erreicht die Kamera SFTPGo nicht; 8080
  bleibt zu.
- [ ] manuell prüfen: `AI_API_KEY` und `ADMIN_PASSWORD` im Portal-/Host-Secret-Store rotieren — beide sind beim Auslesen der aufgelösten Compose-Datei im Klartext durch ein Terminal gelaufen. **`AI_API_KEY` und `ADMIN_PASSWORD` rotieren.** Beide sind beim Auslesen
  der aufgelösten Compose-Datei im Klartext durch das Terminal gelaufen.
- [~] wartet auf einen authentifizierten Aufruf. **Die bisher angegebene Ursache ist
  ausgeschlossen.** Die Gegenprobe aus diesem Eintrag wurde am 2026-09-28 ausgeführt:
  `docker exec portal_backend php artisan migrate:status` → **45 `Ran`, 0 `Pending`**,
  `V041__add_ftp_account_status_to_users.php` ist also gelaufen und die Spalte
  `ftp_account_status` existiert in Produktion. Der beschriebene Mechanismus — ein
  Feldzugriff auf eine fehlende Spalte in `FtpController::status()` (Z. 91) — kann damit
  nicht mehr greifen.
  **Was offen bleibt:** ein authentifizierter `GET /api/management/ftp/status` ist nie
  erfolgt. Ohne Auth liefert die Route nachweislich **401**, nicht 500
  (`backend/routes/api.php:259` definiert ausschließlich `Route::get`; die Gruppe
  `['auth:api','management']` rendert `AuthenticationException` als JSON). Damit ist
  **weder ein 500er belegt noch ein Funktionieren** — der Zustand ist `unbelegt`, nicht
  „in Arbeit".
  **Korrigierte Diagnose aus dem Vorgänger, weiterhin gültig:** es heißt `GET`, nicht
  `POST`; und die Begründung, Laravel suche eine fehlende `login`-Route, trifft nicht zu
  — `auth:api` konsultiert nie eine `login`-Route, und eine solche existiert in
  `backend/routes/` ohnehin nicht.
- [~] wartet auf die Anforderung des Owners. Bisher nicht umgesetzt. **dev-vm-Container aus `volume-backup.sh` ausschließen** (angefordert,
  nicht umgesetzt).

## Nachtrag 2026-09-26, Kamera-Setup aus der Oberfläche bedienbar machen

**Offen (aus dieser Runde):**

- [~] wartet auf einen erreichbaren SFTPGo im E2E-Stack (Harness-Referenz `tests/scripts/ftp-transport-test/`). Die Fail-closed-Absicherung darf **nicht** aufgeweicht werden. **P1-M57 — `profile-ftp-slug.spec.ts` ist rot, weil der E2E-Stack keinen
  SFTPGo hat.** Der Slug-Wechsel provisioniert jetzt und bricht fail-closed ab,
  wenn der Dienst fehlt. Die Absicherung darf **nicht** aufgeweicht werden; der
  E2E-Stack braucht einen erreichbaren SFTPGo oder einen Fake. Harness-Referenz:
  `tests/scripts/ftp-transport-test/`. *In Arbeit.*

- [ ] manuell prüfen: Zertifikat `cert.pem` aus dem Container auf die Speicherkarte kopieren und in der Kamera „Zielserver vertrauen → Aktivieren" setzen (Canon Error 48, cam.start.canon UG-06_Network_0230). **P1-M32 — echter Kamera-Test.** Weiterhin der einzige offene
  Schrittpunkt. Zusätzlich zur Upload-Prüfung: das Zertifikat
  (`/var/lib/sftpgo/ftps/cert.pem` im Container) muss als `.CER`/`.CRT`/`.PEM`
  auf die Speicherkarte, und in der Kamera „Zielserver vertrauen → Aktivieren"
  (sonst Error 48, cam.start.canon UG-06_Network_0230).
- [ ] manuell prüfen: denselben Zertifikatsschritt am Gerät ausführen: `docker cp` aus `/var/lib/sftpgo/ftps/cert.pem` in den Container, dann „Zielserver vertrauen → Aktivieren". Ein GUI-Download ist dafür nicht nötig. **Zertifikat auf die Speicherkarte der Kamera — operativer Testschritt,
  kein Produktziel.** Canon verlangt die Datei plus „Zielserver vertrauen →
  Aktivieren", sonst Error 48. Sie liegt im Container unter
  `/var/lib/sftpgo/ftps/cert.pem` und wird für den Test auf die Karte kopiert
  (`docker cp` oder Portainer-Dateiverwaltung). **Kein GUI-Download nötig:** der
  Import in eine Galerie ist das Ziel und existiert — `FtpController::process()`
  → `runImport()`, abgesichert durch `FtpImportTest` (10 Tests) mit der
  Reihenfolgegarantie „Inbox-Datei wird erst nach Storage- **und** DB-Erfolg
  gelöscht" plus `FtpProcessConcurrencyTest` (9 Tests). Ein Download wäre
  Bequemlichkeit, keine Lücke.
- [ ] omäne — bewusst außerhalb des Auftrags gemeldet, nicht Teil einer anderen Runde. **`useBrandSettings.test.ts`** nutzt `reisinger.pictures` als Fixture ohne
  semantischen Grund (anders als `useBrand.test.ts`, wo es zwingend ist) — auf
  `.invalid` umstellen. Von einem Subagenten gemeldet, außerhalb des Auftrags.
- [ ] manuell prüfen: Dublette — dieselbe Rotation wie oben; einmal ausführen und **beide** Einträge schließen. **`AI_API_KEY` und `ADMIN_PASSWORD` rotieren.** Beide sind beim Auslesen der
  aufgelösten Compose-Datei im Klartext durch ein Terminal gelaufen.

## UI-Review 2026-09-26 — Screenshot-Verifikation der neuen Oberflaechen

Erstmals das Screenshot-Harness auf die neuen Oberflaechen angewendet
(`tests/screenshots/`, Manifest um `kamera-einrichtung` und
`photographer-dashboard` erweitert, inkl. Fotografen-Auth). 4 Aufnahmen
(je Desktop/Mobile), alle Seiten rendern korrekt, keine kritischen Befunde.

| Schwere | Stelle | Befund | Vorschlag |
|---|---|---|---|
| medium | `KameraEinrichtung.tsx` (Schritt 2) | Der Guide nennt **„Neues Kamera-Passwort"**, aber der Button heißt fuer `pending` **„Kamera-Zugang einrichten"**. Ein Fotograf, der dem Guide folgt, sucht einen Button, den es nicht gibt — und genau diese Diskrepanz hat den Slug-Wechsel-Blockade beheben sollen. | Beide Labels nennen, oder den zustandabhaehngigen Text erklären. **Erledigt 2026-09-26: der Guide nennt jetzt den pending-Button „Kamera-Zugang einrichten".** |
| medium | `ManagementFtpInbox.tsx:57` | **„Fuer FTPS ist kein Verschluesselungsmodus hinterlegt — bitte den Support kontaktieren"**. Wird nur angezeigt, wenn der Modus fehlt (in Produktion gesetzt). „Support kontaktieren" ist fuer einen Fotografen eine Sackgasse: er kann eine Server-Konfiguration nicht aendern. | Neutralere Formulierung ohne Support-Weg, z. B. was fehlt und warum. **Erledigt 2026-09-26: neutrale Formulierung „Ohne diesen Modus kann die Kamera FTPS nicht aushandeln — nutze daher SFTP" (Inbox und Guide).** |
| low | `photographer-dashboard` (Mobile) | Der lange Upload-Ordner-Pfad (`ftp_folder`) bricht ueber **drei Zeilen** um und quetscht die Karte | Zeilenumbruch kontrollieren (`break-all` nur fuer lange Pfade) oder kuerzer anzeigen. **Erledigt 2026-09-27: `break-all` + `text-base md:text-lg` in `ManagementFtpInbox.tsx`, linke Spalte `min-w-0` — der Pfad bricht jetzt kontrolliert in zwei Zeilen.** |
| low | Header (Mobile) | **„Reisinger Fot…"** abgeschnitten | Vorbestehend, nicht von dieser Runde — nur vermerkt. **Erledigt 2026-09-27: Root Cause war eine dreifach duplizierte Kopfzeile — nicht der zuerst gefixte `GlobalSearchHeader`. Siehe Folge-Runde unten.** |

**Nicht Beanstandungen:** Die Verbindungstabelle rendert auf beiden Viewports sauber;
die Status-Warnung (`pending`) und der Button sitzen in einer Zeile; die
Abschnitte des Guides sind klar getrennt. Das FTPS-Modus-Problem und der
Button-Name sind **eigenen** Fuerke, keine vorbestehenden Maengel.

**Folge-Runde 2026-09-26 (die drei Kernbefunde des Owners umgesetzt):**
**Folge-Task (Harness-Luecke) — erledigt 2026-09-27:**
**Folge-Runde 2026-09-27 (die zwei `low`-Befunde umgesetzt):**
- [ ] Entscheidung offen: `truncate` wäre hier *korrekt*, aber die stille Kürzung ohne sichtbaren Effekt ist schlechter — `title`-Attribut oder ein Layout, das den Namen umbrechen lässt? Betrifft `Sidebar.tsx:51` — **Latentes Risiko, bewusst nicht angefasst (kein Regressionsfall):**
  `Sidebar.tsx:51` rendert den Portalnamen mit `whitespace-nowrap` **ohne**
  Truncation. Bei der aktuellen Breite passt der Name (Desktop-Screenshot
  bestaetigt), aber eine schmalere Sidebar oder ein laengerer Portalanme liesse
  ihn ungebremst ueberlaufen — genau die Klasse Fehler, die am Mobile-Header
  gerade behoben wurde. Entscheidung offen: `truncate` waere hier *korrekt*
  (einzeilige Navigationsleiste), aber mit sichtbarem Effekt statt stiller Kuerzung
  besser ein `title`-Attribut oder ein Layout, das den Namen umbrechen laesst.
  Aufgenommen, damit die Asymmetrie Mobile (bricht um) vs. Desktop (nowrap)
  bewusst bleibt und nicht als Versehen durchgeht.

## Dialog-Screenshots 2026-09-27 — Abdeckung, Blocker und tote Stellen

Ziel: **jeder Dialog ist bildgeprueft**, nicht nur funktional getestet. Die
Kamera-Anleitung war der Anlass — genau diese Lqecke (kein `click`-Nav-Schritt)
hat den Dialog-Test erzwungen. Bestand (Inventur, gegen den Code geprueft):

| Kategorie | Anzahl | Bemerkung |
|---|---|---|
| `ModalShell`-Familie (inkl. `ModalDialogShell`) | 18 | davon 3 keine eigenen Dialogflaechen (Shared Contract, Form-Wrapper, Inhalt der FTP-Anleitung) und 1 bereits erfasst (`photographer-guide-dialog`) |
| davon neu erfassbar mit vorhandenen Seeds | 4 | `shooting-calculator`, `photo-job`, `volume-preset`, `model-detail` |
| davon neu erfassbar mit neuem Gallery-Seed | 8 | Zugriff, Einladungslink, Bewertungen, Metadaten-Vorgaben, Fotografen-Team, E-Mail, Galerie bearbeiten, Meta-Galerie bearbeiten |
| strukturell nicht erfassbar | 2 | `AIGalleryDefaultsModal` (nur aus einem anderen Dialog heraus) und der globale Bestaetigungsdialog (programmatisch, kein `click`-Target) |
| Roh-Dialoge ohne `ModalShell` | 11 | 6 mit `role="dialog"`, **5 ohne** — die brauchen ein `data-testid`, bevor `waitFor` sie ueberhaupt greifen kann |

- [ ] Entscheidung offen: `API_THROTTLE_LIMIT` in `.env.ci` anheben (vergleichbar mit `AUTH_THROTTLE_LIMIT=1000`) und in `.env.example` kommentieren, dass 60 der Produktions-Sinnwert ist. `config/app.php` und `.env.production` bleiben unangetastet. **Offen, weil versioniert und CI-betreffen:** `.env.ci` und `.env.example`
  stehen weiter auf `API_THROTTLE_LIMIT=60`. CI fährt laut eigenem Kommentar
  4 Playwright-Worker und hat damit dasselbe Burst-Profil — die Drosselung ist
  dort latent, auch wenn sie bisher nicht als Fehler auffaellt. Entscheidung des
  Owners noetig: hoeherer Wert in `.env.ci` (vergleichbar mit dem
  `AUTH_THROTTLE_LIMIT=1000` dort), und in `.env.example` ein Kommentar, dass
  60 der Produktions-Sinnwert ist und Testumgebungen deutlich hoeher muessen
  sein. **Nicht** angefasst: `config/app.php` (Default bleibt 120/5) und
  `.env.production` (liegt nicht auf diesem Rechner).
- [ ] Entscheidung offen: soll das `<main>`-Scoping für die acht Gallery-Aktions-Dialoge bewusst aufgeweicht werden, oder bleiben sie bis zu einer eigenen `ModalShell`-Arbeit eine dokumentierte Lücke? Die bisherige Entscheidung lautet „nicht aufweichen". **Harness-Grenze: `<main>`-Scoping.** `applyNavStep` scoped `target` und
  `waitFor` auf `page.locator('main')`. `DashboardLayout.tsx:93-95` rendert
  `<GalleryModals` **nach** `</main>`, und `ModalShell` nutzt **kein Portal** —
  diese Dialoge liegen also ausserhalb des Landmarks und sind per `click` nicht
  erreichbar. **Entscheidung: Scoping nicht aufweichen**, sondern die Instanzen
  *innerhalb* von `<main>` nutzen (Detail-/Meta-View). Dieselbe Dialog-Komponente
  ist damit abgedeckt; die Struktur-View-Instanzen („Neue Galerie", „Neuer
  Ordner") bleiben eine **dokumentierte Luecke** — wer sie braucht, muss das
  Scoping bewusst aendern.
- [~] wartet auf zwei ungeklärte Voraussetzungen: das `AI_ENABLED`-Verhalten bei leerem `AI_API_KEY` und die Multipart-Feldnamen des `PhotoHistoryModal`-Uploads. Beides würde sonst einen Capture-Zyklus verbrennen. **Verschoben, weil ungeprueft:** `AIBatchEditModal` (lokales
  `AI_ENABLED=false` bei **leerem** `AI_API_KEY` — das Verhalten ist damit
  unbestimmt) und `PhotoHistoryModal` (Multipart-Feldnamen des Uploads
  unverifiziert). Beides wuerde einen Capture-Zyklus verbrennen.
- [ ] **Abdeckungs-SOLL fuer die Dialoge:** der aktuelle Stand ist
  `photographer-guide-dialog` plus die vier neuen Eintraege. Die Gallery-Familie
  (8 Eintraege) haengt an **einem** Gallery-Seed in `seeds.ts`; der wird als
  Referenzfall zuerst gebaut und verifiziert, bevor die uebrigen darauf
  aufsetzen.

## Dialog-UI-Review 2026-09-27 — Auswertung aller 13 Dialog-Aufnahmen

Vollauf `pnpm test:screenshots`: **44 passed / 0 failed (1,6 min)**, 13 Dialoge ×
Desktop/Mobile. Auswertung in zwei Hälften (5 + 8 Dialoge) gegen
`ui-review-checklist.md`, danach **jeder high- und medium-Befund von mir am Bild
nachgeprueft**. Protokoll: 26 Bilder, 38 Befunde, davon **6 bestaetigt, 1
widerlegt**, Rest low. Widerlegt heisst: ein plausibler Scheinbefund, der als
Produktionsfehler verkauft worden waere.

| Befund | Schwere | Von mir geprueft |
|---|---|---|
| `gallery-edit-dialog`: Dialog hoeher als der Viewport, Footer mit „Speichern" abgeschnitten (Desktop + Mobile) | **high** | **bestaetigt** — Desktop zeigt nur einen ~10 px-Streifen der Footer-Buttons, der Primaer-Button ist ohne Scrollen unerreichbar |
| `gallery-invite-dialog` (Mobile): zwei Optionsbeschreibungen verlieren Text am Kartenrand, „durchgewun" endet mitten im Wort — kein Umbruch, kein Ellipsis | **high** | **bestaetigt** — Textverlust; die dritte Karte daneben bricht korrekt um |
| `gallery-access-dialog`: letzte Listenzeile mitten durch die Glyphen abgeschnitten, keine Scroll-/`Fade`-Affordanz | **medium** | **bestaetigt** |
| **Waehrung mit Punkt-Dezimaltrennzeichen** (`369.00 €`, `30.00`) in durchgehend deutscher Oberflaeche | **medium** | **bestaetigt als echter Produktionsfehler** |
| `gallery-metadata-defaults` (Mobile): Fusszeile stapelt vollbreit | — | **bestaetigt korrekt** — das ist das richtige Mobile-Muster |
| `Hinzufügen`-Button zentriert gegen den Name/E-Mail-Block statt auf der Namenslinie | low | **bestaetigt** |
| `model-detail` (Mobile): „Altersnachweis"-Karte 48 % breit neben 100 % breiter „Datenstand"-Karte | low | gemeldet |
| `model-detail`: „Profil-Angaben" / „Basisdaten" typografisch identisch, Hierarchie geht verloren | low | gemeldet |
| `volume-preset`: `€` ist Flex-Geschwister statt Feld-Suffix, Input dadurch schief | low | gemeldet |
| `mm/dd/yyyy` im nativen Datumsfeld | medium | **WIDERLEGT — Harness-Artefakt**, siehe unten |

- [ ] **Ausrichtung: dritte Instanz desselben Musters.** „Hinzufügen" in
  `gallery-access-dialog` zentriert gegen den Name/E-Mail-Block statt auf der
  Namenszeile — exakt die Form, die als erster Befund dieser Runde gemeldet und
  daraufhin in der `ui-review`-Checkliste verankert wurde. Sie greift zum
  wiederholten Mal; der Fix gehoert in die Komponente, nicht in die Checkliste.
- [ ] manuell prüfen: am echten Gerät nachsehen: scrollen die Dialoge überhaupt (macOS überlagert Scrollbars, im Snapshot nicht sichtbar), ist `€` als Suffix gemeint, und wie groß sind die Tap-Targets der Kalkulator-Checkboxen? Davon hängt ab, ob die harten Schnitte UX-Bruch oder Snapshot-Artefakt sind. **Ungeprueft geblieben** (ausdruecklich als `unsicher` gemeldet, nicht
  uebernommen): ob die Dialoge ueberhaupt scrollen (macOS ueberlagert
  Scrollbars, im Snapshot nicht sichtbar) — davon haengt ab, ob die harten
  Schnitte UX-Bruch oder Snapshot-Artefakt sind; ob `€` als Suffix gemeint ist;
  Tap-Target-Groessen der Kalkulator-Checkboxen (Zeilenhoehe vs. 17-px-Kasten);
  die ISO-Darstellung `1995-05-05` neben `27.09.2026` im selben Dialog — im
  Dialog-Component nicht auffindbar, `Geburtsdatum` liegt in
  `CustomerModal.tsx:117` und `ManagementContractView.tsx:494` als natives
  Date-Input, die Quelle des gerenderten Werts blieb offen.
- [ ] **Architektur-Follow-up: der Footer liegt in der Scroll-Region — Problem
  ist `ModalShell`, nicht der Einzelfall.** Aus zwei unabhaengigen Befunden
  bestaetigt: `gallery-edit-dialog` („Speichern" unterhalb der Falz) und
  `ModelDetailModal` (Footer scrollt mit dem Inhalt weg). Ursache: `ModalShell`
  legt die Scroll-Region auf `.modal-box`, und daisyUI setzt darauf
  `max-height:100vh; overflow-y:auto`; der Footer wird **darin** gerendert. Ein
  `flex-1 overflow-y-auto`-Body kann zudem durch das unklassierte `<form>`
  nicht schrumpfen. Das betrifft **alle Formular-Dialoge**, nicht nur die zwei
  gefundenen. Zusaetzlich nimmt `ModalDialogShell` **kein** `boxClassName` an und
  reicht es nicht durch, obwohl `ModalShell` es kann — der bounded-height-Pfad
  ist ueber die geteilte Shell also gar nicht erreichbar.
  **Entschieden: jetzt nicht.** Die Aenderung beruehrt 18 Dialog-Oberflaechen
  und gehoert in eine eigene, gepruefte Arbeit statt als Schlussstueck in eine
  UI-Bug-Runde. `GalleryModal` traegt deshalb vorerst einen lokalen Fix (eigenes
  `<form>` + Submit-Zeile, `boxClassName="max-h-90vh flex flex-col"`, an drei
  Viewports gemessen verifiziert). Das ist **Duplikation mit Vorbehalt**, nicht
  Absicht: sie entfaellt, sobald `ModalDialogShell` `boxClassName` durchreicht
  und einen Klassen-Hook fuer das `<form>` anbietet. Dann wandert `GalleryModal`
  zurueck auf die geteilte Shell.

**Umsetzung 2026-09-27 (alle gegen frische Aufnahmen verifiziert):**
- [~] wartet auf eine andere Spaltenlogik (`table-layout: fixed` mit `word-break` auf dem Wert). `break-all` verschiebt nur die Bruchstelle und ist damit keine Lösung. **Nicht behoben, ehrlich als offen gefuehrt: Mid-Token-Bruch in der
  Verbindungstabelle.** Der Fade ist da, aber der Wert bricht auf Mobile
  weiterhin mitten im Token (`e2e-photographer-uxt` / `hwxr`). `break-all`
  aendert nur die Bruchstelle, nicht die Tatsache des Bruchs — das ist keine
  Loesung. Braucht eine andere Spaltenlogik (z. B. `table-layout: fixed` mit
  `word-break` auf dem Wert), nicht eine weitere Klasse.
- [~] wartet auf die Korrektur des zweiten Satzes in `V042__add_ftp_password_resets_audit_table.php` — er behauptet „unlimited, unobserved mint", was der Reset-Vertrag nicht ist. **Veralteter Migration-Docblock:** `V042__add_ftp_password_resets_audit_table.php`
  behauptet noch, die Endpoint waere „an unlimited, unobserved mint for valid
  credentials". Das ist falsch (ein Reset **ersetzt**, es gibt immer genau ein
  Credential) und wurde im Service-Docblock bereits korrigiert. Die Migration
  dokumentiert eine Schemadescheidung und ihr zweiter Satz ist noch richtig —
  deshalb nur geflaggt, nicht mitgeschleust.

## CI-Befund 2026-09-27 (Push fa8e19d) — drei CI-Ursachen, alle belegt; danach sechs Dialog-Befunde aus derselben Welle

**1. `Frontend (Lint, Build, Vitest)` rot am Schritt „Build" (Lint war gruen).**
`check-i18n.mjs` schlug auf einem **unberuehrten** Checkout fehl. Ursache: Ich
habe `FtpConnectionRows.tsx` selbst geaendert (den Support-Text) und mit
`git add frontend/` committet, **ohne den Lingui-Katalog neu zu erzeugen** — die
Quelle hatte den neuen Text, das committete `messages.js` noch den alten.
**Regel daraus: wer einen UI-String aendert, erzeugt den Katalog im selben
Commit neu** (`pnpm lingui:extract && pnpm lingui:compile`). `pnpm build`
allein genuegt nicht — es laeuft `extract`, aber nicht `compile`.

**2. Sechs E2E-Tests rot, jeweils auf allen drei Retries** (kein Flake) — nicht
drei, wie es zuerst aussah: `magic-link.spec.ts:22` und `:74`,
`client.spec.ts:26`, `photographer/communication.spec.ts:93`,
`selection/photoswipe.spec.ts:24`, `selection/rating-regressions.spec.ts:10`.
Alle neun Fehlschlaege (6 Tests × 3 Retries, Desktop 2/3 + Desktop 3/3 + Mobile
3/3) mit derselben Ursache:
`strict mode violation: .modal-open >> getByRole('button', {name:'Schließen'})
resolved to 2 elements`. Ursache: die Migration der `InviteModal` vom
handgebauten `modal modal-open` (unbenannter `✕`) auf `ModalShell`
(`aria-label="Schließen"`, `ModalShell.tsx:186`) hat den Schliessen-Button
**benannt**, waehrend der Footer denselben Text traegt. **Alle sechs schliessen
dieselbe `InviteModal`** — ich hatte aus dem Shard-2/3-Auszug geschlossen, die
drei anderen Specs betroffen nicht.
**Die UI wird NICHT geaendert:** `ModelDetailModal.tsx:316-324` dokumentiert die
Doppelung ausdruecklich als Entscheidung — ein Kopf- und ein Fuss-Schliessen
gehoeren zusammen, und den Fuss-Button zu entfernen, damit ein Locator
eindeutig wird, waere eine Bedienmoeglichkeit fuer den Test geopfert. Die
Migration hat schlicht ihre Specs nicht mitgezogen; die vier CRM-Specs scopen
deshalb bereits auf `.modal-action`. **Regel daraus: eine Dialog-Migration
zieht alle Specs mit, die auf den Dialog schliessen** — und wer eine
Fehlermeldung sieht, muss den **ganzen** Lauf lesen, nicht den Shard, in dem
sie gelandet ist.

**3. Fast ein dritter Fehlschluss beim Diagnostizieren.** Ich schloss zuerst
„ich habe die Regression eingeschleust" und stuetzte das auf zwei
`cf066a5 success`-Eintraege von `gh run list`. Die waren die Image- und
Tag-Workflows, **nicht** die CI; erst `--workflow=ci.yml` zeigte, dass CI auf
`cf066a5` gruen war — und die Diagnose damit bestaetigte. **Regel daraus:** bei
`gh run list` immer **nach Workflow filtern**, bevor ein alter Lauf als
Referenz dient, sonst zieht man den falschen Lauf als Beweis heran. Das ist
derselbe Fehler wie bei der Auth-Drosselung: eine Zahl aus dem Kontext
gerissen und zur Entscheidung gemacht.

**Folgepunkte aus der Waehrungs-Migration (2026-09-27):**
- [~] wartet auf einen `@smoke`-Lauf, der den Drawer-Schließvorgang nach der Scrim-Entfernung bestätigt — lastabhängig, tritt vor dem Galerie-Aufbau auf und ist von den FTP-/Dialog-Änderungen unabhängig. **Vorbestehender Mobile-Flake im geteilten Login-Helfer:** `AuthHelper.ts:48`,
  `await expect(backdrop).toBeHidden({ timeout: 5000 })` beim Schliessen des
  Sidebar-Drawers. Lastabhaengig, tritt **vor** dem Gallery-Aufbau auf und ist
  damit unabhaengig von den FTP-/Dialog-Aenderungen. Nach §6 zu verfolgen.
**Format-/Layout-Runde 2026-09-27 (alle vier Punkte erledigt):**
- [~] wartet auf die Bereinigung der drei Dateien — nach dem Muster der abgeschlossenen Welle, aber pro Stelle einzeln (Warenkorbzeilen brauchen einen gruppierten Dezimalwert **ohne** Symbol, nicht `formatEuroWhole`). **Drei weitere Instanzen derselben Message-Muster-Beschaendigung**, die
  ausserhalb des Auftragsumfangs lagen: `ManagementMetaGalleryView.tsx:113-116`,
  `CartItemList.tsx:97,110`, `VolumeLicensingCard.tsx:40,101` reichen je einen
  `formatMoney()`-String (mit Symbol) als Placeholder-Wert ein. Nach dem Muster
  derselben Aufgabe zu bereinigen. **Wichtig:** `formatEuroWhole` rundet auf ganze
  Euro — fuer Warenkorbzeilen mit Cent ist das falsch, dort braucht es einen
  gruppierten Dezimalwert **ohne** Symbol. Nicht pauschal vereinheitlichen.
- [~] wartet auf eine Entscheidung zwischen `max-width` (überlebt die Kaskade) und Weglassen der Klasse. Beides zugleich ist ein Widerspruch — der Kommentar ersetzt die Wirkung nicht. **`w-36` / `w-24` auf den Preis-/Mengenfeldern sind wirkungslos** und wurden
  trotzdem behalten, weil sie die gemeinte Breite dokumentieren (mit Kommentar).
  Entweder `max-width` (ueberlebt die Kaskade) oder Klasse weg — beides zugleich
  ist ein Widerspruch.

**Owner-Entscheidungen 2026-09-27 (alle vier umgesetzt):**
- [~] wartet auf den Abschluss des Backend-Fixes — `SettingsController::updateLicenseTerms()` validiert `price_web`/`price_print`/`price_original` noch nicht, `validate()` wirft die ungelisteten Schlüssel weg, `SettingResolver::set()` sieht sie nie. **Offen: die drei Preise persistieren nicht (Backend).**
  `SettingsController::updateLicenseTerms()` validiert sie nicht, und
  `validate()` **wirft nicht gelistete Schluessel weg** -> `SettingResolver::set()`
  sieht sie nie; der Read-Pfad liefert sie auch nicht zurueck. Die drei Keys
  **existieren** in der Tabelle (ge-seedet in Cent: 7500/14500/45000), aber kein
  Controller liest oder schreibt sie. Sichtbare Folge: ein eingegebener Preis
  schnellt nach dem Speichern auf den Default zurueck. Ohne diesen Fix ist die
  Owner-Entscheidung „Felder ergaenzen" wertlos — laeuft.
- [~] wartet auf die Umsetzung der Regel selbst: Aussagen über „wie der Code aussieht" an einen Commit binden (`git show HEAD:<pfad>`), nie an den Working Tree. **Vierte Verwechslung derselben Klasse in dieser Sitzung** → vorbeugende
  Regel: Wenn mehrere Agenten denselben Working Tree veraendern, muss eine
  Aussage ueber „wie der Code aussieht" an einen **Commit** gebunden werden
  (`git show HEAD:<pfad>`), nie an den Working Tree. Die ersten drei waren:
  Throttle-Zahl aus einem Subagenten-Bericht uebernommen, zwei `gh run list`-Eintraege
  fuer einen CI-Lauf gehalten, ein einzelner Shard-Fehlerauszug fuer den ganzen Lauf.
- [~] wartet auf den Abschluss der Neuanordnung — beide Fehler sind als testseitig belegt und die Geschwister-Spec ist mitgefixt; offen ist nur die Einordnung selbst. **Zwei vorbestehende E2E-Fehler neu eingeordnet — beide testseitig, und
  die Last-Attribution war bei beiden falsch:**
  - **`ecommerce.spec.ts` — reproduziert (3 von 4 Fehlschlaege).** Der
    **401 war kein Auth-Fehler**, sondern ein Artefakt des Zeitbudgets: der
    Test lief in die 60-s-Grenze, waehrend der Galerie-`POST` noch unterwegs
    war; Playwright verwarf den Browser-Kontext, die Anfrage kam ohne Session
    an, und der Fehler verschwand vollstaendig, sobald das Budget stimmte. Die
    ZIP-Stufe selbst ist zuverlaessig und schnell (`downloadMs` 449–1049) — der
    Download-Event war ein **Fehlschluss**; die Antwort lebt in einem
    `_blank`-Popup, weshalb `page.waitForResponse('/download-zip')` nie
    matcht. **Die Fix ist ein Timeout, und deshalb ist sie begruendet statt
    erfunden:** entfernt wurde `test.setTimeout(60000)` — die **einzige** Stelle
    im E2E-Satz, die den kalibrierten Standard **nach unten** ueberschrieb. Der
    Kommentar („Erhoehtes Timeout") stammt aus der Zeit, als 30 s Playwright-Default
    war. `playwright.config.ts` setzt `timeout: 120000`, `frontend/AGENTS.md`
    fuehrt 120 s als ausdruecklich freigegebenes Budget mit Pruefspur — und
    dieser Test **ist** ein Login/Upload/Checkout-Fluss. Er erbt jetzt den
    gemessenen Standard, statt einen veralteten, unkalibrierten zu fahren.
  - **`projects-board.spec.ts` — nicht reproduzierbar, aber verifiziert.** Der
    Agent hat nicht „konnte nicht reproduziert" gebucht, sondern messbar gemacht:
    eine Sonde zeichnete **jede** `POST /api/management/projects`-Antwort mit
    Wanduhr-Stempel auf, unabhaengig von jeder Wartung. Entscheidend ist die
    **Renn-Marge** = Antwortankunft − Abonnementzeit: bei 20 Proben raeumten
    **12 von 20 die Antwort in ≤12 ms ab, Minimum 6 ms**, und der App stellte in
    100 % der Laeufe genau **eine** POST mit Status **201** an die richtige URL.
    **Die Last-Zuordnung war invertiert:** bei Last 10 lagen die Margen bei
    7–342 ms, bei Last 5 bei 6–12 ms. Ein *schnelleres* Backend verkleinert das
    Fenster — der Flake wird wahrscheinlicher, wenn die Maschine gesund ist.
    Der Mechanismus wurde separat bewiesen: ein Abonnement **nach** der Antwort
    wartet sein **komplettes** Budget und scheitert, obwohl die Anfrage 200
    lieferte — `page.waitForResponse` hat keinen Antwortpuffer.
    **Die Passzahl ist ausdruecklich NICHT der Beweis** — sie ist vor und nach
    dem Fix identisch (63/3). Der Beweis sind die 6–12 ms plus der
    Mechanismustest; die Fix beseitigt das Rennen **konstruktiv** (Listener vor
    dem auslösenden Klick), nicht durch ein breiteres Fenster.
  - **Derselbe Defekt in der Geschwister-Spec mitgefixt:** 5 Stellen in
    `production-board.spec.ts` registrierten den Listener **nach** dem Klick.
    Dokumentiert an `KanbanHelper.waitForCreate`/`waitForDelete`, damit die
    Falle an der API sichtbar ist. **60/60 gruen** mit `--workers 1`.
- [~] wartet auf eine Live-Reproduktion im Lastbereich zwischen 15 und 22 sowie eine Erklärung der 6-von-8-Schieflage auf Mobile. Ohne positiven Beleg wird nichts gebucht. **Offen und ausdruecklich nicht als geloest gebucht:** (a) Die
  **Last-36,9-Variante** des `projects-board`-Fehlers bleibt unerklaert. Das
  behobene Rennen wird unter Last *leichter* zu gewinnen; was bei Last 36,9
  einen 15-s-`waitForResponse`-Timeout erzeugt hat, ist mit hoher Wahrschein-
  lichkeit eine **eigenstaendige, lastgebundene Ursache**. (b) Im
  Fehler-Schnappschuss stand `Galerie-Typ` wieder auf `Privat`, obwohl
  `selectByLabel` zuvor `Delivery (Downloads)` erfolgreich gewaehlt hatte — das
  deutet auf eine spaete asynchrone Re-Renderung, die das Formular zuruecksetzt.
  Der Agent hat das nicht verfolgt und **nicht als Befund ausgegeben**.
- [ ] manuell prüfen: auf der Host-Box nachsehen, ob `~/dev/LuminaRust/target/debug/deps/kittest_snapshots` noch respawnt — jede Lastzahl dieser Sitzung ist nur mit diesem Hintergrund lesbar. Die E2E-Serialisierung hinter `php artisan serve` (kein `PHP_CLI_SERVER_WORKERS`) ist eine Notiz, kein Task. **Zwei Umgebungsbefunde, die die Messungen dieser Sitzung erklaeren:**
  - **Fremder Prozess auf der Box:** `target/debug/deps/kittest_snapshots` aus
    `~/dev/LuminaRust` respawnte mehrfach bei **1678–1709 % CPU** auf 18 Kernen
    und trieb die Last auf **186–334**. Die frueher notierten „Last 36,9" sind
    mit hoher Wahrscheinlichkeit dieselbe Art externer Kontamination und
    ueberhaupt nicht diesem Repository zuzuschreiben. **Jede Lastzahl dieser
    Sitzung ist vor diesem Hintergrund zu lesen.**
  - **Der E2E-Backend laeuft als `php artisan serve` mit ungesetztem
    `PHP_CLI_SERVER_WORKERS`**, also **einzelgängig** und hinter 8 Playwright-
    Workern serialisiert. Das ist der Verstaerker, der externe Last in
    E2E-Latenz uebersetzt. Nicht geaendert (out of scope), aber die
    Grundursache, warum hier Testbudgets echten Spielraum brauchen.
## Dialog-UI-Befunde aus derselben Welle (2026-09-27) — sechs Produktfragen, keine Implementierungsdetails

- [ ] Entscheidung offen: je Frage einzeln: Initial-Fokus (Fokusfalle schlägt `autoFocus`), `maxWidth`-Skala bauen oder Typ eingrenzen, `editing`-Namenskollision in fünf Dialogen, `CouponFormDrawer` Escape/Backdrop, `ModelInviteDialog`-Wrapper, fehlende Unit-Tests für `ManagementOrgsView`/`ManagementOrgDetailView`. **Sechs Produktfragen aus der Welle, keine Implementierungsdetails:**
  1. **Fokusfalle schlaegt `autoFocus`.** In `ManagementOrdersView` lag der
     Cursor vorher im Preisfeld; jetzt landet der Fokus auf dem Schliessen-
     Element. Ist bewusst getestet, damit es eine Entscheidung ist. Soll das
     Preisfeld gewinnen, braucht die Shell einen Initial-Fokus-Hook.
  2. **`maxWidth` ist halb implementiert.** Nur `'2xl'` erzeugt eine Klasse;
     `'lg'`/`'xl'` werden akzeptiert und still verworfen. Entweder Skala
     implementieren oder Typ eingrenzen.
  3. **`editing={false}` ist in fuenf Dialogen eine Namenskollision.** In
     `ModalDialogShell` bedeutet `editing` „hat Loeschaktion fuer einen
     bestehenden Datensatz", in `TextSnippetModal`/`ProductModal`/
     `CustomerModal`/`CouponFormDrawer` „ist das eine Editsitzung". Das
     Domain-`editing` zu uebergeben, rendert einen `Loeschen`-Button ohne
     Handler. An jeder Stelle dokumentiert, aber eine Falle.
  4. **`CouponFormDrawer`: Escape und Backdrop loesen jetzt die
     Ungespeichert-Warnung aus.** Vorher taten das nur Schliessen und Abbrechen —
     diese beiden Wege konnten getippte Eingabe still verwerfen. Vermutlich
     richtig, aber Nutzer werden es spueren.
  5. **`ModelInviteDialog` brauchte einen Wrapper-`<div>`** fuer
     `data-testid="model-invite-dialog"`, durch das 8 E2E-Assertions scope.
     Die Shell besitzt keinen Testid-Hook.
  6. **`ManagementOrgsView` und `ManagementOrgDetailView` haben keine
     Unit-Testdateien.** Beide sind jetzt konform, aber ungeschuetzt.
- [~] wartet auf eine Live-Reproduktion im Lastbereich 15–22 und einen positiven Beleg für die 6-von-8-Schieflage auf Mobile; die mobile Hälfte hat bisher keinen Mobile-spezifischen Defekt ergeben. **Was der Agent nicht klaeren konnte, offen gesagt:** (a) Er konnte die
  Fehler **nicht reproduzieren**; er hat den Bereich zwischen „gesaettigte Box"
  (Last 15, 22/22 gruen) und den gemeldeten Lasten 186–334 eingegrenzt, diesen
  aber als 10–20-fache Ueberlastung nicht reproduziert. Die Mechanismen stuetzen
  sich auf **Artefakt-Forensik plus deterministischen Mechanismusnachweis**, nicht
  auf eine Live-Reproduktion. (b) Die **6-von-8-Schieflage auf Mobile kann er
  nicht erklaeren**: Gruppe B ueberspannt beide Viewports, Gruppe A ist 4/4
  Mobile — bei 31 Tests pro Projekt mit Chance erklaerbar, aber **kein positiver
  Beleg** fuer eine Mobile-spezifische Ursache und kein Mobile-spezifischer
  Defekt gefunden.
  Typhaelfte der `maxWidth`-Fix hat **keinen** Unit-Test und kann keinen haben —
  Vitest typprueft nicht. Der Sabotage-Nachweis zeigt es exakt: mit verengter
  Union meldet `tsc` `TS2322`, **ohne** Union schluckt `tsc` `"lg"` kommentarlos.
  Dieser Teil haengt **ausschliesslich** an `tsc -b --force` im Build-Gate. Wenn
  jemand `tsc -b` ohne `--force` fahren laesst, ist genau diese Haelfte ungesichert.
- [ ] **Was `ModalShell` fuer die drei blockierten Dialoge braucht** (nicht Teil
  dieser Aufgabe; die Datei gehoert einem abgeschlossenen Agenten): eine Region
  **zwischen** Kopf und begrenztem Body, in die der Aufrufer festen Inhalt legt
  (`bodyHead`/`pinned`-Slot), plus eine Moeglichkeit, eine engere Hoehe als
  90vh zu benennen, ohne zwei konkurrierende `max-h`-Utilities (z. B. dass das
  Opt-in eine Hoehe entgegennimmt oder der 90vh-Deckel ueberschreibbar wird).
  Damit wandern alle drei auf `bodyClassName` fuer Rahmen und Hintergrund der
  Liste.
- [~] wartet auf ein späteres Aufräumen der Stack-Env — der Wert hat keinen Secret-Bezug, wird aber über die Ports-Liste geführt. **`SFTPGO_DATA_PROVIDER__CREATE_DEFAULT_ADMIN`** ist im Compose ein
  Container-Wert ohne Secret-Bezug, wird aber über die Ports-Liste geführt; bei
  einem späteren Aufräumen prüfen, ob es in die Stack-Env gehört.

**Verifikation / Übergabe**
- [~] wartet auf einen separaten Reviewer-Durchgang; die Umsetzung ist fertig, die Prüfung steht aus. Diff-Review gegen `features/security/card-testing-protection.md` (V036 separat, keine Secrets/PII, keine ungeprüften Stripe-/Turnstile-Bypässe) durch separaten Reviewer.
- [~] wartet auf den Abschluss der vorstehenden Live-GO-Punkte (Limit-/Race-Lücke, Radar/3DS/Webhook/Privacy, Monitoring, Kill-Switch, Rollback). Vor Live-GO: offene Limit-/Race-Lücke schließen, Radar/3DS/Webhook/Privacy-Checkliste abhaken, Monitoring-Alarme testen, Emergency-Kill-Switch und Rollback ohne Datenverlust dokumentieren; danach Status im Task-Board auf erledigt setzen.
