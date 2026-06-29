---
domain: ecommerce
topic: licensing-and-downloads
status: active
---

# Feature: Licensing, ZIP-Downloads & Pricing UI

## 1. Overview
This feature defines the business logic for image downloads (single images vs. ZIP archives) and the redesign of the license selection process. The goal is transparent pricing, a smoother UX without modals, and clear restrictions based on customer status.

## 2. ZIP-Download Logic & Permissions
* **Permission:** ZIP downloads (Download all images) are system-wide **exclusively** enabled for two scenarios:
    * The customer has an active flat-rate status.
    * The gallery is explicitly marked as a free download gallery (`is_free_download`).
* **Restriction:** In all other cases, the ZIP download is disabled or hidden. Customers must purchase a license for each image individually.
* **Tracking & Statistics:**
    * When a ZIP is downloaded, the gallery's download counter increases by the *number of images contained in the ZIP* (x).
    * In the audit log (`download_logs`), the download is recorded as a *single* entry (`item_type = full_zip`), which contains the number of images (x) as meta-information (`payload`).
* **Technical Behavior:** The download trigger uses `<a target="_blank">` in a new tab to improve E2E testability (Playwright) and avoid blocking the main thread.

## 3. UI/UX Refactoring: License Selection
* **No Modal Anymore:** The dialog ("Choose License") is removed. License selection is integrated directly into the detail view of each image.
* **Dynamic Visibility:** Unavailable (locked) resolutions or license types are completely hidden in the frontend, rather than being displayed as grayed/locked out.
* **Real-Time Price Calculation:** The UI calculates the final price dynamically and displays it directly. The calculation is based on the selected factors (base price * usage type * usage duration * usage frequency).

## 4. Advanced License Options (Migration V004 Update)
The license matrix is extended with the following parameters:
* **Usage Frequency:** Differentiation between *Single Use* and *Repeated Use* (new price multiplier in `license_options`).
* **Custom Quote (Request Quote):** Integration of a quote workflow. Users can request an individual quote for special requirements instead of purchasing immediately (`is_quote_request` in `orders`).
