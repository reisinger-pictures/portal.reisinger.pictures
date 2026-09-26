# Model-Profile Iteration (Single-Katalog v1, Form/UX, Lifecycle, Admin, Sicherheit)

**Status:** Current SOLL — **implementiert** (Backend/Frontend, V033–V035;
reviewed 2026-09-24). Baut auf
[`05-model-registration.md`](05-model-registration.md) auf. Dieses Dokument ist
der aktuelle Ziel- und Ist-Vertrag; §6 enthält den Decision-Log und §8 den
inzwischen implementierten Contract-Nachtrag.

**Grundlage:** `AGENTS.todo.md` → Block „Model-Profile Iteration (User-Feedback
2026-09-19, erfasst — einzuplanen)". Die Nummerierung dieses Dokuments folgt der
Anforderungsliste, nicht einer Umsetzungsreihenfolge.

## 0. Zweck & Abgrenzung

Diese Iteration stellt die Model-Registrierung auf den **Single-Katalog v1** um:
das öffentliche Formular wird nutzerfreundlicher, der Fragenkatalog wird
entschlackt und um „Bereitschaft" erweitert, Profile bekommen einen Lebenszyklus
mit Reaktivierungs-Mails, die Admin-Ansichten werden auf Karten + Hauptbild +
kompakte Details umgestellt, und die Sicherheit der Bildspeicherung wird belegt.

**Nicht Teil dieses Dokuments** (separate Blöcke in `AGENTS.todo.md`):

- Screenshot-/Vision-Findings F1–F4, E2E-SQLite-Flakiness, Personenzahl-Sanity-Limit
  (in `05-model-registration.md` bzw. `AGENTS.todo.md` geführt).
- **Model-Zugang / Profil-Magic-Link / „Meine Profile"** war ursprünglich ein
  separater Anforderungsblock; der Zugangs- und Owner-Update-Vertrag ist inzwischen
  in §8.2 dieses Dokuments als aktueller SOLL festgehalten.

**Leitprinzip:** Es gibt **einen** Katalog (`CURRENT = 'v1'`). Beim Speichern
wird der `catalog_version`-Stand im Answers-Snapshot festgehalten, damit ein
künftiger Katalogstand sauber versionierbar bleibt.

---

## 1. Katalog (`App\Services\ModelQuestionnaire`)

### 1.1 Versionsmodell — Single-Katalog `v1` (entschieden 2026-09-19)

- **Kein v1/v2-Split.** Der ursprünglich als „v2" geplante Inhalt (ohne Erotik,
  mit Bereitschaft/Stock, Agentur Name+Link, Consent-Texten,
  Ausweis-immer-Pflicht, Kanal-Validierung) wird **DER `v1`-Katalog**;
  `CURRENT` bleibt `v1`. Es gibt keine Versionsverzweigungen und keine
  `catalogV1()`/`catalogV2()`-Trennung.
- Das Frontend-Pendant `CURRENT_MODEL_CATALOG_VERSION`
  (`frontend/src/logic/modelRegistration.ts`) bleibt `v1`; das
  „Profil aktualisieren"-Banner (`isCatalogOutdated()`) greift dementsprechend
  nicht.
- `catalog_version` wird weiterhin im Answers-Snapshot gespeichert und im
  öffentlichen `check`-Payload geliefert (= `v1`), damit ein künftiger
  Katalogstand sauber versionierbar bleibt.
- Die Entscheidung wurde vor dem Live-Rollout getroffen: Es gab zu diesem Zeitpunkt
  keine schützenswerten Alt-Snapshots. Für neue und aktualisierte Profile bleibt
  `v1` der einzige Katalog; ein späterer Versionssplit wäre eine neue Entscheidung.

### 1.2 Änderungen gegenüber dem bisherigen Stand (verbindlich)

| Anforderung | bisher | Single-v1 (SOLL) |
|---|---|---|
| **Erotik** | Kategorie `erotik` mit Alterstor | **entfernt** (entschieden 2026-09-19, §6.1) |
| **Fashion / Business / Portrait** | drei getrennte Kategorien | **bleiben getrennt** (entschieden 2026-09-19, §6.2) |
| **Bereitschaft** | existiert nicht | neue, **vor** der Erfahrung abgefragte Dimension pro Kategorie (5 Stufen, §1.3) |
| **Stock-Fotos** | existiert nicht | eigene Bereitschaftsfrage `willingness_stock` (5 Stufen) |
| **Altersnachweis** | nur bei `bikini`/`akt`/`erotik` | **immer Pflicht** je Person (Anforderung §3.8) |
| **Agentur-Feld** | `agency` (Freitext) | **strukturiert:** `agency_name` (text) + `agency_link` (url), beide optional (entschieden 2026-09-19) |
| **Personen-Fotos** | existiert nicht | neuer Upload max. 5/Person + Sichtbarkeit (Anforderung §3.4) |
| **Aussehen & Maße** | 9 Felder, aufgeklappt | weniger Felder oder Default zugeklappt (Anforderung §3.5) |

