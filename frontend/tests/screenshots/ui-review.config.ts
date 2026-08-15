// UI-review route manifest — single source of truth for which pages get
// screenshotted and in which states. Edit this file to add/remove routes; the
// generic spec picks the changes up automatically.
//
// Auth-Hinweis: fast alle Routen dieses Portals liegen hinter `ProtectedRoute`
// (App.tsx) und erfordern Login (B2B-Dashboard, Warenkorb, Profile, …). Nur
// öffentliche Routen werden hier erfasst. Token-Routen (`/invite/:token`,
// `/contracts/join/:token`, `/reset-password?token=…`) sind ohne gültigen Link
// nicht sinnvoll erreichbar und/oder rendern kein `<main>` (die Spec wartet auf
// `main`), daher ausgenommen. Die Gast-Home `/` rendert `SearchView`
// (ProtectedDashboard-Fallback), ist also ohne Login erreichbar.

export type UiReviewState = "filled" | "empty";
export type UiReviewViewport = "desktop" | "mobile";

export interface UiReviewRoute {
  name: string;
  path: string;
  states: UiReviewState[];
  viewports?: UiReviewViewport[];
  note?: string;
  /** Static <title> of the app — guards against capturing a foreign server on the port. */
  expectedTitle: string;
}

export interface UiReviewConfig {
  /** Must mirror `outputDir` in playwright.screenshots.config.ts. */
  outputDir: string;
  routes: UiReviewRoute[];
}

export const uiReviewConfig: UiReviewConfig = {
  outputDir: "test-results/ui-screenshots",
  routes: [
    {
      name: "home",
      path: "/",
      states: ["filled"],
      note: "Gast-Home = SearchView („Neueste Entdeckungen“); Login + Dashboard liegen hinter Auth. Ohne erreichbares Backend (Vite-Proxy → https://portal.test) zeigen die API-gestützten Sektionen Fehlerzustände (HTTP 502) — Layout, Header und Navigation sind trotzdem vollständig erfassbar.",
      expectedTitle: "Reisinger Foto Portal",
    },
    {
      name: "search",
      path: "/search",
      states: ["filled"],
      note: "Dedizierte Suchseite (dieselbe Suchmaske wie die Gast-Home); leerer Query rendert die identische Ansicht. Ergebnislisten benötigen ein erreichbares Backend.",
      expectedTitle: "Reisinger Foto Portal",
    },
    {
      name: "privacy",
      path: "/privacy",
      states: ["filled"],
      note: "Statische Datenschutzerklärung — vollständig ohne Backend/Auth erreichbar.",
      expectedTitle: "Reisinger Foto Portal",
    },
    {
      name: "impressum",
      path: "/impressum",
      states: ["filled"],
      note: "Statisches Impressum — vollständig ohne Backend/Auth erreichbar.",
      expectedTitle: "Reisinger Foto Portal",
    },
  ],
};

export const routes = uiReviewConfig.routes;
