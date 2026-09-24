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

## 4. Deployment & Admin Security
- **Admin Provisionierung:** `admin:update` läuft im fail-closed Backend-Start nach den Credential-/Pfad-Guards und nach `migrate`/`db:seed`; ein Fehler stoppt den Start vor Queue, Scheduler und PHP-FPM. Es verwendet `ADMIN_EMAIL`/`ADMIN_PASSWORD` ohne Runtime-Fallback.
- **Passwort-Schutz:** Der Passwort-Reset ist für die `ADMIN_EMAIL` deaktiviert, um eine Überschreibung der ENV-Vorgaben zu verhindern.

## 5. ImageProcessor & Windows Dev Parity
- **CLI Fallback:** Die Nutzung der ImageMagick CLI (`magick`/`convert`) anstelle der PHP-Extension ist eine beabsichtigte Einschränkung, um die Funktionsfähigkeit auf Windows-Entwicklungsumgebungen (Laravel Herd) und in E2E-Testumgebungen zu gewährleisten.

## 6. Frontend State & Performance
- **Wysiwyg-Limits:** Texte im Editor sind auf 100.000 Zeichen limitiert. Dies wird im Frontend validiert.
- **React Compiler / Memoization:** The compiler is enabled in the Vite/Babel pipeline and performs automatic memoization. Manual `useMemo`, `useCallback`, `React.memo`, and `forwardRef` are not part of the frontend policy; keep expensive permission checks in plain functions/derived values or module-level helpers when a compiler-unsupported construct must be isolated.
