import React, {useEffect, useRef, useState} from 'react';
import {t} from "@lingui/core/macro";
import {Trans} from "@lingui/react/macro";
import {useStripe, useElements, PaymentElement} from '@stripe/react-stripe-js';
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
    const intervalRef = useRef<ReturnType<typeof setInterval> | null>(null);
    const mountedRef = useRef(true);

    useEffect(() => {
        mountedRef.current = true;
        return () => {
            mountedRef.current = false;
            if (intervalRef.current) {
                clearInterval(intervalRef.current);
                intervalRef.current = null;
            }
        };
    }, []);

    const stopPolling = () => {
        if (!intervalRef.current) return;
        clearInterval(intervalRef.current);
        intervalRef.current = null;
    };

    const finishPolling = (serverPaid: boolean) => {
        stopPolling();
        if (!mountedRef.current) return;
        setIsProcessing(false);
        onSuccess(serverPaid);
    };

    const startPaidStatusPolling = () => {
        stopPolling();
        let attempts = 0;

        intervalRef.current = setInterval(async () => {
            if (!mountedRef.current) return;
            attempts += 1;

            try {
                const response = await fetch(`/api/orders/${orderId}`, {
                    headers: {'Accept': 'application/json'},
                    credentials: 'include'
                });
                if (!response.ok) throw new Error(`Order status request failed: ${response.status}`);
                const currentOrder: unknown = await response.json();

                if (isPaidOrder(currentOrder)) {
                    finishPolling(true);
                } else if (attempts >= PAYMENT_STATUS_MAX_ATTEMPTS) {
                    finishPolling(false);
                }
            } catch {
                if (attempts >= PAYMENT_STATUS_MAX_ATTEMPTS) {
                    finishPolling(false);
                }
            }
        }, PAYMENT_STATUS_POLL_INTERVAL_MS);
    };

    const handleSubmit = async (event: React.FormEvent) => {
        event.preventDefault();
        if (!stripe || !elements) return;
        setIsProcessing(true);
        const {error, paymentIntent} = await stripe.confirmPayment({
            elements,
            redirect: 'if_required'
        });

        if (error) {
            setIsProcessing(false);
            showToast('error', error.message || t`Zahlung fehlgeschlagen.`);
        } else if (paymentIntent?.status === 'succeeded' || paymentIntent?.status === 'processing') {
            // Stripe succeeding is not enough to discard recovery state. The
            // local order must be paid by the authenticated server first.
            startPaidStatusPolling();
        } else {
            setIsProcessing(false);
            showToast('info', 'Zahlung unvollständig — bitte erneut versuchen.');
        }
    };

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
