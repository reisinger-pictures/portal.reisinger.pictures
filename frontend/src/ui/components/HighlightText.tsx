import React from 'react';

interface Props {
    text: string;
    highlight: string;
}

export default function HighlightText({ text, highlight }: Props) {
    if (!highlight.trim()) return <>{text}</>;
    
    // Escaped regex special characters safely
    const escapedHighlight = highlight.replace(/[-[\]{}()*+?.,\\^$|#\s]/g, '\\$&');
    const regex = new RegExp('(' + escapedHighlight + ')', 'gi');
    const parts = text.split(regex);
    
    return (
        <>
            {parts.map((part, i) =>
                regex.test(part) 
                    ? <span key={i} className="text-primary font-bold">{part}</span> 
                    : <span key={i}>{part}</span>
            )}
        </>
    );
}
