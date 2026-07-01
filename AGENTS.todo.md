# Backlog

> Stand: 2026-07-02 — **Alle P1–P3 Tasks der 3. Session abgeschlossen.**  
> Regeln, Workflow & Test-Befehle nach `AGENTS.md` migriert.

---

## PRIO-Tabelle (Restarbeit)

| ID | Prio | Titel | Status | Nächster Schritt |
|----|------|-------|--------|------------------|
| T-18 | 🔴 P1 | SRP-Paketliste definieren | ⏳ Blockiert (User) | User muss Paketliste für `srp` liefern → V018 Migration abschließen |
| F-01 | 🟢 Erledigt | `useState<IptcData>` bei SWR-Revalidation | ✅ | `PhotoDetailView.tsx` guard (line 46) schützt vor Überschreiben bei gleicher Photo-ID |
| F-02 | 🟢 Erledigt | beforeunload + SPA-Click-Interceptor | ✅ | `UIProvider.tsx` Capture-Phase-Interceptor + `ManagementManualInvoiceView.tsx` beforeunload |
| F-03 | 🟡 P2 | react-hook-form Werte bei Validierungsfehler | 🔍 Prüfen | Standard-rhf-Verhalten erhält Werte — gezielte Formulare prüfen (Coupon, License) |
| F-04 | 🟡 P2 | Modal-Formulare: Schutz vor unbeabsichtigtem Close | 🔍 Prüfen | Coupon-/Invite-/AI-/Mail-Modal auditen |
| F-05 | 🟡 P2 | localStorage-Zod-Parsing: Fallback bei korrupten Daten | 🔍 Prüfen | `safeParse`/`catch` in allen localStorage-Reads verifizieren |
| F-06 | 🟢 P3 | WYSIWYG-Editor (100k Limit): Text-Verlust? | 🔍 Prüfen | Editor-Verhalten bei Limit-Überschreitung testen |
| F-07 | 🟢 P3 | Filter/Sort URL-Sync bei Navigation | 🔍 Prüfen | URL-Parameter bleiben bei Grid-Navigation erhalten? |

---

## 🔄 Brand-Migration: ATR → SRP + Per-Brand-Pakete

> **Entscheidung (2026-06-30, User):** Mandant `all-the.rest` (ATR) wird zu
> `buy.reisinger.pictures` (SRP). Mandanten heißen intern `rp` und `srp`.
> V018 ist **nicht in Produktion** → wird in-place geändert. Volle Trennung der kaufbaren
> Pakete/Lizenzen/Settings. **Blocker:** SRP-Paketliste (T-18) vom User ausstehend.

---

## BUG-03 · 🟡 P2 · Formular-Daten-Persistenz

> **Problem:** Benutzereingaben in Formularen dürfen nicht verloren gehen — weder bei
> Validierungsfehlern, Seitennavigation, SWR-Revalidierung noch bei unintendiertem Schließen.
> Systematische Analyse aller Formulare im Frontend.

### Prüfpunkte pro Formular

| ID | Formular | Status | Anmerkung |
|----|----------|--------|-----------|
| F-01 | `PhotoDetailView.tsx` — `useState<IptcData>` bei SWR-Revalidation | ✅ | Guard `data.photo.id !== prevPhotoId` (line 46) verhindert Überschreiben |
| F-02 | `useInvoiceDraft.ts` — beforeunload + SPA-Schutz | ✅ | `UIProvider.tsx` Capture-Phase-Click-Interceptor + beforeunload in ManagementManualInvoiceView |
| F-03 | react-hook-form + Zod — Werte bei onSubmit-Validation-Fehler | 🔍 | Standard-Verhalten prüfen, speziell Coupon/License-Forms |
| F-04 | Modal-Formulare (Coupon, Invite, AI, Mail) — Close-Schutz | 🔍 | Unsaved-Changes-Warning bei unbeabsichtigtem Modal-Close? |
| F-05 | localStorage-Zod-Parsing — Fallback bei korrupten Daten | 🔍 | `safeParse`/`catch` in allen localStorage-Reads verifizieren |
| F-06 | WYSIWYG-Editor (100k Limit) — Text-Verlust? | 🔍 | Verhalten bei Limit-Überschreitung testen |
| F-07 | Filter/Sort in Grids — URL-Sync bei Navigation | 🔍 | Bleiben Suchparameter bei Seitenwechsel erhalten? |
