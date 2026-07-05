import { useState } from 'react';
import { t } from "@lingui/core/macro";
import { Photo } from '../../../logic/useGallery';

export interface GridPhotoActionsProps {
    photo: Photo;
    ratePhoto: (id: string, rating: number, comment: string) => void;
}

export default function GridPhotoActions({ photo, ratePhoto }: GridPhotoActionsProps) {
    const [comment, setComment] = useState(photo.comment || '');
    const [prevPhotoId, setPrevPhotoId] = useState(photo.id);
    if (photo.id !== prevPhotoId) {
        setPrevPhotoId(photo.id);
        setComment(photo.comment || '');
    }

    return (
        <div className="card-body p-4 bg-base-100 flex flex-col items-center gap-3">
            <div className="rating rating-sm">
                <input type="radio" name={`grid_rating_${photo.id}`} className="rating-hidden" checked={Number(photo.rating || 0) === 0} onChange={() => ratePhoto(photo.id, 0, comment)} />
                {[1, 2, 3, 4, 5].map(star => (
                    <input key={star} type="radio" name={`grid_rating_${photo.id}`}
                           className="mask mask-star-2 bg-primary"
                           checked={Number(photo.rating) === star}
                           onChange={() => ratePhoto(photo.id, star, comment)} />
                ))}
            </div>
            <input type="text" placeholder={t`Kommentar...`}
                   value={comment}
                   onChange={e => setComment(e.target.value)}
                   onBlur={() => { if (comment !== (photo.comment || '')) ratePhoto(photo.id, Number(photo.rating || 0), comment); }}
                   onKeyDown={(e) => { if(e.key === 'Enter') e.currentTarget.blur(); }}
                   className="input input-bordered input-xs w-full text-center"/>
        </div>
    );
}