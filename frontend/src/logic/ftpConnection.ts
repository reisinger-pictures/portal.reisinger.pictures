/**
 * What a camera has to be configured with, as returned by
 * `GET /api/management/ftp/status` under `connection`.
 *
 * Every value is nullable on purpose. The backend reads host and ports from the
 * environment without defaults, so a deployment that has not declared them
 * reports `configured: false` instead of a plausible guess — a camera
 * configured from a guessed port fails in a way that looks like a network
 * problem. `null` therefore means "unknown", and the UI says so.
 */
export interface FtpConnection {
    configured: boolean;
    host: string | null;
    username: string | null;
    path: string;
    sftp_port: number | null;
    ftps_port: number | null;
    pasv_port_start: number | null;
    pasv_port_end: number | null;
}

/**
 * The rows the camera settings consist of, identified by a stable field name
 * rather than a label.
 *
 * Labels are deliberately NOT produced here: this module is module scope, and
 * the Lingui `t` macro may only be called inside a function or render body
 * (frontend/AGENTS.md). The component maps `field` to a `<Trans>` label, so the
 * decision logic stays testable without a catalogue and the strings stay
 * translatable.
 */
export type FtpConnectionField = 'host' | 'username' | 'sftp' | 'ftps' | 'passive_ports' | 'path';

export interface FtpConnectionRow {
    field: FtpConnectionField;
    value: string;
}

/**
 * Builds the camera rows, or an empty list when the server has not declared its
 * connection parameters.
 *
 * An incomplete value is dropped rather than rendered blank: a row with an empty
 * value invites a photographer to copy an empty field into the camera, which
 * fails later and further away from the cause. The caller renders "not
 * configured" when the list is empty.
 *
 * The passive range is only shown when both ends are known — a half-open range
 * would read as a port that is not open.
 */
export function describeFtpConnection(connection: FtpConnection): FtpConnectionRow[] {
    if (!connection.configured) {
        return [];
    }

    const rows: FtpConnectionRow[] = [];

    if (connection.host !== null) {
        rows.push({ field: 'host', value: connection.host });
    }
    if (connection.username !== null) {
        rows.push({ field: 'username', value: connection.username });
    }
    if (connection.sftp_port !== null) {
        rows.push({ field: 'sftp', value: String(connection.sftp_port) });
    }
    if (connection.ftps_port !== null) {
        rows.push({ field: 'ftps', value: String(connection.ftps_port) });
    }
    if (connection.pasv_port_start !== null && connection.pasv_port_end !== null) {
        rows.push({ field: 'passive_ports', value: `${connection.pasv_port_start}\u2013${connection.pasv_port_end}` });
    }
    if (connection.path !== '') {
        rows.push({ field: 'path', value: connection.path });
    }

    return rows;
}
