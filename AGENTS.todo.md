# 📝 Projekt-Master-Backlog

Aktuelle DB Version: 14

## 🏗️ Infrastruktur & Technical Debt

- [ ] **Database:** In der nächsten Migration (z.B. V014) sicherstellen, dass die UI-Flags `is_hidden`,
  `is_free_download`, `is_editorial_only` explizit als `boolean` mit `default(false)` migriert werden, um
  Nullable-Inkonsistenzen zu vermeiden.

### ✨ User Experience (UX)

- [ ] **Prompt Optimierung (Vision LLMs):** Recherchiere Best Practices für das Prompting (speziell mit Modellen wie
  Gemma 4) zur perfekten Bild-Metadaten-Extraktion (z.B. genaue Erfassung von Locations innerhalb einer Stadt). Ziel:
  Beschreibungen und Tags auf dem Qualitätsniveau großer professioneller Bildagenturen generieren.
