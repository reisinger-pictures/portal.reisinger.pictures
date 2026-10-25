#!/usr/bin/env bash
# ===========================================================================
# common.sh — gemeinsame Helfer fuer den FTP-Transport-Test-Harness.
#
# Wird von up.sh, verify.sh und down.sh gesourct. Enthaelt bewusst KEINE
# Logik, die einen Fehler als Erfolg durchwinken koennte: jede Messung
# prueft zuerst, ob das Gemessene ueberhaupt zustande kam.
# ===========================================================================

set -euo pipefail

# --- Pfade -----------------------------------------------------------------
HARNESS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
readonly HARNESS_DIR
RUNTIME_DIR="${FTP_HARNESS_RUNTIME_DIR:-$HARNESS_DIR/.runtime}"
readonly RUNTIME_DIR
COMPOSE_FILE="$HARNESS_DIR/docker-compose.yml"
readonly COMPOSE_FILE
COMPOSE_PROJECT="${FTP_HARNESS_PROJECT:-portal_ftp_transport_harness}"
readonly COMPOSE_PROJECT
CREDENTIALS_FILE="$RUNTIME_DIR/credentials.env"
readonly CREDENTIALS_FILE
REPORT_DIR="$RUNTIME_DIR/report"
readonly REPORT_DIR

# --- Ports -----------------------------------------------------------------
# Alle ueber die Umgebung ueberschreibbar. Die Defaults weichen bewusst von
# 8080/2222/989 ab, weil auf einer Entwickler-Maschine haeufig etwas auf
# 8080 lauscht. Der Produktionswert steckt in Container-Port UND
# Passivbereich, nicht im Host-Port.
API_PORT="${FTP_HARNESS_API_PORT:-18080}"
SFTP_PORT="${FTP_HARNESS_SFTP_PORT:-12222}"
FTPS_PORT="${FTP_HARNESS_FTPS_PORT:-19890}"
FTPS_IMPLICIT_PORT="${FTP_HARNESS_FTPS_IMPLICIT_PORT:-19990}"
# Der Harness nimmt 20000-20050 statt des Produktionswerts 50000-50100.
# Grund: auf macOS liegt der Kernel-Ephemeralbereich bei 49152-65535, und
# 101 Ports daraus kann Docker Desktop nicht dauerhaft binden. Der
# Produktionswert bleibt unveraendert in deployment/docker-compose.yml; das
# Harness weicht nur deshalb ab, weil es lokal laufen muss. Beide Werte
# werden im Report genannt, damit die Abweichung sichtbar bleibt.
PASV_PORT_START="${FTP_HARNESS_PASV_PORT_START:-20000}"
PASV_PORT_END="${FTP_HARNESS_PASV_PORT_END:-20050}"
readonly PRODUCTION_PASV_PORT_START=50000
readonly PRODUCTION_PASV_PORT_END=50100
CONTAINER_FTPS_PORT=989
CONTAINER_FTPS_IMPLICIT_PORT=990
CONTAINER_SFTP_PORT=2022

# Ziel-Ownership aus features/infrastructure/19-ftp-upload-pipeline.md 7.9.
# Nur zur Anzeige: die Diskrepanz zur effektiven UID soll sichtbar werden.
readonly PRODUCTION_TARGET_OWNER="1002:webgroup"

# --- Zugangsdaten ----------------------------------------------------------
ADMIN_USERNAME="${FTP_HARNESS_ADMIN_USERNAME:-harness_admin}"
ADMIN_PASSWORD="${FTP_HARNESS_ADMIN_PASSWORD:-harness-admin-pw-2026}"
FTP_USERNAME="${FTP_HARNESS_FTP_USERNAME:-camtest}"
# Show-once-Passwort nach 7.3: ^[a-z0-9]{16,24}$, keine Sonderzeichen,
# keine gemischte Gross-/Kleinschreibung. Der Harness benutzt bewusst ein
# solches Passwort, weil genau das die Kamera eingeben muss (M38 (c)).
FTP_PASSWORD="${FTP_HARNESS_FTP_PASSWORD:-kameratest2026passwd}"
readonly FTP_HOMEDIR="/var/www/ftp/${FTP_USERNAME}"

