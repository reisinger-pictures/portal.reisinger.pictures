# Model-Profile Iteration (Single-Katalog v1, Form/UX, Lifecycle, Admin, Sicherheit)

**Status:** SOLL — **implementiert** (Backend P1+P2, Frontend P3, 2026-09-19). Baut auf
[`05-model-registration.md`](05-model-registration.md) auf. Diese Datei beschreibt
den Zielzustand; Umsetzungs-Nachtrag + Contract-Erweiterung in §8.

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

- **Model-Zugang / Profil-Magic-Link / „Meine Profile"** — eigener Anforderungsblock
  (2026-09-19). Dieses Dokument setzt nur die Lifecycle-Semantik auf, die den
  dortigen Magic Link als Aktionsweg voraussetzt.
- Screenshot-/Vision-Findings F1–F4, E2E-SQLite-Flakiness, Personenzahl-Sanity-Limit
  (in `05-model-registration.md` bzw. `AGENTS.todo.md` geführt).

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
- Begründung: Das Feature ist nicht live, es existieren keine schützenswerten
  Alt-Snapshots. Ein Versionssplit wäre reine Komplexität.

### 1.2 Änderungen gegenüber dem bisherigen Stand (verbindlich)

| Anforderung | bisher | Single-v1 (SOLL) |
|---|---|---|
| **Erotik** | Kategorie `erotik` mit Alterstor | **entfernt** (entschieden 2026-09-19, §7.1) |
| **Fashion / Business / Portrait** | drei getrennte Kategorien | **bleiben getrennt** (entschieden 2026-09-19, §7.2) |
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
- Ob Bereitschaft und Erfahrung zu einer kombinierten Frage verschmolzen werden,
  ist als Alternative zulässig („ggf. kombiniert", §7.6).

### 1.4 Migrationsmatrix Alt-Snapshots — **entfällt**

**Entfällt (entschieden 2026-09-19).** Es gibt keinen v1/v2-Split und keine
schützenswerten Alt-Snapshots (Feature nicht live). `agency` → `agency_name` ist
schlicht der neue Feldname in **einem** Katalog; eine Mapping-/Merge-Regel
(„höchster Ordinalwert") wird nicht benötigt. `age_proof_required` ist für neue
Submits immer `true` (§2.8).

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
  Minderjährigkeit §7.10).

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
- Die UI-Kontrolle ist die geforderte Absicherung. Eine **spiegelnde
  Server-Validierung** ist als Defense-in-Depth vorgesehen, damit dieselbe Regel
  nicht nur clientseitig gilt.

### 2.4 Personen-Fotos (max. 5/Person, öffentlich vs. intern)

Neue Upload-Funktion je Person (nicht je Act).

- **Anzahl:** maximal 5 Fotos pro Person, **server-autoritativ** (der 6.
  Upload wird mit 422 und klarem Feld-Key abgelehnt); clientseitig wird das
  Hinzufügen nach 5 gesperrt.
- **Typen/Größe:** jpg/jpeg/png/webp; Größenlimit analog Altersnachweis
  (10 MB) — exakter Wert offene Detailfrage §7.11.
- **Sichtbarkeit je Foto:** `public` oder `internal`. Default = **`internal`**
  (Privacy-first); `public` erfordert eine explizite Umschaltung und ist von
  einer Foto-/Veröffentlichungs-Einwilligung gedeckt (§2.9).
- **Hauptbild:** genau ein Foto pro Profil ist als `is_primary` markierbar
  (Admin-Auswahl, §5.2). Ohne explizite Wahl greift das erste `public`-Foto,
  sonst ein Platzhalter.
- **Speicherung/Delivery:** private Disk + auth-gated Endpunkt (Details §6).
  Personen-Fotos werden zusätzlich EXIF-/GPS-bereinigt.
- **Löschen:** entfernt die Datei von der privaten Disk; Löschen des
  Hauptbilds setzt `is_primary` zurück.
- **Datenmodell (SOLL):** eigene Entität, z. B. `model_photos`
  (`id`, `model_profile_id`, `customer_id`, `path`, `original_name`,
  `mime_type`, `size_bytes`, `visibility`, `is_primary`, `position`,
  `created_at`).
- **Multipart-Vertrag:** `persons[i][photos][j][file]` plus
  `persons[i][photos][j][visibility]`; das genaue Wire-Format wird mit dem
  API-Vertrag in `05-model-registration.md` festgeschrieben.

### 2.5 Aussehen & Maße

- Die Sektion „Aussehen & Maße" wird **per Default zugeklappt** gerendert
  (Collapsible), damit sie wahrgenommen, aber nicht als Pflichtblock erlebt
  wird.
- Alternative/zusätzlich: Feldumfang reduzieren. **Welche** Felder entfallen,
  ist offene Entscheidung §7.7; Datenverlust bei Neuerfassung ist dabei
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
  das eine reine Routing-Frage (§7.12).

---

## 3. Lifecycle / Retention

### 3.1 Zeitachse

| Phase | Zeit seit letzter Bestätigung | Zustand |
|---|---|---|
| aktiv | 0 – 13 Monate | `active` |
| inaktiv | 13 – 15 Monate | `inactive` |
| abgelaufen | ≥ 15 Monate | `expired` → Hard-Delete (§3.5) |

### 3.2 Timer & Anker

- **Anker** ist `model_profiles.last_confirmed_at` (neues Feld). Gesetzt bei
  Erst-Submit und bei jeder Bestätigung/Aktualisierung.
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

- **Nach 12 Monaten** Update-Mail mit Magic Link auf das Profil (Aktionsweg
  aus dem separaten „Model-Zugang"-Block, Token-TTL 24 h).
- Vorschlag (offene Detailfrage §7.13):
  - T+12 Monate: Reminder 1.
  - T+13 Monate (Eintritt `inactive`): Reminder 2.
  - T+14 Monate: letzte Warnung vor `expired`.
- **Empfänger:** die gespeicherte Kontakt-E-Mail der Person. Existiert keine,
  wird **keine** Mail versendet (konsistent zum Invite-Flow); der Admin sieht
  den Zustand „kein Kontaktweg".
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
- `inactive`/`expired` sind in der Standard-Übersicht gedämpft bzw. nur über
  Filter/„auch inaktive anzeigen" sichtbar (genaue Default-Sicht §7.14).

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

- Ablage auf der **privaten `local`-Disk** (`storage/app/private`), Pfad
  `model-age-proofs/{customer_id}/`, Dateiname vom Storage vergeben.
- **Kein** `public`-Storage; keine direkt aufrufbare URL.
- Download ausschließlich über `ModelManagementController::ageProof`
  (`management`-Middleware + Gate `isAdmin`, brand-gescoped).
- Validierung beim Upload: Datei/MIME + max. 10 MB (Submit-Regeln).
- Orphan-Cleanup bei Transaktions-Rollback (Regressionstest R2 vorhanden).

### 5.2 SOLL Personen-Fotos

- Analog private Disk, z. B. `model-photos/{customer_id}/`, random Filesnamen,
  kein user-kontrollierter Pfadanteil.
- Delivery nur über auth-gated, brand-gescopten Endpunkt.
- MIME-/Typ-/Größenvalidierung; **EXIF/GPS-Stripping** (Re-Encoding), bevor die
  Datei abgelegt oder ausgeliefert wird.
- Anzahl serverseitig geprüft (max. 5), nicht nur clientseitig.
- Beim Löschen/Zustandswechsel werden Dateien mitentfernt (keine Waisen).

### 5.3 Verschlüsselung at rest

- **App-seitig ist keine Verschlüsselung at rest implementiert.** Der aktuelle
  Formulartext („ausschließlich verschlüsselt auf einem privaten Speicher")
  ist damit **nicht korrekt** und muss bis zur Umsetzung angepasst werden.
- Ob verschlüsselte Ablage eingeführt wird, ist offene Entscheidung §7.4
  (Mechanismus: Laravel-eigener verschlüsselter Filesystem-Treiber/Volume;
  Auswirkungen auf Streaming, Re-Encoding und Delivery).
- Unabhängig davon bleibt „privat + auth-gated" die Grundabsicherung.

### 5.4 Zugriffsprotokollierung

- Jeder Download von Altersnachweis/Fotos sollte protokolliert werden
  (wer/wann/welches Profil) — DSGVO-Rechenschaft, konsistent zu den
  bestehenden Delivery-Audit-Logs.

### 5.5 Bedrohungsmodell (Kurz)

| Szenario | Wirkung heute | Gegenmaßnahme |
|---|---|---|
| DB-Leak | keine Bilder (separater Storage) | Trennung beibehalten |
| Storage-Leak | Bilder lesbar | Verschlüsselung at rest (§7.4), Host-Härtung |
| Erratene ID/URL | kein Zugriff | auth-gated + Brand-Scope, random Pfade |
| Admin-Missbrauch | Zugriff möglich | minimale Admin-Rollen, Audit-Log |
| Verwaiste Dateien | Speicherrest | Rollback-Cleanup + Lifecycle-Löschung |

---

## 6. Offene Entscheidungen (explizit)

| # | Entscheidung | Auswirkung |
|---|---|---|
| 7.1 | ✅ **Entschieden (2026-09-19): Erotik entfernt.** | Kategorie/Filter ohne `erotik`; kein Altbestand. |
| 7.2 | ✅ **Entschieden (2026-09-19): Fashion/Editorial, Business/Corporate und Portrait bleiben getrennt.** | Kein Merge, keine „höchster Ordinalwert"-Regel nötig. |
| 7.3 | ✅ **Entschieden (2026-09-19): Agentur-Feld bleibt, strukturiert als `agency_name` (text) + `agency_link` (url), beide optional.** | Kein Alt-Snapshot-Mapping (Single-Katalog). |
| 7.4 | ✅ **Entschieden (2026-09-19): Verschlüsselung at rest umgesetzt** — Paket `ercsctt/laravel-file-encryption` (AES-256-GCM) mit eigenem `FILE_ENCRYPTION_KEY`; `model_profiles.answers` via Laravel `encrypted:array`. | §5, §8.1 |
| 7.5 | ✅ **Entschieden (2026-09-19): `expired` = Hard-Delete** (Zeilen + Dateien, gemeinsame Routine mit DSGVO-Delete, Audit `expired`). Aufbewahrungsfrist Altersnachweis entfällt mit dem Profil. | §3.5, DSGVO, Storage |
| 7.6 | **Bereitschaft getrennt oder kombiniert mit Erfahrung?** Stabile Codes vs. deutsche Werte? | Formular-UX, Admin-Sortierung, Snapshot |
| 7.7 | **Aussehen & Maße:** nur zuklappen oder Feldumfang reduzieren (welche)? | Katalog (Single-v1), Datenmodell |
| 7.8 | **Sichtbarkeit `public`:** nur intern/Portfolio oder echte Veröffentlichung? | Einwilligungstext, Delivery |
| 7.9 | ~~**Altersnachweis bei Migration**~~ — **gegenstandslos** (Single-Katalog, keine Migration). | — |
| 7.10 | **Minderjährigkeit:** harte Mindestalters-/Erziehungsberechtigten-Regel nötig? | Validierung, Rechtsseiten |
| 7.11 | **Foto-Limits:** exakte Größe/Typen und Anzahl (5 bestätigt?) | Upload-Validierung |
| 7.12 | **AGB-Link:** eigener `/agb`-Pfad oder bestehende `/license-terms`? | Routing, Rechtstext |
| 7.13 | **Reminder-Kadenz** (T+12/13/14) bestätigen? | Scheduler, Mail-Texte |
| 7.14 | **Default-Sicht** der Übersicht: inaktive ein-/ausblenden? | Admin-UX |
| 7.15 | ~~**Invite-Versionswechsel**~~ — **gegenstandslos** (Single-Katalog, kein Versionswechsel). | — |

---

## 7. Related

- [`05-model-registration.md`](05-model-registration.md) — v1-SOLL und
  eingefrorener API-Vertrag (Referenz, wird nicht umgeschrieben).
- [`04-birthdate-age-verification.md`](04-birthdate-age-verification.md) —
  Altersberechnung für §2.2.
- `AGENTS.todo.md` — Ausgangs-Anforderungsblock, Test-/Umsetzungs-TODOs.
- Separater Block „Model-Zugang: Profile einsehen/aktualisieren" — liefert den
  in §3 vorausgesetzten Magic-Link-/„Meine Profile"-Aktionsweg.

---

## 8. Nachtrag P3 (2026-09-19) — Entscheidungen & Contract

Nur Ergänzung, kein Rewrite der obigen Spezifikation.

### 8.1 Entschiedene offene Punkte (§7.1–7.4)

- **§7.1 — entschieden:** `erotik` ist aus dem Katalog **entfernt**
  (`SHOOTING_CATEGORIES`, Single-Katalog `v1`). Es gibt keine eingefrorene
  Alt-Definition und keine Alt-Snapshots.
- **§7.2 — entschieden:** Fashion/Editorial und Business/Corporate bleiben
  **getrennt** (kein Merge, auch nicht mit Portrait). Damit gibt es keine
  Merge-/Migrationsregel.
- **§7.3 — entschieden:** Agentur ist strukturiert: `agency_name` (text) +
  `agency_link` (url), beide optional. Ein Mapping `agency` → `agency_name`
  entfällt (Single-Katalog; kein Altwert).
- **§7.4 — entschieden:** Verschlüsselung at rest ist implementiert über das
  Paket **`ercsctt/laravel-file-encryption`** mit **eigenem Key**;
  `model_profiles.answers` nutzt Laravel `encrypted:array` (V034: `json` → `text`).
  Der Formulartext „verschlüsselt auf privatem Speicher" ist damit korrekt.

### 8.2 Contract-Nachtrag (neu, Single-Katalog v1)

| Methode | Pfad | Beschreibung |
|---|---|---|
| `GET` | `/api/me/models` | „Meine Profile" (auth, owner-gescoped, brand-gescoped). |
| `GET` | `/api/model-profil/{token}` | Profil lesen (Token = Credential; `404` unbekannt, `410` widerrufen/abgelaufen). |
| `POST` | `/api/model-profil/{token}` | Profil aktualisieren (`{answers}`; validiert gegen `CURRENT = 'v1'`, schreibt Snapshot + `last_confirmed_at`). |
| `POST` | `/api/model-profil/{token}/confirm` | Bestätigen **ohne** Snapshot-Änderung/Katalog-Bump (Timer-Reset, §3.2). |
| `POST` | `/api/management/models/{customer}/access-link` | 24h-Profil-Link ausstellen/rotieren → `201 {success, link, expires_at}`. |
| `DELETE` | `/api/management/models/{customer}/access-link` | Aktiven Profil-Link widerrufen. |
| `GET` | `/api/management/models/{id}/photos/{photoId}` | auth-gated, brand-gescopter Foto-Download (verschlüsselt at rest). |
| `DELETE` | `/api/management/models/{id}/photos/{photoId}` | Foto löschen (Datei + DB-Eintrag). |
| `POST` | `/api/management/models/{id}/photos/{photoId}/primary` | Hauptbild setzen. |

**Personen-Fotos im Submit (multipart):**
`persons[i][photos][j][file]` + `persons[i][photos][j][visibility]`
(`public|internal`, Default `internal`) + `persons[i][photos][j][is_primary]`;
max. **5 pro Person** (server-autoritativ, 422-Feld-Key `persons.i.photos`).

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
