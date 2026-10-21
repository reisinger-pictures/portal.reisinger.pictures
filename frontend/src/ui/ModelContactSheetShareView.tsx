import { useState } from 'react';
import { useParams } from 'react-router-dom';
import { t } from '@lingui/core/macro';
import { Trans } from '@lingui/react/macro';
import GuestLayout from './components/GuestLayout';
import ErrorMessage from './components/ErrorMessage';
import { useUI } from './components/UIContext';
import { downloadModelContactSheetShare, shareLinkExpiry } from '../logic/modelContactSheet';

function formatDateTime(value: Date | null): string {
    if (!value) return '–';
    return value.toLocaleString('de-DE', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

/**
 * Public page for a shared external contact sheet (Phase 4).
 *
 * The visitor opens the link **without a login** and downloads the PDF. The
 * page itself is deliberately data-free: no names, no photos, no profile
 * data — the external sheet is PII-free, and the page must not become a
 * second data channel. It only offers the download and the expiry hint.
 */
export default function ModelContactSheetShareView() {
    const { token } = useParams<{ token: string }>();
    const { showToast } = useUI();
    const [isDownloading, setIsDownloading] = useState(false);
    const [error, setError] = useState('');

    // The expiry is a display hint taken from the token's own claims; the
    // server stays the sole authority on validity.
    const expiry = shareLinkExpiry(token ?? '');
    const expired = expiry !== null && expiry.getTime() <= new Date().getTime();

    const handleDownload = async () => {
        if (!token) return;
        setIsDownloading(true);
        setError('');
        try {
            await downloadModelContactSheetShare(token);
            showToast('success', t`Contact Sheet wurde heruntergeladen.`);
        } catch {
            setError(t`Dieser Link ist ungültig oder abgelaufen.`);
        } finally {
            setIsDownloading(false);
        }
    };

    return (
        <GuestLayout>
            <div className="mx-auto w-full max-w-3xl p-4 md:p-8">
                <div className="card bg-base-100 border border-base-300 shadow-sm">
                    <div className="card-body gap-4">
                        <h1 className="text-2xl md:text-3xl font-bold">
                            <Trans>Dein Contact Sheet ist bereit</Trans>
                        </h1>

                        {error || expired ? (
                            <ErrorMessage message={error || t`Dieser Link ist ungültig oder abgelaufen.`} />
                        ) : (
                            <>
                                <p className="opacity-70">
                                    <Trans>Hier kannst du das Contact Sheet als PDF herunterladen. Bitte halte den Link geheim — jeder mit dem Link kann die Datei herunterladen.</Trans>
                                </p>
                                {expiry && (
                                    <p className="text-sm opacity-70" data-testid="contact-sheet-share-expiry">
                                        <Trans>Der Link ist gültig bis</Trans> {formatDateTime(expiry)}
                                    </p>
                                )}
                                <div>
                                    <button
                                        type="button"
                                        className="btn btn-primary"
                                        onClick={handleDownload}
                                        disabled={isDownloading}
                                        data-testid="contact-sheet-share-download"
                                    >
                                        {isDownloading
                                            ? <span className="loading loading-spinner"></span>
                                            : <span className="iconify mdi--file-pdf-box"></span>}
                                        <Trans>Contact Sheet herunterladen</Trans>
                                    </button>
                                </div>
                            </>
                        )}
                    </div>
                </div>
            </div>
        </GuestLayout>
    );
}
