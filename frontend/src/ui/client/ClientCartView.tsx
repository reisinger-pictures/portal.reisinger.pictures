import { useCart } from '../../logic/CartContext';
import { useUI } from '../components/UIContext';
import { apiMutate } from '../../api';
import { useAuth } from '../../logic/useAuth';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { useEffect } from 'react';
import { useNavigate, Link } from 'react-router-dom';
import { usePricing, ResolutionTier, UsageTier, DurationTier } from '../../logic/usePricing';
import PageLayout from '../components/PageLayout';

const checkoutSchema = z.object({
    billing_name: z.string().min(2, 'Name ist erforderlich'),
    billing_company: z.string().optional(),
    billing_street: z.string().min(3, 'Straße ist erforderlich'),
    billing_zip: z.string().min(4, 'PLZ ist erforderlich'),
    billing_city: z.string().min(2, 'Ort ist erforderlich'),
    agb_accepted: z.literal(true, { errorMap: () => ({ message: 'Zustimmung erforderlich' }) }),
    withdrawal_waived: z.literal(true, { errorMap: () => ({ message: 'Zustimmung erforderlich' }) }),
});

type CheckoutFormValues = z.infer<typeof checkoutSchema>;

export default function ClientCartView() {
    const { items, removeFromCart, totalAmount, clearCart, addToCart } = useCart();
    const { showToast } = useUI();
    const { user, mutate: mutateUser } = useAuth();
    const navigate = useNavigate();
    const { calculateUpgradePrice } = usePricing(15.00);

    const { register, handleSubmit, reset, formState: { errors, isSubmitting } } = useForm<CheckoutFormValues>({
        resolver: zodResolver(checkoutSchema),
        defaultValues: {
            billing_name: '',
            billing_company: '',
            billing_street: '',
            billing_zip: '',
            billing_city: ''
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
            });
        }
    }, [user, reset]);

    const handleUpdateItem = (item: any, field: string, value: string) => {
        const updatedItem = { ...item, [field]: value };
        const newPrice = calculateUpgradePrice(
            user?.flatrate_level,
            updatedItem.tier as ResolutionTier,
            updatedItem.usage as UsageTier,
            updatedItem.duration as DurationTier
        );
        addToCart({ ...updatedItem, price: newPrice });
    };

    const onCheckout = async (data: CheckoutFormValues) => {
        try {
            const payload = {
                items,
                billing_name: data.billing_name,
                billing_company: data.billing_company,
                billing_street: data.billing_street,
                billing_zip: data.billing_zip,
                billing_city: data.billing_city
            };

            const response = await apiMutate<{ success: boolean, invoice_number: string }>('/api/orders/checkout', 'POST', payload);
            
            if (response.success) {
                showToast('success', `Bestellung erfolgreich! (Beleg: ${response.invoice_number})`);
                clearCart();
                await mutateUser();
                navigate('/orders');
            }
        } catch (error: any) {
            showToast('error', error.message || 'Fehler beim Checkout.');
        }
    };

    return (
        <PageLayout currentView="cart">
            <div className="container mx-auto p-4 md:p-8 max-w-6xl">
                <h1 className="text-3xl font-bold mb-8 flex items-center gap-2">
                    <span className="iconify mdi--cart text-primary"></span> Dein Warenkorb
                </h1>

                {items.length === 0 ? (
                    <div className="flex flex-col items-center justify-center py-20 opacity-50 bg-base-100 rounded-box border border-base-300">
                        <span className="iconify mdi--cart-off text-6xl mb-4"></span>
                        <p className="text-xl">Dein Warenkorb ist leer.</p>
                        <Link to="/" className="btn btn-outline mt-6">Zurück zur Startseite</Link>
                    </div>
                ) : (
                    <div className="grid grid-cols-1 lg:grid-cols-5 gap-8">
                        {/* LINKE SPALTE: WARENKORB ITEMS */}
                        <div className="lg:col-span-3">
                            <h2 className="font-bold text-xl mb-4 flex items-center gap-2">
                                <span className="iconify mdi--format-list-checks text-secondary"></span> Deine Lizenzen
                            </h2>
                            <div className="space-y-4">
                                {items.map(item => (
                                    <div key={item.photoId} className="flex flex-col sm:flex-row justify-between items-start sm:items-center bg-base-100 p-4 rounded-box border border-base-300 shadow-sm gap-4">
                                        <div className="min-w-0 flex-1 w-full">
                                            <p className="font-bold truncate text-base mb-2" title={item.filename}>{item.filename}</p>
                                            <div className="flex flex-wrap gap-2">
                                                <select 
                                                    className="select select-sm select-bordered bg-base-200 text-xs font-normal"
                                                    value={item.tier}
                                                    onChange={(e) => handleUpdateItem(item, 'tier', e.target.value)}
                                                >
                                                    <option value="web">Web & Social</option>
                                                    <option value="print">Print (A4)</option>
                                                    <option value="original">Original</option>
                                                </select>
                                                <select 
                                                    className="select select-sm select-bordered bg-base-200 text-xs font-normal"
                                                    value={item.usage}
                                                    onChange={(e) => handleUpdateItem(item, 'usage', e.target.value)}
                                                >
                                                    <option value="editorial">Redaktionell</option>
                                                    <option value="commercial">Kommerziell</option>
                                                </select>
                                                <select 
                                                    className="select select-sm select-bordered bg-base-200 text-xs font-normal"
                                                    value={item.duration}
                                                    onChange={(e) => handleUpdateItem(item, 'duration', e.target.value)}
                                                >
                                                    <option value="1_year">1 Jahr</option>
                                                    <option value="unlimited">Unbegrenzt</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div className="flex items-center gap-4 shrink-0 w-full sm:w-auto justify-between sm:justify-end border-t sm:border-0 border-base-300 pt-3 sm:pt-0">
                                            <span className="font-mono font-bold text-lg whitespace-nowrap">{item.price.toFixed(2)} €</span>
                                            <button onClick={() => removeFromCart(item.photoId)} className="btn btn-ghost btn-sm btn-square text-error" title="Entfernen">
                                                <span className="iconify mdi--trash-can text-lg"></span>
                                            </button>
                                        </div>
                                    </div>
                                ))}
                            </div>
                            
                            <div className="mt-6 flex justify-between items-center bg-base-100 p-6 rounded-box border border-primary shadow-sm">
                                <span className="font-bold text-lg">Gesamtsumme (Netto)</span>
                                <span className="text-3xl font-mono font-bold text-primary">{totalAmount.toFixed(2)} €</span>
                            </div>
                            <p className="text-sm opacity-60 text-right mt-2">Steuerfrei gem. Kleinunternehmerregelung § 6 Abs. 1 Z 27 UStG.</p>
                        </div>

                        {/* RECHTE SPALTE: FORMULAR */}
                        <div className="lg:col-span-2">
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

                                <div className="space-y-4 mb-8">
                                    <label className="cursor-pointer flex items-start gap-3">
                                        <input type="checkbox" {...register('agb_accepted')} className={`checkbox mt-0.5 ${errors.agb_accepted ? 'checkbox-error' : 'checkbox-primary'}`} />
                                        <span className="label-text text-sm leading-tight">
                                            Ich akzeptiere die <a href="/license-terms" target="_blank" className="link link-primary">Allgemeinen Geschäftsbedingungen und Lizenzvereinbarungen</a>.
                                        </span>
                                    </label>
                                    <label className="cursor-pointer flex items-start gap-3">
                                        <input type="checkbox" {...register('withdrawal_waived')} className={`checkbox mt-0.5 ${errors.withdrawal_waived ? 'checkbox-error' : 'checkbox-primary'}`} />
                                        <span className="label-text text-sm leading-tight">
                                            Ich stimme der sofortigen Ausführung des Vertrages zu und verzichte auf mein Widerrufsrecht, da es sich um digitale Güter handelt.
                                        </span>
                                    </label>
                                </div>

                                <button 
                                    type="submit"
                                    className="btn btn-primary w-full btn-lg" 
                                    disabled={items.length === 0 || isSubmitting} 
                                >
                                    {isSubmitting ? <span className="loading loading-spinner"></span> : 'Zahlungspflichtig bestellen'}
                                </button>
                            </form>
                        </div>
                    </div>
                )}
            </div>
        </PageLayout>
    );
}