# --- Ausgabe ---------------------------------------------------------------
if [ -t 1 ] && [ -z "${NO_COLOR:-}" ]; then
    C_RESET=$'\033[0m'; C_INFO=$'\033[36m'; C_OK=$'\033[32m'
    C_WARN=$'\033[33m'; C_ERR=$'\033[31m'; C_HEAD=$'\033[1m'
else
    C_RESET=''; C_INFO=''; C_OK=''; C_WARN=''; C_ERR=''; C_HEAD=''
fi

log()   { printf '%s[ftp-harness]%s %s\n' "$C_INFO" "$C_RESET" "$*"; }
head1() { printf '\n%s== %s ==%s\n' "$C_HEAD" "$*" "$C_RESET"; }
ok()    { printf '%s  [ok]%s %s\n' "$C_OK" "$C_RESET" "$*"; }
warn()  { printf '%s  [warn]%s %s\n' "$C_WARN" "$C_RESET" "$*" >&2; }
fail()  { printf '%s  [FEHLER]%s %s\n' "$C_ERR" "$C_RESET" "$*" >&2; exit 1; }

compose() {
    # Compose interpoliert ${FTP_HARNESS_*} aus der Umgebung. Die Harness-
    # Defaults sind bisher nur Shell-Variablen, deshalb werden sie hier
    # explizit exportiert — inklusive der Werte, die der Aufrufer gesetzt
    # hat (P1-M31: kein Secret im Repo, der Wert kommt aus der Umgebung).
    FTP_HARNESS_RUNTIME_DIR="$RUNTIME_DIR" \
    FTP_HARNESS_ADMIN_USERNAME="$ADMIN_USERNAME" \
    FTP_HARNESS_ADMIN_PASSWORD="$ADMIN_PASSWORD" \
    FTP_HARNESS_FTPD_CIPHER_SUITES="${FTP_HARNESS_FTPD_CIPHER_SUITES:-}" \
    FTP_HARNESS_API_PORT="$API_PORT" \
    FTP_HARNESS_SFTP_PORT="$SFTP_PORT" \
    FTP_HARNESS_FTPS_PORT="$FTPS_PORT" \
    FTP_HARNESS_FTPS_IMPLICIT_PORT="$FTPS_IMPLICIT_PORT" \
    FTP_HARNESS_PASV_PORT_START="$PASV_PORT_START" \
    FTP_HARNESS_PASV_PORT_END="$PASV_PORT_END" \
    FTP_HARNESS_LOG_LEVEL="${FTP_HARNESS_LOG_LEVEL:-info}" \
        docker compose --project-name "$COMPOSE_PROJECT" -f "$COMPOSE_FILE" "$@"
}

# --- Voraussetzungen -------------------------------------------------------
require_cmd() {
    command -v "$1" >/dev/null 2>&1 \
        || fail "Voraussetzung fehlt: '$1' ist nicht im PATH."
}

# --- Timeout ohne GNU-coreutils -------------------------------------------
# macOS liefert kein `timeout` (nur wer coreutils installiert hat, hat
# `gtimeout`). Der Harness darf davon nicht abhaengen, sonst laeuft er nur
# auf einem Teil der Rechner. Watchdog-Ansatz mit Bordmitteln.
#
# run_with_timeout SEKUNDEN BEFEHL...
#   Exit 0   = Befehl regulaer beendet
#   Exit 124 = Watchdog hat zugeschlagen (Timeout)
run_with_timeout() {
    local secs="$1"; shift
    local flag rc pid watchdog
    flag="$(mktemp "${TMPDIR:-/tmp}/ftpharness-timeout.XXXXXX")"
    "$@" &
    pid=$!
    # Inhalt schreiben, nicht nur truncaten: `mktemp` legt eine leere Datei
    # an, und `: > flag` haelt sie leer. Ein Test auf `-s` (groesser 0)
    # wuerde dann immer "kein Timeout" melden. Gefunden vom self-test.sh.
    ( sleep "$secs"; printf 'TIMEOUT' > "$flag"; kill -TERM "$pid" 2>/dev/null || true ) &
    watchdog=$!
    rc=0
    # 2>/dev/null unterdrueckt die Job-Control-Meldung "Terminated", die
    # Bash sonst beim Reap des per SIGTERM beendeten Kindes auf stderr
    # schreibt. Sie wuerde in jedem Messprotokoll als Fehler aussehen.
    wait "$pid" 2>/dev/null || rc=$?
    kill -TERM "$watchdog" 2>/dev/null || true
    wait "$watchdog" 2>/dev/null || true
    if [ -s "$flag" ]; then rc=124; fi
    rm -f "$flag"
    return "$rc"
}

