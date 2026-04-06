interface Props {
    ratingFilter: string;
    setRatingFilter: (filter: string) => void;
}

export default function SelectionFilterBar({ ratingFilter, setRatingFilter }: Props) {
    const starRatings = ['5', '4', '3', '2', '1'];

    return (
        <div className="flex justify-center md:justify-start mb-6 overflow-x-auto pb-2">
            <div className="join shadow-sm">
                <button className={`btn join-item btn-sm ${ratingFilter === 'all' ? 'btn-neutral' : ''}`} onClick={() => setRatingFilter('all')}>Alle</button>
                <button className={`btn join-item btn-sm ${ratingFilter === 'unrated' ? 'btn-neutral' : ''}`} onClick={() => setRatingFilter('unrated')}>Neu</button>
                <button className={`btn join-item btn-sm ${ratingFilter === 'rated' ? 'btn-neutral' : ''}`} onClick={() => setRatingFilter('rated')}>Favoriten</button>
                
                {starRatings.map(star => (
                    <button 
                        key={star}
                        className={`btn join-item btn-sm ${ratingFilter === star ? 'btn-neutral' : ''}`} 
                        onClick={() => setRatingFilter(star)}
                    >
                        {star} <span className="iconify mdi--star text-primary ml-0.5 text-base"></span>
                    </button>
                ))}

                <button className={`btn join-item btn-sm ${ratingFilter === '0' ? 'btn-neutral' : ''}`} onClick={() => setRatingFilter('0')}>Ignoriert</button>
            </div>
        </div>
    );
}
