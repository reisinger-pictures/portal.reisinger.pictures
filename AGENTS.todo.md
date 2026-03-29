# 📝 Backlog / Next Sprint

## Technical Debt & Refactoring
- [ ] **Frontend:** Refactor `GalleryModals.tsx`. Split into `GalleryModal.tsx` and `GalleryGroupModal.tsx`.
- [ ] **Frontend:** Evaluate a form library (e.g., `react-hook-form` + `zod`) to eliminate `useState` boilerplate and improve validation.
- [ ] **Frontend (God-Component):** Break down `ManagementGalleryView.tsx`. Extract upload dropzone, PhotoSwipe grid, and modals into separate components.
- [ ] **Frontend (God-Component):** Decouple `SelectionView.tsx`. Move PhotoSwipe logic to a custom hook or wrapper.
- [ ] **Frontend (Workaround Removal):** Clean up `useAuth.ts`. Remove direct `window` object mutations (`(window as any).__loggedUserId`).
- [ ] **E2E-Tests:** Remove static timeouts (`waitForTimeout`) in `AuthHelper.ts`, `SidebarHelper.ts`, and `client.spec.ts`. Replace with patient asynchronous assertions.
- [ ] **Frontend (Architecture):** Simplify `PageLayout.tsx`. Extract the global search header into `GlobalSearchHeader.tsx`.
- [ ] **Frontend (Duplication):** Extract PhotoSwipe initialization logic. Currently duplicated across `ManagementGalleryView.tsx`, `ManagementMetaGalleryView.tsx`, `DeliveryView.tsx`, and `SelectionView.tsx`.
- [ ] **Backend (Authorization Duplication):** Refactor `$user->is_admin || $user->canAccessGallery($id)` checks. Move this repeated logic into Laravel Policies or FormRequests.
- [ ] **Backend (Duplication):** Refactor thumbnail URL generation (`thumb_url`, `url`, `srcset`). Move the hardcoded string concatenations from controllers to an accessor on the `Photo` model.

## Features & Bugfixes
- [ ] **Backend (Gallery Defaults Sync):** Implement logic in `GalleryController@updateGallery` to retroactively apply changed metadata defaults to existing `Photo` records and trigger a Meilisearch index sync for affected photos.

## QA & Testing
- [ ] **Backend / PHPUnit:** Write tests for retroactive Gallery Default application. Verify that changing gallery defaults updates the DB records of existing photos and correctly syncs them to Scout/Meilisearch.
- [ ] **Backend / PHPUnit:** Verify that injected IPTC metadata during single/ZIP downloads correctly includes the newly applied gallery defaults.
- [ ] **E2E-Tests:** Write Playwright E2E test for the autocomplete function in the single photo detail view (Smart Assistance).
