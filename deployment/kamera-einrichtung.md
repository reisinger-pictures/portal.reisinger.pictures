# Kamera einrichten — Canon EOS R1 / R6 Mark II → Portal

Anleitung für den ersten echten Upload (P1-M32). Grundlage ist das Canon-Handbuch,
Abschnitt **Übertragen von Bildern auf einen FTP-Server**
(`cam.start.canon/de/C018/manual/html/UG-06_Network_0060.html`); die Menüpfade unten
übernehmen die Nummerierung dieses Abschnitts.

Diese Anleitung ist der einzige offene Schritt des Cutovers. Alles davor ist erledigt
und verifiziert: beide Ports sind von außen erreichbar, SFTP und FTPS handshake nach
beweisbar, TLS 1.2 und 1.3 werden bedient, und der Import in eine Galerie existiert
(`FtpController::process()`).

---

## 1. Das Konto anlegen — es gibt noch keins

**Vor dem Test existiert bewusst kein FTP-Konto.** Ein Fotograf bekommt seinen
`ftp_slug` bei der Registrierung, aber das ist nur der *Namen*; in SFTPGo wird nichts
angelegt, bis es gebraucht wird. Ein Konto vorab zu provisionieren hieße, Credentials
zu erzeugen, die niemand braucht und die niemand kennt — das ist genau der Zustand,
den das Show-once-Konzept vermeiden soll.

Der ausgelöste Moment ist die Anforderung der Zugangsdaten. Erst dann existiert das
Konto, und das Passwort wird **genau einmal** angezeigt:

1. `ftp_slug` im Profil prüfen — er ist der Benutzername der Kamera. Änderungen sind
   per Definition ein Reset (P1-M34): alter Account weg, neuer Account, neues
   Passwort.
2. Zugangsdaten anfordern. Der Endpunkt existiert
   (`POST /api/management/ftp/reset-password`, P1-M33) und legt das Konto in SFTPGo an
   beziehungsweise setzt es zurück. **Einen Button dafür gibt es im UI noch nicht** —
   im Task-Board vermerkt. Bis dahin: Profil-`ftp_slug` neu vergeben (das ist derselbe
   Reset) oder den Endpunkt direkt aufrufen.
3. **Passwort sofort notieren.** Es ist nicht gespeichert und nicht wiederherstellbar.
   Bei Verlust gibt es nur ein neues; das alte entfällt.

Kontrolliere danach `ftp_account_status`: `pending` heißt, SFTPGo kennt das Konto noch
nicht — dann scheitert **jede** Anmeldung der Kamera, unabhängig vom Passwort. Nach der
Anforderung steht dort `active`.

### Die Werte für die Kamera

Abrufbar im Management-UI unter **FTP Inbox → Kamera-Verbindung**:

| Feld | Wert |
|---|---|
| Server | `reisinger.pictures` |
| Benutzername | dein `ftp_slug` (z. B. `florian`) |
| SFTP-Port | `2222` |
| FTPS-Port | `989` |
| Passiver Bereich (Firewall) | `50000–50100` |
| Zielordner | `/` (Stammverzeichnis) |
| Passwort | nur einmal, aus Schritt 2 |

Reihenfolge nicht umstellen: erst Konto und Passwort, dann Kamera. Wer zuerst die
Kamera konfiguriert, hat noch kein gültiges Passwort und wundert sich über Error 41.

## 2. Zertifikat auf die Speicherkarte (nur FTPS)

Canon importiert **ausschließlich** eine Stammzertifikatsdatei mit dem Dateinamen
`ROOT.CER`, `ROOT.CRT` oder `ROOT.PEM`. Ein beliebiges `cert.pem` wird nicht
importiert — die Kamera meldet dann keinen Fehler, sie verschluckt die Datei einfach.
Das ist der häufigste stille Fehler in der ganzen Kette.

Das Zertifikat liegt im Container unter `/var/lib/sftpgo/ftps/cert.pem` (selbstsigniert,
im Dataprovider-Volume, überlebt also Neustarts). Es auf den Server holen und **auf
`ROOT.PEM` umbenennen**:

```bash
scp root@reisinger.pictures:/var/lib/docker/volumes/portal-reisinger-pictures_sftpgo_data/_data/ftps/cert.pem ./ROOT.PEM
# Prüfen, was wirklich drinsteht — muss ein Zertifikat sein, kein Schlüssel:
openssl x509 -in ./ROOT.PEM -noout -subject -dates
```

Dann `ROOT.PEM` auf die Speicherkarte der Kamera legen. Es kann **nur ein**
Stammzertifikat importiert werden; die Karte muss vor dem Import eingesteckt sein.
Verwendet wird die Karte, die unter *Einstellung → Aufn.funkt.+Karte/Ordner ausw*
als Prioritätskarte gewählt ist.

Import in der Kamera (Handbuch-Schritte 1–6 des Abschnitts *Importieren eines
Stammzertifikats für FTPS*):

1. `Kommunikationsfunktionen` → `Bilder zum FTP-Server übertr.`
2. `FTP-Übertragungseinstellungen` → `Stammzertifikat`
3. `Stammzertifikat v. Karte laden` → `OK`

**SFTP braucht das nicht.** Wer den einfacheren Weg nimmt, überspringt diesen Schritt
komplett — es ist nur ein Port, kein Zertifikat, keine passive Port-Range.

---

## 3. Verbindung in der Kamera einrichten

Menüpfad und Nummerierung aus dem Handbuch, Abschnitt *Konfigurieren der
FTP-Server-Verbindungseinstellungen*:

| Schritt | Aktion | Wert |
|---|---|---|
| 1–2 | `Kommunikationsfunktionen` → `Bilder zum FTP-Server übertr.` → `OK` | Netzwerk ggf. erst aktivieren |
| 3–4 | `Gerät für Verbindung hinzufügen` | `Neue Einstellungen` → `OK` |
| 5–6 | Kommunikationsart | dein WLAN; bei einem Fehlschlag `Offline konfigurieren` wählen, dann ist die Kamera beim Konfigurieren offline |
| 7 | `SET` | Einstellungen speichern |
| **8** | **FTP-Modus** | **`SFTP`** (empfohlen) oder `FTPS` |
| 9 | `Adressen-Einstellung` | `reisinger.pictures` |
| 9 | `Portnummerneinstellung` | SFTP `2222` / FTPS `989` |
| 10 | *nur SFTP:* `Benutzername`, `Kennwort` | slug + einmal-Passwort |
| **11** | *nur FTP/FTPS:* **Passiver Modus** | **`Aktivieren`** |
| 13 | *nur FTP/FTPS:* `Anmeldekennwort` | das einmal angezeigte Passwort |
| **14** | **Zielordner** | **`Stammverzeichnis`** |
| 15 | `SET` | speichern; bei Rückfrage `OK` → **Zielserver vertrauen** |

### Der Server läuft auf explizitem FTPS

Für `FTPS` ist `AUTH TLS` eingestellt, nicht implizites TLS. In der Kamera ist
dafür nichts extra einzustellen — der Modus `FTPS` der Kamera macht genau das. Der
Klartext-Greeting, den man beim Testen des Servers sieht (`220 SFTPGo_2.7.6`), ist
dieses Verfahren und **kein Fehler**.

### „Verzeichnisstruktur: Standard" ist zwingend, nicht empfohlen

Unter *Gerät für Verbindung bearbeiten → FTP-Server* gibt es die Option
`Verzeichnisstruktur`. **Nimm `Standard`.**

Der Import im Portal liest die Inbox **nicht** rekursiv:

```php
glob($inboxPath.'/*.{jpg,jpeg,JPG,JPEG}', GLOB_BRACE)   // backend/app/Http/Controllers/FtpController.php
```

`Kamera` legt auf dem Server `A/DCIM/100EOSR1/…` an. Diese Dateien landen dann in
einem Unterordner, den der Import nicht sieht: **Upload erfolgreich, Import 0 Bilder.**
Das ist der zweite stille Fehler und der Grund, warum hier keine Ausnahme gemacht
wird.

### `Vertrauenswürdige Zielserver`

Wenn der Handshake an der Zertifikatsprüfung hängenbleibt, unter *Gerät für
Verbindung bearbeiten → FTP-Server* `Vertrauenswürdige Zielserver` auf `Aktivieren`.
Das ist der von Canon beschriebene Weg für selbstsignierte Server; die
Handbuch-Formulierung ist ausdrücklich, dass ein selbstsigniertes Zertifikat
möglicherweise nicht vertrauenswürdig ist.

---

## 4. Übertragen und importieren

1. Bild auswählen: `Bildauswahl/übertr.` → Bild markieren → `MENU` → `Übertrag.`
   → `OK`. Oder `FTP-Übertragungseinstellungen` → `Autom. Übertragung` → `Aktivieren`,
   dann geht jedes Bild direkt nach der Aufnahme raus.
2. Prüfen, dass die Datei angekommen ist: im Management-UI steht unter
   **Bilder in der Warteschlange** die Anzahl. Die muss hochgehen, sonst ist der
   Upload gescheitert — nicht der Import.
3. Ziel-Galerie setzen (Dropdown **Aktuelle Ziel-Galerie**), dann **Bilder Importieren**.

Reihenfolge nicht umstellen: die Datei wird erst nach erfolgreicher Verarbeitung und
erst nach dem DB-Eintrag gelöscht (`FtpImportTest`). Bricht der Import ab, bleibt die
Datei liegen und der nächste Versuch sieht sie wieder.

---

## 5. Wenn es nicht klappt

| Meldung | Bedeutung | Erste Verdachtsstelle |
|---|---|---|
| **Error 41** — keine Verbindung zum FTP-Server | TCP kommt nicht durch | Firewall 989 bzw. 2222; Passiver Modus auf `Aktivieren` (Handbuch nennt das ausdrücklich für Error 41) |
| **Error 48** | TLS-Vertrauen | `ROOT.PEM` importiert? Dateiname stimmt? `Vertrauenswürdige Zielserver`? |
| Verbindung ok, **0 Bilder** in der Warteschlange | Upload kam nicht an | SFTP-Login schlägt still fehl: Konto steht noch auf `pending` |
| Upload läuft, **Import 0 Bilder** | Datei liegt in einem Unterordner | `Verzeichnisstruktur` steht auf `Kamera` statt `Standard` |
| TLS-Handshake bricht mit „wrong version number" ab | Client erwartet implizites TLS | SFTPGo ist auf **explizit** konfiguriert; `SFTP` in der Kamera wählen oder einen Client mit `AUTH TLS` benutzen |

---

## 6. SFTP zuerst probieren

Beide Protokolle sind eingerichtet und verifiziert. SFTP ist der einfachere Weg:
ein Port, kein Zertifikat, keine passive Port-Range, alle vier gängigen Cipher
werden akzeptiert. FTPS ist der Rückweg für Kameras, die SFTP nicht sprechen — und
der braucht Abschnitt 2 samt Stammzertifikat.

Beide Ports bleiben offen. Es kostet nichts, und ein nicht vorhersehbarer Kameratyp
soll den Cutover nicht blockieren.