# --- Container-Log robust lesen -------------------------------------------
# Ein direktes `compose logs | grep -q` bricht unter `set -o pipefail` mit
# SIGPIPE ab, sobald grep fruehzeitig erfolgreich ist — der Exitcode wird
# dann faelschlich zum Fehlschlag. Deshalb wird das Log immer erst in eine
# Datei geschrieben.
fetch_log() {
    local out="$1"; shift
    compose logs --no-color "$@" > "$out" 2>&1 || true
}

preflight() {
    local missing=0
    require_cmd docker      || missing=1
    require_cmd curl        || missing=1
    require_cmd jq          || missing=1
    require_cmd openssl     || missing=1
    require_cmd sftp        || missing=1
    [ "$missing" -eq 0 ] || exit 1

    docker compose version >/dev/null 2>&1 \
        || fail "'docker compose' (v2) ist nicht verfuegbar."
    docker info >/dev/null 2>&1 \
        || fail "Docker-Daemon laeuft nicht. Harness abgebrochen."
    openssl ciphers -s 'ALL:eNULL' >/dev/null 2>&1 \
        || fail "OpenSSL-cipher-Liste nicht lesbar (openssl zu alt?)."
}

# --- Warteschleifen --------------------------------------------------------

# wait_for_tcp HOST PORT TIMEOUT_SEC BESCHRIEBUNG
# Wartet, bis ein TCP-Port eine Verbindung annimmt. Bricht nach TIMEOUT_SEC
# mit einer klaren Meldung ab — nie mit einem Erfolg, den es nicht gab.
wait_for_tcp() {
    local host="$1" port="$2" timeout="$3" what="$4"
    local deadline=$(( $(date +%s) + timeout ))
    while [ "$(date +%s)" -lt "$deadline" ]; do
        if (exec 3<>"/dev/tcp/${host}/${port}") 2>/dev/null; then
            exec 3<&- 3>&- || true
            return 0
        fi
        sleep 1
    done
    fail "$what: TCP ${host}:${port} war nach ${timeout}s nicht erreichbar.
  Das ist KEIN Messergebnis ueber Cipher oder Zertifikat, sondern ein
  nicht erreichbarer Dienst. Container-Log:
$(compose logs --no-color --tail 40 sftpgo 2>&1 | sed 's/^/    | /')"
}

# http_code_only URL [curl-args...]
# Liefert nur den HTTP-Status als Integer. Bei Verbindungsfehlern 000.
http_code_only() {
    local url="$1"; shift
    curl -s -o /dev/null -m 10 -w '%{http_code}' "$@" "$url" 2>/dev/null || printf '000'
}

# wait_for_http URL TIMEOUT_SEC BESCHRIEBUNG
wait_for_http() {
    local url="$1" timeout="$2" what="$3"
    local deadline=$(( $(date +%s) + timeout )) code
    while [ "$(date +%s)" -lt "$deadline" ]; do
        code="$(http_code_only "$url")"
        case "$code" in
            200) return 0 ;;
            401|403) : ;;  # antwortet, ist aber noch nicht bereit
        esac
        sleep 1
    done
    fail "$what: HTTP ${url} war nach ${timeout}s nicht mit 200 erreichbar (letzter Status ${code:-000}).
  Container-Log:
$(compose logs --no-color --tail 40 sftpgo 2>&1 | sed 's/^/    | /')"
}

