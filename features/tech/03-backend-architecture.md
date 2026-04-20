---
domain: technical
topic: backend-architecture
status: active
---

# Technical Concept: Backend Architecture

## 1. Stateless API & Processing
- The backend serves exclusively as a stateless JSON API.
- All processing tasks (ExifTool) are executed synchronously to keep infrastructure simple (no queue workers), with the exception of lazy thumbnail generation.
- **Fail Fast:** File uploads are strictly validated before touching the disk. Corrupt files yield a 422 error.

## 3. Database Access (Eloquent Only)
- **Strict Eloquent Rule:** The use of the `DB` facade (e.g., `DB::table('...')->insert()`) is strictly forbidden for standard business logic (unless handling highly complex legacy aggregations where Eloquent fails). All database insertions and updates MUST use Eloquent Models to ensure events, UUID traits, casts, and mutators are triggered correctly.

## 2. On-The-Fly Delivery
- **Zip-Streaming:** Full gallery ZIPs are not pre-calculated. They are streamed on-the-fly directly to the client via `maennchen/zipstream-php`.
- **Benefits:** This saves massive amounts of storage space, drastically improves the Time To First Byte (TTFB), and increases perceived interactivity for the user.

## 4. Money Pattern (Fowler)
- **Strikte Cents-Logik:** Das gesamte System arbeitet intern ausnahmslos mit ganzzahligen Cents (`INTEGER`), um Rundungsfehler bei Floating-Point-Arithmetik zu vermeiden.
- **Währung:** Fest auf EUR fixiert, wird nicht in der Datenbank gespeichert.
- **API-Contract:** Alle Geld-Beträge (Preise, Warenkörbe, Rechnungen) werden als Cents (Integer) über die API gesendet und empfangen.
- **Frontend:** Die Konvertierung in Euro (Division durch 100) erfolgt **ausschließlich** für die Anzeige im Frontend (React) oder beim Generieren von PDFs (Blade).

## 5. Dependency Injection & Security
- **Service Container:** Zentrale Dienste wie der `HtmlSanitizer` werden als Singleton im `AppServiceProvider` registriert. Dies sichert das DRY-Prinzip und ermöglicht sauberes Mocking in Unit-Tests.
- **Strikte Whitelists:** HTML-Inputs (z.B. aus dem WYSIWYG-Editor) werden über strenge, explizite Whitelists (`allowElement`) bereinigt, um XSS-Angriffe effektiv zu verhindern. Pauschale Freigaben wie `allowSafeElements()` werden vermieden.

## 6. Performance & Eager Loading
- **N+1 Problemvermeidung:** Für Performance-kritische Controller (wie `StatsController`) wird konsequent Eloquent Eager Loading (`with('gallery.latestPhoto')`) eingesetzt, um die Anzahl der Datenbank-Queries zu minimieren, ohne komplexe, manuelle `whereIn` oder Collections-Mapping-Logik im Controller zu verstreuen.
