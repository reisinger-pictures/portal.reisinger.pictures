---
domain: technical
topic: post-mortem-gallery-bugs
status: active
---

# Post-Mortem: Gallery Tree & Breadcrumb Bugs (March 2026)

## 1. Issue: HTTP 500 "Gallery not found" on nested galleries
* **Root Cause:** In the `GalleryFrontendController`, generating breadcrumbs for nested galleries threw a fatal error because the `GalleryGroup` model was called without the proper namespace (`AppModelsGalleryGroup::find` instead of `\App\Models\GalleryGroup::find`).
* **Fix:** Corrected the namespace.
* **Prevention:** Added PHPUnit test `test_guest_can_view_public_gallery_with_breadcrumbs_without_500_error` to strictly assert HTTP 200 on deep links.

## 2. Issue: Wrong Edit Dialog opened for nested galleries
* **Root Cause:** In `ManagementStructureView.tsx`, the `TreeNode` component used a single `onEdit` prop. When clicking "Edit" on a Gallery node, the parent Group's edit handler was accidentally triggered or inherited.
* **Fix:** Split props into `onEditGroup` and `onEditGallery` and bound them explicitly to the respective node types.
* **Prevention:** Added Playwright E2E test verifying the `h3` title of the modal when editing a nested gallery.

## 3. Issue: Missing Inline Add-Buttons & Prefill
* **Root Cause:** UX friction. Users had to open the global "New Gallery" modal and manually search for the target folder in a large dropdown.
* **Fix:** Added inline `+` buttons (Folder/Gallery) directly to the `TreeNode` summary. Passed the `groupId` down to the modals to initialize the dropdown (`defaultGroupId`).
* **Prevention:** Playwright E2E test clicks the inline button and asserts that the selected value in the dropdown is not empty (contains the UUID of the parent).

## 4. Issue: Cannot type "-" at the end of the slug
* **Root Cause:** The `toSlug` utility function applied `.replace(/(^-|-$)/g, '')` aggressively on every keystroke (onChange). This instantly stripped trailing dashes, physically preventing the user from typing them.
* **Fix:** Changed the regex to `.replace(/^-+/, '')` so trailing dashes are preserved during typing. 
* **Prevention:** Playwright E2E test explicitly fills a slug with a trailing dash and asserts that the input holds the exact value.
