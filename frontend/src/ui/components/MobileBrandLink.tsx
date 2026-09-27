import { Link } from 'react-router-dom';
import { useBrand } from '../../logic/useBrand';

/**
 * The mobile brand lockup: logo plus portal name, linking home.
 *
 * Three dashboards (ManagementDashboard, ClientDashboard and
 * GlobalSearchHeader) each carried their own copy of this markup, and all three
 * sized the name span with `truncate max-w-28`. On a phone the portal name
 * "Reisinger Foto Portal" then collapsed to "Reisinger Fot…" — a visible
 * layout defect. Only GlobalSearchHeader had been corrected to `leading-tight`
 * without `truncate`, so the photographer dashboard kept rendering the ellipsis.
 *
 * Fixing that per dashboard fixes one of three copies and leaves the next
 * dashboard free to truncate the name again. The lockup therefore lives here:
 * the logo, the wrapping name span (`max-w-28 sm:max-w-48`, no `truncate`) and
 * the home link. It reads its own branding through `useBrand()`, so consumers
 * pass only `hidden`.
 *
 * `hidden` preserves the existing behaviour where the lockup yields to the
 * search input once the latter has focus; callers pass their `isSearchFocused`.
 */
interface MobileBrandLinkProps {
    /** Hides the lockup, e.g. while the header's search input has focus. */
    hidden?: boolean;
}

export default function MobileBrandLink({ hidden = false }: MobileBrandLinkProps) {
    const { logoSrc, portalName } = useBrand();

    return (
        <Link to="/" className={`md:hidden flex items-center gap-2 shrink-0 mr-1 ${hidden ? 'hidden' : ''}`}>
            <img src={logoSrc} alt="Logo" className="w-8 h-8 rounded shadow-sm bg-base-100" />
            <span className="font-bold text-sm leading-tight max-w-28 sm:max-w-48">{portalName}</span>
        </Link>
    );
}
