# 📝 Projekt-Master-Backlog

Aktuelle DB Version: 15

## 🏗️ Infrastruktur & Technical Debt

- [ ] **Refactoring:** Das `useEffect`-Antipattern (Side-Effects basierend auf User-Events) und redundanten State durch
  Event-Handler Logik und Derived State im gesamten Frontend ersetzen.
- [ ] **ZIP Downloaad:** Wird im UI nur die Downloadmöglichkeiten angezeigt, die das inkludierte Flatrate-Level
  erlaubt (wenn der Download von anonymen Usern erlaubt ist: Dann sollen alle Möglichkeiten erlaubt sein). Tests dafür
  nicht vergessen....
- [ ] **Dialoge Ropuntrip testen**: Für jeden Dialog / Formular einen roundtrip test in PHP implementieren. Ziel:
  Zeiugen
  dass alles was das UI braucht gespeichert und wieder geladen wird. Sowohl create als auch update testen.

- [x] **Database:** In der nächsten Migration (z.B. V014) sicherstellen, dass die UI-Flags `is_hidden`,
  `is_free_download`, `is_editorial_only` explizit als `boolean` mit `default(false)` migriert werden, um
  Nullable-Inkonsistenzen zu vermeiden.

- [ ] **Migration Script:** Ein optionales CLI-Kommando (Artisan) erstellen, das bestehende Dateien von `filename` auf
  `uuid` umbenennt, um den BREAKING CHANGE für Bestandssysteme zu mildern.
- [ ] **Memory Limit Check:** Sicherstellen, dass das Docker-Setup oder die `php.ini` mindestens 512MB RAM für
  GD-Operationen bei großen JPEGs bereitstellt.
- [ ] **Test Coverage:** Playwright-Tests für den neuen "Admin Download" Button in der `PhotoDetailView` hinzufügen.
- [ ] **Frontend Refactoring:** Die `useEffect`-Antipattern in `WatermarkSettingsCard.tsx` prüfen. Aktuell wird
  `renderSvgToCanvas` manuell getriggert, was okay ist, aber der Render-Prozess für die Buckets könnte noch weiter
  entkoppelt werden.
- [ ] **ZIP-Download UI:** Im ZIP-Dropdown prüfen, ob die Filterung der Tiers (Web/Print/Original) konsistent mit den
  Benutzer-Rollen und dem anonymen Zugriff ist.

### ✨ User Experience (UX)

- [ ] **Prompt Optimierung (Vision LLMs):** Recherchiere Best Practices für das Prompting (speziell mit Modellen wie
  Gemma 4) zur perfekten Bild-Metadaten-Extraktion (z.B. genaue Erfassung von Locations innerhalb einer Stadt). Ziel:
  Beschreibungen und Tags auf dem Qualitätsniveau großer professioneller Bildagenturen generieren.
