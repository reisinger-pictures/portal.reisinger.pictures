import { t } from "@lingui/core/macro";
import { Trans } from "@lingui/react/macro";
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
    onOpenAccess?: () => void;
    onOpenPhotographerTeam?: () => void;
    onOpenAIBatchEdit?: () => void;
}

export default function ManagementGalleryActions({ gallery, canSendMail, downloadsCount, isPhotographer, onOpenRatings, onOpenMetadata, onOpenInvite, onOpenMail, onOpenAccess, onOpenPhotographerTeam, onOpenAIBatchEdit }: Props) {
    return (
        <div className="flex flex-wrap gap-4 items-center">
            {gallery.type === 'delivery' && <span className="badge badge-ghost font-normal">{downloadsCount || 0} <Trans>Downloads</Trans></span>}
            {isPhotographer && (
                <div className="flex gap-2">
                    {gallery.type === 'selection' && (
                        <button onClick={onOpenRatings} className="btn btn-outline btn-primary btn-sm">
                            <span className="iconify mdi--star-outline"></span> <Trans>Bewertungen...</Trans>
                        </button>
                    )}
                    {gallery.type === 'delivery' && (
                        <button onClick={onOpenMetadata} className="btn btn-outline btn-primary btn-sm">
                            <span className="iconify mdi--tag-multiple"></span> <Trans>Vorgaben...</Trans>
                        </button>
                    )}
                    {onOpenAccess && <button onClick={onOpenAccess} className="btn btn-outline btn-sm"><span className="iconify mdi--account-key"></span> <Trans>Zugriff...</Trans></button>}
                    {gallery.type === 'delivery' && onOpenAIBatchEdit && <button onClick={onOpenAIBatchEdit} className="btn btn-outline btn-sm"><span className="iconify mdi--robot-outline"></span> <Trans>KI Beschriftung</Trans></button>}
                    {onOpenPhotographerTeam && <button onClick={onOpenPhotographerTeam} className="btn btn-outline btn-sm"><span className="iconify mdi--camera-account"></span> <Trans>Fotografen...</Trans></button>}
                    <button onClick={onOpenInvite} className="btn btn-outline btn-sm">
                        <span className="iconify mdi--link"></span> <Trans>Einladungslink...</Trans>
                    </button>
                    <button
                        onClick={onOpenMail}
                        className="btn btn-primary btn-sm"
                        disabled={!canSendMail}
                        title={!canSendMail ? t`Keine Empfänger mit Opt-In vorhanden` : ""}
                    >
                        <span className="iconify mdi--email-fast"></span> <Trans>E-Mail senden...</Trans>
                    </button>
                </div>
            )}
        </div>
    );
}