# Zaehlt den wirklich benutzten Passiv-Datenport aus dem curl-Trace.
# Der Server antwortet je nach Aushandlung mit
#   227 Entering Passive Mode (127,0,0,1,78,101)   -> p1*256 + p2
#   229 Entering Extended Passive Mode (|||20029|)  -> Port direkt
# Beides wird ausgewertet, damit der Nachweis unabhaengig davon gilt, ob der
# Client EPSV oder PASV benutzt.
pasv_port_from_trace() {
    # pasv_port_from_trace TRACE-DATEI
    local trace="$1" hex
    hex="$(sed -nE 's/.*229 Entering Extended Passive Mode \(\|\|\|([0-9]+)\|.*/\1/p' "$trace" | tail -1)"
    if [ -n "$hex" ]; then printf '%s' "$hex"; return 0; fi
    local p1 p2
    p1="$(sed -nE 's/.*227 Entering Passive Mode \(([0-9]+),([0-9]+),([0-9]+),([0-9]+),([0-9]+),([0-9]+)\).*/\5/p' "$trace" | tail -1)"
    p2="$(sed -nE 's/.*227 Entering Passive Mode \(([0-9]+),([0-9]+),([0-9]+),([0-9]+),([0-9]+),([0-9]+)\).*/\6/p' "$trace" | tail -1)"
    if [ -n "$p1" ] && [ -n "$p2" ]; then printf '%s' "$(( p1 * 256 + p2 ))"; return 0; fi
    printf ''
}

# --- TLS-Handshake-Vollstaendigkeit (der Kern der Messfalle) ---------------
#
# features/infrastructure/19-ftp-upload-pipeline.md §7.11: ein nicht erreichbarer Dienst
# liefert bei `openssl s_client` "SSL handshake has read 0 bytes" und eine
# LEERE Cipher-Liste. Das als "Cipher nicht angeboten" zu protokollieren war
# ein Fehlschluss, der schon einmal eine Grundsatzentscheidung gekippt hat.
# Deshalb wird die Ausgabe von openssl NIE direkt interpretiert, sondern
# zuerst auf einen vollstaendigen Handshake geprueft.
#
# tls_probe LABEL DATEI [weitere openssl-args...]
#
# WICHTIG, verifiziert am 2026-09-26: fuer das explizite FTPS
# (TLSMode 1 = AUTH TLS) reicht ein blanker `openssl s_client` NICHT. Der
# FTP-Kanal startet im Klartext, der Client liest statt eines ServerHello
# die FTP-Begruessung und bricht mit
#   tls_validate_record_header:wrong version number
# ab - mit derselben leeren Cipher-Liste wie ein toter Dienst. Richtig ist
# `openssl s_client -starttls ftp`, das zuerst `AUTH TLS` sendet. Das ist
# die vierte Variante derselben Messfalle und deshalb fest verdrahtet.
# Das implizite FTPS (TLSMode 2) laesst sich dagegen mit einem blanken
# `openssl s_client` messen; der Harness macht beides.
tls_probe() {
    local label="$1" out="$2"; shift 2
    : > "$out"
    # stderr und stdout landen in derselben Datei, weil openssl die
    # Zusammenfassung je nach Version teils nach stderr schreibt.
    # `run_with_timeout` statt `timeout`: macOS hat kein GNU-`timeout`.
    run_with_timeout 20 openssl s_client -connect "127.0.0.1:${FTPS_PORT}" \
        -starttls ftp -servername localhost -verify_quiet "$@" \
        < /dev/null >> "$out" 2>&1 || true
    tls_assert_complete "$out" "$label"
}

# Gleiche Messung gegen das implizite FTPS-Binding, ohne AUTH TLS.
tls_probe_implicit() {
    local label="$1" out="$2"; shift 2
    : > "$out"
    run_with_timeout 20 openssl s_client -connect "127.0.0.1:${FTPS_IMPLICIT_PORT}" \
        -servername localhost -verify_quiet "$@" \
        < /dev/null >> "$out" 2>&1 || true
    tls_assert_complete "$out" "$label"
}

