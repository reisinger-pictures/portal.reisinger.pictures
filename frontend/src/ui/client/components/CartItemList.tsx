import {CartItem} from '../../../logic/CartContext';
import {formatMoney} from '../../../logic/utils';

export interface CartItemListProps {
    items: CartItem[];
    handleUpdateItem: (item: CartItem, field: string, value: string) => void;
    removeFromCart: (photoId: string) => void;
    hasQuotes: boolean;
    totalAmount: number;
}

export const CartItemList = ({items, handleUpdateItem, removeFromCart, hasQuotes, totalAmount}: CartItemListProps) => (
    <div className="lg:col-span-3">
        <h2 className="font-bold text-xl mb-4 flex items-center gap-2">
            <span
                className="iconify mdi--format-list-checks text-primary"></span> {hasQuotes ? 'Deine Lizenzen & Anfragen' : 'Deine Lizenzen'}
        </h2>
        <div className="space-y-4">
            {items.map((item: CartItem, idx: number) => (
                <div key={item.photoId + idx}
                     className="flex flex-col sm:flex-row justify-between items-start sm:items-center bg-base-100 p-4 rounded-box border border-base-300 shadow-sm gap-4">
                    <div className="flex-1 min-w-0 w-full flex flex-col md:flex-row gap-4 items-start md:items-center">
                        {item.thumb_url && (
                            <img src={item.thumb_url}
                                 className="w-24 h-24 object-cover rounded shadow-sm shrink-0 border border-base-200"
                                 alt="Vorschau"/>
                        )}
                        <div className="w-full">
                            {item.isQuote ? (
                                <div className="w-full">
                                    <div className="font-bold text-sm text-primary mb-2 flex items-center gap-1"><span
                                        className="iconify mdi--file-document-edit-outline"></span> Individuelles
                                        Angebot
                                    </div>
                                    <textarea
                                        className="textarea textarea-bordered w-full h-16 text-sm resize-none"
                                        placeholder="Beschreibe deine speziellen Nutzungsanforderungen (z.B. Weltweite Rechte, Exklusivität)..."
                                        value={item.notes || ''}
                                        onChange={(e) => handleUpdateItem(item, 'notes', e.target.value)}
                                    />
                                </div>
                            ) : (
                                <div className="flex flex-col gap-1">
                                    <div className="font-bold text-sm">{item.useCaseName || 'Standard Lizenz'}</div>
                                    {item.modifierNames && item.modifierNames.length > 0 && (
                                        <div className="text-sm opacity-80 text-warning flex items-center gap-1">
                                            <span
                                                className="iconify mdi--plus-circle-outline"></span> {item.modifierNames.join(', ')}
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
                                <span className="text-sm font-sans opacity-70 block">(Preis auf Anfrage)</span>
                            </div>
                        ) : (
                            <span
                                className="font-mono font-bold text-lg whitespace-nowrap">{formatMoney(item.price)}</span>
                        )}
                        <button onClick={() => removeFromCart(item.photoId)}
                                className="btn btn-ghost btn-sm btn-square text-error" title="Entfernen">
                            <span className="iconify mdi--trash-can text-lg"></span>
                        </button>
                    </div>
                </div>
            ))}
        </div>

        <div
            className="mt-6 flex justify-between items-center bg-base-100 p-6 rounded-box border border-primary shadow-sm">
            <span className="font-bold text-lg">Gesamtsumme</span>
            <span
                className="text-3xl font-mono font-bold text-primary">{hasQuotes ? '--- €' : formatMoney(totalAmount)}</span>
        </div>
        <p className="text-sm opacity-60 text-right mt-2">Steuerfrei gem. Kleinunternehmerregelung § 6 Abs. 1 Z 27
            UStG.</p>
    </div>
);
