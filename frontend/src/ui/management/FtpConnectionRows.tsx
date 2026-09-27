import { Trans } from "@lingui/react/macro";
import type { ReactElement } from 'react';
import {describeFtpConnection, type FtpConnection, type FtpConnectionField, type FtpConnectionRow} from '../../logic/ftpConnection';

/**
 * The field → label wording, in one place.
 *
 * Both the inbox card and the setup page render the same connection block, and a
 * second copy of this map is how the two drift apart — the page would then be the
 * thing a photographer trusts while showing a port the server stopped listening
 * on. The map is exhaustive over `FtpConnectionField`, so a new field fails the
 * build instead of rendering an empty cell.
 *
 * `<Trans>` elements rather than `t` strings: only a module-scope `t` is forbidden
 * (check-i18n.mjs), and a static element tree is re-rendered by the `Trans`
 * component itself, so a locale switch still updates the labels.
 */
const connectionLabels: Record<FtpConnectionField, ReactElement> = {
    host: <Trans>Server</Trans>,
    username: <Trans>Benutzername</Trans>,
    sftp: <Trans>SFTP-Port</Trans>,
    ftps: <Trans>FTPS-Port</Trans>,
    ftps_tls_mode: <Trans>FTPS-Verschlüsselung</Trans>,
    passive_ports: <Trans>Passiver Bereich (Firewall)</Trans>,
    path: <Trans>Zielordner</Trans>,
};

/**
 * The values a camera needs, rendered from the server's own response.
 *
 * Both callers pass `status.connection` through `describeFtpConnection` rather
 * than assembling rows themselves, so the two pages cannot disagree about which
 * values exist: an unknown port is dropped on both, and an empty list on both
 * means "not configured" on both.
 *
 * Above `sm` this is an ordinary two-column table: daisyUI's `table` classes, the
 * header row, the per-cell row separators.
 *
 * Below `sm` the two columns do not fit, and no amount of `word-break` tuning can
 * make them fit: the guide dialog hands the table 270px on a 412px phone, the label
 * column claims 159px of it, and the value is a single unbreakable mono token of
 * 169px that then has to be reassembled from three lines before it can be typed
 * into a camera. A column narrower than its content cannot be fixed by choosing a
 * different break point — so below `sm` the row stops being two columns and becomes
 * two stacked blocks, label above value, each with the full table width. The DOM
 * order and the element names stay exactly as they are (still `tr`, still `td`,
 * label cell first): only the display of the wrappers changes, which keeps the
 * `thead`'s column semantics, keeps the value selectable as one string, and keeps
 * every existing locator (`td`, `tr` filtered by its label) pointing at the same
 * element.
 *
 * Two details of that switch are load-bearing:
 *
 * - `sr-only sm:not-sr-only` on the header row: stacked, there is no second column
 *   for "Wert" to name, so the row is not rendered — but it stays in the DOM, and
 *   therefore in the accessibility tree, as the cells' column header.
 * - `max-sm:border-b-0!` on the label cell: daisyUI draws the row separator as a
 *   `border-bottom` on every cell but the last row's, so in the stacked layout it
 *   would land *between* a label and its own value. The value cell keeps that
 *   border, which is where the row separator belongs. The `!` is required because
 *   daisyUI ships its cell rules in a cascade layer that comes after Tailwind's
 *   utilities; `max-sm` keeps the desktop separator — which the label cell needs
 *   there — untouched.
 */
export function FtpConnectionRows({rows}: {rows: FtpConnectionRow[]}) {
    return (
        <div className="overflow-x-auto bg-base-100 rounded-box border border-base-300 shadow-sm">
            <table className="table table-zebra w-full">
                <thead className="sr-only sm:not-sr-only">
                    <tr>
                        <th><Trans>Einstellung</Trans></th>
                        <th><Trans>Wert</Trans></th>
                    </tr>
                </thead>
                <tbody className="block sm:table-row-group">
                    {rows.map(row => (
                        <tr key={row.field} className="block sm:table-row">
                            {/* `block sm:table-cell` on the cells is what makes the row
                                two blocks instead of two columns: a `table-cell` inside a
                                `block` row would be wrapped in an anonymous, shrink-to-fit
                                table and stay as narrow as its content — the same defect in
                                a different guise. */}
                            <td className="text-sm block sm:table-cell max-sm:border-b-0!">{connectionLabels[row.field]}</td>
                            {/* The value is a single unbreakable mono token (host, port
                                range, path). `break-all` is now only the safety net for a
                                value that is longer than the whole stacked row; `sm:break-normal`
                                restores the normal breaking rules from `sm` up. */}
                            <td className="block sm:table-cell"><code className="font-mono text-sm break-all sm:break-normal">{row.value}</code></td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

/**
 * The "the server has nothing for the camera" state, shared by both pages.
 *
 * Naming no missing field on purpose: which env var is unset is an admin
 * detail, and the photographer's only action is the same either way.
 *
 * The copy states the consequence and points at the guide, and deliberately
 * does **not** promise that setting up the account will succeed. The two
 * buttons are rendered outside this branch on the inbox card, so they are
 * visible here — but whether provisioning actually completes when the
 * deployment has not declared its FTP values is not something this component
 * can know, and an unverified promise is the same failure mode as the dead end
 * it replaces. The guide, by contrast, exists unconditionally and is useful
 * whether or not the account can be created yet.
 *
 * "Contact support" was the original dead end: nobody can fix a server
 * configuration from the outside, so the sentence sent the photographer
 * somewhere they could not act.
 *
 * Located by section heading, never by proximity. This component renders in
 * two places — the inbox card and, through FtpConnectionBlock, inside the
 * guide dialog — and only the inbox carries the buttons. "FTP Inbox" is a
 * heading that is on screen there, so the pointer stays true in both.
 */
export function FtpConnectionUnconfigured() {
    return (
        <div className="alert alert-warning shadow-sm" role="status">
            <span className="iconify mdi--alert-circle-outline text-xl"></span>
            <div>
                <h3 className="font-bold"><Trans>Verbindungsdaten der Kamera nicht verfügbar</Trans></h3>
                <p className="text-sm"><Trans>Die Server-Konfiguration ist unvollständig, daher lassen sich hier keine
                    Verbindungsdaten anzeigen. Wie du die Kamera einrichtest, erklärt die
                    Anleitung — im Dashboard unter FTP Inbox über „Anleitung öffnen".</Trans></p>
            </div>
        </div>
    );
}

/**
 * The whole block, warning and table included, so a caller cannot render the
 * table for an unconfigured server by forgetting the branch.
 */
export function FtpConnectionBlock({connection}: {connection: FtpConnection}) {
    // `configured` statt „keine Zeilen": eine unvollstaendige Konfiguration
    // kann trotzdem Werte liefern — Host und Ports stehen, der Zielordner ist
    // kaputt. Eine Tabelle, die nur die Haelfte zeigt, ist schlimmer als der
    // Hinweis: der Fotograf haette den Zielordner dann geraten.
    if (!connection.configured) {
        return <FtpConnectionUnconfigured />;
    }

    const rows = describeFtpConnection(connection);

    return <FtpConnectionRows rows={rows} />;
}
