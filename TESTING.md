# Testing Guidelines

Dieses Dokument definiert die Richtlinien für automatisierte Tests im Reisinger Foto Portal. Es ergänzt die `ARCHITECTURE.md` und `AGENTS.md`.

## 1. Philosophie & Robustheit
* **Kein Try-Catch Anti-Pattern:** Maskiere niemals fehlschlagende Tests, indem du Produktionscode in einen `try-catch` Block packst.
* **Geduldige Asserts (Auto-Retries):** Nutze in E2E-Tests *niemals* statische `sleeps` oder `waitForLoadState('networkidle')`, da Frameworks wie SWR ständig im Hintergrund kommunizieren können. Nutze **immer** asynchrone Asserts mit ausreichendem Timeout: `await expect(locator).toBeVisible({ timeout: 15000 })`.
* **Single Reason to Fail (SRP):** Tests (sowohl PHPUnit als auch Playwright) MÜSSEN sich auf ein einzelnes Verhalten fokussieren. Vermeide monolithische 20-Schritte-Tests. Fällt ein Test aus, muss sofort klar sein, warum.

## 2. E2E Tests (Playwright)
* **Test Isolation (No DB Reset):** E2E-Tests laufen direkt gegen die lokale Entwicklungsumgebung (`portal_db`). Sie MÜSSEN zerstörungsfrei sein! Verwende immer hochdynamische Namen/Identifier (z.B. `Date.now()`) für neu erstellte Entitäten, damit Tests nicht mit bestehenden Entwicklerdaten oder parallelen Testläufen kollidieren.
* **Modularisierung:** Da E2E-Tests User-Journeys simulieren, müssen sie in klare, logische `test()`-Blöcke unterteilt werden, die aufeinander aufbauen. Wenn Status geteilt werden muss, nutze `test.describe.serial()`. Ein unabhängiges Status-Setup via API wird jedoch dringend bevorzugt.
* **Page Object Model (POM):** Dupliziere keine Playwright-Logik. Nutze die bereitgestellten Helper-Klassen (z.B. `ModalHelper`, `SidebarHelper`). Beschränke Modal-Suchen strikt auf den aktiven Container: `.locator('.modal-open')`.
* **Mobile-First UI Testing:** E2E-Tests müssen explizit gegen mobile Viewports (z.B. Mobile Chrome) ausgeführt werden. Tests müssen sicherstellen, dass kritische UI-Elemente auf mobilen Geräten tatsächlich klickbar/berührbar sind und nicht durch z-Index-Probleme oder überlaufende Container verdeckt werden.

## 3. Backend Tests (PHPUnit)
* **API Resources:** Prüfe in PHPUnit nicht nur den HTTP-Statuscode, sondern immer auch die Struktur der JSON-Antwort, um sicherzustellen, dass keine unbeabsichtigten Felder geleakt werden.
* **E-Mail & Link Integrity Testing:** * **Mailpit API Extraction:** Das Testen von E-Mails über Mocking ist verboten. Tests müssen die lokale Mailpit-API (`http://127.0.0.1:8026/api/v1/messages`) abfragen.
    * **Link Resolution:** Es reicht nicht zu prüfen, ob eine E-Mail angekommen ist. Sowohl PHPUnit- als auch Playwright-Tests MÜSSEN den HTML-Körper der E-Mail parsen, generierte Aktions-Links (z.B. Magic Links, Reset Links) extrahieren und bestätigen, dass das Navigieren zu diesen Links zu einer erfolgreichen Auflösung führt (HTTP 200 oder korrekter UI-Status).
