import { Gallery } from '../../../logic/useGalleries';

interface Props {
    gallery: Gallery;
    canSendMail: boolean;
    downloadsCount: number;
    isPhotographer: boolean;
    onOpenRatings: () => void;
    onOpenMetadata: () => void;
    onOpenInvite: () => void;
    onOpenMail: () => void;
}

export default function ManagementGalleryActions({ gallery, canSendMail, downloadsCount, isPhotographer, onOpenRatings, onOpenMetadata, onOpenInvite, onOpenMail }: Props) {
    return (
        <div className="flex flex-wrap gap-4 items-center">
            {gallery.type === 'delivery' && <span className="badge badge-ghost font-normal">{downloadsCount || 0} Downloads</span>}
            {isPhotographer && (
                <div className="flex gap-2">
                    {gallery.type === 'selection' && (
                        <button onClick={onOpenRatings} className="btn btn-secondary btn-sm">
                            <span className="iconify mdi--star-outline"></span> Bewertungen...
                        </button>
                    )}
                    {gallery.type === 'delivery' && (
                        <button onClick={onOpenMetadata} className="btn btn-secondary btn-sm">
                            <span className="iconify mdi--tag-multiple"></span> Vorgaben...
                        </button>
                    )}
                    <button onClick={onOpenInvite} className="btn btn-outline btn-sm">
                        <span className="iconify mdi--link"></span> Einladungslink...
                    </button>
                    <button
                        onClick={onOpenMail}
                        className="btn btn-primary btn-sm"
                        disabled={!canSendMail}
                        title={!canSendMail ? "Keine Empfänger mit Opt-In vorhanden" : ""}
                    >
                        <span className="iconify mdi--email-fast"></span> E-Mail senden...
                    </button>
                </div>
            )}
        </div>
    );
}
