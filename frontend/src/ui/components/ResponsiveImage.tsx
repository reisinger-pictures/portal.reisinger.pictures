import React, { useState } from 'react';

interface ResponsiveImageProps extends React.ImgHTMLAttributes<HTMLImageElement> {
    containerClassName?: string;
}

export default function ResponsiveImage({ src, srcSet, sizes, alt, className, containerClassName = '', ...props }: ResponsiveImageProps) {
    const [loaded, setLoaded] = useState(false);

    return (
        <div className={`relative overflow-hidden bg-base-300 ${containerClassName}`}>
            {!loaded && (
                <div className="absolute inset-0 flex items-center justify-center">
                    <span className="loading loading-spinner text-primary/30"></span>
                </div>
            )}
            <img
                src={src}
                srcSet={srcSet}
                sizes={sizes ||"(max-width: 600px) 400px, (max-width: 1200px) 800px, 1200px"}
                alt={alt || ''}
                className={`transition-opacity duration-500 ${loaded ? 'opacity-100' : 'opacity-0'} ${className || ''}`}
                onLoad={() => setLoaded(true)}
                loading="lazy"
                {...props}
            />
        </div>
    );
}
