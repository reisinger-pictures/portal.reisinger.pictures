import {test, expect} from '@playwright/test';
import path from 'node:path';
import {pathToFileURL} from 'node:url';
import {createRequire} from 'node:module';

const require = createRequire(import.meta.url);
const SRP_REFERER = 'http://buy.localhost:4321/';

// Pfad zu den pdfjs-Standard-Schriftarten (unterdrückt pdf.js-Warnung)
const standardFontDataUrl = pathToFileURL(
    path.join(path.dirname(require.resolve('pdfjs-dist/package.json')), 'standard_fonts'),
) + '/'; // trailing slash required by pdf.js

test.describe('Brand E2E — infra prerequisites (Gaps 4 & 5)', () => {

    test('PDF generated via SRP referer contains SRP-branded content and no B2B leak', async ({request}) => {
        // Gap 4 + 5 combined: API calls go to localhost:4321 (Playwright baseURL)
        // with Referer: http://buy.localhost:4321/. BrandContextMiddleware picks up
        // the SRP brand from the Referer header in local environment.
        // pdf-parse extracts PDF text for content-level brand assertion.

        // 1. Admin login with SRP Referer so cookie carries SRP session
        const loginRes = await request.post('/api/auth/login', {
            data: {email: 'florian@reisinger.pictures', password: 'admin'},
            headers: {'Accept': 'application/json', 'Referer': SRP_REFERER},
        });
        expect(loginRes.ok()).toBeTruthy();
        const adminCookies = loginRes.headers()['set-cookie'] || '';

        const srpHeaders = {
            'Accept': 'application/json',
            'Cookie': adminCookies,
            'Referer': SRP_REFERER,
        };

        // 2. Seed billing settings with SRP-specific IBAN (via SRP Referer so brand-scoped)
        const seedRes = await request.put('/api/management/settings/billing-details', {
            data: {
                bank_holder: 'SRP Reisinger Pictures GmbH',
                bank_iban: 'SRP-TEST-IBAN-9876',
                bank_bic: 'TESTBICXXX',
                company_street: 'SRP-Teststr. 1',
                company_zip: '1010',
                company_city: 'Wien',
                company_country: 'Österreich',
            },
            headers: srpHeaders,
        });
        expect(seedRes.ok()).toBeTruthy();

        // 3. Create a manual invoice via SRP Referer — the PDF should render SRP IBAN
        const invoiceRes = await request.post('/api/management/invoices/manual', {
            data: {
                type: 'invoice',
                invoice_number: `SRP-TEST-${Date.now()}`,
                date: new Date().toISOString().split('T')[0],
                due_date: 'Zahlbar sofort',
                customer_name: 'SRP Testkunde',
                customer_street: 'Testweg 1',
                customer_zip: '1020',
                customer_city: 'Wien',
                items: [{type: 'item', description: 'SRP Testleistung', qty: 1, price: 10000}],
            },
            headers: srpHeaders,
        });
        expect(invoiceRes.ok()).toBeTruthy();

        // 4. Read response as PDF buffer
        const contentType = invoiceRes.headers()['content-type'] || '';
        expect(contentType).toMatch(/application\/pdf/i);

        const pdfBuffer = Buffer.from(await invoiceRes.body());

        // 5. Parse PDF text — pass standardFontDataUrl to suppress pdf.js warning
        const pdfModule = await import('pdf-parse');
        const PDFParseClass = pdfModule.PDFParse as unknown as new (options: {
            data: Uint8Array;
            standardFontDataUrl?: string;
        }) => { load(): Promise<void>; getText(): Promise<{ text: string }> };
        const pdfParser = new PDFParseClass({
            data: new Uint8Array(pdfBuffer),
            standardFontDataUrl,
        });
        await pdfParser.load();
        const pdfData = await pdfParser.getText();
        const pdfText = pdfData.text;

        // 6. Assert brand-specific content
        expect(pdfText).toContain('Reisinger');
        expect(pdfText).toContain('SRP-TEST-IBAN-9876');
        expect(pdfText).not.toContain('B2B');
    });
});
