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
            'Checkout-Fingerprint-Hash',
            'IP-Risikoschlüssel',
            'Payment-ID',
            'Zahlungsstatus',
            'Sicherheitsprozessor',
        ]) {
            expect(
                screen.getAllByText(new RegExp(identifier)).length,
                `identifier class "${identifier}" is not disclosed in the privacy notice`
            ).toBeGreaterThan(0);
        }
    });

    it('names Mollie as the provider of new online payments and what the portal sends', () => {
        const { container } = renderPrivacy();
        const text = container.textContent ?? '';

        // The provider of a new online payment, named with its legal entity.
        expect(text).toContain('Mollie B.V.');
        // The transmitted data is an exhaustive list, not a vague "payment data".
        for (const sent of [
            'Zahlungsbetrag',
            'Währung',
            'Bestell- und Vorgangsreferenz',
            'gewählte Zahlungsart',
            'Rücksprung-',
            'Webhook-Adresse',
        ]) {
            expect(text, `the Mollie data transfer does not name "${sent}"`).toContain(sent);
        }
        // The two references are named precisely enough that a reader can tell
        // they are identifiers and carry no personal data (review #58, point 9).
        expect(text).toContain('die Kennung der Bestellung');
        expect(text).toContain('die laufende Nummer des Zahlungsversuchs');
        expect(text).toContain('sie enthalten weder Name noch Adresse, Kartendaten oder Rechnungsdaten');
        // The provider-side payment id is stored and disclosed as such.
        expect(text).toContain('die von Mollie vergebene Zahlungs-ID (Payment-ID)');
        expect(text).toContain('Diese Kennung ist eine Zahlungs- und Kontrollinformation, keine Kartendaten');
        // The customer leaves the portal, so no card or billing data is forwarded.
        expect(text).toContain('gehostete Checkout-Seite');
        expect(text).toContain('nicht über das Portal an Mollie übermittelt');
        // paybybank/eps are approved at the customer's bank.
        expect(text).toContain('erfolgt die Freigabe bei der Bank des Kunden');
        // Mollie is the only payment provider; no other provider is named.
        expect(text).not.toMatch(/Stripe/i);
    });

    it('states how the defense stores, derives and bounds its signals', () => {
        const { container } = renderPrivacy();
        const text = container.textContent ?? '';

        // The IP risk key is a keyed one-way hash; the limiter never sees a raw IP.
        expect(text).toContain('schlüsselabhängiger, nicht umkehrbarer Hash');
        // Payments before the switch are described in the past tense and without
        // any further data transfer.
        expect(text).toContain('vor der Umstellung auf Mollie');
        expect(text).toContain('keine Daten mehr übermittelt');
        // Every payment attempt is disclosed with its bounded counter and its
        // provider status, processed once per provider payment id.
        expect(text).toContain('die laufende Nummer des Versuchs');
        expect(text).toContain('Die Anzahl der Versuche je Bestellung ist begrenzt');
        expect(text).toContain('den von Mollie gemeldeten Zahlungsstatus');
        // The checkout fingerprint is server-derived and not cross-site tracking.
        expect(text).toContain('serverseitig aus dem kanonisierten Checkout-Kontext');
        expect(text).toContain('kein Browser-Fingerprint');
        // The account-age/attempt gates read the account creation date and the
        // user ID; that was undocumented before and is the reason a reader can
        // connect the quota with its own account.
        expect(text).toContain('das Erstellungsdatum Ihres Portalkontos');
        // Neither provider payload nor card data is kept in the portal.
        expect(text).toContain('keinen vollständigen Zahlungsdatensatz des Anbieters');
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
