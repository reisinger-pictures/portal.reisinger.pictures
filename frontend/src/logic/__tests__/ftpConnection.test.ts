import {describe, it, expect} from 'vitest';
import {describeFtpConnection, type FtpConnection} from '../ftpConnection';

/**
 * The camera connection rows (P1-M39).
 *
 * The failure mode this file guards is a photographer copying an empty field
 * into their camera: a blank port or a half-open passive range reads like a real
 * value on the camera's screen and then fails at connect time, far away from the
 * cause. So every incomplete value has to *disappear* instead of render blank,
 * and an unconfigured server has to produce no rows at all — the UI decides
 * between "here are your settings" and "ask support" purely on the length of this
 * list.
 *
 * The row order is part of the contract as well: it is the reading order of the
 * camera's own connection dialog (server, user, protocol ports, firewall, path),
 * and a list that reorders between renders makes the two states harder to tell
 * apart at a glance.
 */

const configured: FtpConnection = {
    configured: true,
    // Reserved per RFC 2606, so it can never resolve to a real server. The host
    // carries no meaning for this logic, and a production hostname here would be
    // one copy-paste away from an actual request.
    host: 'sftp.example.invalid',
    username: 'florian',
    path: '/',
    sftp_port: 2222,
    ftps_port: 989,
    pasv_port_start: 50000,
    pasv_port_end: 50100,
    // The deployment's real value, so the row-order expectation below pins where
    // the mode belongs. `null` and the two modes each get their own case below.
    ftps_tls_mode: 'explicit',
};

const connection = (overrides: Partial<FtpConnection> = {}): FtpConnection => ({
    ...configured,
    ...overrides,
});