# tls_assert_complete DATEI LABEL
# Gibt 0 zurueck, wenn die Aufzeichnung einen vollstaendigen TLS-Handshake
# belegt. Sonst 1 - und schreibt den Grund nach stderr.
#
# Die Regexen nutzen POSIX-Klassen ([[:space:]]) und keine GNU-Erweiterung
# fuer Whitespace: die kennt nur GNU grep, nicht das BSD-grep von macOS.
tls_assert_complete() {
    local out="$1" label="$2"
    # 1) Kein ServerHello und keine Cipher-Suite => Handshake nie vollstaendig.
    if ! grep -qE '^(New, TLS|Server certificate|  Cipher[[:space:]]*:)' "$out"; then
        printf 'TLS-HANDSHAKE UNVOLLSTAENDIG (%s): kein ServerHello und keine Cipher-Suite im Protokoll.\n' "$label" >&2
        if grep -qE 'handshake has read 0 bytes' "$out"; then
            printf '  Ursache laut openssl: 0 Bytes gelesen - der Dienst ist nicht erreichbar.\n' >&2
            printf '  Das ist KEIN Befund ueber verfuegbare Ciphers.\n' >&2
        elif grep -qE 'wrong version number' "$out"; then
            printf '  Ursache laut openssl: wrong version number - der Kanal ist im Klartext.\n' >&2
            printf '  Gegen das explizite FTPS (AUTH TLS) braucht s_client zwingend -starttls ftp.\n' >&2
        elif grep -qE 'Connection refused|connect:errno|Connection reset' "$out"; then
            printf '  Ursache laut openssl: Verbindung abgelehnt - der Dienst laeuft nicht.\n' >&2
            printf '  Das ist KEIN Befund ueber verfuegbare Ciphers.\n' >&2
        else
            printf '  Rohprotokoll: %s\n' "$out" >&2
        fi
        return 1
    fi
    # 2) Cipher-Suite muss eine konkrete sein, nicht "(NONE)".
    if ! grep -qE '(Cipher is|Cipher[[:space:]]*:)[[:space:]]+[A-Za-z0-9][A-Za-z0-9_-]*' "$out" ||
       grep -qE '(Cipher is|Cipher[[:space:]]*:)[[:space:]]+\(NONE\)' "$out"; then
        printf 'TLS-HANDSHAKE UNVOLLSTAENDIG (%s): Protokollversion gesetzt, aber keine konkrete Cipher-Suite.\n' "$label" >&2
        return 1
    fi
    # 3) Ein Alert ist ein Messergebnis, aber kein vollstaendiger Handshake.
    if grep -qE 'alert protocol version|sslv3 alert|handshake failure|no shared cipher|no cipher match' "$out"; then
        printf 'TLS-HANDSHAKE FEHLGESCHLAGEN (%s): Alert im Protokoll. Rohprotokoll: %s\n' "$label" "$out" >&2
        return 1
    fi
    return 0
}

# negotiated_protocol DATEI  /  negotiated_cipher DATEI
#
# Die beiden Protokollzeilen unterscheiden sich je nach OpenSSL-Version:
#   1.x: "    New, TLSv1.3, Cipher is TLS_AES_128_GCM_SHA256"
#   3.x: "Protocol  : TLSv1.3"  /  "Cipher    : TLS_AES_128_GCM_SHA256"
# Beide Formen werden gelesen, aber NUR nachdem tls_assert_complete geklappt
# hat — sonst waeren die Felder leer und wuerden als "nicht angeboten"
# fehlgelesen.
tls_protocol() {
    local v
    v="$(sed -nE 's/^New, ([^,]+), Cipher is .*$/\1/p' "$1" | head -1)"
    if [ -z "$v" ]; then
        v="$(sed -nE 's/^[[:space:]]*Protocol[[:space:]]*:[[:space:]]*(\S+).*/\1/p' "$1" | head -1)"
    fi
    printf '%s' "$v"
}

tls_cipher() {
    local v
    v="$(sed -nE 's/^New, [^,]+, Cipher is (.*)$/\1/p' "$1" | head -1)"
    if [ -z "$v" ]; then
        v="$(sed -nE 's/^[[:space:]]*Cipher[[:space:]]*:[[:space:]]*(\S+).*/\1/p' "$1" | head -1)"
    fi
    printf '%s' "$v"
}

