import {expect, FrameLocator, Locator, Page} from '@playwright/test';
import {CreditCard} from './CreditCardHelper';

export interface ResolvedStripeFrames {
    stripeFrame: FrameLocator;
    cardNumberFrame: FrameLocator;
    expiryFrame: FrameLocator;
    cvcFrame: FrameLocator;
}

export class StripeHelper {
    static async resolveStripeIframes(page: Page): Promise<ResolvedStripeFrames> {
        const stripeFrame = page.frameLocator(
            'iframe[title*="payment" i], iframe[title*="secure" i], iframe[title*="sichere" i]'
        ).first();

        const cardInput = stripeFrame.locator(
            'input[autocomplete="cc-number"], input[name="cardnumber"], input[name="number"]'
        ).first();

        try {
            await cardInput.waitFor({state: 'visible', timeout: 5000});
        } catch {
            const cardTab = stripeFrame
                .getByRole('tab', {name: /Card|Kreditkarte|Karte/i})
                .or(stripeFrame.getByRole('button', {name: /Card|Kreditkarte|Karte/i}))
                .first();
            try {
                await cardTab.waitFor({state: 'visible', timeout: 5000});
                await cardTab.click();
                await cardInput.waitFor({state: 'visible', timeout: 10000});
            } catch {
                // No tab fallback needed — input may already be visible
            }
        }

        // Desktop: Stripe rendert Card-Input in einem separaten, dedizierten Iframe
        const cardNumberFrame = page.frameLocator(
            'iframe[title*="card number" i], iframe[title*="kartennummer" i]'
        ).first();

        let resolvedCardInput: Locator;
        try {
            resolvedCardInput = cardNumberFrame.locator('input').first();
            await resolvedCardInput.waitFor({state: 'visible', timeout: 5000});
        } catch {
            resolvedCardInput = stripeFrame.locator(
                'input[autocomplete="cc-number"], input[name="cardnumber"], input[name="number"]'
            ).first();
        }

        await expect(resolvedCardInput).toBeVisible({timeout: 30000});

        const expiryFrame = page.frameLocator(
            'iframe[title*="expiration date" i], iframe[title*="expiry" i], iframe[title*="expiration" i], iframe[title*="ablauf" i]'
        ).first();

        const cvcFrame = page.frameLocator(
            'iframe[title*="security code" i], iframe[title*="cvc" i], iframe[title*="sicherheitscode" i]'
        ).first();

        return {stripeFrame, cardNumberFrame, expiryFrame, cvcFrame};
    }

    static async fillStripeForm(
        page: Page,
        card: CreditCard,
        frames?: ResolvedStripeFrames
    ): Promise<void> {
        const {stripeFrame, cardNumberFrame, expiryFrame, cvcFrame} = frames ?? await StripeHelper.resolveStripeIframes(page);

        await StripeHelper.fillInputInFrame(cardNumberFrame, stripeFrame, card.number, 'input[autocomplete="cc-number"], input[name="cardnumber"], input[name="number"]');
        await StripeHelper.fillInputInFrame(expiryFrame, stripeFrame, card.exp, 'input[autocomplete="cc-exp"], input[name="exp-date"], input[name="expiry"]');
        await StripeHelper.fillInputInFrame(cvcFrame, stripeFrame, card.cvc, 'input[autocomplete="cc-csc"], input[name="cvc"]');

        // Anti-Flakiness: Blur erzwingt Stripe-interne Validierung
        await page.locator('body').blur().catch(() => {
        });
    }

    private static async fillInputInFrame(
        dedicatedFrame: FrameLocator,
        fallbackFrame: FrameLocator,
        value: string,
        fallbackSelector: string
    ): Promise<void> {
        const dedicatedInput = dedicatedFrame.locator('input').first();
        try {
            await dedicatedInput.waitFor({state: 'visible', timeout: 3000});
            await dedicatedInput.fill(value);
        } catch {
            const fallbackInput = fallbackFrame.locator(fallbackSelector).first();
            await fallbackInput.fill(value);
        }
    }
}
