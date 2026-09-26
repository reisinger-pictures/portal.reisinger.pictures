# FTP-Transport-Test-Harness

Lokale Testumgebung für den **Datei-Transport über beide Protokolle** (SFTP und
FTPS) gegen eine gesund konfigurierte SFTPGo-Instanz.

Board: **P1-M35** (Harness und Config-Weg) und **P1-M38** (Integrationstest).
Feature-Doc: `features/infrastructure/19-ftp-upload-pipeline.md`, Abschnitte
**7.13** (Port-Erreichbarkeit), **7.14** (Teststrategie) und **7.11** (Messfalle).
UID-Diskrepanz: **P1-I9**.

> **Das ist ein Test-Harness, kein Feature.** Nichts hiervon wird in den
> Produktionsbetrieb übernommen. `deployment/docker-compose.yml` — der einzige
> versionierte Compose-Stack für den Betrieb — wird von diesem Verzeichnis aus
> **nicht angefasst**.

> **Vorbekannter, hier nicht verursachter Fehler:** `tests/infrastructure/
> ci-security-contract.sh` verlangt für `deployment/docker-compose.yml` einen
> Digest-Pin (`image: …@sha256:…`). P1-M37 hat bewusst `drakkan/sftpgo:2.7.x`
> ohne Digest eingetragen (Begründung dort: Digest-Pinning verhindere
> Security-Patches ohne Review). Der Scanner läuft deshalb an HEAD rot. Der
> Harness übernimmt denselben Tag-Pin, um die Messung vergleichbar zu halten,
> und liegt **nicht** in der Dateiliste des Scanners — er verändert an diesem
> Zustand nichts.

---

## Zweck

Der Harness beantwortet Fragen, die vorher nur durch Raten beantwortet
werden konnten, und die eine Grundsatzentscheidung kippen können:

1. **Startet SFTPGo überhaupt gesund?** Config-Weg, Datenprovider, Admin-
   Bootstrap. Ohne das ist jede Messung wertlos.
2. **Kann ein echter Client über FTPS *und* über SFTP hochladen?** Mit
   Passwortprüfung, Dateigröße und Prüfsumme, nicht nur mit einem grünen
   Handshake.
3. **Ist der passive Datenkanal benutzbar?** Über EPSV *und* über das
   klassische PASV — der zweite TCP-Pfad, an dem FTPS-Clients lautlos
   scheitern.
4. **Welche TLS-Cipher-Suites bietet der Dienst tatsächlich an?** Und
   stimmt das mit dem überein, was die Kamera braucht?
5. **Unter welcher UID läuft der Dienst** — und wie passt das zur
   Ziel-Ownership `1002:webgroup`?

## Voraussetzungen

| Werkzeug | Zweck | Anmerkung |
|---|---|---|
| Docker + `docker compose` v2 | Start des Stacks | Pflicht |
| `curl` | FTPS-Upload, Admin-API | Pflicht |
| `jq` | JSON-Auswertung | Pflicht |
| `openssl` | TLS-Messung, Zertifikatserzeugung | Pflicht, Version 1.1.1 oder 3.x |
| `sftp` (OpenSSH) | SFTP-Upload | Pflicht |
| `python3` | Bind-Vorprüfung des Passivbereichs | optional, ohne sie wird gewarnt statt geprüft |

Es wird **kein GNU-`timeout`** und **kein `sshpass`** gebraucht. Beides ist auf
macOS nicht standardmäßig vorhanden; der Harness bringt für beides eine
Bordmittel-Lösung mit.

## Starten

```bash
bash tests/scripts/ftp-transport-test/up.sh
```

Das ist der einzige nötige Befehl. `up.sh` macht der Reihe nach:

1. Voraussetzungen und Portfreiheit prüfen
2. Laufzeitverzeichnis `.runtime/` und ein selbstsigniertes FTPS-Zertifikat anlegen
3. Compose-Stack starten
4. Auf die Admin-API warten (bounded, mit klarer Timeout-Meldung und Log-Auszug)
5. Admin-Bootstrap prüfen: `GET /api/v2/token` mit Basic-Auth
6. Auf SFTP- und FTPS-Ports warten **und die Bindings im Log gegenprüfen**
7. Test-User provisionieren: `POST /api/v2/users`
8. Credentials nach `.runtime/credentials.env` schreiben (Modus 0600, gitignored)
9. `verify.sh` ausführen: Uploads über beide Protokolle, Cipher-Report, UID

