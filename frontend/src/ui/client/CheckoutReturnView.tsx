import {useEffect, useState} from 'react';
import {Link, useSearchParams} from 'react-router-dom';
import {Trans} from '@lingui/react/macro';
import {fetcher, type OrderPaymentStatus} from '../../api';
import PageLayout from '../components/PageLayout';

const POLL_INTERVAL_MS = 3000;
const MAX_POLLS = 20;

export interface CheckoutReturnViewProps {
    /**
     * Poll cadence and bound. They are props so a test can assert the bounded
     * poll without waiting a minute; production uses the defaults above.
     */
    pollIntervalMs?: number;
    maxPolls?: number;
}

type Phase = 'polling' | 'paid' | 'not_completed' | 'ambiguous' | 'unconfirmed' | 'error' | 'invalid';

const isFailedPaymentStatus = (paymentStatus: string | null | undefined): boolean => (
    paymentStatus === 'failed' || paymentStatus === 'canceled' || paymentStatus === 'expired'
);

/**
 * Return page of the hosted Mollie checkout.
 *
 * The redirect parameters prove nothing: this view only polls the owner-scoped
 * order endpoint, which answers from a payment fetched with the server
 * credential. `paid` therefore comes from the backend, never from the URL.
 */
export default function CheckoutReturnView({
    pollIntervalMs = POLL_INTERVAL_MS,
    maxPolls = MAX_POLLS,
}: CheckoutReturnViewProps) {
    const [searchParams] = useSearchParams();
    const orderId = searchParams.get('order_id');
    const [phase, setPhase] = useState<Phase>(orderId ? 'polling' : 'invalid');
    const [paymentStatus, setPaymentStatus] = useState<OrderPaymentStatus | null>(null);
    const [attempt, setAttempt] = useState(0);

    useEffect(() => {
        if (!orderId) return;

        let cancelled = false;
        let polls = 0;
        let timer: ReturnType<typeof setTimeout> | null = null;
        const controller = new AbortController();

        const poll = async () => {
            polls += 1;
            try {
                const data = await fetcher<OrderPaymentStatus>(
                    `/api/orders/${encodeURIComponent(orderId)}/payment-status`,
                    {signal: controller.signal},
                );
                if (cancelled) return;

                setPaymentStatus(data);

                if (data.status === 'paid') {
                    setPhase('paid');
                    return;
                }
                if (data.payment_ambiguous) {
                    setPhase('ambiguous');
                    return;
                }
                if (isFailedPaymentStatus(data.payment_status)) {
                    setPhase('not_completed');
                    return;
                }
                if (polls >= maxPolls) {
                    setPhase('unconfirmed');
                    return;
                }

                timer = setTimeout(() => {
                    void poll();
                }, pollIntervalMs);
            } catch {
                if (!cancelled) setPhase('error');
            }
        };

        void poll();

        return () => {
            cancelled = true;
            controller.abort();
            if (timer !== null) clearTimeout(timer);
        };
    }, [orderId, attempt, pollIntervalMs, maxPolls]);

    const retry = () => {
        setPhase('polling');
        setAttempt(value => value + 1);
    };

    const checkoutUrl = paymentStatus?.checkout_url ?? null;

    return (
        <PageLayout currentView="cart">
            <div className="container mx-auto p-4 md:p-8 max-w-3xl">
                <h1 className="text-3xl font-bold mb-8 flex items-center gap-2">
                    <span className="iconify mdi--credit-card-check text-primary"></span>
                    <Trans>Zahlung</Trans>
                </h1>

                {phase === 'invalid' && (
                    <div role="alert" className="alert alert-error shadow-sm">
                        <span className="iconify mdi--alert-circle text-xl"></span>
                        <span><Trans>Zu dieser Zahlung fehlt die Bestellreferenz. Bitte öffne deine Einkäufe, um den Status zu prüfen.</Trans></span>
                    </div>
                )}

                {phase === 'polling' && (
                    <div role="status" data-testid="payment-return-polling" className="card bg-base-100 border border-base-300 shadow-sm p-8 text-center">
                        <span className="loading loading-spinner loading-lg mx-auto mb-4"></span>
                        <h2 className="font-bold text-xl mb-2"><Trans>Zahlung wird geprüft</Trans></h2>
                        <p className="opacity-70">
                            <Trans>Wir warten auf die Bestätigung des Zahlungsdienstleisters. Das dauert in der Regel nur wenige Sekunden.</Trans>
                        </p>
                    </div>
                )}

                {phase === 'paid' && (
                    <div role="status" data-testid="payment-return-paid" className="card bg-base-100 border border-base-300 shadow-sm p-8">
                        <div className="flex items-center gap-3 mb-3">
                            <span className="iconify mdi--check-circle text-success text-3xl"></span>
                            <h2 className="font-bold text-xl"><Trans>Zahlung erfolgreich</Trans></h2>
                        </div>
                        <p className="mb-2"><Trans>Deine Bestellung ist bezahlt. Die Rechnung wurde per E-Mail versendet.</Trans></p>
                        {paymentStatus?.invoice_number && (
                            <p className="opacity-70 mb-6"><Trans>Beleg</Trans>: {paymentStatus.invoice_number}</p>
                        )}
                        <Link to="/orders" className="btn btn-primary"><Trans>Zu meinen Einkäufen</Trans></Link>
                    </div>
                )}

                {phase === 'not_completed' && (
                    <div role="alert" data-testid="payment-return-not-completed" className="card bg-base-100 border border-base-300 shadow-sm p-8">
                        <div className="flex items-center gap-3 mb-3">
                            <span className="iconify mdi--close-circle text-error text-3xl"></span>
                            <h2 className="font-bold text-xl"><Trans>Die Zahlung wurde nicht abgeschlossen</Trans></h2>
                        </div>
                        <p className="mb-6"><Trans>Es wurde nichts belastet. Du kannst die Bestellung erneut bezahlen.</Trans></p>
                        <Link to="/cart" className="btn btn-primary"><Trans>Zurück zum Warenkorb</Trans></Link>
                    </div>
                )}

                {phase === 'ambiguous' && (
                    <div role="alert" data-testid="payment-return-ambiguous" className="card bg-base-100 border border-base-300 shadow-sm p-8">
                        <div className="flex items-center gap-3 mb-3">
                            <span className="iconify mdi--help-circle text-warning text-3xl"></span>
                            <h2 className="font-bold text-xl"><Trans>Zahlung wird manuell geprüft</Trans></h2>
                        </div>
                        <p className="mb-6">
                            <Trans>Der Zahlungsvorgang konnte nicht eindeutig abgeschlossen werden. Bitte wende dich an den Support, damit die Zahlung geprüft wird.</Trans>
                        </p>
                        <Link to="/orders" className="btn btn-outline"><Trans>Zu meinen Einkäufen</Trans></Link>
                    </div>
                )}

                {phase === 'unconfirmed' && (
                    <div role="status" data-testid="payment-return-unconfirmed" className="card bg-base-100 border border-base-300 shadow-sm p-8">
                        <div className="flex items-center gap-3 mb-3">
                            <span className="iconify mdi--clock-outline text-warning text-3xl"></span>
                            <h2 className="font-bold text-xl"><Trans>Zahlung noch nicht bestätigt</Trans></h2>
                        </div>
                        <p className="mb-6">
                            <Trans>Die Bestätigung ist noch nicht eingetroffen. Der Zahlungsvorgang bleibt erhalten; bitte prüfe den Status in Kürze erneut.</Trans>
                        </p>
                        <div className="flex flex-wrap gap-3">
                            <button type="button" className="btn btn-primary" onClick={retry}><Trans>Erneut prüfen</Trans></button>
                            {checkoutUrl && (
                                <a href={checkoutUrl} className="btn btn-outline"><Trans>Weiter zur Zahlung</Trans></a>
                            )}
                            <Link to="/orders" className="btn btn-ghost"><Trans>Zu meinen Einkäufen</Trans></Link>
                        </div>
                    </div>
                )}

                {phase === 'error' && (
                    <div role="alert" data-testid="payment-return-error" className="card bg-base-100 border border-base-300 shadow-sm p-8">
                        <div className="flex items-center gap-3 mb-3">
                            <span className="iconify mdi--alert-circle text-error text-3xl"></span>
                            <h2 className="font-bold text-xl"><Trans>Der Zahlungsstatus konnte nicht geladen werden</Trans></h2>
                        </div>
                        <p className="mb-6"><Trans>Bitte versuche es erneut. Ist die Zahlung erfolgt, bleibt sie selbstverständlich erhalten.</Trans></p>
                        <div className="flex flex-wrap gap-3">
                            <button type="button" className="btn btn-primary" onClick={retry}><Trans>Erneut prüfen</Trans></button>
                            <Link to="/orders" className="btn btn-ghost"><Trans>Zu meinen Einkäufen</Trans></Link>
                        </div>
                    </div>
                )}
            </div>
        </PageLayout>
    );
}