### 1.3 Bereitschafts-Dimension (5 Stufen)

Neue, ordinale Skala mit stabilen Codes (Anzeige über deutsche Labels):

| Code | Label (UI) | Ordinal |
|---|---|---|
| `nein` | nein | 0 |
| `eher_nicht` | eher nicht | 1 |
| `wenn_es_sein_muss` | eher ja | 2 |
| `gerne` | gerne | 3 |
| `sehr_gerne` | sehr gerne | 4 |

- **Pro Kategorie** eine Frage `willingness_<category>` (Pflicht, Vorbelegung
  `nein`), **gefolgt** von `experience_<category>` (optional, Vorbelegung `--`).
  Reihenfolge im Formular: Bereitschaft **vor** Erfahrung.
- **Erfahrungsskala (stabile Codes, einzelner Katalog):** `--` → „Keine",
  `-` → „Wenig", `0` → „Mittel", `+` → „Erfahren", `++` → „Profi". Der
  No-Experience-Sentinel ist `--` und zählt nicht als gewählte Kategorie; die
  API liefert die deutschen Labels als `option_labels` (analog Bereitschaft).
- **Stock-Fotos:** `willingness_stock` (gleiche Skala, scope `person`).
- **Admin-Suche/Priorisierung:** filterbar nach Stufe(n) und **sortierbar** nach
  Bereitschaft (höchster Ordinalwert zuerst) für eine gewählte Kategorie
  (Anforderung §5.3).
- Die Entscheidung ist umgesetzt: Bereitschaft und Erfahrung bleiben getrennte
  Fragen. Eine kombinierte Frage war eine historische Alternative, nicht der
  aktuelle Contract.

### 1.4 Migrationsmatrix Alt-Snapshots — **entfällt**

**Entfällt (entschieden 2026-09-19).** Es gibt keinen v1/v2-Split und keine
Migration von Alt-Snapshots. `agency` → `agency_name` ist schlicht der neue
Feldname in **einem** Katalog; eine Mapping-/Merge-Regel wird nicht benötigt.
`age_proof_required` ist für jedes aktuelle Submit/Update `true` (§2.8).

### 1.5 Such-/Filterkompatibilität

- Der Admin-Kategoriefilter (`category`) prüft in-memory gegen den
  Answers-Snapshot (in `05-model-registration.md` dokumentierte
  Skalierungsgrenze bleibt bewusst bestehen, bis eine denormalisierte Spalte
  nötig wird).
- Bereitschafts-Filter (`willingness_<key>=<level>`, Alias `willingness_category`
  + `willingness_level`) sind **Mindest-Schwellen**: Ein Level bedeutet „mindestens
  diese Stufe" (Ordinal ≥ Schwelle); mehrere Kategorien/Stock sind ODER-verknüpft.
- **Default-Sortierung = Match-Score** `max(willingnessOrdinal*10 + experienceOrdinal)`
  über alle Kategorien/Stock (Bereitschaft schlägt Erfahrung), Tiebreak neueste;
  `sort=newest` behält die alte Reihenfolge, `sort=experience` sortiert nach
  Erfahrung (mit `category` kategoriespezifisch, sonst Max über alle Kategorien,
  Tiebreak Max-Bereitschaft), `sort=willingness` bleibt. Unbekannte Keys/Levels
  werden defensiv auf den Rohwert zurückgeführt.
- **Skalierungsnotiz:** Die Score-/Kategorie-Auswertung läuft in-memory über den
  Answers-Snapshot; bei sehr vielen Models ist eine denormalisierte Score-Spalte
  der nächste Schritt.

---

## 2. Formular / UX (öffentliche Registrierung)

Alle Punkte beziehen sich auf `frontend/src/ui/ModelRegistrationView.tsx` und
die Schema-Factory in `frontend/src/logic/modelRegistration.ts`.

### 2.1 Managerperson-Radio nur bei >1 Person

- Das Radio `manager_index` wird **nur** gerendert, wenn `persons.length > 1`.
- Bei genau einer Person ist diese implizit Managerperson; `manager_index = 0`
  wird weiterhin mitgesendet.
- `consent_all_persons` (`visible_if: is_manager`) bleibt an denselben Wert
  gebunden. Entfernt man Personen, wird `manager_index` wie bisher geclampt.

