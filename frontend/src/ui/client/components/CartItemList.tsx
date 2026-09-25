import {t} from '@lingui/core/macro';
import {Trans, Plural} from '@lingui/react/macro';
import type {CartPricingGroup, VolumeLicensingResult} from '../../../logic/CartContext';
import {CartItem} from '../../../logic/CartContext';
import {formatMoney} from '../../../logic/utils';

export interface CartItemListProps {
    items: CartItem[];
    handleUpdateItem: (item: CartItem, field: string, value: string) => void;
    removeFromCart: (photoId: string) => void;
    hasQuotes: boolean;
    totalAmount: number;
    readOnly?: boolean;
    /** Volume licensing pricing summary (optional — only for volume groups). */
    volumeLicensing?: VolumeLicensingResult;
    /** Server-priced discount in cents. */
    discountAmount?: number;
    /** Display total after discount; defaults to `totalAmount - discountAmount`. */
    netTotalAmount?: number;
}

const legacyVolumeGroup = (
    items: CartItem[],
    volumeLicensing: VolumeLicensingResult,
): CartPricingGroup => {
    const payableItems = items.filter(item => !item.isQuote);
    return {
        key: 'volume_licensing|legacy',
        licensingMode: 'volume_licensing',
        presetId: 'default',
        presetName: null,
        items: payableItems,
        itemIds: payableItems.map(item => item.photoId),
        totalCents: volumeLicensing.totalCents,
        pricePerItemCents: volumeLicensing.pricePerItemCents,
        tiers: volumeLicensing.tiers,
        tierIndex: volumeLicensing.tierIndex,
        isMaxTier: volumeLicensing.isMaxTier,
        nextTierCount: volumeLicensing.nextTierCount,
        nextTierLabel: volumeLicensing.nextTierLabel,
        isVolumePricing: true,
        itemPriceCents: Object.fromEntries(payableItems.map(item => [item.photoId, volumeLicensing.pricePerItemCents])),
    };
};

