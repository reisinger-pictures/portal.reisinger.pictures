import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';

/**
 * D-9 — the sidebar's portal name must WRAP, not run past the edge.
 *
 * The name span carried `whitespace-nowrap` with no truncation mechanism behind
 * it, so a longer brand name simply overflowed the sidebar instead of being
 * shortened. Truncation was the rejected remedy (a silently clipped name is
 * worse than one that breaks across two lines), so the fix is a wrap — and only
 * a rendered-browser measurement can tell the two apart.
 *
 * WHY THE BRAND CONFIG IS STUBBED: the deployed name is "Reisinger Foto Portal",
 * which fits the 18rem sidebar on one line. A test that only ever renders the
 * real name therefore measures nothing — it would pass with the defect present.
 * `portal_name` is brand configuration served by `GET /api/settings/brand-config`,
 * so a long name is produced by stubbing that one field, with the real login and
 * the real navigation around it. `page.route` on a read endpoint is not the
 * forbidden localStorage injection: nothing is written into a client-side store,
 * the response itself is replaced.
 *
 * WHY NOT scrollWidth-vs-clientWidth, THE MEASUREMENT THE MOBILE SPEC USES:
 * measured, and it does not work here. With the defect, the name span reports
 * scrollWidth 470 === clientWidth 470 — the element GREW to fit its text, so its
 * own box is not clipped and the usual overflow metric is blind to the defect.
 * The overflow lands one level up, on the brand row (scrollWidth 514 vs clientWidth
 * 271). `mobile-brand-lockup.spec.ts` names this exact blind spot in its own
 * comment ("NOT measured: clipping applied to an ANCESTOR"); the sidebar is that
 * case, so this spec measures the geometry directly instead — the name's right
 * edge against the edge of the box that is supposed to contain it.
 */
test.describe('Sidebar portal name (D-9)', () => {
    let helper: E2ESessionHelper;

    // A single unbroken token is the harshest case: there is no space to break
    // at, so the name only fits by breaking the word itself. That makes the
    // assertion non-vacuous — one line of this string is 470px in an 18rem
    // sidebar, so it cannot possibly pass unwrapped.
    const LONG_NAME = 'Reisinger-Fotostudio-Galerienportal-International';

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('bricht einen langen Portalnamen in der Sidebar um, statt ihn überlaufen zu lassen', { tag: ['@regression', '@feature:sidebar'] }, async ({ page, request }) => {
        test.skip(test.info().project.name !== 'Desktop Chrome', 'Die Sidebar ist am Desktop md:relative; am Handy liegt sie hinter dem Menü-Button');

        helper = new E2ESessionHelper(request);
        const photogUser = await helper.createIsolatedUser('photographer');

        await page.route('**/api/settings/brand-config', async route => {
            const response = await route.fetch();
            const config = (await response.json()) as Record<string, unknown>;
            await route.fulfill({ json: { ...config, portal_name: LONG_NAME } });
        });

        const auth = new AuthHelper(page);
        await auth.login(photogUser.email, photogUser.password);

        // Landmark-scoped: the dashboard header carries its own lockup
        // (MobileBrandLink, covered by its own spec), and this assertion is about
        // the sidebar. Scoping to `aside` keeps the two apart.
        const sidebar = page.getByRole('complementary');
        await expect(sidebar).toBeVisible();

        const brandLink = sidebar
            .getByRole('link')
            .filter({ has: page.getByRole('img', { name: 'Logo' }) })
            .first();
        await expect(brandLink).toBeVisible();

        // The stub has to have taken effect, or the measurement below is
        // vacuous — a short name fits on one line and overflows nothing.
        const name = brandLink.getByText(LONG_NAME, { exact: true });
        await expect(name).toBeVisible();

        const geometry = await name.evaluate((element: HTMLElement) => {
            const link = element.closest('a') as HTMLElement;
            const row = link.parentElement as HTMLElement;
            const edge = (e: HTMLElement) => e.getBoundingClientRect().right;
            return {
                nameRight: edge(element),
                linkRight: edge(link),
                asideRight: edge(element.closest('aside') as HTMLElement),
                rowScrollWidth: row.scrollWidth,
                rowClientWidth: row.clientWidth,
                nameHeight: element.getBoundingClientRect().height,
            };
        });

        // 1px tolerance for subpixel rounding, as in the mobile spec.
        expect(
            geometry.nameRight,
            'the portal name runs past the brand link and out of the sidebar',
        ).toBeLessThanOrEqual(geometry.linkRight + 1);
        // The same claim measured against the sidebar itself rather than the
        // link — the user-visible edge, and the one the D-9 text names.
        expect(
            geometry.nameRight,
            'the portal name runs past the sidebar edge',
        ).toBeLessThanOrEqual(geometry.asideRight + 1);
        // The ancestor-level overflow the span's own box cannot report. This is
        // the check the scrollWidth-vs-clientWidth idiom misses, measured here on
        // the brand row that actually clips.
        expect(
            geometry.rowScrollWidth,
            'the brand row overflows: the name was not allowed to wrap',
        ).toBeLessThanOrEqual(geometry.rowClientWidth + 1);
        // And it really did wrap rather than merely fit: one line of this name
        // is 470px wide, so a contained name has to occupy more than one line.
        // Without this the first three assertions could pass on a short name.
        expect(geometry.nameHeight).toBeGreaterThan(40);
    });
});
