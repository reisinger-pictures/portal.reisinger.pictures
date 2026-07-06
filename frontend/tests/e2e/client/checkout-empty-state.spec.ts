import {test, expect} from '@playwright/test';
import {AuthHelper} from '../helpers/AuthHelper';
import {E2ESessionHelper} from '../helpers/E2ESessionHelper';
import {SidebarHelper} from '../helpers/SidebarHelper';

// E2E-01 §1 (Empty-Cart-UX, echt) + §5 (Accessibility-Stichprobe).
// Grounded in `src/ui/client/ClientCartView.tsx`.
test.describe('Empty Cart — UI & Accessibility', () => {
    let helper: E2ESessionHelper;
    let clientUser = {email: '', password: '', id: ''};

    test.beforeEach(async ({request}) => {
        helper = new E2ESessionHelper(request);
        clientUser = await helper.createIsolatedUser('client');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('Empty cart renders empty-state UI with an accessible home link', { tags: ['@feature:client:cart'] }, async ({page}) => {
        const auth = new AuthHelper(page);
        await auth.login(clientUser.email, clientUser.password);

        const sidebar = new SidebarHelper(page);
        await sidebar.navigateTo('Warenkorb');
        await expect(page).toHaveURL(/.*\/cart/);
        await expect(page.getByRole('heading', {name: 'Dein Warenkorb'})).toBeVisible();

        // Empty-State: keine Items → Hinweis statt Checkout-Formular.
        await expect(page.getByText('Dein Warenkorb ist leer.')).toBeVisible();

        // Accessibility-Stichprobe (§5): primärer CTA des Empty-States hat einen zugänglichen Namen.
        const homeLink = page.getByRole('link', {name: 'Zurück zur Startseite'});
        await expect(homeLink).toBeVisible();
        await expect(homeLink).toHaveAccessibleName('Zurück zur Startseite');

        // Navigation funktioniert aus dem Empty-State heraus.
        await homeLink.click();
        await expect(page).toHaveURL(/localhost:4321\/?$/);
    });
});