export const CartItemList = ({
    items,
    handleUpdateItem,
    removeFromCart,
    hasQuotes,
    totalAmount,
    readOnly = false,
    volumeLicensing,
    discountAmount = 0,
    netTotalAmount,
}: CartItemListProps) => {
    const groupedVolumeLicensing = volumeLicensing?.groups
        ?.filter(group => group.isVolumePricing) ?? [];
    const volumeGroups = volumeLicensing?.groups !== undefined
        ? groupedVolumeLicensing
        : volumeLicensing?.isVolumePricing
            ? [legacyVolumeGroup(items, volumeLicensing)]
            : [];
    const isVolumeLicensingMode = volumeGroups.length > 0;
    const payableItemCount = items.filter(item => !item.isQuote).length;
    const cappedDiscount = Math.min(Math.max(0, discountAmount), Math.max(0, totalAmount));
    const displayedTotal = hasQuotes
        ? 0
        : Math.max(0, netTotalAmount ?? totalAmount - cappedDiscount);
    const formattedTotalAmount = formatMoney(totalAmount);
    const formattedDiscount = formatMoney(cappedDiscount);
    const showDiscount = !hasQuotes && cappedDiscount > 0;

    const groupForItem = (item: CartItem): CartPricingGroup | undefined => (
        volumeGroups.find(group => group.itemIds.includes(item.photoId))
    );
    const priceForItem = (item: CartItem, group?: CartPricingGroup): number => (
        group
            ? group.itemPriceCents[item.photoId] ?? group.pricePerItemCents ?? item.price
            : item.price
    );

    return (
        <div className="lg:col-span-3">
            <h2 className="font-bold text-xl mb-4 flex items-center gap-2">
                <span
                    className="iconify mdi--format-list-checks text-primary"></span> {hasQuotes ? <Trans>Deine Lizenzen & Anfragen</Trans> : <Trans>Deine Lizenzen</Trans>}
            </h2>

            {/* One banner per effective server pricing group keeps custom presets visible. */}
            {isVolumeLicensingMode && items.length > 0 && (
                <div className="space-y-2 mb-4" data-testid="volume-pricing-groups">
                    {volumeGroups.map(group => {
                        const payableCount = group.items.filter(item => !item.isQuote).length;
                        const groupPrice = group.pricePerItemCents ?? 0;
                        const pricePerItemStr = formatMoney(groupPrice);
                        const tierNum = group.tierIndex;
                        const nextCount = group.nextTierCount;
                        const nextLabel = group.nextTierLabel;
                        return (
                            <div
                                key={group.key}
                                data-testid={`volume-pricing-group-${group.presetId}`}
                                className="p-3 bg-primary/5 rounded-box border border-primary/20 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-2"
                            >
                                <div className="flex items-center gap-2 flex-wrap">
                                    <span className="badge badge-primary badge-sm uppercase text-xs tracking-wider"><Trans>Mengenrabatt</Trans></span>
                                    <span className="text-sm font-semibold">
                                        <Trans>{pricePerItemStr} pro Bild (Tier {tierNum})</Trans>
                                    </span>
                                    {group.presetName && <span className="text-xs opacity-70">{group.presetName}</span>}
                                </div>
                                <div className="flex items-center gap-3 flex-wrap">
                                    <span className="text-xs opacity-70">
                                        <Trans>{payableCount} Bilder</Trans>
                                    </span>
                                    {group.nextTierCount > 0 && (
                                        <span className="text-xs opacity-70">
                                            <Trans><Plural value={nextCount} one="Noch # Bild" other="Noch # Bilder" /> bis {nextLabel}</Trans>
                                        </span>
                                    )}
                                    {group.isMaxTier && (
                                        <span className="text-xs text-success font-bold"><Trans>Bester Rabatt aktiv</Trans></span>
                                    )}
                                </div>
                            </div>
                        );
                    })}
                </div>
            )}

            <div className="space-y-4">
                {items.map((item: CartItem, idx: number) => {
                    const volumeGroup = groupForItem(item);
                    const isVolumeItem = volumeGroup !== undefined;
                    const tierNum = volumeGroup?.tierIndex ?? 0;
                    return (
                        <div key={item.photoId + idx}
                             className="flex flex-col sm:flex-row justify-between items-start sm:items-center bg-base-100 p-4 rounded-box border border-base-300 shadow-sm gap-4">
                            <div className="flex-1 min-w-0 w-full flex flex-col md:flex-row gap-4 items-start md:items-center">
                                {item.thumb_url && (
                                    <img src={item.thumb_url}
                                         className="w-24 h-24 object-cover rounded shadow-sm shrink-0 border border-base-200"
                                         alt={t`Vorschau`}/>
                                )}
                                <div className="w-full">
                                    {item.isQuote ? (
                                        <div className="w-full">
                                            <div className="font-bold text-sm text-primary mb-2 flex items-center gap-1"><span
                                                className="iconify mdi--file-document-edit-outline"></span> <Trans>Individuelles Angebot</Trans>
                                            </div>
                                            <textarea
                                                className="textarea textarea-bordered w-full h-16 text-sm resize-none"
                                                placeholder={t`Beschreibe deine speziellen Nutzungsanforderungen (z.B. Weltweite Rechte, Exklusivität)...`}
                                                value={item.notes || ''}
                                                readOnly={readOnly}
                                                onChange={(e) => handleUpdateItem(item, 'notes', e.target.value)}
                                            />
                                        </div>
                                    ) : (
                                        <div className="flex flex-col gap-1">
                                            <div className="font-bold text-sm">{item.useCaseName || t`Standard Lizenz`}</div>
                                            {item.modifierNames && item.modifierNames.length > 0 && (
                                                <div className="text-sm opacity-80 text-warning flex items-center gap-1">
                                                    <span
                                                        className="iconify mdi--plus-circle-outline"></span> {item.modifierNames.join(', ')}
                                                </div>
                                            )}
                                            {isVolumeItem && (
                                                <div className="text-xs opacity-60 mt-1 flex items-center gap-1">
                                                    <span className="iconify mdi--percent text-primary"></span>
                                                    <Trans>Volumenpreis (Tier {tierNum})</Trans>
                                                </div>
                                            )}
                                        </div>
                                    )}
                                </div>
                            </div>
                            <div
                                className="flex items-center gap-4 shrink-0 w-full sm:w-auto justify-between sm:justify-end border-t sm:border-0 border-base-300 pt-3 sm:pt-0 mt-3 sm:mt-0">
                                {item.isQuote ? (
                                    <div className="text-right">
                                        <span
                                            className="font-mono font-bold text-lg whitespace-nowrap text-warning">--- €</span>
                                        <span className="text-sm font-sans opacity-70 block"><Trans>(Preis auf Anfrage)</Trans></span>
                                    </div>
                                ) : (
                                    <div className="text-right">
                                        {isVolumeItem ? (
                                            <>
                                                <span
                                                    className="font-mono font-bold text-lg whitespace-nowrap">{formatMoney(priceForItem(item, volumeGroup))}</span>
                                                <span className="text-xs opacity-60 block"><Trans>(Volumenpreis)</Trans></span>
                                            </>
                                        ) : (
                                            <span
                                                className="font-mono font-bold text-lg whitespace-nowrap">{formatMoney(item.price)}</span>
                                        )}
                                    </div>
                                )}
                                <button onClick={() => removeFromCart(item.photoId)} disabled={readOnly}
                                        className="btn btn-ghost btn-sm btn-square text-error" title={t`Entfernen`}>
                                    <span className="iconify mdi--trash-can text-lg"></span>
                                </button>
                            </div>
                        </div>
                    );
                })}
            </div>

            <div
                className="mt-6 flex justify-between items-center bg-base-100 p-6 rounded-box border border-primary shadow-sm">
                <div className="flex flex-col gap-1">
                    <span className="font-bold text-lg"><Trans>Gesamtsumme</Trans></span>
                    {isVolumeLicensingMode && volumeGroups.map(group => {
                        const groupPayableCount = group.items.filter(item => !item.isQuote).length;
                        const groupTotalPriceText = formatMoney(group.pricePerItemCents ?? 0);
                        const groupTotalTierIndex = group.tierIndex;
                        return (
                            <span key={`total-${group.key}`} className="text-xs opacity-60">
                                <Trans>{groupPayableCount} Bilder × {groupTotalPriceText} (Tier {groupTotalTierIndex})</Trans>
                            </span>
                        );
                    })}
                    {!isVolumeLicensingMode && !hasQuotes && payableItemCount > 0 && (
                        <span className="text-xs opacity-60"><Trans>{payableItemCount} Bilder</Trans></span>
                    )}
                    {showDiscount && (
                        <>
                            <span className="text-xs opacity-70" data-testid="cart-subtotal">
                                <Trans>Zwischensumme: {formattedTotalAmount}</Trans>
                            </span>
                            <span className="text-sm text-success font-semibold" data-testid="cart-discount">
                                <Trans>Rabatt: −{formattedDiscount}</Trans>
                            </span>
                        </>
                    )}
                </div>
                <span
                    className="text-3xl font-mono font-bold text-primary"
                    data-testid="cart-total"
                >
                    {hasQuotes ? '--- €' : formatMoney(displayedTotal)}
                </span>
            </div>
            {showDiscount && (
                <p className="text-xs opacity-60 text-right mt-1" data-testid="cart-discount-note">
                    <Trans>Der Rabatt wird auf den serverberechneten Warenkorb angewendet.</Trans>
                </p>
            )}
            <p className="text-sm opacity-60 text-right mt-2"><Trans>Steuerfrei gem. Kleinunternehmerregelung § 6 Abs. 1 Z 27 UStG.</Trans></p>
        </div>
    );
};
