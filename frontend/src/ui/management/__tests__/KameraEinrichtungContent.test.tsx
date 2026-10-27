import { afterEach, describe, expect, it, vi } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { FtpConnection } from '../../../logic/ftpConnection';
import KameraEinrichtungContent from '../KameraEinrichtungContent';
import { renderWithProviders } from '../../../test-setup';

/**
 * The guide states the hourly reset quota, and the quota is a security rule that
 * lives in `FtpCredentialService::RESET_LIMIT_PER_HOUR` (backend). The sentence
 * used to say "drei Anforderungen pro Stunde" as a literal while the server
 * enforced ten, so the guide told the photographer a fact that was false — and
 * nothing failed, because a number written into German copy has no test that can
 * contradict it.
 *
 * These tests pin the other half of the fix: the number is a prop, so the copy
 * follows it. Both directions are asserted — the given value appears, and a value
 * the component never received appears nowhere — because "the prop is rendered"
 * and "the copy has no number of its own" are different claims, and only the pair
 * rules out a literal coming back.
 */
const CONNECTION: FtpConnection = {
    configured: true,
    host: 'sftp.example.invalid',
    username: 'florian',
    path: '/',
    sftp_port: 2222,
    ftps_port: 989,
    pasv_port_start: 50000,
    pasv_port_end: 50100,
    ftps_tls_mode: 'explicit',
};

/** The list item that asks for the credentials, with its German text flattened. */
function credentialsStepText() {
    const item = screen
        .getAllByRole('listitem')
        .find(entry => entry.textContent?.includes('Anforderungen pro Stunde'));

    expect(item, 'The guide must still contain the step that requests the credentials.').toBeDefined();

    return (item?.textContent ?? '').replace(/\s+/g, ' ');
}

describe('KameraEinrichtungContent: reset quota', () => {
    it('states the quota it is given, not a number of its own', () => {
        renderWithProviders(<KameraEinrichtungContent connection={CONNECTION} resetLimitPerHour={10} />);

        expect(credentialsStepText()).toContain('Es sind 10 Anforderungen pro Stunde möglich.');
    });

    it('follows the quota when the server enforces a different one', () => {
        // A deliberately odd value: a copy that still said "drei", or one that
        // hardcoded the production number, cannot pass both cases.
        renderWithProviders(<KameraEinrichtungContent connection={CONNECTION} resetLimitPerHour={3} />);

        const text = credentialsStepText();

        expect(text).toContain('Es sind 3 Anforderungen pro Stunde möglich.');
        expect(text).not.toContain('10 Anforderungen');
    });
});

describe('KameraEinrichtungContent: FTPS certificate download', () => {
    afterEach(() => {
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
    });

    it('fetches the authenticated certificate endpoint and saves the server filename', async () => {
        const certificate = '-----BEGIN CERTIFICATE-----\npublic-certificate\n-----END CERTIFICATE-----\n';
        const fetchMock = vi.fn<typeof fetch>().mockResolvedValue(new Response(certificate, {
            status: 200,
            headers: {
                'Content-Type': 'application/x-pem-file',
                'Content-Disposition': 'attachment; filename="ROOT.PEM"',
            },
        }));
        vi.stubGlobal('fetch', fetchMock);

        const createObjectURL = vi.fn(() => 'blob:root-certificate');
        const revokeObjectURL = vi.fn();
        vi.stubGlobal('URL', {createObjectURL, revokeObjectURL});
        let downloadedFilename: string | null = null;
        vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (this: HTMLAnchorElement) {
            downloadedFilename = this.download;
        });

        renderWithProviders(<KameraEinrichtungContent connection={CONNECTION} resetLimitPerHour={10} />);

        expect(screen.getByText('Die Kamera akzeptiert das Zertifikat auch ohne Import über „Zielserver vertrauen“.')).toBeVisible();
        await userEvent.click(screen.getByRole('button', {name: 'ROOT.PEM herunterladen'}));

        await waitFor(() => expect(downloadedFilename).toBe('ROOT.PEM'));
        expect(fetchMock.mock.calls[0]?.[0]).toBe('/api/management/ftp/certificate');
        expect(fetchMock.mock.calls[0]?.[1]).toMatchObject({method: 'GET', credentials: 'include'});
        expect(createObjectURL).toHaveBeenCalledWith(expect.any(Blob));
        expect(downloadedFilename).toBe('ROOT.PEM');
        expect(revokeObjectURL).toHaveBeenCalledWith('blob:root-certificate');
    });

    it('shows a visible German error when the certificate request fails', async () => {
        const fetchMock = vi.fn<typeof fetch>().mockRejectedValue(new TypeError('Failed to fetch'));
        vi.stubGlobal('fetch', fetchMock);

        renderWithProviders(<KameraEinrichtungContent connection={CONNECTION} resetLimitPerHour={10} />);

        await userEvent.click(screen.getByRole('button', {name: 'ROOT.PEM herunterladen'}));

        expect(await screen.findByRole('alert')).toHaveTextContent(
            'Das Zertifikat konnte nicht heruntergeladen werden.',
        );
    });
});