# --- Klartext-Login-Ablehnung (TLS-Zwang) ----------------------------------
#
# features/infrastructure/19-ftp-upload-pipeline.md 7.13: `TLS_MODE=1` ist
# nicht "explizites TLS mit optionalem Klartext-Zweig", sondern in
# ftpserverlib `MandatoryEncryption`. Ohne `AUTH TLS` wird `USER` mit
# `421 TLS is required` beantwortet. Diese Zusage stand in unserer eigenen
# Doku einmal falsch herum (Klartext angeblich moeglich, Firewall-Regel
# noetig); der folgende Probe haelt die gemessene Richtung fest.
#
# Bewusst KEIN TLS-Client und kein Duplikat von tls_probe: hier wird die
# Klartextstrecke absichtlich im Klartext gesprochen. Was zaehlt, ist die
# Antwort auf `USER`, nicht ein Handshake. Der Klartext-Greeting
# (`220 SFTPGo_2.7.6`) ist erwartet und kommt VOR `AUTH TLS` — genau er hat
# die falsche Annahme plausibel gemacht.

# _plaintext_session PORT BENUTZER PASSWORT
# Schreibt das Transkript nach stdout. Ein nicht erreichbarer Dienst ist ein
# Fehlerfall (return 1), kein Messergebnis.
_plaintext_session() {
    local port="$1" user="$2" pass="$3" line
    exec 3<>"/dev/tcp/127.0.0.1/${port}" 2>/dev/null || return 1
    # `read -t` ist der Timeout: macOS hat kein GNU-`timeout`, und der Server
    # schliesst die Verbindung nach der Ablehnung — ohne Timeout wuerde der
    # Probe dort haengen.
    if IFS= read -r -t 10 line <&3; then
        printf 'greeting: %s\n' "$line"
    else
        printf 'greeting: <keine>\n'
    fi
    # `|| true`: nach dem 421 schliesst der Server, der naechste Schreibzugriff
    # bekommt EPIPE. Das ist Teil der Messung, nicht ihr Abbruch.
    printf 'USER %s\r\n' "$user" >&3 || true
    if IFS= read -r -t 10 line <&3; then
        printf 'USER -> %s\n' "$line"
    else
        printf 'USER -> <keine Antwort>\n'
    fi
    printf 'PASS %s\r\n' "$pass" >&3 || true
    if IFS= read -r -t 10 line <&3; then
        printf 'PASS -> %s\n' "$line"
    else
        printf 'PASS -> <keine Antwort>\n'
    fi
    exec 3<&- 3>&- || true
    return 0
}

# plaintext_login_probe DATEI
# Misst gegen das explizite FTPS-Binding und legt das Transkript ab.
# Exit 0 = Messung zustande gekommen, 124 = Watchdog, sonst Verbindungsfehler.
plaintext_login_probe() {
    local out="$1"
    : > "$out"
    run_with_timeout 25 _plaintext_session "$FTPS_PORT" "$FTP_USERNAME" "$FTP_PASSWORD" \
        >> "$out" 2>&1
}

# plaintext_refusal_assert DATEI
# 0 = die Aufzeichnung belegt den TLS-Zwang: der Server hat gesprochen, `USER`
#     wurde ohne AUTH TLS mit 4xx plus `TLS` abgelehnt und KEIN Befehl mit
#     2xx/3xx quittiert (also kein Login).
# 1 = sonst, mit benanntem Grund auf stderr. Die drei Fehlerbilder sind
#     getrennt, weil sie verschiedene Ursachen haben: toter Dienst, offener
#     Klartext-Zugang, unvollstaendige Messung.
plaintext_refusal_assert() {
    local out="$1"
    if ! grep -qE '^greeting: [0-9][0-9][0-9]' "$out"; then
        printf 'KLARTEXT-PROBE OHNE GREETING: der Dienst hat nicht einmal gesprochen.\n' >&2
        printf '  Das ist KEIN Befund ueber den TLS-Zwang, sondern ein nicht erreichbarer Dienst.\n' >&2
        printf '  Rohprotokoll: %s\n' "$out" >&2
        return 1
    fi
    if grep -qE '^(USER|PASS) -> [23][0-9][0-9]' "$out"; then
        printf 'KLARTEXT-LOGIN MOEGLICH: ein Befehl ohne AUTH TLS wurde mit 2xx/3xx quittiert.\n' >&2
        printf '  Das widerspricht TLS_MODE=1 = MandatoryEncryption.\n' >&2
        printf '  Rohprotokoll: %s\n' "$out" >&2
        return 1
    fi
    if ! grep -qiE '^USER -> 4[0-9][0-9].*TLS' "$out"; then
        printf 'KEINE ABLEHNUNG MESSBAR: `USER` bekam weder 2xx/3xx noch ein 4xx mit TLS-Bezug.\n' >&2
        printf '  Die Probe hat damit nichts ueber den TLS-Zwang bewiesen.\n' >&2
        printf '  Rohprotokoll: %s\n' "$out" >&2
        return 1
    fi
    return 0
}

