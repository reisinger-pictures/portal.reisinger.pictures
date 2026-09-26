import {useEffect, useState} from 'react';
import {CartItem, useCart} from '../../logic/CartContext';
import type {VolumeLicensingResult} from '../../logic/CartContext';
import {splitTotalEvenly} from '../../logic/cartLogic';
import {useUI} from '../components/UIContext';
import {apiMutate, type ApiError, type CheckoutResponse} from '../../api';
import {useAuth} from '../../logic/useAuth';
import {usePermissions} from '../../logic/usePermissions';
import useCoupon, {calculateCouponDiscount} from '../../logic/useCoupon';
import {UserRole} from '../../logic/useUsers';
import {useForm} from 'react-hook-form';
import {zodResolver} from '@hookform/resolvers/zod';
import {z} from 'zod';
import {Link, useNavigate, useSearchParams} from 'react-router-dom';

import {t} from "@lingui/core/macro";
import {Trans} from "@lingui/react/macro";
import {Elements} from '@stripe/react-stripe-js';
import PageLayout from '../components/PageLayout';

import {stripePromise} from '../../logic/stripe';
import {
    clearCheckoutSession,
    createCheckoutCartMarker,
    loadOrCreateCheckoutSession,
    replaceCheckoutSession
} from '../../logic/checkoutSession';
import {StripeCheckoutForm} from './components/StripeCheckoutForm';
import {CartItemList} from './components/CartItemList';
import {TurnstileWidget} from './components/TurnstileWidget';
import CouponInput from './components/CouponInput';

// Schema-Factory (kein module-scope `t` — siehe frontend/AGENTS.md, Lingui-Regel)
const createCheckoutSchema = () => z.object({
    billing_name: z.string().min(2, t`Name ist erforderlich`),
    billing_company: z.string().optional(),
    billing_street: z.string().min(3, t`Straße ist erforderlich`),
    billing_zip: z.string().min(4, t`PLZ ist erforderlich`),
    billing_city: z.string().min(2, t`Ort ist erforderlich`),
    quote_message: z.string().optional(),
    agb_accepted: z.literal(true, {message: t`Zustimmung erforderlich`}),
    withdrawal_waived: z.boolean().optional()
});

type CheckoutFormValues = z.infer<ReturnType<typeof createCheckoutSchema>>;

const hasApiErrorFlag = (error: unknown, status: number, flag: string): boolean => {
    if (!(error instanceof Error)) return false;
    const apiError = error as ApiError;
    if (apiError.status !== status || typeof apiError.info !== 'object' || apiError.info === null) {
        return false;
    }
    return (apiError.info as Record<string, unknown>)[flag] === true;
};

const isTurnstileRequiredError = (error: unknown) => hasApiErrorFlag(error, 403, 'turnstile_required');
const isIdempotencyConflict = (error: unknown) => hasApiErrorFlag(error, 409, 'idempotency_conflict');

interface CouponScopeContext {
    galleryIds: string[];
    metaGalleryIds: string[];
    validationContextKey: string;
}

