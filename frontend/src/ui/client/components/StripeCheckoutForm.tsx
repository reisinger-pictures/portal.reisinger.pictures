import React, {useEffect, useRef, useState, useSyncExternalStore} from 'react';
import {t} from "@lingui/core/macro";
import {Trans} from "@lingui/react/macro";
import {useStripe, useElements, PaymentElement} from '@stripe/react-stripe-js';
import {fetcher} from '../../../api';
import {
    getStripeLoaderStatus,
    retryStripeLoader,
    subscribeToStripeLoaderStatus,
} from '../../../logic/stripe';
import {useUI} from '../../components/UIContext';

const PAYMENT_STATUS_POLL_INTERVAL_MS = 2000;
const PAYMENT_STATUS_MAX_ATTEMPTS = 30;

const isPaidOrder = (value: unknown): boolean => {
    if (typeof value !== 'object' || value === null) return false;
    return (value as {status?: unknown}).status === 'paid';
};

export interface StripeCheckoutFormProps {
    orderId: string;
    defaultEmail?: string;
    defaultName?: string;
    billingAddress?: {line1: string; postalCode: string; city: string};
    /** True only after the authenticated local order endpoint reports `paid`. */
    onSuccess: (serverPaid: boolean) => void;
}

export function StripeCheckoutForm({orderId, defaultEmail, defaultName, billingAddress, onSuccess}: StripeCheckoutFormProps) {
    const stripe = useStripe();
    const elements = useElements();
    const [isProcessing, setIsProcessing] = useState(false);
    const {showToast} = useUI();
    const timeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null);
    const mountedRef = useRef(true);
    const paymentConfirmationRef = useRef(false);
    const pollingFinishedRef = useRef(false);
    const loaderStatus = useSyncExternalStore(
        subscribeToStripeLoaderStatus,
        getStripeLoaderStatus,
        getStripeLoaderStatus
    );

    useEffect(() => {
        mountedRef.current = true;
        return () => {
            mountedRef.current = false;
            if (timeoutRef.current) {
                clearTimeout(timeoutRef.current);
                timeoutRef.current = null;
            }
        };
    }, []);

    const stopPolling = () => {
        if (!timeoutRef.current) return;
        clearTimeout(timeoutRef.current);
        timeoutRef.current = null;
    };

    const finishPolling = (serverPaid: boolean) => {
        if (pollingFinishedRef.current) return;
        pollingFinishedRef.current = true;
        paymentConfirmationRef.current = false;
        stopPolling();
        if (!mountedRef.current) return;
        setIsProcessing(false);
        onSuccess(serverPaid);
    };

    const startPaidStatusPolling = () => {
        stopPolling();
        pollingFinishedRef.current = false;
        let attempts = 0;

        const pollPaidStatus = async () => {
            if (!mountedRef.current || pollingFinishedRef.current) return;
            attempts += 1;

            try {
                const currentOrder = await fetcher<unknown>(`/api/orders/${orderId}`);
                if (isPaidOrder(currentOrder)) {
                    finishPolling(true);
                    return;
                }
            } catch {
                // A failed poll is bounded below; the payment confirmation itself
                // is never replayed by the refresh pipeline.
            }

            if (attempts >= PAYMENT_STATUS_MAX_ATTEMPTS) {
                finishPolling(false);
                return;
            }
            if (!mountedRef.current || pollingFinishedRef.current) return;
            timeoutRef.current = setTimeout(() => {
                void pollPaidStatus();
            }, PAYMENT_STATUS_POLL_INTERVAL_MS);
        };

        timeoutRef.current = setTimeout(() => {
            void pollPaidStatus();
        }, PAYMENT_STATUS_POLL_INTERVAL_MS);
    };

    const handleSubmit = async (event: React.FormEvent) => {
        event.preventDefault();
        if (!stripe || !elements || paymentConfirmationRef.current) return;
        paymentConfirmationRef.current = true;
        setIsProcessing(true);

        try {
            const {error, paymentIntent} = await stripe.confirmPayment({
                elements,
                redirect: 'if_required'
            });

            if (error) {
                paymentConfirmationRef.current = false;
                setIsProcessing(false);
                showToast('error', error.message || t`Zahlung fehlgeschlagen.`);
            } else if (paymentIntent?.status === 'succeeded' || paymentIntent?.status === 'processing') {
                // Stripe succeeding is not enough to discard recovery state. Only
                // the safe GET poll is refreshed/retried; confirmation is never repeated.
                startPaidStatusPolling();
            } else {
                paymentConfirmationRef.current = false;
                setIsProcessing(false);
                showToast('info', t`Zahlung unvollständig — bitte erneut versuchen.`);
            }
        } catch {
            paymentConfirmationRef.current = false;
            setIsProcessing(false);
            showToast('error', t`Zahlung fehlgeschlagen.`);
        }
    };

    if (!stripe && loaderStatus === 'error') {
        return (
            <div role="alert" className="alert alert-error shadow-sm flex flex-col items-start gap-4">
                <span className="iconify mdi--credit-card-off text-3xl" aria-hidden="true"></span>
                <div>
                    <h3 className="font-bold text-lg"><Trans>Sicherer Zahlungsdienst nicht verfügbar</Trans></h3>
                    <p className="text-sm mt-1"><Trans>Stripe konnte nicht geladen werden. Bitte versuche es erneut oder öffne die Rechnung als Ausweichoption.</Trans></p>
                </div>
                <div className="flex flex-wrap gap-2">
                    <button type="button" onClick={() => void retryStripeLoader()} className="btn btn-sm btn-outline btn-error">
                        <span className="iconify mdi--refresh" aria-hidden="true"></span>
                        <Trans>Erneut versuchen</Trans>
                    </button>
                    <a href={`/api/orders/${encodeURIComponent(orderId)}/invoice`}
                       target="_blank" rel="noopener noreferrer"
                       className="btn btn-sm btn-ghost border-current">
                        <span className="iconify mdi--file-pdf-box" aria-hidden="true"></span>
                        <Trans>Rechnung als PDF öffnen</Trans>
                    </a>
                </div>
            </div>
        );
    }

    if (!stripe) {
        return (
            <div role="status" className="alert shadow-sm flex items-center gap-3">
                <span className="loading loading-spinner"></span>
                <Trans>Sicherer Zahlungsdienst wird geladen...</Trans>
            </div>
        );
    }

    return (
        <form onSubmit={handleSubmit} className="space-y-4">
            <PaymentElement options={{
                defaultValues: {
                    billingDetails: {
                        name: defaultName,
                        email: defaultEmail,
                        ...(billingAddress ? {
                            address: {
                                line1: billingAddress.line1,
                                postal_code: billingAddress.postalCode,
                                city: billingAddress.city,
                                country: 'AT'
                            }
                        } : {})
                    }
                }
            }}/>
            <button type="submit" disabled={isProcessing || !stripe} className="btn btn-primary w-full btn-lg">
                {isProcessing ? <span className="loading loading-spinner"></span> : <Trans>Jetzt bezahlen</Trans>}
            </button>
            {isProcessing &&
                <p className="text-sm text-center opacity-70 mt-2 flex items-center justify-center gap-2">
                    <span className="loading loading-spinner loading-xs"></span>
                    <Trans>Zahlung wird verifiziert...</Trans>
                </p>}
        </form>
    );
}