### 2.2 Geburtsdatum + berechnetes Alter

- Direkt neben dem Geburtsdatumsfeld wird das **berechnete Alter** angezeigt
  („X Jahre"), live beim Ändern des Datums.
- Grundlage ist die bestehende Altersberechnung aus
  `features/crm/04-birthdate-age-verification.md`; keine zweite, abweichende
  Logik.
- Validierung bleibt: Datum in der Vergangenheit, Pflichtfeld. Ein
  Mindestalter als **harte** Regel ist nicht Teil der Anforderung (offene Frage
  Minderjährigkeit §6.10).

### 2.3 Kontaktweg-Validierung (UI-seitig)

`preferred_contact` (Multiselect) darf nur Kanäle enthalten, deren
zugehöriges Feld gültig befüllt ist:

| Kanal | Voraussetzung |
|---|---|
| E-Mail | `email` nicht leer (gültige Adresse) |
| Telefon | `phone` nicht leer |
| WhatsApp | `phone` nicht leer |
| Instagram | `instagram` nicht leer |
| Sonstiges | keine Abhängigkeit |

Regeln:

- Ein Kanal **ohne** erfüllte Voraussetzung wird im Multiselect
  deaktiviert/nicht angeboten.
- Wird das Trägerfeld geleert, wird der zugehörige Kanal automatisch aus der
  Auswahl entfernt.
- Die Schema-Factory spiegelt die Regel in `superRefine` (Fehlermeldung am
  Multiselect), damit serverseitige 422-Fehler konsistent gemappt werden.
- Die UI-Kontrolle bleibt die erste UX-Schicht; der Server spiegelt dieselbe
  Regel in `ModelQuestionnaire::contactChannelErrors()` als Defense-in-Depth.
  Ein ungültiger Kanal-Feld-Key wird als 422 zurückgegeben.

### 2.4 Personen-Fotos (max. 5/Person, öffentlich vs. intern)

Die Foto-Funktion ist **umgesetzt** und Teil des aktuellen Single-v1-Contracts.

- **Anzahl:** maximal 5 Fotos pro Person, **server-autoritativ** (der 6.
  Upload wird mit 422 und klarem Feld-Key `persons.<i>.photos` abgelehnt);
  clientseitig wird das Hinzufügen nach 5 gesperrt.
- **Typen/Größe:** `jpg/jpeg/png/webp`, jeweils maximal 10 MB. Der Altersnachweis
  akzeptiert zusätzlich PDF; Fotos nicht.
- **Sichtbarkeit je Foto:** `public` oder `internal`. Default = **`internal`**
  (Privacy-first); `public` erfordert eine explizite Umschaltung und ist von
  einer Foto-/Veröffentlichungs-Einwilligung gedeckt (§2.9).
- **Hauptbild:** höchstens ein Foto pro Profil ist `is_primary`; ein Primary muss
  `public` sein. Beim Submit wird der erste neue Public-Foto oder — wenn kein
  neues Public-Foto existiert — ein bestehendes Public-Foto zum Primary; ohne
  Public-Foto bleibt das Feld leer. Der Owner kann den Primary über das
  Update-Contract explizit setzen oder mit `is_primary=false` auflösen.
- **Speicherung/Delivery:** Dateien liegen verschlüsselt auf der privaten
  `local`-Disk; Management-Download und Contact Sheet entschlüsseln sie nur
  serverseitig. Personen-Fotos werden vor der Ablage EXIF-/GPS-bereinigt.
- **Löschen:** Management-Löschung entfernt die Datei und den DB-Eintrag; ein
  gelöschtes Primary-Foto setzt `is_primary` zurück.
- **Datenmodell:** `model_photos` (`id`, `model_profile_id`, `customer_id`,
  `path`, `original_name`, `mime_type`, `size_bytes`, `visibility`,
  `is_primary`, `position`, timestamps).
- **Submit-Wire-Format:** `persons[i][photos][j][file]`,
  `persons[i][photos][j][visibility]` und
  `persons[i][photos][j][is_primary]`. Nicht-sequelle Foto-Indizes sind erlaubt;
  der Server verknüpft Metadaten und Datei über denselben Schlüssel.

### 2.5 Aussehen & Maße

- Die Sektion „Aussehen & Maße" wird **per Default zugeklappt** gerendert
  (Collapsible), damit sie wahrgenommen, aber nicht als Pflichtblock erlebt
  wird.
- Alternative/zusätzlich: Feldumfang reduzieren. **Welche** Felder entfallen,
  ist offene Entscheidung §6.7; Datenverlust bei Neuerfassung ist dabei
  beabsichtigt.

### 2.6 Bereitschaft vor Erfahrung + Stock

- Reihenfolge in der Sektion „Erfahrung & Portfolio": je Kategorie erst
  Bereitschaft, dann Erfahrung (§1.3); danach `willingness_stock`.
- Vorbelegungen vermeiden Klickzwang (Bereitschaft `nein`, Erfahrung `--`).

### 2.7 Act-Kontext & prominente Mehrpersonen-Option

- **Oben** im Formular steht eine prominente Mehrpersonen-Option (z. B.
  Segmentierung „Act mit 1 Person / mehreren Personen" bzw. ein deutlich
  sichtbarer „Weitere Person hinzufügen"-Einstieg), damit Mehrpersonen-Acts
  sofort erkennbar sind.
- `act_notes` („Anmerkungen zum Act") wird **nur** gerendert, wenn
  `persons.length > 1`. Bei einer Einzelperson entfällt die Act-Sektion.
- `act_type` bleibt aus `person_count` abgeleitet (`single`/`couple`/`group`).

### 2.8 Ausweis-Upload immer Pflicht

- Die Altersnachweis-Frage (`age_proof`) hat kein `visible_if` und ist
  **bedingungslos** `required = true` (je Person).
- Der Upload ist immer Pflicht; `model_profiles.age_proof_required` ist bei
  jedem Submit `true`.
- Die Kategorie-Flags `requires_age_proof` bleiben als Metadaten erhalten,
  steuern aber nicht mehr die Upload-Pflicht.
- Die Beschreibung des Felds wurde angepasst (nicht mehr „Pflicht bei Bikini,
  Akt oder Erotik", sondern für jede Person unabhängig vom Alter).
- Der Admin-Status „Altersnachweis" ist immer relevant
  (hochgeladen / ausstehend).

### 2.9 Einwilligungen (Text + Verlinkung)

- Jede Einwilligung bekommt einen **vollen Satz** statt einer nackten
  Überschrift sowie Links auf die bestehenden Rechtsseiten:
  - `consent_privacy` → „Ich habe die [Datenschutzerklärung](/privacy) gelesen
    und akzeptiere sie."
  - `consent_accuracy` → „Ich versichere die Richtigkeit meiner Angaben."
  - `consent_contact` → „Ich stimme der Kontaktaufnahme zu."
  - `consent_all_persons` (nur Manager, nur >1 Person) → „Ich versichere, dass
    alle erfassten Personen mit der Angabe ihrer Daten einverstanden sind."
  - `consent_photos` **neu** → Zustimmung zur Speicherung der Personen-Fotos
    und, falls `public`, zur Veröffentlichung; verlinkt AGB
    (`/license-terms`) und Datenschutz (`/privacy`).
- Links öffnen in neuem Tab (`target="_blank"` + `rel="noopener noreferrer"`).
- AGB-Seite ist unter `/license-terms` vorhanden; ein eigener
  „AGB"-Pfad existiert nicht. Sollte ein separater AGB-Link gewünscht sein, ist
  das eine reine Routing-Frage (§6.12).

---

## 3. Lifecycle / Retention

### 3.1 Zeitachse

| Phase | Zeit seit letzter Bestätigung | Zustand |
|---|---|---|
| aktiv | 0 – 13 Monate | `active` |
| inaktiv | 13 – 15 Monate | `inactive` |
| abgelaufen | ≥ 15 Monate | `expired` → Hard-Delete (§3.5) |

### 3.2 Timer & Anker

- **Anker** ist `model_profiles.last_confirmed_at`; er wird beim Erst-Submit und
  bei jeder Bestätigung/Aktualisierung gesetzt.
- **„Updaten"-Klick setzt den Timer auf 0 — auch ohne Änderung.** Dafür werden
  zwei getrennte Aktionen definiert:
  - **Bestätigen** (`confirm`): setzt `last_confirmed_at = now`, **ohne**
    Snapshot-Änderung und **ohne** Katalog-Bump. Ein v1-Profil kann so „weiter
    aktiv" bleiben, ohne migriert zu werden.
  - **Bearbeiten & Speichern** (`edit`): validiert gegen den aktuellen Katalog,
    schreibt einen neuen Snapshot + `catalog_version = CURRENT` und setzt
    `last_confirmed_at = now`.
- Damit löst sich die Spannung zum „Profil aktualisieren"-Banner: Bestätigen ≠
  Migration; nur Bearbeiten migriert.
- Das Frontend zeigt „Letzte Bestätigung" und den abgeleiteten Zustand.

### 3.3 Mail-Regeln

- **Implementierte Reminder-Kadenz:**
  - T+12 Monate: Reminder 1.
  - T+13 Monate (Eintritt `inactive`): Reminder 2.
  - T+14 Monate: letzte Warnung vor `expired`.
  - Der tägliche Lifecycle-Command setzt jede Stufe idempotent; ohne
    Kontaktadresse wird sie nicht als versendet markiert.
- **Empfänger:** die gespeicherte Kontakt-E-Mail der Person. Existiert keine,
  wird **keine** Mail versendet; der Admin sieht den Zustand „kein Kontaktweg".
  Die Erinnerung enthält einen 24-Stunden-Profil-Magic-Link.
- **Brand-aware:** Mail wird mit der Marke des Profils gerendert.
- **Idempotenz:** pro Stufe wird höchstens einmal gesendet
  (`last_reminder_stage`/`last_reminder_at`); ein täglicher Scheduler-Job
  berechnet Übergänge und verschickt idempotent.
- Der Versand hängt nicht am Katalogstand: auch ein nicht migriertes v1-Profil
  erhält Reminder (Aktion dann `confirm`).

**Fertigstellungs-Mail an den Einladenden:** Die Erfolgsmail nach dem Submit
enthält Act-Typ, Personenanzahl und je Person Name, Alter, Ort, Top-Kategorien
(mit Bereitschafts-Label), Ausweis-Status (hochgeladen/ausstehend) und
Portal-Konto (ja/nein) als kompakte Tabelle. Jede Person trägt zusätzlich einen
Brand-korrekten Deeplink auf ihr Admin-Profil
(`/admin-models?model=<customerId>` via `BrandRegistry::frontendUrl($brand)`)
und wird direkt aus dem Submit-Kontext gespeist.

### 3.4 Zustände in der Admin-Sicht

- `active` / `inactive` / `expired` werden als Badge dargestellt und sind
  filterbar (§5.3).
- Der aktuelle Vertrag ist: **Default `active`**. `lifecycle_status=inactive`
  oder `all` ist nur für Super-Admin erlaubt; für andere Management-Rollen
  antwortet der Endpoint fail-closed mit 403.

### 3.5 Löschung / Aufbewahrung

- **Entschieden (2026-09-19): `expired` = Hard-Delete.** Sobald
  `last_confirmed_at` älter als 15 Monate (13 aktiv + 2 inaktiv) ist, löscht der
  tägliche Lifecycle-Job das Profil restlos — Customer-Zeile,
  `ModelProfile`/Snapshot, alle Fotos + verschlüsselten Dateien, Altersnachweis,
  Zugangs-Tokens und `act_members`; mitgliederlose Acts werden mitentfernt.
- Die Löschung nutzt dieselbe Routine wie der DSGVO-Super-Admin-Endpoint
  (`ModelProfileEraser`, DRY) und wird mit `reason=expired` audit-geloggt
  (ohne PII). Ein verknüpftes Portal-Konto wird nur entknüpft, nie gelöscht.
- Ausstehende Mail-/Lösch-Jobs müssen idempotent und ohne Doppelversand sein;
  der Expiry-Lauf läuft vor dem Reminder-Versand, damit kein Profil mehr
  angeschrieben wird, das im selben Lauf gelöscht wird.
- **Akzeptiertes Risiko (Race):** Das gleichzeitige Ausstellen zweier
  Zugangslinks (`ModelAccessToken::issueFor` = Widerrufen + Anlegen, nicht
  atomar) kann kurzzeitig zwei aktive Tokens erzeugen — niedriges Risiko,
  bewusst akzeptiert (kein portabler partieller Unique-Index über
  MariaDB/SQLite).

---

## 4. Admin-Ansichten

### 4.1 Model-Detail kompakt

- Leere Felder werden **nicht** angezeigt: Answer-Zeilen ohne Wert entfallen,
  leere Summary-Karten (z. B. Ort) werden ausgelassen.
- Der Altersnachweis-Status bleibt immer sichtbar (immer relevant).
- Neu: Galerie der Personen-Fotos (Thumbnails, `public`/`internal`-Badge,
  Hauptbild-Markierung, auth-gated Download), Auswahl des Hauptbilds.
- Katalogstand + „Profil aktualisieren"-Banner bleiben erhalten.

### 4.2 Models-Übersicht als Karten-Layout

- Die Tabelle wird durch ein **Karten-Grid** ersetzt. Karte zeigt:
  Hauptbild (oder Platzhalter), Anzeigename, Alter, Ort,
  Top-Kategorien (Badges), Bereitschaft (für die fokussierte Kategorie),
  Lifecycle-Status.
- Klick auf die Karte öffnet das Detail-Modal.
- **Hauptbild** = Auswahl aus den Personen-Fotos (§2.4), im Detail gepflegt.

### 4.3 Filter

- **Kategorie-Filter als Multi-Select** (ODER-Verknüpfung, in-memory gegen den
  Answers-Snapshot). Query z. B. `category[]=portrait&category[]=sport`.
- Zusätzlich: **Bereitschafts-Mindest-Schwelle** (`willingness_<key>=<level>`,
  Alias `willingness_category` + `willingness_level`; ODER über Kategorien/Stock).
- **Default-Sortierung = Match-Score** (`willingness*10 + experience`, Max über
  Kategorien/Stock); `sort=newest` = Neueste zuerst, `sort=experience` =
  Erfahrung absteigend (mit `category` kategoriespezifisch, sonst Max über alle
  Kategorien; Tiebreak Bereitschaft), `sort=willingness` = reine Bereitschaft.
- Bestehende Filter (`q`, `gender`, `city`, `country`, `age_min/max`,
  `act_type`) bleiben; new: Lifecycle-Status.

---

## 5. Sicherheit / Bildspeicherung (Konzept-Antwort)

Belegbarer Ist-Zustand und SOLL für die neue Foto-Funktion.

### 5.1 Ist-Zustand Altersnachweis (belegt)

- Ablage **verschlüsselt** auf der privaten `local`-Disk
  (`storage/app/private`), Pfad `model-age-proofs/{customer_id}/`, Dateiname vom
  Storage vergeben.
- **Kein** `public`-Storage; keine direkt aufrufbare URL.
- Download ausschließlich über `ModelManagementController::ageProof`
  (`management`-Middleware + Gate `isAdmin`, brand-gescoped).
- Validierung beim Upload: Datei/MIME + max. 10 MB (Submit-Regeln).
- Orphan-Cleanup bei Transaktions-Rollback und bei Ersetzen des alten Proofs.

### 5.2 Ist-Zustand Personen-Fotos

- Personen-Fotos werden analog unter `model-photos/{customer_id}/` mit
  zufälligem Dateinamen **verschlüsselt** abgelegt; der Pfad ist nicht
  user-kontrolliert.
- Delivery nur über auth-gated, brand-gescopte Management-Endpunkte; der
  Contact Sheet-Service entschlüsselt serverseitig für das PDF.
- MIME-/Typ-/Größenvalidierung und **EXIF/GPS-Stripping** (Re-Encoding) erfolgen
  vor der Verschlüsselung.
- Die Anzahl wird serverseitig geprüft (max. 5 pro Person).
- Beim Löschen/Zustandswechsel werden die Dateien mitentfernt (keine Waisen).

### 5.3 Verschlüsselung at rest (umgesetzt)

- Altersnachweis und Personen-Fotos werden über
  `ercsctt/laravel-file-encryption` mit AES-256-GCM verschlüsselt; der eigene
  Key kommt aus `FILE_ENCRYPTION_KEY` (nicht `APP_KEY`), Previous-Keys werden
  für Rotation unterstützt.
- `model_profiles.answers` wird mit Laravel `encrypted:array` gespeichert;
  V034 änderte die Spalte dafür von JSON auf Text.
- Rasterbilder werden nach Möglichkeit GD-re-encoded, um EXIF/GPS zu entfernen;
  PDF-/ unbekannte Dateitypen bleiben inhaltlich unverändert, werden aber
  ebenfalls verschlüsselt.
- „Privat + auth-gated" bleibt zusätzlich erforderlich; Verschlüsselung ersetzt
  weder Authorization noch Audit-Logging.

### 5.4 Zugriffsprotokollierung (umgesetzt)

- Altersnachweis-/Foto-Downloads werden über `model.file.download` mit User,
  Model-Profil, Customer und Foto protokolliert; Contact-Sheet-Exporte über
  `model.contact_sheet.export` ohne PII im Log.

### 5.5 Bedrohungsmodell (Kurz)

| Szenario | Wirkung heute | Gegenmaßnahme |
|---|---|---|
| DB-Leak | keine Bilddateien; Answers-Snapshot ist verschlüsselt | Trennung + `encrypted:array` beibehalten |
| Storage-Leak | Ciphertext ohne Key; Key-/Host-Kompromittierung bleibt ein Risiko | File-Encryption-Key, Host-Härtung, Rotation |
| Erratene ID/URL | kein Zugriff | auth-gated + Brand-Scope + zufällige Pfade |
| Admin-Missbrauch | Zugriff möglich | minimale Rollen, Audit-Log, minimaler Exportumfang |
| Verwaiste Dateien | Speicherrest möglich nach Crash | Rollback-/Lifecycle-Cleanup und `.plain-*`-Sweep |

---

## 6. Entscheidungs- und Restpunkt-Log

Die Nummern in diesem Log sind Entscheidungs-IDs, keine Markdown-Überschriften.
Technisch umgesetzte Punkte sind als solche markiert; offene Punkte dürfen den
aktuellen Vertrag nicht als „noch nicht implementiert" missverständlich
darstellen.

| ID | Entscheidung / Status | Auswirkung |
|---|---|---|
| 6.1 | ✅ **Entschieden (2026-09-19): Erotik entfernt.** | Kategorie/Filter ohne `erotik`; kein Altbestand. |
| 6.2 | ✅ **Entschieden (2026-09-19): Fashion/Editorial, Business/Corporate und Portrait bleiben getrennt.** | Kein Merge, keine „höchster Ordinalwert"-Regel nötig. |
| 6.3 | ✅ **Entschieden (2026-09-19): Agentur-Feld bleibt, strukturiert als `agency_name` (text) + `agency_link` (url), beide optional.** | Kein Alt-Snapshot-Mapping (Single-Katalog). |
| 6.4 | ✅ **Entschieden und umgesetzt (2026-09-19): Verschlüsselung at rest** — Paket `ercsctt/laravel-file-encryption` (AES-256-GCM) mit eigenem `FILE_ENCRYPTION_KEY`; `model_profiles.answers` via Laravel `encrypted:array`. | §5, §8.1 |
| 6.5 | ✅ **Entschieden und umgesetzt (2026-09-19): `expired` = Hard-Delete** (Zeilen + Dateien, gemeinsame Routine mit DSGVO-Delete, Audit `expired`). | §3.5, DSGVO, Storage |
| 6.6 | ✅ **Umgesetzt:** getrennte Bereitschaft- und Erfahrungsfragen mit stabilen Codes. | §1.3; die frühere Alternative „kombiniert" ist nicht der Current Contract. |
| 6.7 | **Offen:** Welche optionalen Aussehen-/Maß-Felder entfallen? | Katalogpflege; die aktuelle UI klappt die Sektion standardmäßig auf. |
| 6.8 | ✅ **Technischer Contract umgesetzt:** `public|internal`, Default `internal`, Primary nur `public`; die Produktfrage, wer welche öffentlichen Fotos freigibt, bleibt eine Governance-/Einwilligungsentscheidung. | §2.4, §5.2 |
| 6.9 | ~~**Altersnachweis bei Migration**~~ — **gegenstandslos** (Single-Katalog, keine Migration). | — |
| 6.10 | **Offen:** harte Mindestalters-/Erziehungsberechtigten-Regel. | Validierung, Rechtsseiten |
| 6.11 | ✅ **Technische Limits umgesetzt:** maximal 5 Fotos, jpg/jpeg/png/webp, 10 MB; Scope-Erweiterungen bleiben eine separate Entscheidung. | §2.4 |
| 6.12 | **Offen:** eigener `/agb`-Pfad oder bestehender `/license-terms`-Link. | Routing, Rechtstext |
| 6.13 | ✅ **Umgesetzt:** T+12/13/14-Reminder, idempotent und brand-aware. | §3.3 |
| 6.14 | ✅ **Umgesetzt:** Default `active`; `inactive|all` nur für Super-Admin, sonst 403. | §3.4 |
| 6.15 | ~~**Invite-Versionswechsel**~~ — **gegenstandslos** (Single-Katalog, kein Versionswechsel). | — |

---

## 7. Related

- [`05-model-registration.md`](05-model-registration.md) — v1-Registrierung und
  öffentlicher Multipart-Wire-Contract.
- [`04-birthdate-age-verification.md`](04-birthdate-age-verification.md) —
  Altersberechnung für §2.2.
- [`07-model-contact-sheet-export.md`](07-model-contact-sheet-export.md) —
  aktueller PDF-Export für interne/externe Varianten.
- `AGENTS.todo.md` — Ausgangs-Anforderungsblock und Verifikations-TODOs.
- Profile-Magic-Link, „Meine Profile" und Owner-Updates sind in §8.2 als
  aktueller Contract dokumentiert.

---

## 8. Nachtrag P3 (2026-09-19) — Entscheidungen & Contract

Die folgenden Angaben präzisieren den aktuellen Contract; sie ersetzen keine
älteren, ausdrücklich als historisch markierten Entscheidungen.

### 8.1 Entschiedene offene Punkte (§6.1–6.4)

- **§6.1 — entschieden:** `erotik` ist aus dem Katalog **entfernt**
  (`SHOOTING_CATEGORIES`, Single-Katalog `v1`). Es gibt keine eingefrorene
  Alt-Definition und keine Alt-Snapshots.
- **§6.2 — entschieden:** Fashion/Editorial und Business/Corporate bleiben
  **getrennt** (kein Merge, auch nicht mit Portrait). Damit gibt es keine
  Merge-/Migrationsregel.
- **§6.3 — entschieden:** Agentur ist strukturiert: `agency_name` (text) +
  `agency_link` (url), beide optional. Ein Mapping `agency` → `agency_name`
  entfällt (Single-Katalog; kein Altwert).
- **§6.4 — entschieden:** Verschlüsselung at rest ist implementiert über das
  Paket **`ercsctt/laravel-file-encryption`** mit **eigenem Key**;
  `model_profiles.answers` nutzt Laravel `encrypted:array` (V034: `json` → `text`).
  Der Formulartext „verschlüsselt auf privatem Speicher" ist damit korrekt.

### 8.2 Contract-Nachtrag (neu, Single-Katalog v1)

| Methode | Pfad | Beschreibung |
|---|---|---|
| `GET` | `/api/me/models` | „Meine Profile" (auth, owner-gescoped, brand-gescoped). |
| `GET` | `/api/model-profil/{token}` | Profil lesen (Token = Credential; `404` unbekannt, `410` widerrufen/abgelaufen). |
| `POST` | `/api/model-profil/{token}` | Owner-Update als `multipart/form-data`: `answers` (validiert gegen `CURRENT = 'v1'`), optional `age_proof` (bei vorhandenem Proof optional, sonst erforderlich) und `photos[i][id|visibility|is_primary]`; schreibt Snapshot + `last_confirmed_at`. |
| `POST` | `/api/model-profil/{token}/confirm` | Bestätigen **ohne** Snapshot-Änderung/Katalog-Bump (Timer-Reset, §3.2). |
| `POST` | `/api/model-profil/{token}/transfer-manager` | Nur der aktuelle Act-Manager darf `{act_id, new_manager_customer_id}` übertragen; das Ziel muss Mitglied desselben Acts sein. |
| `POST` | `/api/management/models/{customer}/access-link` | 24h-Profil-Link ausstellen/rotieren → `201 {success, link, expires_at}`. |
| `DELETE` | `/api/management/models/{customer}/access-link` | Aktiven Profil-Link widerrufen. |
| `GET` | `/api/management/models/{id}/photos/{photoId}` | auth-gated, brand-gescopter Foto-Download (verschlüsselt at rest). |
| `DELETE` | `/api/management/models/{id}/photos/{photoId}` | Foto löschen (Datei + DB-Eintrag). |
| `POST` | `/api/management/models/{id}/photos/{photoId}/primary` | Hauptbild setzen. |

**Personen-Fotos im Submit (multipart):**
`persons[i][photos][j][file]` + `persons[i][photos][j][visibility]`
(`public|internal`, Default `internal`) + `persons[i][photos][j][is_primary]`;
max. **5 pro Person** (server-autoritativ, 422-Feld-Key `persons.i.photos`).

**Owner-Foto-Update:** Der Owner-Endpunkt lädt über diesen Submit-Vertrag **keine
neuen Fotos** hoch. Er sendet für bestehende Fotos nur IDs und kann `visibility`
sowie `is_primary` ändern; ein Primary muss `public` sein, ein explizites
`is_primary=false` auf dem aktuellen Primary löscht es. Datei-Upload und
Altersnachweis-Re-Upload bleiben die einzigen Datei-Felder dieses Owner-Requests.

**Admin-Suche (Single-Katalog):** `category[]` (Multi-Select, ODER);
`willingness_<key>=<level>` als **Mindest-Schwelle** (Ordinal ≥ Level, ODER über
Kategorien/Stock; Alias `willingness_category` + `willingness_level`).
Sortierung: **Default = Match-Score** (`willingness*10 + experience`, Max über
Kategorien/Stock, Tiebreak neueste), `sort=newest`, `sort=experience` (mit
`category` kategoriespezifisch, sonst Max über alle Kategorien) und
`sort=willingness&willingness_category=<key>`.
`serialize()` liefert zusätzlich `photos[]`, `primary_photo_id`,
`willingness`-Map, `last_confirmed_at`, `lifecycle_status` und `access_link`.

**Frontend-Pendant:** `CURRENT_MODEL_CATALOG_VERSION = 'v1'`; öffentliche Route
`/model-profil/:token`, „Meine Profile" unter `/my-models` (Sidebar nur bei
verknüpften Profilen).
