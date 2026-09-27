import { Page, expect } from '@playwright/test';
import { NetworkHelper } from './NetworkHelper';

export class AuthHelper {
    private network: NetworkHelper;

    constructor(private page: Page) {
        this.network = new NetworkHelper(page);
    }

    async login(email = 'admin@example.com', password = 'admin', loginUrl?: string) {
        await this.page.goto(loginUrl ?? '/');

        // "The boot is finished", asserted as the ABSENCE of both loaders.
        //
        // The previous guard waited on `app-loader` alone and could never fail.
        // `app-loader` is rendered *inside* the lazy route (ProtectedRoute /
        // ProtectedDashboard), so while the ProtectedDashboard chunk is being
        // fetched it does not exist at all — measured count 0, and `toBeHidden`
        // passes instantly on an absent element. Measured signature with the
        // chunk gated: guard passed in 4ms, `getByRole('main')` count 0. The
        // loader wait therefore consumed none of its 15s budget and the `main`
        // wait below burned all of its own and failed with "element(s) not
        // found" — the load-dependent @smoke failure, with the real cause (the
        // route chunk was still in flight) named nowhere in the report.
        //
        // `app-loader-fallback` (App.tsx SuspenseFallback) is the element that
        // *is* on screen for that entire window, so asserting its absence is
        // what gives this wait something to observe. Both ids are required:
        // neither loader present means the boot is over, and the two waits
        // carry separate 15s budgets so a failure names its cause instead of
        // collapsing into one undifferentiated "main not found". Order is
        // sequential boot order (Suspense fallback, then auth loader), so the
        // first wait absorbs the chunk fetch: failing there means the chunk
        // never resolved, failing on the second means it resolved but auth
        // never did.
        //
        // Honest limit: this makes the wait able to fail and makes the two
        // budgets separately attributable. It does NOT make the suite survive
        // extreme CPU starvation — under a whole-machine stall the `main`
        // budget below is still the one that expires, and no timeout was raised
        // to paper over that, because a longer budget would hide the defect
        // rather than report it.
        await expect(this.page.getByTestId('app-loader-fallback')).toHaveCount(0, { timeout: 15000 });
        await expect(this.page.getByTestId('app-loader')).toHaveCount(0, { timeout: 15000 });
        await expect(this.page.getByRole('main').first()).toBeVisible({ timeout: 15000 });

        const sidebar = this.page.getByRole('complementary');
        const emailInput = sidebar.getByRole('textbox', { name: 'E-Mail Adresse' }).first();
        const passwordInput = sidebar.getByLabel('Passwort', { exact: true }).first();

        if (await emailInput.isVisible()) {
            // Drawer state is read from `aria-expanded` on the trigger — the
            // contract all three headers set, and the one SidebarHelper
            // already uses. It is deliberately NOT read from a `fixed inset-0`
            // scrim: no such element exists in this app. `DashboardLayout`
            // renders the drawer as `fixed inset-y-0 left-0` with no overlay
            // behind it, so `div.fixed.inset-0` matched nothing and the
            // `toBeVisible()` on it was unsatisfiable by construction — it
            // could only ever be skipped, never passed. Which of the two
            // happened depended purely on whether the trigger happened to be
            // visible when the check ran, which is why this surfaced as a
            // load-dependent flake rather than a hard failure. Same reasoning
            // as SidebarHelper.navigateTo().
            const menuBtn = this.page.getByRole('button', { name: 'Menü öffnen' }).first();
            if (await menuBtn.isVisible()) {
                await expect(async () => {
                    if (await menuBtn.isVisible() && (await menuBtn.getAttribute('aria-expanded')) !== 'true') {
                        await menuBtn.click();
                    }
                    await expect(menuBtn).toHaveAttribute('aria-expanded', 'true', { timeout: 2000 });
                }).toPass({ timeout: 10000 });
            }

            await emailInput.fill(email);
            await passwordInput.fill(password);

            const loginPromise = this.network.waitForLogin();
            const mePromise = this.network.waitForMe();

            await sidebar.getByRole('button', { name: 'Login', exact: true }).first().scrollIntoViewIfNeeded();
            await this.page.keyboard.press('Enter');
            await loginPromise;
            await mePromise;

            await expect(emailInput).toBeHidden({ timeout: 15000 });
        }
        // No drawer dismissal is needed: a successful login swaps
        // ProtectedDashboard for ManagementDashboard/ClientDashboard, each of
        // which owns a fresh `isSidebarOpen = useState(false)`, so the drawer
        // unmounts closed. When the form was absent the drawer was never
        // opened. SidebarHelper.navigateTo() re-opens it on demand.
    }

    async logout(logoutUrl?: string) {
        await this.page.context().clearCookies();
        await this.page.goto(logoutUrl ?? '/');
        // "The app has resolved who the user is" is the only precondition
        // logout() actually needs, and `app-loader` is the element that
        // declares it: ProtectedDashboard and ProtectedRoute both render it
        // *while* `useAuth().isLoading` and unmount it on the next render.
        //
        // The obvious-looking alternative, `.loading-spinner.loading-lg`, is not
        // a weaker spelling of the same signal — it is a different signal. 41
        // call sites in src/ render that exact class string, and on the guest
        // landing page three of them are mounted in sequence:
        //
        //   1. App.tsx `SuspenseFallback` (data-testid="app-loader-fallback")
        //      while the lazy ProtectedDashboard chunk is still being fetched by
        //      the Vite dev server,
        //   2. `app-loader` while /api/auth/me is in flight,
        //   3. SearchView's feed spinner — ProtectedDashboard's guest fallback is
        //      `if (!user) return <SearchView/>`, so the moment auth resolves
        //      `app-loader` unmounts and a `.loading-spinner.loading-lg` with the
        //      *same* classes takes its place at the same DOM position.
        //
        // `.first()` is re-evaluated on every poll, so a `toBeHidden` that
        // started on the app-loader silently re-binds at step 3 and from then
        // on waits for the public search feed — a request whose latency
        // logout() neither causes nor cares about. Measured on Mobile Chrome,
        // that assertion spent a p50 of ~4.3s of its own 5s budget (max
        // observed 4988ms of 5000ms) purely tailgating `/api/search`, which is
        // the whole flake: under load that tail crosses the budget. Scoping to
        // the testid cuts the same wait to a p50 of ~0.5s and makes binding to
        // an unrelated spinner structurally impossible.
        await expect(this.page.getByTestId('app-loader').first()).toBeHidden({ timeout: 5000 });

        // The guest login form is the signal that actually means "logged out".
        // `Sidebar` renders `SidebarLoginForm` only when `!user`, and the
        // authenticated-only "Abmelden" button only when `user`, so this pair is
        // a complete statement about the session rather than a proxy for it.
        //
        // `toBeAttached`, not `toBeVisible`: below the `md` breakpoint the
        // sidebar is `fixed … -translate-x-full`, so the form is in the DOM but
        // parked off-canvas (measured bounding box on Galaxy A55: x = -456 on a
        // 480px viewport). Playwright counts off-screen-but-rendered as
        // visible, so `toBeVisible` would pass for the wrong reason.
        const sidebar = this.page.getByRole('complementary');
        await expect(sidebar.getByRole('textbox', { name: 'E-Mail Adresse' }).first())
            .toBeAttached({ timeout: 5000 });
        await expect(sidebar.getByRole('button', { name: 'Abmelden' })).toHaveCount(0);
    }
}
