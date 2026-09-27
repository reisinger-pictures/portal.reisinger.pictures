import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';

/**
 * Regression pin for the mobile brand lockup in the dashboard header.
 *
 * The lockup (logo + portal name, linking home) used to carry `truncate` on
 * the name span, so a phone rendered "Reisinger Foto Portal" as
 * "Reisinger Fot…". The Vitest spec on `MobileBrandLink` can only inspect the
 * class list — jsdom applies no CSS, so it cannot observe actual clipping.
 * This spec measures the rendered box instead:
 *
 * A truncated element is `overflow: hidden` + `white-space: nowrap`, so its
 * scrollWidth exceeds its clientWidth. A wrapping element reports
 * scrollWidth === clientWidth. The assertion below compares the two with 1px
 * tolerance for subpixel rounding; it deliberately does NOT assert the brand
 * string (the name is configuration — the clipping is the defect).
 *
 * The lockup is `md:hidden`, so it only exists on the mobile viewport; the
 * desktop project is skipped (see `test.info().project.name`).
 */
test.describe('Mobile Brand-Lockup im Dashboard-Header', () => {
    let helper: E2ESessionHelper;

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('zeigt den vollständigen Portalnamen auf dem Handy ungekürzt an', { tag: ['@regression', '@mobile'] }, async ({ page, request }) => {
        test.skip(test.info().project.name !== 'Mobile Chrome', 'Das Mobile-Lockup ist md:hidden und existiert am Desktop nicht');

        helper = new E2ESessionHelper(request);
        const photogUser = await helper.createIsolatedUser('photographer');
        const auth = new AuthHelper(page);
        await auth.login(photogUser.email, photogUser.password);

        // Landmark-scoped: the dashboard header holds the lockup; the sidebar
        // carries a second logo/name pair that must not satisfy this locator.
        const header = page.locator('header').first();
        await expect(header).toBeVisible();

        const brandLink = header
            .getByRole('link')
            .filter({ has: page.getByRole('img', { name: 'Logo' }) })
            .first();
        await expect(brandLink).toBeVisible();

        // The name span is the element that used to be clipped (`max-w-28`).
        const brandName = brandLink.locator('span').first();
        await expect(brandName).toBeVisible();

        // SCOPE OF THIS GUARD — read before trusting it:
        // - Measured: the name span itself, on BOTH axes. Horizontal clipping
        //   (`truncate`/`text-ellipsis`) and vertical clipping (`line-clamp-1`,
        //   or a fixed height + `overflow-hidden`) of the span are caught.
        // - NOT measured: clipping applied to an ANCESTOR (`<Link>`, the header
        //   wrapper). The span's own scrollWidth/scrollHeight still equal its
        //   client box even when an ancestor clips it.
        // - Fragile locator: `brandLink.locator('span').first()` — a future
        //   sibling span inserted before the name would make this measure the
        //   wrong node.
        // - For those two cases the complementary guard is the (cheaper, faster)
        //   Vitest class-list pin in MobileBrandLink.test.tsx.
        const { clientWidth, scrollWidth, clientHeight, scrollHeight } = await brandName.evaluate((element: HTMLElement) => ({
            clientWidth: element.clientWidth,
            scrollWidth: element.scrollWidth,
            clientHeight: element.clientHeight,
            scrollHeight: element.scrollHeight,
        }));

        // Sanity guard against a vacuous pass: an inline box reports 0/0, which
        // would make the overflow comparison meaningless. As a flex item the
        // span is blockified and has a real measurable width.
        expect(clientWidth).toBeGreaterThan(0);
        // scrollWidth > clientWidth means the text overflows its own box — a
        // `truncate`d span does, a wrapping span does not.
        expect(scrollWidth).toBeLessThanOrEqual(clientWidth + 1);
        // Same comparison on the vertical axis: `line-clamp-1` (or a fixed
        // height + `overflow-hidden`) keeps scrollWidth === clientWidth but
        // grows scrollHeight past clientHeight, so this catches what the
        // horizontal assertion above cannot.
        expect(scrollHeight, 'vertical clipping: the name span scrollHeight exceeds its clientHeight').toBeLessThanOrEqual(clientHeight + 1);
    });
});
