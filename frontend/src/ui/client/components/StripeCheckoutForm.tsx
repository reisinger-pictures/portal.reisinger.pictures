import React, {useState, useRef, useEffect} from 'react';
import {t} from "@lingui/core/macro";
import {Trans} from "@lingui/react/macro";
import {useStripe, useElements, PaymentElement} from '@stripe/react-stripe-js';
import {useUI} from '../../components/UIContext';
import {Order} from '../../../api';

export interface StripeCheckoutFormProps {
    orderId: string;
    defaultEmail?: string;
    defaultName?: string;
    onSuccess: (webhookSuccess: boolean) => void;
}

export function StripeCheckoutForm({orderId, defaultEmail, defaultName, onSuccess}: StripeCheckoutFormProps) {
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

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault();
        if (!stripe || !elements) return;
        setIsProcessing(true);
        const {error, paymentIntent} = await stripe.confirmPayment({
            elements,
            redirect: 'if_required',
        });

        if (error) {
            setIsProcessing(false);
            showToast('error', error.message || t`Zahlung fehlgeschlagen.`);
        } else if (paymentIntent && paymentIntent.status === 'succeeded') {
            setIsProcessing(false);
            onSuccess(true);
            return;
        } else if (paymentIntent && paymentIntent.status === 'processing') {
            let attempts = 0;
            intervalRef.current = setInterval(async () => {
                if (!mountedRef.current) return;
                attempts++;
                try {
                    const res = await fetch('/api/orders', {
                        headers: {'Accept': 'application/json'},
                        credentials: 'include'
                    });
                    const orders = await res.json();
                    const currentOrder = orders.find((o: Order) => o.id === orderId);

                    if (currentOrder && currentOrder.status === 'paid') {
                        if (intervalRef.current) clearInterval(intervalRef.current);
                        intervalRef.current = null;
                        if (mountedRef.current) setIsProcessing(false);
                        onSuccess(true);
                    } else if (attempts >= 15) {
                        if (intervalRef.current) clearInterval(intervalRef.current);
                        intervalRef.current = null;
                        if (mountedRef.current) setIsProcessing(false);
                        onSuccess(false);
                    }
                } catch {
                    if (attempts >= 15) {
                        if (intervalRef.current) clearInterval(intervalRef.current);
                        intervalRef.current = null;
                        if (mountedRef.current) setIsProcessing(false);
                        onSuccess(false);
                    }
                }
            }, 1000);
        } else {
            setIsProcessing(false);
        }
    };

    return (
        <form onSubmit={handleSubmit} className="space-y-4">
            <PaymentElement options={{defaultValues: {billingDetails: {name: defaultName, email: defaultEmail}}}}/>
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
