---
domain: technical
topic: frontend-architecture
status: active
---

# Technical Concept: Frontend Architecture

## 1. Core Stack
- React SPA, Vite, TypeScript, TailwindCSS v4, DaisyUI.
- **Data Fetching:** Strict use of `SWR` for data fetching, caching, and mutation.

## 2. Component Philosophy
- **Dumb Components:** UI components in `src/ui` should remain stateless. Logic lives in `src/logic/use*.ts`.
- **Error Boundaries:** Critical UI sections are wrapped in React `ErrorBoundary` components to prevent total application crashes on localized data errors.

## 3. UI/UX Rules
- **Mobile-First:** Action buttons must not be hidden behind CSS `:hover` states.
- **Feedback:** Use the global `UIContext` (Toast messages). Never use native `alert()`.
- **State via URL:** Application state (search queries, tabs) MUST be derived from the URL.


## 4. Routing & URL Patterns
- **RESTful URLs:** Das Routing folgt REST-Konventionen, um Vorhersehbarkeit zu gewährleisten.
  - `/galleries` -> Übersicht und Verwaltung aller Galerien und Ordner.
  - `/:slug` (bzw. `/galleries/:slug`) -> Detailansicht einer spezifischen Galerie.
- **Rollen-Ansichten (Views):** Anstatt unterschiedliche URLs für Fotografen und Kunden zu verwenden, wird die selbe Galerie-URL genutzt. Die Steuerung der Ansicht (Management vs. Client) erfolgt dynamisch über die Berechtigungsprüfung im Frontend.
  - Ein erzwungener Wechsel in die Kundenansicht für Fotografen erfolgt über den URL-Parameter `?view=client`.