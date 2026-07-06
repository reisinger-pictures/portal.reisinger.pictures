import {useEffect, useState} from 'react';
import {CartItem, useCart} from '../../logic/CartContext';
import {useUI} from '../components/UIContext';
import {apiMutate, CheckoutResponse} from '../../api';
import {useAuth} from '../../logic/useAuth';
import {usePermissions} from '../../logic/usePermissions';
import useCoupon from '../../logic/useCoupon';
import {UserRole} from '../../logic/useUsers';
import {useForm} from 'react-hook-form';
import {zodResolver} from '@hookform/resolvers/zod';
import {z} from 'zod';
import {Link, useNavigate, useSearchParams} from 'react-router-dom';

import {t} from "@lingui/core/macro";
import {Trans} from "@lingui/react/macro";
import {loadStripe} from '@stripe/stripe-js';
import {Elements} from '@stripe/react-stripe-js';
import PageLayout from '../components/PageLayout';

import {StripeCheckoutForm} from './components/StripeCheckoutForm';
import {CartItemList} from './components/CartItemList';
import CouponInput from './components/CouponInput';

const stripePublicKey = import.meta.env.VITE_STRIPE_PUBLIC_KEY;
const stripePromise = loadStripe(stripePublicKey);

const checkoutSchema = z.object({
    billing_name: z.string().min(2, t`Name ist erforderlich`),
    billing_company: z.string().optional(),
    billing_street: z.string().min(3, t`Straße ist erforderlich`),
    billing_zip: z.string().min(4, t`PLZ ist erforderlich`),
    billing_city: z.string().min(2, t`Ort ist erforderlich`),
    quote_message: z.string().optional(),
    agb_accepted: z.literal(true, {message: t`Zustimmung erforderlich`}),
    withdrawal_waived: z.boolean().optional()
});

type CheckoutFormValues = z.infer<typeof checkoutSchema>;