# --- Symlink-Erzeugung durch das Kamera-Konto (MEDIA-5, #29) ----------------
#
# Vorbedingung der Importer-Frage: kann das Kamera-Konto ueberhaupt einen
# Symlink anlegen, und was steht als Ziel im Link? SFTPGo v2.7.6 verlangt
# dafuer `common.symlink_mode & 1` (internal/common/connection.go:973); der
# Default ist 0 (internal/config/config.go:213 und die ausgelieferte
# sftpgo.json:12). Fuer den FTP-Weg zusaetzlich `ftpd.enable_site`
# (Default false, config.go:300, sftpgo.json:147), das die Bindung als
# `DisableSite` an ftpserverlib weiterreicht (internal/ftpd/server.go:126).
#
# Der interessante Fall ist ein RELATIVES Ziel: SFTPGo setzt die virtuelle
# Aufloesung nur fuer die Rechtepruefung ein und schreibt bei relativem Ziel
# den uebergebenen String woertlich in den Link
# (internal/common/connection.go:990-1004). Deshalb wird genau der gemessen.
#
# symlink_verdict ANLEGE-LOG GESPEICHERTES-ZIEL
#   ACCEPTED <ziel>      das Ziel wurde (vom Host aus) im Link gelesen
#   REFUSED_UNSUPPORTED  Server lehnt das Anlegen als nicht unterstuetzt ab
#   REFUSED_PERMISSION   Server verweigert das Anlegen (create_symlinks fehlt)
#   UNKLAR               keine belastbare Aussage (Exit 1)
#
# Das Ziel kommt NICHT aus der sftp-Ausgabe, sondern vom Host-Dateisystem
# (readlink auf den Bind-Mount). Grund, gemessen am 2026-10-25: die
# sftp-Ausgabe enthaelt im Fehlerfall Zeilen wie "Connection closed." — die
# wurden in einer ersten Fassung als Linkziel gelesen und erzeugten ein
# falsches "ACCEPTED". Client-Text ist keine Messung, lstat auf dem Host ist
# eine.
symlink_verdict() {
    local create_log="$1" target="$2"
    if [ -n "$target" ]; then
        printf 'ACCEPTED %s' "$target"
        return 0
    fi
    if grep -qiE 'operation unsupported|not implemented|unsupported' "$create_log"; then
        printf 'REFUSED_UNSUPPORTED'
        return 0
    fi
    if grep -qiE 'permission denied' "$create_log"; then
        printf 'REFUSED_PERMISSION'
        return 0
    fi
    printf 'UNKLAR'
    return 1
}

# Ist das Anlege-Log gar kein Messergebnis, sondern eine gescheiterte
# Verbindung/Anmeldung? Dann darf daraus kein "abgelehnt" werden.
symlink_log_is_transport_failure() {
    grep -qiE 'connection (closed|refused|reset)|kex_exchange_identification|no matching host key|Permission denied \(' "$1"
}

