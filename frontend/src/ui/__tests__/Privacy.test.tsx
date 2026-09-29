import { describe, it, expect, vi } from 'vitest';
import { screen } from '@testing-library/react';
import { renderWithProviders } from '../../test-setup';
import { MemoryRouter } from 'react-router-dom';
import Privacy from '../Privacy';

vi.mock('../components/PageLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => (
        <div data-testid="page-layout">{children}</div>
    ),
}));

const renderPrivacy = () => renderWithProviders(
    <MemoryRouter>
        <Privacy />
    </MemoryRouter>,
);

describe('Privacy', () => {
    // D-16 splits this page in two: the technical description of the stored
    // identifiers is complete and documented here, the legal layer (legal
    // ground, concrete retention, deletion/anonymization rules) is a named gap
    // awaiting DPO/owner approval. The first test pins the half that is
    // supposed to be written; the third pins the half that is not.
    it('names every identifier class the checkout abuse defense stores', () => {
        renderPrivacy();

        for (const identifier of [
            'Stripe Customer-ID',
            'PaymentIntent-ID',
            'Checkout-Fingerprint-Hash',
            'IP-Risikoschlüssel',
            'Decline-Code',
            'Sicherheitsprozessor',
        ]) {
            expect(
                screen.getAllByText(new RegExp(identifier)).length,
                `identifier class "${identifier}" is not disclosed in the privacy notice`
            ).toBeGreaterThan(0);
        }
    });

    it('states how the defense stores, derives and bounds its signals', () => {
        const { container } = renderPrivacy();
        const text = container.textContent ?? '';

        // The IP risk key is a keyed one-way hash; the limiter never sees a raw IP.
        expect(text).toContain('schlüsselabhängiger, nicht umkehrbarer Hash');
        // The Stripe Customer is created lazily and survives a portal account
        // deletion on Stripe's side, so a reader is told about the mapping.
        expect(text).toContain('erst mit der ersten zahlungspflichtigen Bestellung');
        expect(text).toContain('nicht automatisch im Stripe-Dashboard gelöscht');
        // The account-age/attempt gates read the account creation date and the
        // user ID; that was undocumented before and is the reason a reader can
        // connect the quota with its own account.
        expect(text).toContain('das Erstellungsdatum Ihres Portalkontos');
        // Only the signed, identity-matched webhook writes the bounded telemetry.
        expect(text).toContain('ausschließlich das signierte und eindeutig zuordenbare Zahlungsereignis');
        // The checkout session entry is bound to the signed-in account.
        expect(text).toContain('Der Eintrag ist dem angemeldeten Portalkonto zugeordnet');
        // Neither token, nor Siteverify response, nor the outcome is kept.
        expect(text).toContain('Prüfergebnis selbst werden im Portal gespeichert');
    });

    it('keeps the legal layer a named gap instead of inventing it', () => {
        const { container } = renderPrivacy();
        const text = container.textContent ?? '';

        expect(text).toContain('sind noch nicht abschließend festgelegt');
        expect(text).toContain('der Betreiber teilt sie Ihnen auf Anfrage');
        // A concrete retention period or a cited DSGVO article is the owner/DPO
        // decision this page must not make on its own. Adding one turns this
        // test red, which is the point: it forces that decision to be made.
        expect(text).not.toMatch(
            /\d+\s*(Tag|Tage|Tagen|Woche|Wochen|Monat|Monate|Monaten|Jahr|Jahre|Jahren)\b/
        );
        expect(text).not.toMatch(/(Art\.|§)\s*\d+\s*DSGVO/);
    });
});