const couponScopeContextForCart = (
    items: CartItem[],
    volumeLicensing: VolumeLicensingResult | undefined,
    quoteToken: string | null,
    enabled: boolean,
): CouponScopeContext => {
    let eligibleItems: CartItem[];
    if (volumeLicensing?.groups === undefined) {
        // Legacy pricing results did not expose groups; before mixed-cart
        // grouping, every non-quote item belonged to the volume scope.
        eligibleItems = items.filter(item => !item.isQuote);
    } else {
        const volumePhotoIds = new Set(
            volumeLicensing.groups
                .filter(group => group.isVolumePricing)
                .flatMap(group => group.items.map(item => item.photoId)),
        );
        eligibleItems = items.filter(item => !item.isQuote && volumePhotoIds.has(item.photoId));
    }

    const galleryIds = Array.from(new Set(
        eligibleItems
            .map(item => item.galleryId)
            .filter((id): id is string => typeof id === 'string' && id.length > 0),
    )).sort();
    const metaGalleryIds = Array.from(new Set(
        eligibleItems
            .map(item => item.galleryGroupId)
            .filter((id): id is string => typeof id === 'string' && id.length > 0),
    )).sort();
    // Include the complete cart identity, not only coupon-eligible volume
    // items. Otherwise a scope/quote item mutation could leave a previously
    // validated coupon attached to a different cart.
    const identityItems = items
        .map(item => ({
            photoId: item.photoId,
            tier: item.tier,
            galleryId: item.galleryId ?? null,
            galleryGroupId: item.galleryGroupId ?? null,
            isQuote: item.isQuote === true,
        }))
        .sort((a, b) => a.photoId.localeCompare(b.photoId));
    const itemPrices = Object.entries(volumeLicensing?.volumeItemPrices ?? {})
        .sort(([left], [right]) => left.localeCompare(right));

    return {
        galleryIds,
        metaGalleryIds,
        validationContextKey: JSON.stringify({
            enabled,
            quoteToken,
            items: identityItems,
            volumeSubtotalCents: volumeLicensing?.volumeSubtotalCents ?? null,
            itemPrices,
        }),
    };
};

