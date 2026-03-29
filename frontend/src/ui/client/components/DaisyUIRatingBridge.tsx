import { useState, useEffect } from 'react';

export default function DaisyUIRatingBridge({ photo, ratePhoto }: { photo: any, ratePhoto: any }) {
    const [comment, setComment] = useState(photo?.comment || '');

    useEffect(() => {
        setComment(photo?.comment || '');
    }, [photo.id, photo.comment]);

    const stopProp = (e: any) => e.stopPropagation();

    const currentRating = Number(photo.rating) || 0;

    const handleRatingClick = (rating: number) => {
        ratePhoto(photo.id, rating, comment);
    };

    return (
        <div className="w-full max-w-lg mx-auto flex flex-col items-center gap-3 text-base-content"
             onPointerDown={stopProp} onMouseDown={stopProp} onTouchStart={stopProp}>

            <div className="bg-base-100/90 backdrop-blur-md px-6 py-3 rounded-full shadow-2xl pointer-events-auto border border-base-content/10 flex items-center justify-center">
                <div className="rating rating-lg gap-1">
                    <input type="radio" name={`lightbox_rating_${photo.id}`} className="rating-hidden" checked={currentRating === 0} readOnly onClick={() => handleRatingClick(0)} />
                    {[1, 2, 3, 4, 5].map(star => (
                        <input
                            key={star}
                            type="radio"
                            name={`lightbox_rating_${photo.id}`}
                            className="mask mask-star-2 bg-primary"
                            checked={currentRating === star}
                            readOnly
                            onClick={() => handleRatingClick(star)}
                            title={`${star} Stern${star > 1 ? 'e' : ''} (Shortcut: ${star})`}
                        />
                    ))}
                </div>
            </div>

            <input
                type="text"
                className="input input-bordered w-full bg-base-100/90 backdrop-blur-md shadow-2xl pointer-events-auto text-center placeholder-base-content/50 border-base-content/10"
                placeholder="Kommentar hinzufügen... (Speichert automatisch)"
                value={comment}
                onChange={(e) => setComment(e.target.value)}
                onBlur={() => { if (comment !== (photo.comment || '')) ratePhoto(photo.id, currentRating, comment); }}
                onKeyDown={(e) => {
                    e.stopPropagation();
                    if(e.key === 'Enter') e.currentTarget.blur();
                }}
            />
        </div>
    );
}
