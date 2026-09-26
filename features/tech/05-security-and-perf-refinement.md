---
domain: technical
topic: security-and-perf-refinement
status: active
---

# Technical Concept: Security, Performance & Lifecycle Refinement

## 1. Path Traversal Protection
- **Absicherung:** Der `FileDeliveryController` validiert Datei-Identifier strikt gegen die Datenbank. Pfade werden via `realpath()` aufgelöst und gegen den `PHOTO_STORAGE_PATH` geprüft, um Ausbrüche aus dem Bilder-Verzeichnis zu verhindern.

## 2. Invoice & Sequence Logic (Natural Keys)
- **Natural Keys:** Die Rechnungsnummer (z.B. P-2026-0001) wird als primärer Schlüssel in `invoice_snapshots` verwendet.
- **Implementierung:** Die Vergabe erfolgt atomar über ein Eloquent `creating` Event für das `Order` Model, um Lücken im Nummernkreis zu vermeiden.

## 3. Storage Lifecycle (Queue-Based)
- **Lösch-Strategie:** Das Löschen großer Datenmengen (`destroyGallery`) erfolgt asynchron über die Queue. Jobs arbeiten in Batches von max. 100 Dateien, um die Systemlast (I/O) gering zu halten.
- **Durable CRM-/Gallery-Cleanup:** Datei- und Scout-Cleanup wird erst nach erfolgreichem Commit dispatcht. Wenn der primäre Dispatch innerhalb einer Transaktion oder unmittelbar danach fehlschlägt, persistiert `DurableDispatchService` eine `CrmCleanupOutboxJob` in der bestehenden UUID-basierten `jobs`-Tabelle. Für manuelle Galerie-/Foto-Löschung und `CleanupGalleries` wird der Fallback bereits innerhalb der Löschtransaktion als durable Intent geschrieben und nach erfolgreichem Primärdispatch wieder entfernt; ein Crash zwischen Commit und Callback hinterlässt thereby einen retrybaren Job. `CrmCleanupOutboxJob` verwendet für die öffentliche `photos`-Disk die getrennten Operationen `gallery_folder` und `photo_files`; die synchron laufende Scout-Entfernung wird wegen `scout.after_commit=false` für Gallery/Photo separat als `gallery_search`/`photo_search` nach Commit geplant; bei Gallery-Kaskaden werden die vor dem Löschen erfassten Child-Photo-IDs als `gallery_photos_search` in Chunks von maximal 100 IDs verarbeitet. Verschlüsselte Model-Dateien behalten ihre bestehende `file_cleanup`-Operation auf der privaten `local`-Disk. Der Job führt jede Operation idempotent aus, verwendet fünf Gesamtversuche mit Backoff `[30, 60, 120, 300, 600]` (auch die primären Gallery/Photo-Cleanup-Jobs) und landet bei terminalem Fehler sichtbar in `failed_jobs` mit Wrapper- und Underlying-Auditereignissen. Die Zustellung ist at-least-once; der Cleanup muss daher mehrfach ausführbar sein. Ein Fehler beim Schreiben der Fallback-Zeile ist ein kritischer, betrieblich zu alarmierender Zustand; schlägt nur das Entfernen des bereits committeten Intents fehl, bleibt der idempotente Duplikat-Job zur operativen Sichtbarkeit zurück.

## 4. Deployment & Admin Security
- **Admin Provisionierung:** `admin:update` läuft im fail-closed Backend-Start nach den Credential-/Pfad-Guards und nach `migrate`/`db:seed`; ein Fehler stoppt den Start vor Queue, Scheduler und PHP-FPM. Es verwendet `ADMIN_EMAIL`/`ADMIN_PASSWORD` ohne Runtime-Fallback.
- **Passwort-Schutz:** Der Passwort-Reset ist für die `ADMIN_EMAIL` deaktiviert, um eine Überschreibung der ENV-Vorgaben zu verhindern.

## 5. ImageProcessor & Windows Dev Parity
- **CLI Fallback:** Die Nutzung der ImageMagick CLI (`magick`/`convert`) anstelle der PHP-Extension ist eine beabsichtigte Einschränkung, um die Funktionsfähigkeit auf Windows-Entwicklungsumgebungen (Laravel Herd) und in E2E-Testumgebungen zu gewährleisten.

## 6. Frontend State & Performance
- **Wysiwyg-Limits:** Texte im Editor sind auf 100.000 Zeichen limitiert. Dies wird im Frontend validiert.
- **React Compiler / Memoization:** The compiler is enabled in the Vite/Babel pipeline and performs automatic memoization. Manual `useMemo`, `useCallback`, `React.memo`, and `forwardRef` are not part of the frontend policy; keep expensive permission checks in plain functions/derived values or module-level helpers when a compiler-unsupported construct must be isolated.
