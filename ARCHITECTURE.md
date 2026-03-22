# System Architecture: portal.reisinger.pictures

## 1. High-Level Components
* **Frontend (React SPA):** Built with Vite, React, TypeScript, TailwindCSS v4, and DaisyUI. STRICT Separation of Logic (SWR data fetching) and UI (Dumb components).
* **Backend (Laravel):** Stateless JSON API (JWT Auth). Handles business logic, **on-the-fly zip streaming**, and **synchronous ExifTool injection/thumbnail generation** to keep the infrastructure footprint small (no background queue workers required).
* **Database (MariaDB):** Managed via native **Laravel Migrations**. Surrogate integer primary keys. (Flyway is deprecated).
* **Search Engine (Meilisearch):** Typo-tolerant search via Laravel Scout.
* **Lightroom Plugin (Lua):** Custom plugin with TWO distinct workflows (Selection vs. Delivery).

## 2. Core Workflows (Selection vs. Delivery)
The system strictly separates the selection phase (client rating) from the delivery phase (high-res downloads). Ratings are synced back to Lightroom via the API.
* **Selection Galleries (DAU Protection):** Selection galleries MUST NOT allow downloads. To deter basic users from saving preview images, simple right-click and drag protections (e.g., global contextmenu prevention and `draggable={false}`) must be applied in the frontend.

## 3. Security, Auditing & Data Access (CRITICAL)
* **DAO / API Resources Layer:** The backend MUST NEVER return raw Eloquent models directly to the frontend. Always use Laravel API Resources or Data Transfer Objects (DTOs) for backend communication. This ensures sensitive fields (e.g., `password_hash`) are strictly hidden and not leaked in API responses (Search, Dashboard, etc.).
* **IDOR Prevention:** Whenever modifying, rating, or reading an object, verify that the authenticated user has explicit access to the *parent* entity (e.g., Gallery).
* **Immutable Logs & Auditing:** Denormalized snapshots (`gallery_name_snapshot`) and `ON DELETE SET NULL` ensure immutable audit trails in `download_logs`. Unprivileged users are implicitly `pending`.
* **Metadata Versioning & Integrity:** To allow client participation without risking data loss, client modifications to IPTC metadata automatically trigger a version snapshot (`photo_metadata_versions`). Photographers and Admins can view the history and revert changes.
* **No IP Tracking:** IP tracking is strictly disabled to comply with GDPR.
* **Leak Tracing (ExifTool):** During the delivery workflow, the `DownloadController` dynamically injects the downloader's name into the `SpecialInstructions` IPTC field of the JPEG. This traces leaked images without visible watermarks.
* **XSS Prevention:** When using DOM manipulation for 3rd party libraries, database data MUST be escaped or injected safely.
* **No Blind `firstOrCreate` in Auth:** Never issue a JWT for a user found via `firstOrCreate` on public/invite routes without verifying if they already have a password or admin rights.

## 4. Search & Indexing (Meilisearch & Scout)
* **Real-time Synchronization:** The search index updates synchronously (`SCOUT_QUEUE=false`). Models use the `Laravel\Scout\Searchable` trait.
* **Tenant Isolation:** We use Meilisearch natively to enforce our robust permission system (`whereIn('gallery_id', [...])`). Filter rules must always be validated to ensure authorized access.
* **Maintenance:** Run `php artisan scout:sync-index-settings` and `php artisan scout:import "App\Models\Photo"` manually if the DB is modified outside Eloquent.

## 5. URL-Driven State & Progressive Role Enhancement
* **Path & Query Parameters First:** Application state MUST be derived from the URL. Use **Path Parameters** (`/users`) for main views and **Query Parameters** (`?tab=mappings`) for sub-views/filters. Do not use local `useState` for navigation.
* **Strict Role Separation:** Roles NEVER overlap. `admin` rights do NOT imply `photographer` rights. Admins manage the system, Photographers manage content, Clients view content.
* **Progressive View Upgrading:** We avoid role-specific routes. An admin visiting `/galleries/wedding-2026` gets the management UI injected, while a client visiting the exact same URL gets the download UI.

## 6. Component Architecture & UI Rules (React)
* **Mobile-Friendliness (No Hover-Only):** The visibility of interaction elements (e.g., Edit/Delete buttons on cards or sidebars) MUST NOT be hidden via CSS hover states (`group-hover:opacity-100`). Buttons must be directly visible and usable on touch devices.
* **Form UI Rules:** All informational descriptions and helper texts MUST be placed strictly BELOW the corresponding input field, not above or beside it.
* **Keep Components Small & Focused:** Components should rarely exceed 150 lines. Extract Modals and complex forms into distinct `.tsx` components.
* **Domain Separation:** Do not mix completely different business domains in one view.
* **No alert() Dialogs:** Error messages and success feedback MUST NEVER be displayed using the browser's native `alert()` function. They must be beautifully integrated into the UI using inline alerts or DaisyUI Toast components.
* **Type Reusability (DRY):** Always extract and reuse TypeScript interfaces/types from the logic layer (e.g., `useGalleries.ts`) in UI components. Do not redefine types inline in component props or map functions (e.g., avoid `(g: { id: number... }) =>`).
* **Graceful Degradation & Error Boundaries:** Wrap distinct UI sections in React Error Boundaries (`ErrorBoundary`) so localized bugs or malformed API responses don't crash the entire app.

## 7. Local Development & Storage Paths
* No local `.env` dependency for development; overrides are in config files mirroring `docker-compose.local.yml`.
* Use `clean_dev_storage.mjs` to clear: `photos/`, `ftp/`, `backend/storage/app/private/temp/`, `backend/storage/app/private/watermark_master_*.png`, and `backend/storage/framework/cache/data/`.

## 8. Authentication & Token Management
* **Silent Token Refresh Pattern:** The frontend uses an Axios-like interceptor pattern in `api.ts` (`fetcher` and `apiMutate`). If an API request returns a `401 Unauthorized`, the request is paused, a silent background call to `/api/auth/refresh` is made to obtain a new JWT via `HttpOnly` cookie, and the original request is retried. A promise-lock (`isRefreshing`) prevents concurrent refresh spamming.
* **JWT Storage (Security First):** The JWT is stored exclusively in secure, `HttpOnly` cookies (`rp_jwt`). `localStorage` is strictly avoided to eliminate XSS (Cross-Site Scripting) vulnerabilities regarding token theft. All frontend API calls must explicitly include `credentials: 'include'` to pass the cookie.