export default function ClientCartView() {
    const {items, removeFromCart, totalAmount, clearCart, addToCart, volumeLicensing} = useCart();
    const cartGalleryId = items.length > 0 && items.every(i => i.galleryId === items[0].galleryId)
        ? items[0].galleryId
        : undefined;
    const {showToast} = useUI();
    const {user, mutate: mutateUser} = useAuth();
    const {isPowerUser, isAdmin} = usePermissions();
    const {couponCode, isValid: isCouponValid, removeCoupon} = useCoupon();
    const navigate = useNavigate();
    const [clientSecret, setClientSecret] = useState<string | null>(null);
    const [pendingOrderId, setPendingOrderId] = useState<string | null>(null);

    const [searchParams] = useSearchParams();
    const [paymentMethod, setPaymentMethod] = useState<'stripe' | 'invoice'>('stripe');

    const redirectStatus = searchParams.get('redirect_status');
    useEffect(() => {
        if (!redirectStatus) return;
        if (redirectStatus === 'succeeded') {
            clearCart();
            removeCoupon();
            mutateUser().then(() => {
                showToast('success', t`Zahlung erfolgreich!`);
                navigate('/orders', {replace: true});
            });
        } else {
            showToast('error', t`Zahlung fehlgeschlagen — bitte versuche es erneut.`);
            navigate('/cart', {replace: true});
        }
    }, [redirectStatus, clearCart, removeCoupon, mutateUser, showToast, navigate]);

    const hasQuotes = items.some(i => i.isQuote);

    const incomingToken = searchParams.get('quote_token');
    useEffect(() => {
        if (!incomingToken) return;

        fetch('/api/orders/quote-decode?token=' + encodeURIComponent(incomingToken))
            .then(async res => {
                const data = await res.json();
                if (!res.ok || !data.photos || data.price === undefined) {
                    // Expired / invalid / tampered token — do NOT clear the cart.
                    showToast('error', data.error || t`Angebot ist abgelaufen — bitte kontaktieren Sie den Fotografen.`);
                    return;
                }
                clearCart();
                data.photos.forEach((pid: string) => {
                    addToCart({
                        photoId: pid,
                        filename: 'Individuelles Angebot',
                        tier: 'original',
                        price: Math.round(data.price / data.photos.length),
                        isQuote: false,
                        notes: ''
                    });
                });
                showToast('info', t`Angebot aus Link wiederhergestellt.`);
                const newParams = new URLSearchParams(window.location.search);
                newParams.delete('quote_token');
                const cleanPath = window.location.pathname + (newParams.toString() ? '?' + newParams.toString() : '');
                window.history.replaceState(null, '', cleanPath);
            }).catch(err => console.error('Token Decode Error:', err));
    }, [incomingToken, clearCart, addToCart, showToast]);

    const {register, handleSubmit, reset, setError, formState: {errors, isSubmitting}} = useForm<CheckoutFormValues>({
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
        const updatedItem = {...item, [field]: value};
        addToCart(updatedItem);
    };

    const onCheckout = async (data: CheckoutFormValues) => {
        if (!hasQuotes && !data.withdrawal_waived) {
            setError('withdrawal_waived', {type: 'manual', message: 'Verzicht auf Widerruf ist zwingend erforderlich'});
            showToast('error', t`Bitte bestätige den Verzicht auf das Widerrufsrecht.`);
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
                withdrawal_waived: !!data.withdrawal_waived,
                coupon_code: isCouponValid && couponCode ? couponCode : null
            };

            const response = await apiMutate<CheckoutResponse>('/api/orders/checkout', 'POST', payload);

            if (response.requires_action && response.client_secret) {
                setClientSecret(response.client_secret);
                if (response.order_id) setPendingOrderId(response.order_id);
                showToast('info', t`Bitte schließe die Zahlung ab.`);
            } else if (response.success) {
                const invoiceNumber = response.invoice_number;
                showToast('success', hasQuotes ? t`Angebot erfolgreich angefragt!` : t`Bestellung erfolgreich! (Beleg: ${invoiceNumber})`);
                clearCart();
                removeCoupon();
                await mutateUser();
                navigate('/orders');
            }
        } catch (error: unknown) {
            showToast('error', error instanceof Error ? error.message : t`Fehler beim Checkout.`);
        }
    };

    return (
        <PageLayout currentView="cart">
            <div className="container mx-auto p-4 md:p-8 max-w-6xl">
                <h1 className="text-3xl font-bold mb-8 flex items-center gap-2">
                    <span className="iconify mdi--cart text-primary"></span> <Trans>Dein Warenkorb</Trans>
                </h1>

                {(!user) && (
                    <div className="alert alert-error shadow-sm mb-8">
                        <span className="iconify mdi--alert-circle text-xl"></span>
                        <span><Trans>Lade Rechnungsdaten...</Trans></span>
                    </div>
                )}

                {items.length === 0 ? (
                    <div
                        className="flex flex-col items-center justify-center py-20 opacity-50 bg-base-100 rounded-box border border-base-300">
                        <span className="iconify mdi--cart-off text-6xl mb-4"></span>
                        <p className="text-xl"><Trans>Dein Warenkorb ist leer.</Trans></p>
                        <Link to="/" className="btn btn-outline mt-6"><Trans>Zurück zur Startseite</Trans></Link>
                    </div>
                ) : (
                    <div className="grid grid-cols-1 lg:grid-cols-5 gap-8">

                        <div className="lg:col-span-3 space-y-6">
                            <CartItemList items={items} handleUpdateItem={handleUpdateItem} removeFromCart={removeFromCart}
                                           hasQuotes={hasQuotes} totalAmount={totalAmount} volumeLicensing={volumeLicensing}/>
                            <CouponInput galleryId={cartGalleryId} />
                        </div>

                        <div className="lg:col-span-2">
                            {clientSecret ? (
                                <div
                                    className="bg-base-100 p-6 rounded-box border border-base-300 shadow-sm sticky top-24">
                                    <h2 className="font-bold text-xl mb-6 flex items-center gap-2">
                                        <span className="iconify mdi--credit-card text-primary"></span> <Trans>Zahlung abschließen</Trans>
                                    </h2>
                                    <Elements stripe={stripePromise} options={{clientSecret}}>
                                        <StripeCheckoutForm orderId={pendingOrderId!} defaultEmail={user?.email}
                                                            defaultName={user?.billing_name || user?.name}
                                                            onSuccess={(webhookSuccess) => {
                                                                if (webhookSuccess) {
                                                                    showToast('success', t`Zahlung erfolgreich! Rechnung wurde versendet.`);
                                                                } else {
                                                                    showToast('info', t`Zahlung bei Stripe erfolgreich, aber das lokale Webhook-Event fehlt.`);
                                                                }
                                                                clearCart();
                                                                mutateUser();
                                                                navigate('/orders');
                                                            }}/>
                                    </Elements>
                                </div>
                            ) : (
                                <form id="checkout-form" onSubmit={handleSubmit(onCheckout)}
                                      className="bg-base-100 p-6 rounded-box border border-base-300 shadow-sm sticky top-24" noValidate>
                                    <h2 className="font-bold text-xl mb-6 flex items-center gap-2">
                                        <span
                                            className="iconify mdi--card-account-details text-primary"></span> <Trans>Rechnungsadresse</Trans>
                                    </h2>

                                    <div className="space-y-4">
                                        <div className="form-control">
                                            <label className="label py-1"><span
                                                className="label-text text-sm font-bold"><Trans>Vor- & Nachname</Trans></span></label>
                                            <input type="text" required {...register('billing_name')}
                                                   className={`input input-bordered ${errors.billing_name ? 'input-error' : ''}`}/>
                                        </div>
                                        <div className="form-control">
                                            <label className="label py-1"><span
                                                className="label-text text-sm font-bold"><Trans>Firma</Trans></span></label>
                                            <input type="text" {...register('billing_company')}
                                                   className="input input-bordered"/>
                                        </div>
                                        <div className="form-control">
                                            <label className="label py-1"><span
                                                className="label-text text-sm font-bold"><Trans>Straße & Hausnummer</Trans></span></label>
                                            <input type="text" required {...register('billing_street')}
                                                   className={`input input-bordered ${errors.billing_street ? 'input-error' : ''}`}/>
                                        </div>
                                        <div className="flex gap-4">
                                            <div className="form-control w-1/3">
                                                <label className="label py-1"><span
                                                    className="label-text text-sm font-bold"><Trans>PLZ</Trans></span></label>
                                                <input type="text" required {...register('billing_zip')}
                                                       className={`input input-bordered ${errors.billing_zip ? 'input-error' : ''}`}/>
                                            </div>
                                            <div className="form-control flex-1">
                                                <label className="label py-1"><span
                                                    className="label-text text-sm font-bold"><Trans>Ort</Trans></span></label>
                                                <input type="text" required {...register('billing_city')}
                                                       className={`input input-bordered ${errors.billing_city ? 'input-error' : ''}`}/>
                                            </div>
                                        </div>
                                        <div className="form-control">
                                            <label className="label py-1"><span
                                                className="label-text text-sm font-bold opacity-50"><Trans>Land</Trans></span></label>
                                            <input type="text" value={t`Österreich`} disabled
                                                   className="input input-bordered opacity-70"/>
                                        </div>
                                    </div>

                                    <div className="divider my-6"></div>

                                    {hasQuotes && (
                                        <div className="form-control mb-6">
                                            <label className="label py-1"><span
                                                className="label-text text-sm font-bold text-primary"><Trans>Allgemeine Anmerkungen zum Angebot</Trans></span></label>
                                            <textarea {...register('quote_message')}
                                                      className="textarea textarea-bordered h-20 w-full resize-none"
                                                      placeholder={t`Zusätzliche Infos für den Fotografen...`}></textarea>
                                        </div>
                                    )}

                                    {!hasQuotes && (
                                        <div className="form-control mb-6">
                                            <label className="label py-1"><span
                                                className="label-text text-sm font-bold"><Trans>Zahlungsart</Trans></span></label>
                                            <div
                                                className="flex flex-col gap-3 bg-base-200 p-4 rounded-box border border-base-300">
                                                <label className="cursor-pointer flex items-center gap-3">
                                                    <input type="radio" name="payment_method" value="stripe"
                                                           className="radio radio-primary"
                                                           checked={paymentMethod === 'stripe'}
                                                           onChange={() => setPaymentMethod('stripe')}/>
                                                    <span className="font-bold flex items-center gap-2"><span
                                                        className="iconify mdi--credit-card"></span> <Trans>Kreditkarte (Stripe)</Trans></span>
                                                </label>
                                                {(user?.roles?.includes(UserRole.CLIENT) || isPowerUser || isAdmin) && (
                                                    <label className="cursor-pointer flex items-center gap-3">
                                                        <input type="radio" name="payment_method" value="invoice"
                                                               className="radio radio-primary"
                                                               checked={paymentMethod === 'invoice'}
                                                               onChange={() => setPaymentMethod('invoice')}/>
                                                        <span className="font-bold flex items-center gap-2"><span
                                                            className="iconify mdi--receipt-text-outline"></span> <Trans>Kauf auf Rechnung</Trans></span>
                                                    </label>
                                                )}
                                            </div>
                                        </div>
                                    )}

                                    <div className="space-y-4 mb-8">
                                        <label className="cursor-pointer flex items-start gap-3 p-3 rounded-box hover:bg-base-300/50 transition-colors">
                                            <input type="checkbox" {...register('agb_accepted')}
                                                   className={`checkbox mt-0.5 shrink-0 ${errors.agb_accepted ? 'checkbox-error' : 'checkbox-primary'}`}/>
                                            <span className="label-text text-sm leading-tight">
                                                <Trans>Ich akzeptiere die <a href="/license-terms" target="_blank"
                                                                      className="link link-primary">Allgemeinen Geschäftsbedingungen und Lizenzvereinbarungen</a>.</Trans>
                                            </span>
                                        </label>
                                        {!hasQuotes && (
                                            <label className="cursor-pointer flex items-start gap-3 p-3 rounded-box hover:bg-base-300/50 transition-colors">
                                                <input type="checkbox" {...register('withdrawal_waived')}
                                                       className={`checkbox mt-0.5 shrink-0 ${errors.withdrawal_waived ? 'checkbox-error' : 'checkbox-primary'}`}/>
                                                <span className="label-text text-sm leading-tight">
                                                    <Trans>Ich stimme der sofortigen Ausführung des Vertrages zu und verzichte auf mein Widerrufsrecht, da es sich um digitale Güter handelt.</Trans>
                                                </span>
                                            </label>
                                        )}
                                    </div>

                                    <button
                                        type="submit"
                                        className="btn btn-primary w-full btn-lg"
                                        disabled={items.length === 0 || isSubmitting}
                                    >
                                        {isSubmitting ? <span
                                            className="loading loading-spinner"></span> : (hasQuotes ? <Trans>Unverbindlich anfragen</Trans> : <Trans>Zahlungspflichtig bestellen</Trans>)}
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
