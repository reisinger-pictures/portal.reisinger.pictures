import {useState} from 'react';
import {t} from '@lingui/core/macro';
import {Trans} from '@lingui/react/macro';
import type {EurHelpText} from '../../../logic/useEur';

interface Props {
    help?: EurHelpText;
}

/** Zeigt den unveränderten Hilfetext aus dem gepinnten Artefakt als Popover. */
export default function EurHelpPopover({help}: Props) {
    const [isOpen, setIsOpen] = useState(false);

    if (help === undefined || (help.hilfetext === '' && help.portal_note === null)) {
        return null;
    }

    const kz = help.kz;
    const label = t`Hilfe zu Kennzahl ${kz}`;

    return (
        <span className="relative inline-block">
            <button
                type="button"
                className="btn btn-ghost btn-xs"
                aria-label={label}
                aria-expanded={isOpen}
                onClick={() => setIsOpen((open) => !open)}
            >
                ?
            </button>
            {isOpen && (
                <span role="tooltip" className="absolute left-0 top-full z-20 mt-1 w-80 rounded-box border border-base-300 bg-base-100 p-3 text-left text-sm shadow-lg">
                    <span className="block font-semibold">{help.bezeichnung} · {help.kz}</span>
                    {help.hilfetext !== '' && <span className="mt-2 block whitespace-normal">{help.hilfetext}</span>}
                    {help.portal_note !== null && (
                        <span className="mt-2 block border-t border-base-300 pt-2">
                            <Trans>Hinweis:</Trans> {help.portal_note}
                        </span>
                    )}
                </span>
            )}
        </span>
    );
}
