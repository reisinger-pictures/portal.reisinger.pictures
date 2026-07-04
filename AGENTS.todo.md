# Task Board — Portal Reisinger Pictures

## Session: Search Bar Unification & Duplicate Refactoring

### ✅ Done
- Created shared `SearchBarWithSuggestions.tsx` component
- Replaced search bars in `SearchView.tsx`, `GlobalSearchHeader.tsx`, `ManagementDashboard.tsx`, `ClientDashboard.tsx`
- Created `SearchHelper.ts` E2E test utility
- Updated all E2E tests to use unified placeholder `"Suche in allen Galerien..."`

### 🔲 Duplicate Refactoring Pipeline (prioritized)

#### P0 — GalleryModal / GalleryGroupModal merge
- Extract shared checkbox group (`is_free_download`, `is_editorial_only`, `is_hidden`)
- Extract shared modal dialog shell
- Move `toSlug` to `utils.ts`
- Files: `GalleryModal.tsx` (273 lines), `GalleryGroupModal.tsx` (206 lines)
- Est. savings: ~100–130 lines
- **Tests:** E2E tests for gallery/group create/edit flows

#### P0 — EmptyState component
- Extract shared empty-state UI for photo/gallery grids
- Files: `SelectionView.tsx`, `DeliveryView.tsx`, `ManagementDashboard.tsx`, `ManagementGalleryView.tsx`
- Est. savings: ~20–30 lines
- **Tests:** Visual regression in E2E (snapshot empty states)

#### P0 — Pagination component
- Extract shared pagination UI ("← Zurück" / "Weiter →")
- Files: `ManagementCouponsView.tsx`, `ManagementStatsView.tsx`
- Est. savings: ~15–20 lines
- **Tests:** PHPUnit tests for pagination + E2E

#### P1 — NotificationsOptIn component
- Extract shared notification toggle
- Files: `SelectionView.tsx`, `DeliveryView.tsx`
- Est. savings: ~10–15 lines
- **Tests:** E2E interaction tests

#### P1 — SearchView → use PageLayout
- Replace standalone sidebar+header layout with PageLayout
- Est. savings: ~40–60 lines
- **Tests:** E2E guest search tests must remain green

#### P2 — ManagementPageShell
- Create shared wrapper for management views (loading/error/header/card/table)
- Files: 8 management views
- Est. savings: ~200–320 lines
- **Tests:** PHPUnit + E2E for each management view

#### P2 — formatMoney consistency
- Fix `ManagementCouponsView.tsx` to use shared `formatMoney` from `utils.ts`
- Est. savings: ~5 lines
