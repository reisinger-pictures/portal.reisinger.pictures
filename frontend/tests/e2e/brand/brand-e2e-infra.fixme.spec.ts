import { test, expect } from '@playwright/test';

/**
 * DOCUMENTED GAPS — intentionally skipped. These specs describe the brand-leak scenarios that
 * need infrastructure we do not have on the single dev Vite instance. They are recorded here so
 * the gap is visible in the test report (status: fixme) rather than silently missing.
 *
 * Prerequisite to enable both: a 2-Vite-instance dev setup where one instance proxies to the
 * backend with an SRP host (e.g. http://localhost:4322 -> Referer/host 'portal-srp.test' /
 * 'story.reisinger.pictures') and the other stays B2B (http://localhost:4321). Today playwright.config.ts
 * pins baseURL to http://localhost:4321, so BrandContextMiddleware always resolves B2B.
 *
 * Run (they will show as fixme/skipped, which is the point):
 *   node ai_test_runner.mjs brand/brand-e2e-infra.fixme.spec.ts
 */

test.describe('Brand E2E — infra prerequisites (Gaps 4 & 5)', () => {

    // GAP 4: true cross-host invoice bank-detail leak.
    test.fixme('SRP order invoice served from a B2B host still shows the SRP IBAN (B-01 F2)', async () => {
        // PREREQUISITE: 2-Vite setup (ports 4321 B2B / 4322 SRP) + a brand-seeding test helper.
        // The management API (StoreUserRequest / UpdateUserRequest / StoreGalleryRequest) does not
        // expose `brand`, so an SRP-branded order + invoice snapshot cannot be provisioned through
        // E2ESessionHelper today. Brand is only set via PHPUnit factories
        // (backend/tests/Feature/BrandLeakTest.php).
        //
        // WHAT THIS WOULD ASSERT once infra exists:
        //  1. Seed SRP bank settings (srp_bank_iban) and B2B bank settings (bank_iban) distinctly.
        //  2. Create an SRP-branded order + InvoiceSnapshot for an isolated user.
        //  3. From the B2B instance (port 4321), hit /api/orders/{id}/invoice and assert the PDF
        //     text contains the SRP IBAN and NOT the B2B IBAN.
        //  4. Inverse: a B2B order rendered via the SRP host must contain the B2B IBAN only.
        expect(true).toBeTruthy();
    });

    // GAP 5: PDF content-level IBAN assertion (needs a PDF text-extraction dependency).
    test.fixme('invoice PDF binary contains the order-brand IBAN string', async () => {
        // PREREQUISITE: a Playwright-friendly PDF text extractor (e.g. pdf-parse) added to the
        // frontend dev deps, plus the 2-Vite + brand-seeding infra from Gap 4.
        //
        // WHAT THIS WOULD ASSERT:
        //  Download the invoice via page.waitForEvent('download') (pattern from
        //  admin/manual-documents.spec.ts), read the saved PDF through the extractor, and assert
        //  the expected brand's IBAN substring is present and the other brand's IBAN is absent.
        expect(true).toBeTruthy();
    });
});