# Liegt das gespeicherte Ziel ausserhalb des Home? Nur fuer die Anzeige: der
# Probe-Link zeigt bewusst mit `..` heraus. Ein absoluter Zielpfad zaehlt als
# "ausserhalb", wenn er nicht mit dem Home-Praefix beginnt.
symlink_target_escapes() {
    local target="$1" home="$2"
    case "$target" in
        ../*) return 0 ;;
    esac
    case "$target" in
        /*) case "$target" in
                "$home"|"$home"/*) return 1 ;;
                *) return 0 ;;
            esac ;;
    esac
    return 1
}

# --- Kleinkram -------------------------------------------------------------

sha256_of() {
    if command -v sha256sum >/dev/null 2>&1; then sha256sum "$1" | awk '{print $1}';
    else shasum -a 256 "$1" | awk '{print $1}'; fi
}

file_size_of() {
    wc -c < "$1" | tr -d '[:space:]'
}

# Laeuft ein Port auf dem Host bereits (z. B. opencode auf 8080)?
port_in_use() {
    local port="$1"
    if (exec 3<>"/dev/tcp/127.0.0.1/${port}") 2>/dev/null; then
        exec 3<&- 3>&- || true
        return 0
    fi
    return 1
}

check_port_free() {
    local port="$1" what="$2"
    if port_in_use "$port"; then
        fail "Host-Port ${port} ist bereits belegt (${what}).
  Der Harness bricht hier bewusst ab, statt stillschweigend einen anderen
  Port zu nehmen: die Messung soll gegen die dokumentierten Ports laufen.
  Override z. B. mit FTP_HARNESS_API_PORT=28080."
    fi
}

# Prueft per echtem bind(), ob der passive Bereich frei ist. Ein blosser
# connect()-Test reicht nicht: ein ausgehender Socket im Kernel-Ephemeral-
# bereich nimmt keine Verbindungen an, blockiert aber das bind().
#
# Zwei Feinheiten, ohne die die Pruefung falsche Alarme erzeugt:
#   * SO_REUSEADDR — Docker setzt es beim Publizieren der Ports ebenfalls.
#     Ohne es meldet die Pruefung Ports als belegt, die nur im TIME_WAIT
#     stehen und den Stack-Start gar nicht verhindern wuerden.
#   * Mehrere Versuche — ein einzelner ausgehender Socket kann einen Port
#     kurz belegen. Erst wenn ein Port in ALLEN Versuchen blockiert ist, ist
#     er wirklich nicht verfuegbar. Sonst scheitert der Harness an einem
#     Ausreisser, den es beim zweiten Versuch nicht mehr gibt.
check_passive_range_bindable() {
    local start="$1" end="$2"
    if ! command -v python3 >/dev/null 2>&1; then
        warn "python3 fehlt, der passive Bereich ${start}-${end} wird nicht vorab geprueft."
        return 0
    fi
    local busy attempt
    for attempt in 1 2 3; do
        busy="$(range_busy_ports "$start" "$end")"
        [ -z "$busy" ] && return 0
        [ "$attempt" -lt 3 ] && sleep 1
    done
    if [ -n "$busy" ]; then
        local count
        count="$(printf '%s' "$busy" | tr ',' '\n' | grep -c . || true)"
        fail "Der passive Bereich ${start}-${end} ist nicht bindbar; belegt: ${busy} (${count} Ports).
  Zwei moegliche Ursachen:
    1. Ports sind von einem anderen Prozess belegt — dann den Bereich verschieben:
         FTP_HARNESS_PASV_PORT_START=21000 FTP_HARNESS_PASV_PORT_END=21050 bash up.sh
    2. Der Bereich liegt im Kernel-Ephemeralbereich des Hosts. Auf macOS ist das
       49152-65535; Docker kann Ports daraus nicht dauerhaft halten. Deshalb ist
       der Harness-Default ${start}-${end} und NICHT der Produktionswert
       ${PRODUCTION_PASV_PORT_START}-${PRODUCTION_PASV_PORT_END}."
    fi
}

# range_busy_ports START END -> kommagetrennte Liste der nicht bindbaren Ports
# Eigenes Skript statt Inline-Heredoc, weil ein `python3 - <<EOF` in einer
# Bash-Funktion nicht vernuenftig auszugeben oder zu protokollieren ist.
range_busy_ports() {
    python3 "$HARNESS_DIR/lib/range_busy_ports.py" "$1" "$2"
}