export default function ClientCartView() {
    "use no memo";
    const {items, quoteToken, setQuoteToken, removeFromCart, totalAmount, clearCart, addToCart, volumeLicensing, unresolvedItemCount} = useCart();
    const hasQuotes = items.some(i => i.isQuote);
    const hasVolumePricing = volumeLicensing?.isVolumePricing ?? true;
    const volumeSubtotalCents = volumeLicensing?.volumeSubtotalCents;
    const volumeItemPrices = Object.values(volumeLicensing?.volumeItemPrices ?? {});
    const couponEligible = !hasQuotes && quoteToken === null && hasVolumePricing;
    const couponScopeContext = couponScopeContextForCart(
        items,
        volumeLicensing,
        quoteToken,
        couponEligible,
    );
    const {showToast} = useUI();
    const {user, mutate: mutateUser} = useAuth();
    const userId = user?.id ?? null;
    const {isPowerUser, isAdmin} = usePermissions();
    const couponState = useCoupon({
        galleryId: couponScopeContext.galleryIds,
        metaGalleryId: couponScopeContext.metaGalleryIds,
        validationContextKey: couponScopeContext.validationContextKey,
        pricedTotalCents: volumeSubtotalCents,
        eligibleItemPricesCents: volumeItemPrices,
        enabled: couponEligible,
    });
    const {couponCode, coupon: appliedCoupon, isValid: isCouponValid, discount: couponDiscount, removeCoupon} = couponState;
    const currentCouponDiscount = volumeSubtotalCents !== undefined && appliedCoupon
        ? calculateCouponDiscount(appliedCoupon, volumeSubtotalCents, volumeItemPrices)
        : couponDiscount;
    const effectiveCouponDiscount = couponEligible && isCouponValid && typeof currentCouponDiscount === 'number'
        ? Math.min(Math.max(0, currentCouponDiscount), Math.max(0, totalAmount))
        : 0;
    const netTotalAmount = hasQuotes
        ? 0
        : Math.max(0, totalAmount - effectiveCouponDiscount);
    const isFreeCheckout = !hasQuotes
        && quoteToken === null
        && totalAmount > 0
        && effectiveCouponDiscount >= totalAmount;
    const navigate = useNavigate();
    const [searchParams] = useSearchParams();
    const redirectStatus = searchParams.get('redirect_status');
    const [clientSecret, setClientSecret] = useState<string | null>(null);
    const [pendingOrderId, setPendingOrderId] = useState<string | null>(null);
    const [checkoutBilling, setCheckoutBilling] = useState<{line1: string; postalCode: string; city: string} | null>(null);
    const [paymentRecoveryPending, setPaymentRecoveryPending] = useState(() => redirectStatus === 'succeeded');
    const [turnstileRequired, setTurnstileRequired] = useState(false);
    const [turnstileToken, setTurnstileToken] = useState<string | null>(null);
    const [turnstileWidgetVersion, setTurnstileWidgetVersion] = useState(0);
    const turnstileSiteKey = import.meta.env.VITE_TURNSTILE_SITE_KEY?.trim() ?? '';

    const [paymentMethod, setPaymentMethod] = useState<'stripe' | 'invoice'>('stripe');
    const cartMarker = createCheckoutCartMarker(items, quoteToken);

    const checkoutSchema = createCheckoutSchema();

    useEffect(() => {
        if (!redirectStatus) return;
        if (redirectStatus === 'succeeded') {
            showToast('info', t`Die Zahlung wird geprüft. Der Warenkorb bleibt für die Wiederherstellung erhalten.`);
            navigate('/cart', {replace: true});
        } else {
            showToast('error', t`Zahlung fehlgeschlagen — bitte versuche es erneut.`);
            navigate('/cart', {replace: true});
        }
    }, [redirectStatus, showToast, navigate]);

    const isImmediateStripeCheckout = !hasQuotes && paymentMethod === 'stripe' && netTotalAmount > 0;

    const getCheckoutRecovery = () => (
        userId && items.length > 0
            ? loadOrCreateCheckoutSession(userId, cartMarker)
            : null
    );

    const rotateCheckoutRecovery = () => {
        if (userId) replaceCheckoutSession(userId, cartMarker);
    };

    const handlePaymentMethodChange = (method: 'stripe' | 'invoice') => {
        if (paymentRecoveryPending) return;
        setPaymentMethod(method);
        const nextIsImmediateStripe = !hasQuotes && method === 'stripe' && netTotalAmount > 0;
        if (!nextIsImmediateStripe) {
            setTurnstileRequired(false);
            setTurnstileToken(null);
            setTurnstileWidgetVersion(version => version + 1);
        }
    };

    const incomingToken = searchParams.get('quote_token');
    useEffect(() => {
        if (!incomingToken) return;

        // Quote decoding is a public token endpoint. Keep it on a direct fetch
        // (no centralized auth refresh), but make the request cancellable so a
        // changed URL/unmounted cart can never apply an older quote.
        const controller = new AbortController();
        let cancelled = false;

        const decodeQuote = async () => {
            try {
                const res = await fetch('/api/orders/quote-decode?token=' + encodeURIComponent(incomingToken), {
                    signal: controller.signal
                });
                if (cancelled || controller.signal.aborted) return;
                const data = await res.json();
                if (cancelled || controller.signal.aborted) return;
                if (!res.ok || !data.photos || data.price === undefined) {
                    // Expired / invalid / tampered token — do NOT clear the cart.
                    showToast('error', data.error || t`Angebot ist abgelaufen — bitte kontaktieren Sie den Fotografen.`);
                    return;
                }
                if (userId) clearCheckoutSession(userId);
                clearCart();
                const perPhotoPrices = splitTotalEvenly(data.price, data.photos.length);
                data.photos.forEach((pid: string, index: number) => {
                    addToCart({
                        photoId: pid,
                        filename: 'Individuelles Angebot',
                        tier: 'original',
                        price: perPhotoPrices[index],
                        isQuote: false,
                        notes: ''
                    });
                });
                setQuoteToken(incomingToken);
                showToast('info', t`Angebot aus Link wiederhergestellt.`);
                const newParams = new URLSearchParams(window.location.search);
                newParams.delete('quote_token');
                const cleanPath = window.location.pathname + (newParams.toString() ? '?' + newParams.toString() : '');
                window.history.replaceState(null, '', cleanPath);
            } catch (err) {
                if (cancelled || controller.signal.aborted) return;
                console.error('Token Decode Error:', err);
            }
        };

        void decodeQuote();
        return () => {
            cancelled = true;
            controller.abort();
        };
    }, [incomingToken, userId, clearCart, addToCart, setQuoteToken, showToast]);

    const {register, handleSubmit, reset, setError, formState: {errors, isSubmitting, isDirty}} = useForm<CheckoutFormValues>({
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
        // SWR may revalidate the auth response on focus. Do not replace values
        // the customer has already edited, including consent checkboxes.
        if (user && !isDirty) {
            reset({
                billing_name: user.billing_name || user.name || '',
                billing_company: user.billing_company || '',
                billing_street: user.billing_street || '',
                billing_zip: user.billing_zip || '',
                billing_city: user.billing_city || '',
                quote_message: ''
            });
        }
    }, [user, reset, isDirty]);

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
        if (turnstileRequired && !isImmediateStripeCheckout) {
            setTurnstileRequired(false);
            setTurnstileToken(null);
            setTurnstileWidgetVersion(version => version + 1);
        }
        if (isImmediateStripeCheckout && turnstileRequired && !turnstileSiteKey) {
            showToast('error', t`Die Sicherheitsprüfung ist nicht konfiguriert. Bitte wende dich an den Support.`);
            return;
        }
        if (isImmediateStripeCheckout && turnstileRequired && !turnstileToken) {
            showToast('info', t`Bitte bestätige zuerst die Sicherheitsprüfung.`);
            return;
        }

        const checkoutRecovery = getCheckoutRecovery();
        if (!checkoutRecovery) return;

        const turnstileTokenForRequest = isImmediateStripeCheckout && turnstileRequired ? turnstileToken : null;
        try {
            const payload: Record<string, unknown> = {
                items,
                    quote_token: quoteToken,
                billing_name: data.billing_name,
                billing_company: data.billing_company,
                billing_street: data.billing_street,
                billing_zip: data.billing_zip,
                billing_city: data.billing_city,
                payment_method: paymentMethod,
                quote_message: data.quote_message,
                withdrawal_waived: !!data.withdrawal_waived,
                coupon_code: couponEligible && isCouponValid && couponCode ? couponCode : null
            };
            if (turnstileTokenForRequest) payload.turnstile_token = turnstileTokenForRequest;

            const response = await apiMutate<CheckoutResponse>('/api/orders/checkout', 'POST', payload, {
                headers: {'Idempotency-Key': checkoutRecovery.idempotencyKey}
            });

            if (response.payment_pending) {
                // The remote PI may already be succeeded while the signed
                // webhook is still catching up. Keep the recovery session and
                // poll/retry the order status instead of treating it as a new
                // actionable payment.
                setPaymentRecoveryPending(true);
                setTurnstileRequired(false);
                setTurnstileToken(null);
                setClientSecret(null);
                setPendingOrderId(response.order_id ?? null);
                setCheckoutBilling(null);
                showToast('info', t`Die Zahlung wird derzeit geprüft. Bitte später erneut prüfen.`);
            } else if (response.requires_action && response.client_secret) {
                setPaymentRecoveryPending(false);
                setTurnstileRequired(false);
                setTurnstileToken(null);
                setClientSecret(response.client_secret);
                if (response.order_id) setPendingOrderId(response.order_id);
                // Billing-Adresse an Stripe übergeben: Stripe sammelt sonst eine
                // PLZ mit US-Default-Country -> "Postleitzahl ist ungültig" für
                // österreichische PLZ wie 1010 (CI-reproduzierbar, lokal nicht).
                setCheckoutBilling({line1: data.billing_street, postalCode: data.billing_zip, city: data.billing_city});
                showToast('info', t`Bitte schließe die Zahlung ab.`);
            } else if (response.success) {
                setPaymentRecoveryPending(false);
                setTurnstileRequired(false);
                setTurnstileToken(null);
                if (userId) clearCheckoutSession(userId);
                const invoiceNumber = response.invoice_number;
                const successMessage = hasQuotes
                    ? t`Angebot erfolgreich angefragt!`
                    : invoiceNumber
                        ? t`Bestellung erfolgreich! (Beleg: ${invoiceNumber})`
                        : t`Bestellung erfolgreich!`;
                showToast('success', successMessage);
                clearCart();
                removeCoupon();
                await mutateUser();
                navigate('/orders');
            }
        } catch (error: unknown) {
            if (turnstileTokenForRequest) {
                setTurnstileToken(null);
                setTurnstileWidgetVersion(version => version + 1);
            }
            if (isIdempotencyConflict(error)) {
                rotateCheckoutRecovery();
            }
            if (isImmediateStripeCheckout && isTurnstileRequiredError(error)) {
                setTurnstileRequired(true);
                if (turnstileSiteKey) {
                    showToast('info', t`Bitte bestätige die Sicherheitsprüfung und versuche es erneut.`);
                } else {
                    showToast('error', t`Die Sicherheitsprüfung ist nicht konfiguriert. Bitte wende dich an den Support.`);
                }
                return;
            }
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

                        {unresolvedItemCount > 0 && (
                            <div role="status" data-testid="cart-price-pending"
                                 className="lg:col-span-5 alert alert-warning">
                                <span className="iconify mdi--alert-circle text-xl" aria-hidden="true"></span>
                                <Trans>Für {unresolvedItemCount} Artikel ist der Preis noch nicht verfügbar. Die Summe wird beim Bezahlen verbindlich berechnet.</Trans>
                            </div>
                        )}

                        <div className="lg:col-span-3 space-y-6">
                            <CartItemList items={items} handleUpdateItem={handleUpdateItem} removeFromCart={removeFromCart}
                                           hasQuotes={hasQuotes} totalAmount={totalAmount} readOnly={paymentRecoveryPending}
                                           volumeLicensing={quoteToken === null ? volumeLicensing : undefined} discountAmount={effectiveCouponDiscount}
                                           netTotalAmount={netTotalAmount}/>
                            {couponEligible && <CouponInput key={couponScopeContext.validationContextKey}
                                                               state={couponState} disabled={paymentRecoveryPending}
                                                               displayedDiscount={effectiveCouponDiscount} />}
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
                                                            billingAddress={checkoutBilling ?? undefined}
                                                            onSuccess={(serverPaid) => {
                                                                if (!serverPaid) {
                                                                    setClientSecret(null);
                                                                    setPendingOrderId(null);
                                                                    setCheckoutBilling(null);
                                                                    setPaymentRecoveryPending(true);
                                                                    showToast('info', t`Die Zahlung ist noch nicht als bezahlt bestätigt. Der Warenkorb bleibt erhalten.`);
                                                                    return;
                                                                }
                                                                setPaymentRecoveryPending(false);
                                                                if (userId) clearCheckoutSession(userId);
                                                                showToast('success', t`Zahlung erfolgreich! Rechnung wurde versendet.`);
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

                                    {paymentRecoveryPending && (
                                        <div role="status"
                                             className="alert alert-info mb-6">
                                            <span className="iconify mdi--refresh text-xl"></span>
                                            <span><Trans>Die Zahlung ist noch nicht als bezahlt bestätigt. Warenkorb und Checkout-Wiederherstellung bleiben erhalten. Bitte später erneut prüfen; dabei wird der bestehende Zahlungsvorgang wiederverwendet.</Trans></span>
                                        </div>
                                    )}

                                    <div className="space-y-4">
                                        <div className="form-control">
                                            <label className="label py-1" htmlFor="checkout-billing-name"><span
                                                className="label-text text-sm font-bold"><Trans>Vor- & Nachname</Trans></span></label>
                                            <input id="checkout-billing-name" type="text" required {...register('billing_name')} disabled={paymentRecoveryPending}
                                                   className={`input input-bordered ${errors.billing_name ? 'input-error' : ''}`}/>
                                        </div>
                                        <div className="form-control">
                                            <label className="label py-1" htmlFor="checkout-billing-company"><span
                                                className="label-text text-sm font-bold"><Trans>Firma</Trans></span></label>
                                            <input id="checkout-billing-company" type="text" {...register('billing_company')} disabled={paymentRecoveryPending}
                                                   className="input input-bordered"/>
                                        </div>
                                        <div className="form-control">
                                            <label className="label py-1" htmlFor="checkout-billing-street"><span
                                                className="label-text text-sm font-bold"><Trans>Straße & Hausnummer</Trans></span></label>
                                            <input id="checkout-billing-street" type="text" required {...register('billing_street')} disabled={paymentRecoveryPending}
                                                   className={`input input-bordered ${errors.billing_street ? 'input-error' : ''}`}/>
                                        </div>
                                        <div className="flex gap-4">
                                            <div className="form-control w-1/3">
                                                <label className="label py-1" htmlFor="checkout-billing-zip"><span
                                                    className="label-text text-sm font-bold"><Trans>PLZ</Trans></span></label>
                                                <input id="checkout-billing-zip" type="text" required {...register('billing_zip')} disabled={paymentRecoveryPending}
                                                       className={`input input-bordered ${errors.billing_zip ? 'input-error' : ''}`}/>
                                            </div>
                                            <div className="form-control flex-1">
                                                <label className="label py-1" htmlFor="checkout-billing-city"><span
                                                    className="label-text text-sm font-bold"><Trans>Ort</Trans></span></label>
                                                <input id="checkout-billing-city" type="text" required {...register('billing_city')} disabled={paymentRecoveryPending}
                                                       className={`input input-bordered ${errors.billing_city ? 'input-error' : ''}`}/>
                                            </div>
                                        </div>
                                        <div className="form-control">
                                            <label className="label py-1" htmlFor="checkout-billing-country"><span
                                                className="label-text text-sm font-bold opacity-50"><Trans>Land</Trans></span></label>
                                            <input id="checkout-billing-country" type="text" value={t`Österreich`} disabled
                                                   className="input input-bordered opacity-70"/>
                                        </div>
                                    </div>

                                    <div className="divider my-6"></div>

                                    {hasQuotes && (
                                        <div className="form-control mb-6">
                                            <label className="label py-1" htmlFor="checkout-quote-message"><span
                                                className="label-text text-sm font-bold text-primary"><Trans>Allgemeine Anmerkungen zum Angebot</Trans></span></label>
                                            <textarea id="checkout-quote-message" {...register('quote_message')} readOnly={paymentRecoveryPending}
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
                                                <label className="cursor-pointer flex items-center gap-3" htmlFor="checkout-payment-stripe">
                                                    <input id="checkout-payment-stripe" type="radio" name="payment_method" value="stripe" required aria-label={t`Kreditkarte (Stripe)`}
                                                           className="radio radio-primary"
                                                           checked={paymentMethod === 'stripe'}
                                                           disabled={paymentRecoveryPending}
                                                           onChange={() => handlePaymentMethodChange('stripe')}/>
                                                    <span className="font-bold flex items-center gap-2"><span
                                                        className="iconify mdi--credit-card"></span> <Trans>Kreditkarte (Stripe)</Trans></span>
                                                </label>
                                                {(user?.roles?.includes(UserRole.CLIENT) || isPowerUser || isAdmin) && (
                                                    <label className="cursor-pointer flex items-center gap-3" htmlFor="checkout-payment-invoice">
                                                        <input id="checkout-payment-invoice" type="radio" name="payment_method" value="invoice" required aria-label={t`Kauf auf Rechnung`}
                                                               className="radio radio-primary"
                                                               checked={paymentMethod === 'invoice'}
                                                               disabled={paymentRecoveryPending}
                                                               onChange={() => handlePaymentMethodChange('invoice')}/>
                                                        <span className="font-bold flex items-center gap-2"><span
                                                            className="iconify mdi--receipt-text-outline"></span> <Trans>Kauf auf Rechnung</Trans></span>
                                                    </label>
                                                )}
                                            </div>
                                        </div>
                                    )}

                                    {isFreeCheckout && (
                                         <div role="status" className="alert alert-success mb-6">
                                             <Trans>Der Rabatt macht diese Bestellung kostenlos. Eine Stripe-Zahlung ist nicht erforderlich.</Trans>
                                         </div>
                                     )}

                                    <div className="space-y-4 mb-8">
                                        <label className="cursor-pointer flex items-start gap-3 p-3 rounded-box hover:bg-base-300/50 transition-colors" htmlFor="checkout-agb-accepted">
                                            <input id="checkout-agb-accepted" type="checkbox" required {...register('agb_accepted')} disabled={paymentRecoveryPending}
                                                   className={`checkbox mt-0.5 shrink-0 ${errors.agb_accepted ? 'checkbox-error' : 'checkbox-primary'}`}/>
                                            <span className="label-text text-sm leading-tight">
                                                <Trans>Ich akzeptiere die <a href="/license-terms" target="_blank" rel="noopener noreferrer"
                                                                      className="link link-primary">Allgemeinen Geschäftsbedingungen und Lizenzvereinbarungen</a>.</Trans>
                                            </span>
                                        </label>
                                        {!hasQuotes && (
                                            <label className="cursor-pointer flex items-start gap-3 p-3 rounded-box hover:bg-base-300/50 transition-colors" htmlFor="checkout-withdrawal-waived">
                                                <input id="checkout-withdrawal-waived" type="checkbox" required {...register('withdrawal_waived')} disabled={paymentRecoveryPending}
                                                       className={`checkbox mt-0.5 shrink-0 ${errors.withdrawal_waived ? 'checkbox-error' : 'checkbox-primary'}`}/>
                                                <span className="label-text text-sm leading-tight">
                                                    <Trans>Ich bin einverstanden, dass der Download meiner Fotos unmittelbar nach Zahlungsabschluss beginnt (sofortiger Download). Mir ist bekannt, dass mein Rücktritts- bzw. Widerrufsrecht damit vorzeitig erlischt.</Trans>
                                                </span>
                                            </label>
                                        )}
                                    </div>

                                    {isImmediateStripeCheckout && turnstileRequired && !turnstileSiteKey && (
                                        <div role="alert"
                                             className="alert alert-error mb-6">
                                            <span className="iconify mdi--alert-circle text-xl"></span>
                                            <span><Trans>Die Sicherheitsprüfung ist nicht konfiguriert. Bitte wende dich an den Support.</Trans></span>
                                        </div>
                                    )}

                                    {isImmediateStripeCheckout && turnstileRequired && turnstileSiteKey && (
                                        <section aria-labelledby="checkout-turnstile-heading"
                                                 className="rounded-box border border-base-300 bg-base-200 p-4 mb-6 space-y-3">
                                            <h3 id="checkout-turnstile-heading" className="font-bold">
                                                <Trans>Sicherheitsprüfung</Trans>
                                            </h3>
                                            <p className="text-sm opacity-70">
                                                <Trans>Bestätige die einmalige Prüfung, um den Checkout fortzusetzen.</Trans>
                                            </p>
                                            <TurnstileWidget
                                                key={turnstileWidgetVersion}
                                                siteKey={turnstileSiteKey}
                                                userId={user?.id ?? ''}
                                                onSuccess={setTurnstileToken}
                                                onExpire={() => setTurnstileToken(null)}
                                                onError={() => {
                                                    setTurnstileToken(null);
                                                    showToast('error', t`Die Sicherheitsprüfung konnte nicht geladen werden. Bitte versuche es erneut.`);
                                                }}
                                            />
                                        </section>
                                    )}

                                    <button
                                        type="submit"
                                        className="btn btn-primary w-full btn-lg"
                                        disabled={items.length === 0 || isSubmitting || (isImmediateStripeCheckout && turnstileRequired && (!turnstileSiteKey || !turnstileToken))}
                                    >
                                        {isSubmitting ? <span
                                            className="loading loading-spinner"></span> : paymentRecoveryPending ? <Trans>Zahlung erneut prüfen</Trans> : (hasQuotes ? <Trans>Unverbindlich anfragen</Trans> : isFreeCheckout ? <Trans>Kostenlos bestellen</Trans> : <Trans>Zahlungspflichtig bestellen</Trans>)}
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
