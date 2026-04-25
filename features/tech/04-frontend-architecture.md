---
domain: technical
topic: frontend-architecture
status: active
---

# Technical Concept: Frontend Architecture

## 1. Core Stack
- React SPA, Vite, TypeScript, TailwindCSS v4, DaisyUI.
- **Data Fetching:** Strict use of `SWR` for data fetching, caching, and mutation. *(Architekturentscheidung: Eine Migration zu React Query wurde offiziell verworfen. SWR bleibt der feste Standard. Keine Umbauten einplanen!)*

## 2. Component Philosophy
- **Dumb Components:** UI components in `src/ui` should remain stateless. Logic lives in `src/logic/use*.ts`.
- **Error Boundaries:** Critical UI sections are wrapped in React `ErrorBoundary` components to prevent total application crashes on localized data errors.

## 3. UI/UX Rules
- **Form Standard Styling (STRICT):**
  - **Größen:** Alle Formularelemente (Inputs, Selects, Buttons) nutzen in regulären Formularen die Standardgröße 'md' (DaisyUI Default, kein `-sm` oder `-xs` Suffix). Dies sorgt für bessere Bedienbarkeit auf Touch-Geräten.
  - **Ausnahme (Table Context):** Innerhalb von engmaschigen Tabellen (z.B. Batch-Edits, User-Listen) und für Modal-Schließen-Buttons (`btn-circle absolute`) sind `input-sm`, `btn-sm` und `btn-xs` ausdrücklich erlaubt, um die Übersichtlichkeit zu wahren.
  - **Layout:** Formulare sind konsequent mittels CSS-Grid zu strukturieren. Standard-Pattern: `grid grid-cols-1 md:grid-cols-2 gap-4`.
- **Mobile-First:** Action buttons must not be hidden behind CSS `:hover` states.
- **Feedback:** Use the global `UIContext` (Toast messages). Never use native `alert()`.
- **State via URL:** Application state (search queries, tabs) MUST be derived from the URL.
- **Forms:** Strict use of `react-hook-form` and `zod` for form state management and validation. Avoid cascading `useState` hooks for form fields.
- **Component Granularity (SRP):** Strictly separate concerns. Components should be small and focused. Extract modals, complex forms, and distinct UI sections (like cards or dropzones) into their own sub-components to prevent "God-Components" and minimize unnecessary re-renders.
- **Loading States & Anti-Flicker (STRICT):** Formulare und detailreiche UIs dürfen erst gerendert werden, wenn die Initialdaten vollständig vom Server geladen sind (`if (isLoading) return <Spinner />`). Dies verhindert ein unschönes 'Aufblinken' leerer Formulare und das vorzeitige Triggern von Validierungsfehlern durch leere Default-States.
- **Strict Typing (No `any` & No Inline Types):** Inline typing (e.g., `user: { id: string, name: string }` or `Record<string, any>`) is strictly forbidden. Always define explicit `interface` or `type` contracts for form data, component props, state, and API payloads, and import/export them across components. This prevents ESLint violations, redundant type definitions, and brittle code.


## 4. Routing & URL Patterns
- **RESTful URLs:** Das Routing folgt REST-Konventionen, um Vorhersehbarkeit zu gewährleisten.
  - `/galleries` -> Übersicht und Verwaltung aller Galerien und Ordner.
  - `/:slug` (bzw. `/galleries/:slug`) -> Detailansicht einer spezifischen Galerie.
- **Rollen-Ansichten (Views):** Anstatt unterschiedliche URLs für Fotografen und Kunden zu verwenden, wird die selbe Galerie-URL genutzt. Die Steuerung der Ansicht (Management vs. Client) erfolgt dynamisch über die Berechtigungsprüfung im Frontend.
  - Ein erzwungener Wechsel in die Kundenansicht für Fotografen erfolgt über den URL-Parameter `?view=client`.

## 5. Error Handling & Exceptions
- **Keine leeren Catches:** Leere `catch`-Blöcke (Silent Failures / Catch and Ignore) sind extrem gefährlich und strengstens verboten.
- **Kein "Catch & Log":** Das reine Loggen von Fehlern (z.B. `catch (e) { console.debug(e); }`) ohne weitere Fehlerbehandlung oder Propagierung ist ebenfalls untersagt. Fehler müssen entweder sinnvoll im UI behandelt (z.B. Fallback-State, Toast-Message) oder weitergeworfen werden.

## 6. Smart Assistance (Metadata Auto-Complete)
- **Concept:** To accelerate the workflow, IPTC location fields provide smart auto-completion.
- **Behavior:** Selecting a city from a static dataset automatically fills in the `state`, `country` (e.g., Österreich), and `iso_country` (e.g., AT). Manually typing a known country updates the `iso_country` field.
- **UX Constraint:** All fields **must remain fully editable** (no `readonly`).
