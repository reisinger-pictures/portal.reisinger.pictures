import React, { useEffect, useState } from 'react';
import { useCart, CartItem } from '../../logic/CartContext';
import { useUI } from '../components/UIContext';
import { apiMutate } from '../../api';
import { useAuth } from '../../logic/useAuth';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { useNavigate, Link, useSearchParams } from 'react-router-dom';
import { usePricing, ResolutionTier, UsageTier, DurationTier, FrequencyTier } from '../../logic/usePricing';
import { useLicenseTerms } from '../../logic/useLicenseTerms';
import { loadStripe } from '@stripe/stripe-js';
import { Elements, PaymentElement, useStripe, useElements } from '@stripe/react-stripe-js';
import PageLayout from '../components/PageLayout';

const stripePublicKey = import.meta.env.VITE_STRIPE_PUBLIC_KEY;
const stripePromise = loadStripe(stripePublicKey);

function StripeCheckoutForm({ orderId, defaultEmail, defaultName, onSuccess }: { orderId: string, defaultEmail?: string, defaultName?: string, onSuccess: (webhookSuccess: boolean) => void }) {
    const stripe = useStripe();
    const elements = useElements();
    const [isProcessing, setIsProcessing] = useState(false);
    const { showToast } = useUI();

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault();
        if (!stripe || !elements) return;
        setIsProcessing(true);
        const { error, paymentIntent } = await stripe.confirmPayment({
            elements,
            redirect: 'if_required',
        });
        
        if (error) {
            setIsProcessing(false);
            showToast('error', error.message || 'Zahlung fehlgeschlagen.');
        } else if (paymentIntent && paymentIntent.status === 'succeeded') {
            let attempts = 0;
            const pollInterval = setInterval(async () => {
                attempts++;
                try {
                    const res = await fetch('/api/orders', { headers: { 'Accept': 'application/json' }, credentials: 'include' });
                    const orders = await res.json();
                    const currentOrder = orders.find((o: { id: string, status: string }) => o.id === orderId);
                    
                    if (currentOrder && currentOrder.status === 'paid') {
                        clearInterval(pollInterval);
                        setIsProcessing(false);
                        onSuccess(true);
                    } else if (attempts >= 5) {
                        clearInterval(pollInterval);
                        setIsProcessing(false);
                        onSuccess(false);
                    }
                } catch {
                    if (attempts >= 5) {
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
            <PaymentElement options={{ defaultValues: { billingDetails: { name: defaultName, email: defaultEmail } } }} />
            <button type="submit" disabled={isProcessing || !stripe} className="btn btn-primary w-full btn-lg">
                {isProcessing ? <span className="loading loading-spinner"></span> : 'Jetzt bezahlen'}
            </button>
            {isProcessing && <p className="text-xs text-center opacity-70 mt-2">Bitte warten, Zahlung wird verifiziert...</p>}
        </form>
    );
}

const checkoutSchema = z.object({
    billing_name: z.string().min(2, 'Name ist erforderlich'),
    billing_company: z.string().optional(),
    billing_street: z.string().min(3, 'Straße ist erforderlich'),
    billing_zip: z.string().min(4, 'PLZ ist erforderlich'),
    billing_city: z.string().min(2, 'Ort ist erforderlich'),
    quote_message: z.string().optional(),
    agb_accepted: z.literal(true, { errorMap: () => ({ message: 'Zustimmung erforderlich' }) }),
    withdrawal_waived: z.boolean().optional()
});

type CheckoutFormValues = z.infer<typeof checkoutSchema>;

interface CartItemListProps {
    items: CartItem[];
    handleUpdateItem: (item: CartItem, field: string, value: string) => void;
    removeFromCart: (photoId: string) => void;
    hasQuotes: boolean;
    totalAmount: number;
}

const CartItemList = ({ items, handleUpdateItem, removeFromCart, hasQuotes, totalAmount }: CartItemListProps) => (
    <div className="lg:col-span-3">
        <h2 className="font-bold text-xl mb-4 flex items-center gap-2">
            <span className="iconify mdi--format-list-checks text-secondary"></span> {hasQuotes ? 'Deine Lizenzen & Anfragen' : 'Deine Lizenzen'}
        </h2>
        <div className="space-y-4">
            {items.map((item: CartItem, idx: number) => (
                <div key={item.photoId + idx} className="flex flex-col sm:flex-row justify-between items-start sm:items-center bg-base-100 p-4 rounded-box border border-base-300 shadow-sm gap-4">
                    <div className="flex-1 min-w-0 w-full flex flex-col md:flex-row gap-4 items-start md:items-center">
                        {item.thumb_url && (
                            <img src={item.thumb_url} className="w-24 h-24 object-cover rounded shadow-sm shrink-0 border border-base-200" alt="Vorschau" />
                        )}
                        <div className="w-full">
                            {item.isQuote ? (
                                <div className="w-full">
                                    <div className="font-bold text-sm text-primary mb-2 flex items-center gap-1"><span className="iconify mdi--file-document-edit-outline"></span> Individuelles Angebot</div>
                                    <textarea 
                                        className="textarea textarea-bordered w-full h-16 text-sm resize-none"
                                        placeholder="Beschreibe deine speziellen Nutzungsanforderungen (z.B. Weltweite Rechte, Exklusivität)..."
                                        value={item.notes || ''}
                                        onChange={(e) => handleUpdateItem(item, 'notes', e.target.value)}
                                    />
                                </div>
                            ) : (
                                <div className="flex flex-wrap gap-2">
                                    <select className="select select-sm select-bordered bg-base-200 text-xs font-normal" value={item.tier} onChange={(e) => handleUpdateItem(item, 'tier', e.target.value)}>
                                        <option value="web">Web & Social</option>
                                        <option value="print">Print (A4)</option>
                                        <option value="original">Original</option>
                                    </select>
                                    <select className="select select-sm select-bordered bg-base-200 text-xs font-normal" value={item.usage} onChange={(e) => handleUpdateItem(item, 'usage', e.target.value)}>
                                        <option value="editorial">Redaktionell</option>
                                        <option value="commercial">Kommerziell</option>
                                    </select>
                                    <select className="select select-sm select-bordered bg-base-200 text-xs font-normal" value={item.duration} onChange={(e) => handleUpdateItem(item, 'duration', e.target.value)}>
                                        <option value="1_year">1 Jahr</option>
                                        <option value="unlimited">Unbegrenzt</option>
                                    </select>
                                    <select className="select select-sm select-bordered bg-base-200 text-xs font-normal" value={item.frequency || 'einmalig'} onChange={(e) => handleUpdateItem(item, 'frequency', e.target.value)}>
                                        <option value="einmalig">Einmalig</option>
                                        <option value="mehrmalig">Mehrmalig</option>
                                    </select>
                                </div>
                            )}
                        </div>
                    </div>
                    <div className="flex items-center gap-4 shrink-0 w-full sm:w-auto justify-between sm:justify-end border-t sm:border-0 border-base-300 pt-3 sm:pt-0 mt-3 sm:mt-0">
                        {item.isQuote ? (
                            <div className="text-right">
                                <span className="font-mono font-bold text-lg whitespace-nowrap text-warning">--- €</span>
                                <span className="text-xs font-sans opacity-70 block">(Preis auf Anfrage)</span>
                            </div>
                        ) : (
                            <span className="font-mono font-bold text-lg whitespace-nowrap">{item.price.toFixed(2)} €</span>
                        )}
                        <button onClick={() => removeFromCart(item.photoId)} className="btn btn-ghost btn-sm btn-square text-error" title="Entfernen">
                            <span className="iconify mdi--trash-can text-lg"></span>
                        </button>
                    </div>
                </div>
            ))}
        </div>
        
        <div className="mt-6 flex justify-between items-center bg-base-100 p-6 rounded-box border border-primary shadow-sm">
            <span className="font-bold text-lg">Gesamtsumme</span>
            <span className="text-3xl font-mono font-bold text-primary">{hasQuotes ? '--- €' : `${totalAmount.toFixed(2)} €`}</span>
        </div>
        <p className="text-sm opacity-60 text-right mt-2">Steuerfrei gem. Kleinunternehmerregelung § 6 Abs. 1 Z 27 UStG.</p>
    </div>
);

export default function ClientCartView() {
    const { items, removeFromCart, totalAmount, clearCart, addToCart } = useCart();
    const { showToast } = useUI();
    const { user, mutate: mutateUser } = useAuth();
    const navigate = useNavigate();
    const [clientSecret, setClientSecret] = useState<string | null>(null);
    const [pendingOrderId, setPendingOrderId] = useState<string | null>(null);
    const { terms, isLoading: termsLoading } = useLicenseTerms();
    const { calculateUpgradePrice } = usePricing(parseFloat(terms?.base_price || '35.00'));
    const [searchParams] = useSearchParams();
    const [paymentMethod, setPaymentMethod] = useState<'stripe' | 'invoice'>('stripe');

    const hasQuotes = items.some(i => i.isQuote);

    const incomingToken = searchParams.get('quote_token');
    useEffect(() => {
        if (!incomingToken) return;
        
        fetch('/api/orders/quote-decode?token=' + encodeURIComponent(incomingToken))
            .then(res => res.json())
            .then(data => {
                if (data.photos && data.price !== undefined) {
                    clearCart();
                    data.photos.forEach((pid: string) => {
                        addToCart({
                            photoId: pid,
                            filename: 'Individuelles Angebot',
                            tier: 'original',
                            usage: 'commercial',
                            duration: 'unlimited',
                            price: data.price / data.photos.length,
                            isQuote: false,
                            notes: ''
                        });
                    });
                    showToast('info', 'Angebot aus Link wiederhergestellt.');
                    const newParams = new URLSearchParams(window.location.search);
                    newParams.delete('quote_token');
                    const cleanPath = window.location.pathname + (newParams.toString() ? '?' + newParams.toString() : '');
                    window.history.replaceState(null, '', cleanPath);
                }
            }).catch(err => console.error('Token Decode Error:', err));
    // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [incomingToken]);

    const { register, handleSubmit, reset, setError, formState: { errors, isSubmitting } } = useForm<CheckoutFormValues>({
        resolver: zodResolver(checkoutSchema),
        defaultValues: {
            billing_name: '',
            billing_company: '',
            billing_street: '',
            billing_zip: '',
            billing_city: '',
            quote_message: ''
        }
    });

    useEffect(() => {
        if (user) {
            reset({
                billing_name: user.billing_name || user.name || '',
                billing_company: user.billing_company || '',
                billing_street: user.billing_street || '',
                billing_zip: user.billing_zip || '',
                billing_city: user.billing_city || '',
                quote_message: ''
            });
        }
    }, [user, reset]);

    const handleUpdateItem = (item: CartItem, field: string, value: string) => {
        const updatedItem = { ...item, [field]: value };
        if (!item.isQuote) {
            const newPrice = calculateUpgradePrice(
                user?.flatrate_level,
                updatedItem.tier as ResolutionTier,
                updatedItem.usage as UsageTier,
                updatedItem.duration as DurationTier,
                updatedItem.frequency as FrequencyTier || 'einmalig'
            );
            updatedItem.price = newPrice;
        }
        addToCart(updatedItem);
    };

    const onCheckout = async (data: CheckoutFormValues) => {
        if (!hasQuotes && !data.withdrawal_waived) {
            setError('withdrawal_waived', { type: 'manual', message: 'Verzicht auf Widerruf ist zwingend erforderlich' });
            showToast('error', 'Bitte bestätige den Verzicht auf das Widerrufsrecht.');
            return;
        }
        try {
            const payload = {
                items,
                billing_name: data.billing_name,
                billing_company: data.billing_company,
                billing_street: data.billing_street,
                billing_zip: data.billing_zip,
                billing_city: data.billing_city,
                payment_method: paymentMethod,
                quote_message: data.quote_message,
                withdrawal_waived: !!data.withdrawal_waived
            };

            const response = await apiMutate<{ success?: boolean, requires_action?: boolean, client_secret?: string, invoice_number: string, order_id?: string }>('/api/orders/checkout', 'POST', payload);
            
            if (response.requires_action && response.client_secret) {
                setClientSecret(response.client_secret);
                if (response.order_id) setPendingOrderId(response.order_id);
                showToast('info', 'Bitte schließe die Zahlung ab.');
            } else if (response.success) {
                showToast('success', hasQuotes ? 'Angebot erfolgreich angefragt!' : `Bestellung erfolgreich! (Beleg: ${response.invoice_number})`);
                clearCart();
                await mutateUser();
                navigate('/orders');
            }
        } catch (error: unknown) {
            showToast('error', error instanceof Error ? error.message : 'Fehler beim Checkout.');
        }
    };

    return (
        <PageLayout currentView="cart">
            <div className="container mx-auto p-4 md:p-8 max-w-6xl">
                <h1 className="text-3xl font-bold mb-8 flex items-center gap-2">
                    <span className="iconify mdi--cart text-primary"></span> Dein Warenkorb
                </h1>

                {!termsLoading && (!terms?.bank_holder || !terms?.company_street || !terms?.company_zip || !terms?.company_city || !terms?.bank_iban) && (
                    <div className="alert alert-error shadow-sm mb-8">
                        <span className="iconify mdi--alert-circle text-xl"></span>
                        <span>Der Betreiber hat noch keine vollständigen Rechnungsdaten hinterlegt. Ein Kauf ist derzeit aus rechtlichen Gründen nicht möglich.</span>
                    </div>
                )}

                {items.length === 0 ? (
                    <div className="flex flex-col items-center justify-center py-20 opacity-50 bg-base-100 rounded-box border border-base-300">
                        <span className="iconify mdi--cart-off text-6xl mb-4"></span>
                        <p className="text-xl">Dein Warenkorb ist leer.</p>
                        <Link to="/" className="btn btn-outline mt-6">Zurück zur Startseite</Link>
                    </div>
                ) : (
                    <div className="grid grid-cols-1 lg:grid-cols-5 gap-8">
                        
                        <CartItemList items={items} handleUpdateItem={handleUpdateItem} removeFromCart={removeFromCart} hasQuotes={hasQuotes} totalAmount={totalAmount} />

                        <div className="lg:col-span-2">
                            {clientSecret ? (
                                <div className="bg-base-100 p-6 rounded-box border border-base-300 shadow-sm sticky top-24">
                                    <h2 className="font-bold text-xl mb-6 flex items-center gap-2">
                                        <span className="iconify mdi--credit-card text-secondary"></span> Zahlung abschließen
                                    </h2>
                                    <Elements stripe={stripePromise} options={{ clientSecret }}>
                                        <StripeCheckoutForm orderId={pendingOrderId!} defaultEmail={user?.email} defaultName={user?.billing_name || user?.name} onSuccess={(webhookSuccess) => {
                                            if (webhookSuccess) {
                                                showToast('success', 'Zahlung erfolgreich! Rechnung wurde versendet.');
                                            } else {
                                                showToast('info', 'Zahlung bei Stripe erfolgreich, aber das lokale Webhook-Event fehlt.');
                                            }
                                            clearCart();
                                            mutateUser();
                                            navigate('/orders');
                                        }} />
                                    </Elements>
                                </div>
                            ) : (
                                <form id="checkout-form" onSubmit={handleSubmit(onCheckout)} className="bg-base-100 p-6 rounded-box border border-base-300 shadow-sm sticky top-24">
                                    <h2 className="font-bold text-xl mb-6 flex items-center gap-2">
                                        <span className="iconify mdi--card-account-details text-secondary"></span> Rechnungsadresse
                                    </h2>
                                    
                                    <div className="space-y-4">
                                        <div className="form-control">
                                            <label className="label py-1"><span className="label-text text-sm font-bold">Vor- & Nachname</span></label>
                                            <input type="text" {...register('billing_name')} className={`input input-bordered ${errors.billing_name ? 'input-error' : ''}`} />
                                        </div>
                                        <div className="form-control">
                                            <label className="label py-1"><span className="label-text text-sm font-bold">Firma (Optional)</span></label>
                                            <input type="text" {...register('billing_company')} className="input input-bordered" />
                                        </div>
                                        <div className="form-control">
                                            <label className="label py-1"><span className="label-text text-sm font-bold">Straße & Hausnummer</span></label>
                                            <input type="text" {...register('billing_street')} className={`input input-bordered ${errors.billing_street ? 'input-error' : ''}`} />
                                        </div>
                                        <div className="flex gap-4">
                                            <div className="form-control w-1/3">
                                                <label className="label py-1"><span className="label-text text-sm font-bold">PLZ</span></label>
                                                <input type="text" {...register('billing_zip')} className={`input input-bordered ${errors.billing_zip ? 'input-error' : ''}`} />
                                            </div>
                                            <div className="form-control flex-1">
                                                <label className="label py-1"><span className="label-text text-sm font-bold">Ort</span></label>
                                                <input type="text" {...register('billing_city')} className={`input input-bordered ${errors.billing_city ? 'input-error' : ''}`} />
                                            </div>
                                        </div>
                                        <div className="form-control">
                                            <label className="label py-1"><span className="label-text text-sm font-bold opacity-50">Land (Phase 1: Nur Inland)</span></label>
                                            <input type="text" value="Österreich" disabled className="input input-bordered opacity-70" />
                                        </div>
                                    </div>

                                    <div className="divider my-6"></div>

                                    {hasQuotes && (
                                        <div className="form-control mb-6">
                                            <label className="label py-1"><span className="label-text text-sm font-bold text-primary">Allgemeine Anmerkungen zum Angebot</span></label>
                                            <textarea {...register('quote_message')} className="textarea textarea-bordered h-20 w-full resize-none" placeholder="Zusätzliche Infos für den Fotografen..."></textarea>
                                        </div>
                                    )}

                                    {!hasQuotes && (
                                        <div className="form-control mb-6">
                                            <label className="label py-1"><span className="label-text text-sm font-bold">Zahlungsart</span></label>
                                            <div className="flex flex-col gap-3 bg-base-200 p-4 rounded-box border border-base-300">
                                                <label className="cursor-pointer flex items-center gap-3">
                                                    <input type="radio" name="payment_method" value="stripe" className="radio radio-primary" checked={paymentMethod === 'stripe'} onChange={() => setPaymentMethod('stripe')} />
                                                    <span className="font-bold flex items-center gap-2"><span className="iconify mdi--credit-card"></span> Kreditkarte (Stripe)</span>
                                                </label>
                                                {(user?.roles?.includes('client') || user?.is_power_user || user?.is_admin) && (
                                                    <label className="cursor-pointer flex items-center gap-3">
                                                        <input type="radio" name="payment_method" value="invoice" className="radio radio-primary" checked={paymentMethod === 'invoice'} onChange={() => setPaymentMethod('invoice')} />
                                                        <span className="font-bold flex items-center gap-2"><span className="iconify mdi--receipt-text-outline"></span> Kauf auf Rechnung</span>
                                                    </label>
                                                )}
                                            </div>
                                        </div>
                                    )}

                                    <div className="space-y-4 mb-8">
                                        <label className="cursor-pointer flex items-start gap-3">
                                            <input type="checkbox" {...register('agb_accepted')} className={`checkbox mt-0.5 ${errors.agb_accepted ? 'checkbox-error' : 'checkbox-primary'}`} />
                                            <span className="label-text text-sm leading-tight">
                                                Ich akzeptiere die <a href="/license-terms" target="_blank" className="link link-primary">Allgemeinen Geschäftsbedingungen und Lizenzvereinbarungen</a>.
                                            </span>
                                        </label>
                                        {!hasQuotes && (
                                            <label className="cursor-pointer flex items-start gap-3">
                                                <input type="checkbox" {...register('withdrawal_waived')} className={`checkbox mt-0.5 ${errors.withdrawal_waived ? 'checkbox-error' : 'checkbox-primary'}`} />
                                                <span className="label-text text-sm leading-tight">
                                                    Ich stimme der sofortigen Ausführung des Vertrages zu und verzichte auf mein Widerrufsrecht, da es sich um digitale Güter handelt.
                                                </span>
                                            </label>
                                        )}
                                    </div>

                                    <button 
                                        type="submit"
                                        className="btn btn-primary w-full btn-lg" 
                                        disabled={items.length === 0 || isSubmitting || (!termsLoading && (!terms?.bank_holder || !terms?.company_street || !terms?.company_zip || !terms?.company_city || !terms?.bank_iban))} 
                                    >
                                        {isSubmitting ? <span className="loading loading-spinner"></span> : (hasQuotes ? 'Unverbindlich anfragen' : 'Zahlungspflichtig bestellen')}
                                    </button>
                                </form>
                            )}
                        </div>
                    </div>
                )}
            </div>
        </PageLayout>
    );
}
