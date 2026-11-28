import { Trans } from '@lingui/react/macro';
import { formatEuro } from '../../../logic/formatCurrency';
import {
    eaPdfAusfuellhilfeUrl,
    useEaAusfuellhilfe,
    type EaAusfuellhilfeField,
} from '../../../logic/useEa';

interface Props {
    year: number;
}

function unitSuffix(unit: string): string {
    switch (unit) {
        case 'minutes': return ' Min.';
        case 'per-10000': return ' Basispunkte';
        default: return '';
    }
}

/** D-91: null bleibt leer; ein vorhandener Cent-Wert wird erst hier in Euro formatiert. */
function fieldValue(field: EaAusfuellhilfeField): string {
    if (field.value_cents !== null) return formatEuro(field.value_cents / 100);
    if (field.value_text !== null && field.value_text !== '') {
        return `${field.value_text}${unitSuffix(field.unit)}`;
    }
    return '—';
}

export default function EaAusfuellhilfePanel({year}: Props) {
    const {data, error, isLoading} = useEaAusfuellhilfe(year);

    return (
        <div className="rounded-box border border-base-300 bg-base-100 p-6">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 className="text-xl font-semibold"><Trans>Werte für FinanzOnline</Trans></h2>
                    <p className="text-sm opacity-70">
                        <Trans>Leere Felder bleiben leer und werden nicht als Null ausgewiesen.</Trans>
                    </p>
                </div>
                <a className="btn btn-outline" href={eaPdfAusfuellhilfeUrl(year)} download>
                    <Trans>Ausfüllhilfe als PDF herunterladen</Trans>
                </a>
            </div>

            {error !== undefined && error !== null && (
                <div className="alert alert-error mt-4" role="alert">
                    <span><Trans>Die Ausfüllhilfe konnte nicht geladen werden.</Trans></span>
                </div>
            )}
            {isLoading && data === undefined && (
                <p className="mt-4 text-sm opacity-70" role="status"><Trans>Ausfüllhilfe wird geladen …</Trans></p>
            )}

            {data !== undefined && (
                <>
                    {data.notice !== '' && (
                        <p className="alert alert-info mt-4" role="status">{data.notice}</p>
                    )}

                    {data.sections.map((section) => (
                        <section className="mt-6" key={section.id}>
                            <h3 className="text-lg font-semibold">{section.title_de}</h3>
                            <div className="overflow-x-auto mt-2">
                                <table className="table table-sm">
                                    <thead>
                                        <tr>
                                            <th><Trans>Reihenfolge</Trans></th>
                                            <th><Trans>Formularfeld</Trans></th>
                                            <th><Trans>Kennzahl</Trans></th>
                                            <th><Trans>Bezeichnung</Trans></th>
                                            <th className="text-right"><Trans>Wert</Trans></th>
                                            <th><Trans>Quelle</Trans></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {[...section.fields]
                                            .sort((left, right) => left.order - right.order)
                                            .map((field) => (
                                                <tr key={`${section.id}-${field.order}-${field.cell}`}>
                                                    <td>{field.order}</td>
                                                    <td className="font-mono">{field.sheet}!{field.cell}</td>
                                                    <td>
                                                        {field.kz !== null && <span className="badge badge-outline">{field.kz}</span>}
                                                    </td>
                                                    <td>{field.label_de}</td>
                                                    <td className="text-right whitespace-nowrap">
                                                        {fieldValue(field)}
                                                        {field.deviations.map((deviation) => (
                                                            <details className="inline-block ml-1" key={deviation.code}>
                                                                <summary className="cursor-pointer text-warning" title={deviation.text_de}>
                                                                    ⚠ {deviation.code}
                                                                </summary>
                                                                <span className="block text-left text-sm">{deviation.text_de}</span>
                                                            </details>
                                                        ))}
                                                    </td>
                                                    <td className="text-sm opacity-70">{field.source}</td>
                                                </tr>
                                            ))}
                                    </tbody>
                                </table>
                            </div>
                        </section>
                    ))}
                </>
            )}
        </div>
    );
}