describe('describeFtpConnection', () => {
    it('returns no rows when the server has not declared its connection parameters', () => {
        // Not a "hide the incomplete values" case: `configured: false` means the
        // backend found no host/ports at all, so there is nothing to describe.
        expect(describeFtpConnection(connection({configured: false}))).toEqual([]);
    });

    it('returns no rows for an unconfigured server even when values are present', () => {
        // The flag wins over any stray value: a half-populated payload must not
        // read as a usable configuration.
        expect(describeFtpConnection({...configured, configured: false})).toEqual([]);
    });

    it('describes a fully configured connection in camera order', () => {
        expect(describeFtpConnection(configured)).toEqual([
            {field: 'host', value: 'sftp.example.invalid'},
            {field: 'username', value: 'florian'},
            {field: 'sftp', value: '2222'},
            {field: 'ftps', value: '989'},
            {field: 'ftps_tls_mode', value: 'explicit'},
            {field: 'passive_ports', value: '50000\u201350100'},
            {field: 'path', value: '/'},
        ]);
    });

    it('drops the sftp row when the port is unknown and keeps the rest', () => {
        const rows = describeFtpConnection(connection({sftp_port: null}));

        expect(rows.map(row => row.field)).not.toContain('sftp');
        expect(rows).toHaveLength(6);
        expect(rows.map(row => row.field)).toEqual([
            'host',
            'username',
            'ftps',
            'ftps_tls_mode',
            'passive_ports',
            'path',
        ]);
    });

    it('drops the ftps row when the port is unknown and keeps the rest', () => {
        const rows = describeFtpConnection(connection({ftps_port: null}));

        expect(rows.map(row => row.field)).not.toContain('ftps');
        expect(rows).toHaveLength(6);
    });

    it('drops the passive range when only its start is known', () => {
        // A one-sided range would be rendered as a port range the firewall has
        // not been opened for — the exact misconfiguration this row exists to
        // prevent.
        const rows = describeFtpConnection(connection({pasv_port_end: null}));

        expect(rows.map(row => row.field)).not.toContain('passive_ports');
        expect(rows).toHaveLength(6);
    });

    it('drops the passive range when only its end is known', () => {
        const rows = describeFtpConnection(connection({pasv_port_start: null}));

        expect(rows.map(row => row.field)).not.toContain('passive_ports');
        expect(rows).toHaveLength(6);
    });

    it('drops the host row when the host is unknown and keeps the rest', () => {
        const rows = describeFtpConnection(connection({host: null}));

        expect(rows.map(row => row.field)).not.toContain('host');
        expect(rows).toHaveLength(6);
    });

    it('drops the username row when the username is unknown and keeps the rest', () => {
        const rows = describeFtpConnection(connection({username: null}));

        expect(rows.map(row => row.field)).not.toContain('username');
        expect(rows).toHaveLength(6);
    });

    it('drops the path row when the deployment declared an unusable folder', () => {
        // `null` means the deployment set FTP_UPLOAD_PATH to something the server
        // refuses to confirm. A row is worse than no row here: the photographer
        // would then copy a folder the import does not read, which looks like an
        // empty inbox rather than an error.
        const rows = describeFtpConnection(connection({path: null}));

        expect(rows.map(row => row.field)).not.toContain('path');
        expect(rows).toHaveLength(6);
    });

    it('keeps the account root as a real path, not as "nothing declared"', () => {
        // `/` is the correct default and a value a camera has to be told
        // explicitly (Canon calls it *Stammverzeichnis*). Dropping it would send
        // the photographer to a wizard field with nothing to enter.
        const rows = describeFtpConnection(connection({path: '/'}));

        expect(rows.find(row => row.field === 'path')?.value).toBe('/');
    });

    it('keeps a configured subfolder verbatim', () => {
        // The camera field takes the value as typed, so it must not be trimmed or
        // rewritten into another spelling of the same folder.
        const rows = describeFtpConnection(connection({path: '/shoots/2026-09'}));

        expect(rows.find(row => row.field === 'path')?.value).toBe('/shoots/2026-09');
    });

    it('drops the TLS mode row when the deployment declares no mode', () => {
        // The mode decides whether an FTPS handshake can complete at all. An
        // unknown mode must vanish rather than fall back to a default: a wrong
        // guess surfaces in the camera as Error 48, which sends the photographer
        // to the certificate menu instead of to the truth.
        const rows = describeFtpConnection(connection({ftps_tls_mode: null}));

        expect(rows.map(row => row.field)).not.toContain('ftps_tls_mode');
        expect(rows).toHaveLength(6);
        expect(rows.map(row => row.field)).toEqual([
            'host',
            'username',
            'sftp',
            'ftps',
            'passive_ports',
            'path',
        ]);
    });

    it('reports the explicit TLS mode verbatim', () => {
        const rows = describeFtpConnection(connection({ftps_tls_mode: 'explicit'}));

        expect(rows).toContainEqual({field: 'ftps_tls_mode', value: 'explicit'});
        // Directly after the FTPS port: the mode qualifies that port, and reading
        // order follows the camera's own dialog.
        expect(rows.map(row => row.field)).toEqual([
            'host',
            'username',
            'sftp',
            'ftps',
            'ftps_tls_mode',
            'passive_ports',
            'path',
        ]);
    });

    it('reports the implicit TLS mode verbatim', () => {
        const rows = describeFtpConnection(connection({ftps_tls_mode: 'implicit'}));

        expect(rows).toContainEqual({field: 'ftps_tls_mode', value: 'implicit'});
    });

    it('does not rewrite a mode it does not recognise', () => {
        // The backend is the authority on the allowed values. Normalising an
        // unknown mode into `explicit` here would hide exactly the configuration
        // drift the field was added to expose.
        const rows = describeFtpConnection(connection({ftps_tls_mode: 'implicit-stapling'}));

        expect(rows).toContainEqual({field: 'ftps_tls_mode', value: 'implicit-stapling'});
    });

    it('keeps a path that only looks empty-ish, because it is a real root', () => {
        expect(describeFtpConnection(connection({path: '/'}))).toContainEqual({field: 'path', value: '/'});
    });

    it('renders ports as strings, so a camera field can copy them verbatim', () => {
        const rows = describeFtpConnection(configured);

        expect(rows.find(row => row.field === 'sftp')?.value).toBe('2222');
        expect(rows.find(row => row.field === 'ftps')?.value).toBe('989');
        expect(rows.every(row => typeof row.value === 'string')).toBe(true);
    });

    it('returns an empty list when every value of a configured server is unknown', () => {
        // `configured: true` with nothing to show is the degenerate case the UI
        // also renders as "not configured" — the two are intentionally
        // indistinguishable, because telling a photographer apart from the admin
        // who has to fix it helps nobody.
        expect(describeFtpConnection({
            configured: true,
            host: null,
            username: null,
            path: null,
            sftp_port: null,
            ftps_port: null,
            pasv_port_start: null,
            pasv_port_end: null,
            ftps_tls_mode: null,
        })).toEqual([]);
    });
});
