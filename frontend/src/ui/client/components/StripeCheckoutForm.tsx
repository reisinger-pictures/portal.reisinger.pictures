import React, {useState} from 'react';
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
            showToast('error', error.message || 'Zahlung fehlgeschlagen.');
        } else if (paymentIntent && (paymentIntent.status === 'succeeded' || paymentIntent.status === 'processing')) {
            let attempts = 0;
            const pollInterval = setInterval(async () => {
                attempts++;
                try {
                    const res = await fetch('/api/orders', {
                        headers: {'Accept': 'application/json'},
                        credentials: 'include'
                    });
                    const orders = await res.json();
                    const currentOrder = orders.find((o: Order) => o.id === orderId);

                    if (currentOrder && currentOrder.status === 'paid') {
                        clearInterval(pollInterval);
                        setIsProcessing(false);
                        onSuccess(true);
                    } else if (attempts >= 15) {
                        clearInterval(pollInterval);
                        setIsProcessing(false);
                        onSuccess(false);
                    }
                } catch {
                    if (attempts >= 15) {
                        clearInterval(pollInterval);
                        setIsProcessing(false);
                        onSuccess(false);
                    }
                }
            }, 2000);
        } else {
            setIsProcessing(false);
        }
    };

    return (
        <form onSubmit={handleSubmit} className="space-y-4">
            <PaymentElement options={{defaultValues: {billingDetails: {name: defaultName, email: defaultEmail}}}}/>
            <button type="submit" disabled={isProcessing || !stripe} className="btn btn-primary w-full btn-lg">
                {isProcessing ? <span className="loading loading-spinner"></span> : 'Jetzt bezahlen'}
            </button>
            {isProcessing &&
                <p className="text-sm text-center opacity-70 mt-2">Bitte warten, Zahlung wird verifiziert...</p>}
        </form>
    );
}
