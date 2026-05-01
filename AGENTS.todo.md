# 📝 Projekt-Master-Backlog

Aktuelle DB Version: 14

## 🏗️ Infrastruktur & Technical Debt

- [ ] **Database:** In der nächsten Migration (z.B. V014) sicherstellen, dass die UI-Flags `is_hidden`,
  `is_free_download`, `is_editorial_only` explizit als `boolean` mit `default(false)` migriert werden, um
  Nullable-Inkonsistenzen zu vermeiden.

## 📝 TODOs: AI Batch-Edit & Refactoring

### 🔒 Sicherheit & Robustheit

- [ ] **Robustes JSON-Parsing:** `generateMetadata` in `useLMStudio.ts` mit `try-catch` absichern und ggf. Validierung (
  z.B. Zod) hinzufügen, um fehlerhafte KI-Antworten abzufangen.
- [ ] **Backend-Autorisierung:** Verifizieren, dass der API-Endpunkt `updateMetadata` im Backend die Berechtigung des
  Users für die spezifische `galleryId` prüft (nicht nur die globale Rolle).
- [ ] **Konfigurations-Optionen:** Hardcoded URL `http://127.0.0.1:1234` durch eine Konfiguration (z.B. via Environment
  Variable oder User-Settings) ersetzbar machen.

### 🚀 Performance & Refactoring

- [ ] **Helper-Extraktion:** Die Funktion `getCompressedBase64` aus `useLMStudio.ts` in eine zentrale Utility-Klasse (
  z.B. `frontend/src/logic/utils/ImageHelper.ts`) auslagern.
- [ ] **Error-Logging:** Leeren `catch`-Block beim Meilisearch-Fallback in `AIBatchEditModal.tsx` durch aussagekräftiges
  `console.warn` oder Error-Tracking ersetzen.
- [ ] **Todo-Management:** Die stetig wachsende Liste in `AGENTS.todo.md` sichten und abgeschlossene Punkte löschen.

### ✨ User Experience (UX)

- [ ] **Batch-Automation:** Einen Button "Alle generieren" im `AIBatchEditModal` implementieren, der die Generierung für
  alle Bilder in der Liste sequentiell (oder parallel <-- NEIN, nur 1 Request parallel solange wir lokal sind, gernew
  mehr bei externen provideren, dan aber in einem request) startet.
- [ ] **Input-Validierung:** Max-Length Validierung für den generierten Titel (50 Zeichen) direkt im UI-Feld
  anzeigen. (<-- eventuell sollen wir den Titel auch länger machen)
- [ ] **Prompt verbessern**: Prompt wenn möglich verkürzen. Ich weiß nicht ob wir nicht den Default JSON ausgeben
  sollen. Soll wirklich so viel im User und so wenig im System promt stehen? Kann man das Format auch kürzer / besser
  definieren. 50 Zeichen für den Titel ist zu wenig. Es sollen alle Felder im UI danach ausgefüllt sein (gerne auch mit
  Meilisearch). Mir fehlt auf die schnelle die Location innerhalb einer Stadt im JSON... Ziel ist wie eine große
  Bildagentur die Bilder zu beschreiben. Recherchiere wie das Best practise promting mit gemma 4 ist.