Weitere Skripte:

```bash
bash tests/scripts/ftp-transport-test/verify.sh     # nur Verifikation, gegen laufenden Stack
bash tests/scripts/ftp-transport-test/self-test.sh  # Wetter-Unit-Tests, ohne Docker, < 2 s
bash tests/scripts/ftp-transport-test/down.sh       # vollständiger Abbau, keine Spuren
```

`up.sh --skip-verify` bringt nur den Stack hoch. `up.sh --keep-data` behält
SQLite-DB und Credentials für einen zweiten `verify.sh`-Lauf.

### Übergabe ans CI

`verify.sh --require-camera-ciphers` macht fehlende Kamerasuites zu einem
Fehlschlag. **Ohne** dieses Flag bleiben sie ein *Befund* und der Lauf wird
grün — siehe [Befund oder Fehler](#befund-oder-fehler).

---

## Ports

| Zweck | Host (Default) | Container | Überschreibbar mit |
|---|---|---|---|
| HTTP + Admin-API | `127.0.0.1:18080` | 8080 | `FTP_HARNESS_API_PORT` |
| SFTP (SSH) | `127.0.0.1:12222` | 2022 | `FTP_HARNESS_SFTP_PORT` |
| FTPS explizit (AUTH TLS) | `127.0.0.1:19890` | 989 | `FTP_HARNESS_FTPS_PORT` |
| FTPS implizit | `127.0.0.1:19990` | 990 | `FTP_HARNESS_FTPS_IMPLICIT_PORT` |
| Passiver Datenkanal | `127.0.0.1:20000-20050` | 20000-20050 | `FTP_HARNESS_PASV_PORT_START` / `_END` |

Alle Host-Ports sind auf `127.0.0.1` gebunden.

**Zwei bewusste Abweichungen vom Produktionsstack:**

- **Host-Ports.** Der Admin-Port 8080 ist auf Entwickler-Rechnern häufig
  belegen (u. a. durch den Agenten-Harness selbst). Darum laufen die
  Defaults auf 18080/12222/19890/19990. Die **Container**-Ports sind
  identisch zum Produktionsstack.
- **Passivbereich 20000-20050 statt 50000-50100.** Auf macOS liegt der
  Kernel-Ephemeralbereich bei `49152-65535`. 101 Ports daraus kann Docker
  Desktop nicht dauerhaft binden — der Stack startet reproduzierbar nicht
  (`address already in use`). `deployment/docker-compose.yml` behält
  `50000-50100` unverändert; der Harness weicht nur ab, weil er lokal laufen
  muss. Beide Werte werden in jedem Lauf ausdrücklich genannt.

### UID und Ownership

`up.sh` misst drei Werte und stellt sie der Ziel-Ownership gegenüber:

```
Compose-Deklaration user: 1000:1000
Image-Default      user: 1000:1000
Im Container      id   : uid=1000(sftpgo) gid=1000(sftpgo) groups=1000(sftpgo)
Ziel-Ownership Produktion (19-ftp 7.9): 1002:webgroup
```

Die **Diskrepanz ist real und wird bei jedem Lauf gemeldet**: der Dienst läuft
als `1000:1000`, der Host-Pfad `/home/webadmin/websites/ftp` gehört
`1002:webgroup`. Funktional auffällig wird das erst bei `2755` statt `2777`
(P1-I9), aber es mischt zwei Ownership-Modelle im Website-Baum.

---

## Konfigurationsbefunde

Die folgenden Punkte sind **am 2026-09-26 empirisch verifiziert**, jeweils
gegen `drakkan/sftpgo:2.7.x` (SFTPGo 2.7.6) und gegen den Quellcode desselben
Releases. Sie sind der eigentliche Grund, warum dieser Harness existiert.

### (a) Der Config-Weg

**Befund:** Das Image meldet in der Debug-Ausgabe
`config file used: '"/etc/sftpgo/sftpgo.json"'`. Das ist **nicht** der Pfad,
gelesen wird. Die entscheidende Zeile ist die Service-Zeile darüber:

```
starting SFTPGo 2.7.6-... config dir: ., config file: , ...
```

`config dir: .` heißt: das Verzeichnis aus `--config-dir`, dessen Default
`.` ist — aufgelöst gegen das **WorkingDir des Images**, also
`/var/lib/sftpgo`. `config file:` ist **leer**, es wurde also gar keine
Datei gelesen. `/etc/sftpgo/sftpgo.json` existiert zwar im Image (10619 Bytes,
Build-Artefakt), wird aber nicht verwendet.

Konsequenzen:

- Der Mount für den Datenprovider muss nach **`/var/lib/sftpgo`** gehen. Er
  ist damit genau der Mount, den `deployment/docker-compose.yml` schon hat
  (`sftpgo_data:/var/lib/sftpgo`). Die Board-Notiz unter P1-M35, dieser Mount
  sei „wirkungslos" gewesen, ist **nicht haltbar** — `sftpgo.db` landet
  nachweislich dort.
- Seit **2.6** liegt die Konfiguration in der Datenbank, nicht als Datei im
  Config-Verzeichnis. Ein Mount auf ein Config-Verzeichnis bringt deshalb
  **nichts**, es sei denn, man legt eine Datei ab, die SFTPGo beim ersten
  Start in die DB migriert.

**Es gibt keine REST-API für die Konfiguration.** Verifiziert gegen
`GET /openapi/openapi.yaml` der laufenden Instanz: die v2-API kennt
`/token`, `/users`, `/folders`, … aber **kein `/api/v2/config`**. Wer im
Portal-Client (P1-M22) einen Config-Endpunkt erwartet, bekommt 404.

Der einzige verbleibende Weg ohne Config-Datei sind **ENV-Overrides mit
doppeltem Unterstrich** (Viper `AutomaticEnv` + `KeyReplacer("." → "__")`,
`internal/config/config.go:485-490`):

| Variablen-Präfix | Wirkung |
|---|---|
| `SFTPGO_FTPD__BINDINGS__<i>__*` | FTPS-Bindings: Port, Adresse, TLS-Modus, Zertifikat, Cipher |
| `SFTPGO_SFTPD__BINDINGS__<i>__*` | SFTP-Bindings |
| `SFTPGO_HTTPD__BINDINGS__<i>__*` | HTTP-/Admin-Bindings |
| `SFTPGO_FTPD__PASSIVE_PORT_RANGE__START` / `_END` | Passiver Datenbereich |
| `SFTPGO_DATA_PROVIDER__CREATE_DEFAULT_ADMIN` | siehe (c) |
| `SFTPGO_DEFAULT_ADMIN_USERNAME` / `_PASSWORD` | Admin-Zugangsdaten |

### (b) `ftpd.port` gibt es nicht

**Befund:** Der FTPD-Default hat `Bindings: [{Port: 0}]`, FTPD ist also
**deaktiviert** — ohne weitere Konfiguration gibt es **kein FTPS**. Ein
`ftpd.port` wird **stillschweigend** ignoriert, ohne jede Fehlermeldung. Richtig
ist `ftpd.bindings[].port` bzw. `SFTPGO_FTPD__BINDINGS__0__PORT`.

**Folgerung für `deployment/docker-compose.yml`:** Der Stack setzt
`SFTPGO_DEFAULT_ADMIN_*`, aber keine Bindings. Er startet damit zwar, bietet
aber **weder FTPS auf 989 noch SFTP auf 2222** — genau die Ports, die er
publiziert. Das ist kein Messproblem, sondern eine Lücke im Stack selbst.

### (c) `TLSMode`: 1 = explizit, 2 = implizit

Aus `internal/ftpd/ftpd.go:71-73`:

> Set to 1 to require TLS for both data and control connection.
> Set to 2 to enable implicit TLS

`curl --ftp-ssl-reqd` braucht **Modus 1**. Mit Modus 2 (`tls.NewListener`)
schickt `curl` unverschlüsselt `AUTH TLS`, der TLS-Listener verwirft das, und
der Upload hängt bis zum Timeout. Der Harness konfiguriert deshalb **beide**
Modi, weil nicht jede Kamera dasselbe FTPS spricht.

### (d) Der Admin-Bootstrap braucht **zwei** Variablen

**Befund, Ursache gefunden:** `SFTPGO_DEFAULT_ADMIN_USERNAME` und
`_PASSWORD` allein reichen **nicht**. In
`internal/dataprovider/dataprovider.go:974` wird der Default-Admin nur
angelegt, wenn `data_provider.create_default_admin` wahr ist — und der Default
ist `false` (`internal/config/config.go:390`).

```go
if checkAdmins && config.CreateDefaultAdmin {
    err = checkDefaultAdmin()
```

Ergebnis ohne die zweite Variable: die Tabelle `admins` bleibt **leer**,
`/api/v2/token` antwortet **401**, und die Provisionierung aus P1-M22 kann
nicht laufen. Genau das ist in P1-M35 als Symptom (b)/(c) beschrieben.

**Die Zeile, die fehlt:**

```yaml
SFTPGO_DATA_PROVIDER__CREATE_DEFAULT_ADMIN: "true"
```

**Und:** `SFTPGO_DEFAULT_ADMIN_PERMISSIONS` gibt es in 2.7.x **nicht mehr**.
`Admin.setFromEnv()` (`internal/dataprovider/admin.go:649-660`) setzt
`Permissions` immer auf `*`. Die Variable ist wirkungslos und wird hier
bewusst weggelassen, damit niemand eine tote Konfiguration pflegt.
`deployment/docker-compose.yml` setzt sie weiterhin.

### (e) `/api/v2/token` ist ein **GET**

Der OpenAPI-Vertrag der laufenden Instanz definiert:

```yaml
/token:
  get:
    security: [ { BasicAuth: [] } ]
```

Ein `POST` auf denselben Pfad antwortet **405 Method Not Allowed**. Wer das im
Portal-Client falsch nachbaut, hält 405 für ein Auth-Problem. Der Harness
verwendet `GET` mit Basic-Auth und prüft den Statuscode ausdrücklich.

### (f) `sftp -b` kann kein Passwort

`sftp -b` setzt implizit `BatchMode=yes`, und BatchMode schaltet die
Passwort-Authentifizierung ab. Ergebnis ist reproduzierbar
`Permission denied (password,publickey,keyboard-interactive)`. Der
nicht-interaktive Weg ist: Kommandos über `stdin`, Passwort über `SSH_ASKPASS`
mit `SSH_ASKPASS_REQUIRE=force`. Kein TTY, kein Prompt.

---

## Messergebnisse vom 2026-09-26

Vollständiger Lauf aus sauberem Zustand, `drakkan/sftpgo:2.7.x`
(SFTPGo 2.7.6, Image-Default, **keine** Cipher-Overrides):

| | Protokoll | Cipher |
|---|---|---|
| FTPS explizit (`AUTH TLS`) | TLSv1.3 | `TLS_AES_128_GCM_SHA256` |
| FTPS implizit | TLSv1.3 | `TLS_AES_128_GCM_SHA256` |
| FTPS, TLS 1.2 erzwungen | TLSv1.2 | `ECDHE-RSA-AES128-GCM-SHA256` |
| SFTP | — | KEX `ecdh-sha2-nistp256`, Cipher `aes128-gcm@openssh.com` |

**Angebotene Suites, vollständig:**

- **TLS 1.2 — 3 Suites:** `ECDHE-RSA-AES128-GCM-SHA256`,
  `ECDHE-RSA-AES256-GCM-SHA384`, `ECDHE-RSA-CHACHA20-POLY1305`
- **TLS 1.3 — 3 Suites:** `TLS_AES_128_GCM_SHA256`,
  `TLS_AES_256_GCM_SHA384`, `TLS_CHACHA20_POLY1305_SHA256`
- **Kamerarelevante Suites (TLS 1.2): alle vier abgelehnt**

| Suite | Angeboten? |
|---|---|
| `AES128-SHA` | **nein** |
| `AES256-SHA` | **nein** |
| `ECDHE-RSA-AES128-SHA` | **nein** |
| `AES128-GCM-SHA256` | **nein** (nur als TLS-1.3-Suite `TLS_AES_128_GCM_SHA256` erreichbar) |

Das ist die **gleiche Lücke, die pure-ftpd hatte**. Sie ist damit nicht
automatisch gelöst, nur weil SFTPGo in Go geschrieben ist: Go bietet
CBC/SHA1-Suites nicht in seiner Default-Liste an.

### Der Ausweg ist konfigurierbar — und verifiziert

```bash
bash tests/scripts/ftp-transport-test/down.sh
FTP_HARNESS_FTPD_CIPHER_SUITES='TLS_RSA_WITH_AES_128_CBC_SHA,TLS_RSA_WITH_AES_256_CBC_SHA,TLS_ECDHE_RSA_WITH_AES_128_CBC_SHA' \
  bash tests/scripts/ftp-transport-test/up.sh
```

Damit werden `AES128-SHA` und `AES256-SHA` angeboten (gemessen:
`SSLv3 AES128-SHA`, `SSLv3 AES256-SHA`). Drei Stolperfallen, alle davon
verifiziert:

1. **Die Namen sind die Go-Namen** aus `crypto/tls`, nicht die
   OpenSSL-Namen. `'AES128-SHA'` in dieser Variablen ist wirkungslos.
2. **Trennzeichen ist das Komma**, nicht der Doppelpunkt
   (`lookupStringListFromEnv`, `internal/config/config.go:2275`).
3. **Unbekannte Namen werden stillschweigend ignoriert** — und wenn *alle*
   Namen unbekannt sind, fällt SFTPGo stillschweigend auf seine
   Go-Default-Liste zurück (`util.GetTLSCiphersFromNames`). Man sieht also
   eine laufende Instanz und eine wirkungslose Konfiguration gleichzeitig.
4. Die Liste **ersetzt** die Go-Default-Liste vollständig. Die
   ECDHE-GCM-Suiten müssen mit aufgeführt werden, sonst verliert man den
   Desktop-Weg *und* den Kameraweg.

**Konsequenz für P1-M27:** Die Cipher-Frage ist damit beantwortet, aber nicht
entschieden. `AES128-SHA` ist erreichbar, wenn man es bezahlt. Ob die Kamera
das braucht, weiß nur die Kamera.

---

## Befund oder Fehler

Der Harness trennt zwei Dinge, die leicht verwechselt werden:

| | Bedeutung | Exit-Code |
|---|---|---|
| **Fehlschlag** | Der Harness oder der Dienst hat nicht getan, was er tun soll. | 1 |
| **Befund** | Der Harness hat sauber gemessen und etwas gefunden, das eine Entscheidung verlangt. | 0 |

Ohne diese Trennung würde ein echter Befund entweder den Lauf rot färben — und
wird dann „repariert", indem man die Messung abschaltet — oder einen Fehler
grün aussehen lassen. Beides ist in diesem Projekt schon passiert
(19-ftp 7.11). Mit `--require-camera-ciphers` wird ein Befund auf
Verlangen zum Fehlschlag.

### Kein Erfolg bei unvollständigem Handshake

Das ist die zentrale Eigenschaft, dreifach abgesichert:

1. **Jede** Messung — Referenz-Handshake, TLS-1.2-Variante, implizites
   Binding, jede einzelne Cipher-Suite — läuft durch `tls_assert_complete`,
   **bevor** irgendetwas ausgelesen wird. Der Report wird erst geschrieben,
   wenn dieser Aufruf geklappt hat.
2. `self-test.sh` prüft das gegen **eingefrorene echte Transkripte** der
   vier bekannten Fehlerbilder. Dafür braucht es kein Docker und keine
   laufende Instanz — läuft in unter zwei Sekunden und ist damit als
   Regressionsschutz brauchbar.
3. Alte Reports werden vor jeder Messung gelöscht. Ein liegengebliebener
   Report, der als frisch aussieht, wäre schlimmer als gar keiner.

Vier Fehlerbilder, die alle zu einer leeren Cipher-Liste führen und alle
abgelehnt werden:

| Fehlerbild | Kennzeichen |
|---|---|
| Dienst nicht erreichbar | `SSL handshake has read 0 bytes` |
| Klartext-Kanal (explizites FTPS ohne `-starttls ftp`) | `wrong version number` |
| Server lehnt die Suite ab | `ssl/tls alert handshake failure` |
| Protokoll gesetzt, Suite fehlt | `New, (NONE), Cipher is (NONE)` |

---

## Was dieser Harness **nicht** beweist

Der ehrliche Teil. Ein grüner Lauf heißt **nicht**, dass die Kamera den
Server erreicht. P1-M32 bleibt ein operativer Schritt, den kein Test ersetzt.

**Nicht bewiesen wird:**

- **Dass die echte Kamera den Dienst erreicht.** Die Kamera ist kein `curl`.
  Sie hat eine eigene TLS-Bibliothek, eine eigene Cipher-Liste, ein
  Passwortfeld, das ausschließlich alphanumerisch akzeptiert, und sie
  benutzt womöglich EPSV nicht. Ein serverseitig vollständig angebotenes
  `AES128-SHA` sagt nichts darüber, ob die Kamera danach fragt.
- **Dass der passive Bereich in der Firewall des Produktionshosts
  freigegeben ist.** Der Harness prüft Docker-Publishing und
  `force_passive_ip`. Die Firewall `strato-vps` 6e bleibt unberührt.
- **Dass die Ownership-Kette stimmt.** Der Harness zeigt, unter welcher UID
  der Dienst läuft, und dass er gegen `1002:webgroup` abweicht. Er prüft
  **nicht** den `setgid`-Bit auf `ftp/<slug>` und **nicht**, dass der
  Backend-Container als UID 1000 in `2755`-Verzeichnisse schreiben und
  `unlink`en kann (P1-I9) — dafür wäre der Produktionsstack nötig.
- **Dass `SftpGoClient` (P1-M22) funktioniert.** Der Harness spricht die
  Admin-API direkt mit `curl`, nicht über den PHP-Client. Dessen
  Fehlerpfade gehören in PHPUnit-Tests mit gemocktem HTTP.
- **Dass die Instanz unter Last oder im Dauerbetrieb hält.** Ein Upload
  genügt.
- **Nur gemessen, nicht behauptet:** TLS 1.3 ist auf dem Client
  standardmäßig aktiv, deshalb wird die TLS-1.2-Variante separat erzwungen
  und ausgewiesen. Ohne das hätte man eine TLS-1.3-Zahl gelesen und
  geglaubt, sie sei eine TLS-1.2-Aussage.

**Nebenbei mitgeprüft, aber nicht die Aufgabe:** der SFTP-Verhandlungspfad
(`ecdh-sha2-nistp256` / `aes128-gcm@openssh.com`) landet im Log, ist aber
nicht Teil des Abnahmekriteriums.

---

## Aufräumen

```bash
bash tests/scripts/ftp-transport-test/down.sh              # alles weg
bash tests/scripts/ftp-transport-test/down.sh --keep-data  # nur Container weg
```

`down.sh` entfernt Container, Netz und `.runtime/` (SQLite-DB, Zertifikate,
Credentials, Payloads, Reports) und **prüft zum Schluss selbst nach**, dass
kein Container und kein Verzeichnis übrig ist.

Bewusst **nicht** getan: kein `docker volume prune`, kein Eingriff in fremde
Compose-Projekte, kein `chown -R` und kein `adduser -h` (19-ftp 7.9).

## Dateien

| Datei | Inhalt |
|---|---|
| `up.sh` | Stack starten, Zertifikat erzeugen, Admin prüfen, User provisionieren, `verify.sh` aufrufen |
| `verify.sh` | Uploads über FTPS (EPSV + PASV) und SFTP, Größe/Prüfsumme, Cipher-Report, UID |
| `down.sh` | Vollständiger Abbau mit Nachkontrolle |
| `self-test.sh` | Hermetische Wetter-Unit-Tests und Quelltext-Invarianten, ohne Docker |
| `docker-compose.yml` | Der Harness-Stack. Explizit **kein** Produktionsstack. |
| `lib/common.sh` | Helfer: Wetterprüfung, Timeouts, Warteschleifen, Passivport-Parser |
| `fixtures/` | Eingefrorene echte `openssl`- und `curl`-Transkripte als Testdaten |
| `.gitignore` | Schließt `.runtime/` aus |

## Playwright-Tag

P1-M38 sieht für den Integrationstest ein Playwright-Tag
`@feature:ftp-transport` vor. **Das ist hier nicht umgesetzt** und sollte es
auch nicht sein: dieser Test ist ein Skript, kein Browser-Test. Er gehört als
eigener CI-Job neben die Playwright-Suites, nicht in sie — sonst blockiert er
den Smoke-Lauf. Ein sinnvoller CI-Anker ist `bash
tests/scripts/ftp-transport-test/self-test.sh` (ohne Docker, Sekunden) plus
der Harness-Lauf in einem Job mit Docker.
