#!/usr/bin/env bash
# ===========================================================================
# up.sh — FTP-Transport-Test-Harness hochfahren und Test-User provisionieren.
#
# Board: P1-M35 (Harness + Config-Weg) und P1-M38 (Integrationstest).
# Feature-Doc: features/infrastructure/19-ftp-upload-pipeline.md 7.13, 7.14
#
# Ablauf:
#   1. Voraussetzungen und Portfreiheit pruefen
#   2. Laufzeitverzeichnis (.runtime) und FTPS-Zertifikat erzeugen
#   3. Compose-Stack starten
#   4. Auf Admin-API warten (bounded, mit klarer Timeout-Meldung)
#   5. Admin-Bootstrap pruefen (GET /api/v2/token, Basic-Auth)
#   6. Auf SFTP- und FTPS-Port warten
#   7. Test-User provisionieren (POST /api/v2/users)
#   8. Credentials nach .runtime/credentials.env schreiben (chmod 600)
#   9. verify.sh ausfuehren (Uploads ueber beide Protokolle + Cipher-Report),
#      ausser mit --skip-verify
#
# WICHTIG: /api/v2/token ist ein GET, kein POST. Der openapi-Vertrag der
# laufenden Instanz (GET /openapi/openapi.yaml) definiert `get: security:
# [BasicAuth]`. Ein POST antwortet 405. Wer das im Portal-Client (P1-M22)
# falsch nachbaut, bekommt 405 und haelt es fuer ein Auth-Problem.
# ===========================================================================
set -euo pipefail

# shellcheck source=lib/common.sh
. "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib/common.sh"

SKIP_VERIFY=0
KEEP_EXISTING_DATA=0
for arg in "$@"; do
    case "$arg" in
        --skip-verify) SKIP_VERIFY=1 ;;
        --keep-data)   KEEP_EXISTING_DATA=1 ;;
        -h|--help)
            sed -n '2,25p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
            exit 0 ;;
        *) fail "Unbekanntes Argument: $arg (siehe --help)" ;;
    esac
done

head1 "1/9 Voraussetzungen"
preflight
ok "docker, curl, jq, openssl, sftp vorhanden"
for p in "$API_PORT" "$SFTP_PORT" "$FTPS_PORT" "$FTPS_IMPLICIT_PORT"; do
    check_port_free "$p" "Harness-Port $p"
done
ok "Host-Ports ${API_PORT}/${SFTP_PORT}/${FTPS_PORT}/${FTPS_IMPLICIT_PORT} frei"
check_passive_range_bindable "$PASV_PORT_START" "$PASV_PORT_END"
ok "Passivbereich ${PASV_PORT_START}-${PASV_PORT_END} bindbar"
log "Produktions-Passivbereich bleibt ${PRODUCTION_PASV_PORT_START}-${PRODUCTION_PASV_PORT_END}"
log "  (Abweichung nur, weil macOS den Kernel-Ephemeralbereich auf 49152-65535 legt)"

head1 "2/9 Laufzeitverzeichnis und FTPS-Zertifikat"
if [ "$KEEP_EXISTING_DATA" -eq 1 ] && [ -f "$RUNTIME_DIR/data/sftpgo.db" ]; then
    log "--keep-data: vorhandene SQLite-Datenbank wird weiterverwendet."
else
    # Kein `chown -R`, kein `adduser -h` auf fremde Pfade. 19-ftp 7.9 verbietet
    # beides fuer alles, was unter /home/webadmin/websites liegt; das Harness
    # raeumt ausschliesslich sein eigenes .runtime-Verzeichnis ab.
    if [ -d "$RUNTIME_DIR" ]; then
        log "Raeume altes .runtime-Verzeichnis ..."
        rm -rf "$RUNTIME_DIR"
    fi
    mkdir -p "$RUNTIME_DIR"/{data,ftp,certs,report,payload}
    ok "Laufzeitverzeichnis: $RUNTIME_DIR"
fi
mkdir -p "$RUNTIME_DIR"/{data,ftp,certs,report,payload}

# Die Datenbank-Dateien muessen fuer UID 1000 beschreibbar sein, sonst
# startet der Dienst ohne Schema und ohne Admin (P1-M35 (b)).
chmod 0777 "$RUNTIME_DIR/data" "$RUNTIME_DIR/ftp" 2>/dev/null || true

if [ ! -f "$RUNTIME_DIR/certs/ftps.crt" ] || [ ! -f "$RUNTIME_DIR/certs/ftps.key" ]; then
    log "Erzeuge selbstsigniertes FTPS-Zertifikat (RSA 2048, SAN localhost/127.0.0.1) ..."
    openssl req -x509 -newkey rsa:2048 -nodes \
        -keyout "$RUNTIME_DIR/certs/ftps.key" \
        -out    "$RUNTIME_DIR/certs/ftps.crt" \
        -days 30 -subj "/CN=portal-ftp-transport-harness" \
        -addext "subjectAltName=DNS:localhost,IP:127.0.0.1" >/dev/null 2>&1 \
        || fail "Zertifikatserzeugung fehlgeschlagen (openssl)."
    # SFTPGo laeuft als UID 1000 und muss den Key lesen koennen.
    chmod 0644 "$RUNTIME_DIR/certs/ftps.key"
    ok "Zertifikat erzeugt: $(openssl x509 -in "$RUNTIME_DIR/certs/ftps.crt" -noout -subject 2>/dev/null)"
else
    ok "FTPS-Zertifikat vorhanden (wiederverwendet)"
fi

head1 "3/9 Compose-Stack starten"
# Der Stack ist absichtlich NICHT `deployment/docker-compose.yml` — dort steht
# der Produktionsbetrieb mit Host-Pfad /home/webadmin/websites/ftp.
log "Start: compose -p ${COMPOSE_PROJECT}"
compose up -d --force-recreate sftpgo
ok "Container gestartet"

head1 "4/9 Warten auf die Admin-API"
API="http://127.0.0.1:${API_PORT}/api/v2"
wait_for_http "http://127.0.0.1:${API_PORT}/healthz" 90 "Admin-API"
ok "GET /healthz liefert 200 auf 127.0.0.1:${API_PORT}"

head1 "5/9 Admin-Bootstrap pruefen (GET /api/v2/token, Basic-Auth)"
TOKEN_JSON="$RUNTIME_DIR/report/token.json"
token_code="$(curl -s -m 15 -o "$TOKEN_JSON" -w '%{http_code}' \
    -u "${ADMIN_USERNAME}:${ADMIN_PASSWORD}" "${API}/token" || printf '000')"
case "$token_code" in
    200) ok "Admin-Token erhalten (${ADMIN_USERNAME})" ;;
    401)
        fail "/api/v2/token -> 401 '$(jq -r '.error // "?"' "$TOKEN_JSON" 2>/dev/null)'.
  Ursache ist in aller Regel die fehlende Zeile
  SFTPGO_DATA_PROVIDER__CREATE_DEFAULT_ADMIN=true im Compose.
  Ohne sie legt SFTPGo keinen Default-Admin an, auch nicht wenn
  SFTPGO_DEFAULT_ADMIN_USERNAME/PASSWORD gesetzt sind (create_default_admin
  ist per Default false, siehe internal/dataprovider/dataprovider.go).
  Bestaetigung aus dem Log:
$(compose logs --no-color sftpgo 2>&1 | grep -iE 'admin|schema_version' | tail -5 | sed 's/^/    | /')" ;;
    000) fail "/api/v2/token nicht erreichbar (Timeout/Verbindung). API-Port ${API_PORT}." ;;
    *)  fail "/api/v2/token -> HTTP ${token_code}: $(head -c 300 "$TOKEN_JSON")" ;;
esac
ACCESS_TOKEN="$(jq -r '.access_token // empty' "$TOKEN_JSON")"
[ -n "$ACCESS_TOKEN" ] || fail "Token-Antwort ohne access_token."

head1 "6/9 Warten auf SFTP- und FTPS-Ports"
wait_for_tcp 127.0.0.1 "$SFTP_PORT" 60 "SFTP (SSH)"
ok "SFTP-Port 127.0.0.1:${SFTP_PORT} nimmt Verbindungen an"
wait_for_tcp 127.0.0.1 "$FTPS_PORT" 60 "FTPS (explizit)"
ok "FTPS-Port 127.0.0.1:${FTPS_PORT} nimmt Verbindungen an"
wait_for_tcp 127.0.0.1 "$FTPS_IMPLICIT_PORT" 60 "FTPS (implizit)"
ok "FTPS-Port 127.0.0.1:${FTPS_IMPLICIT_PORT} (implicit TLS) nimmt Verbindungen an"

# Bindings aus dem Log gegen die Erwartung stellen. Ohne diesen Schritt
# koennte der Dienst laufen, aber auf einem anderen Port als konfiguriert —
# genau der Fehler, der in P1-M35 als "Cipher nicht angeboten" fehlgedeutet
# wurde.
LOG_SNAPSHOT="$RUNTIME_DIR/report/container-startup.log"
fetch_log "$LOG_SNAPSHOT" sftpgo
for needle in "binding: 0.0.0.0:${CONTAINER_FTPS_PORT}" "binding: 0.0.0.0:${CONTAINER_FTPS_IMPLICIT_PORT}"; do
    if ! grep -qF "$needle" "$LOG_SNAPSHOT"; then
        fail "FTPD-Binding fehlt im Log: '${needle}'.
  Erwartet wird 'starting FTP serving, binding: <addr>:<port>'.
  Konfigurierte Bindings im Harness-Compose:
$(grep -n 'SFTPGO_FTPD__BINDINGS__' "$COMPOSE_FILE" | sed 's/^/    | /')
  Bisheriges Log:
$(grep -iE 'ftpd|ftpserver' "$LOG_SNAPSHOT" | tail -5 | cut -c1-200 | sed 's/^/    | /')"
    fi
done
ok "FTPD-Bindings ${CONTAINER_FTPS_PORT} (explizit) und ${CONTAINER_FTPS_IMPLICIT_PORT} (implizit) bestaetigt"
if ! grep -qF "address: [::]:${CONTAINER_SFTP_PORT}" "$LOG_SNAPSHOT"; then
    fail "SFTP-Listener auf Container-Port ${CONTAINER_SFTP_PORT} nicht im Log bestaetigt."
fi
ok "SFTP-Listener auf Container-Port ${CONTAINER_SFTP_PORT} bestaetigt"

head1 "7/9 Test-User provisionieren (POST /api/v2/users)"
USER_JSON="$RUNTIME_DIR/report/user.json"
delete_code="$(curl -s -m 15 -o /dev/null -w '%{http_code}' -X DELETE \
    -H "Authorization: Bearer ${ACCESS_TOKEN}" "${API}/users/${FTP_USERNAME}" || printf '000')"
case "$delete_code" in
    200|204|404) : ;;
    *) warn "DELETE /users/${FTP_USERNAME} -> HTTP ${delete_code}, Provisionierung wird eskalieren." ;;
esac

create_code="$(curl -s -m 20 -o "$USER_JSON" -w '%{http_code}' -X POST \
    -H "Authorization: Bearer ${ACCESS_TOKEN}" \
    -H 'Content-Type: application/json' \
    -d "$(jq -nc --arg u "$FTP_USERNAME" --arg p "$FTP_PASSWORD" --arg h "$FTP_HOMEDIR" '{
            username: $u,
            password: $p,
            status: 1,
            home_dir: $h,
            description: "FTP-Transport-Test-Harness (P1-M38)",
            permissions: {"/": ["*"]}
        }')" \
    "${API}/users" || printf '000')"
case "$create_code" in
    200|201) ok "User angelegt: $(jq -r '.username + " id=" + (.id|tostring) + " home=" + .home_dir' "$USER_JSON")" ;;
    000) fail "POST /api/v2/users nicht erreichbar." ;;
    4*) fail "POST /api/v2/users -> HTTP ${create_code}: $(jq -r '.error // .' "$USER_JSON" 2>/dev/null | head -c 400)" ;;
    *)  fail "POST /api/v2/users -> unerwartetes HTTP ${create_code}." ;;
esac

# Das Home-Verzeichnis muss fuer UID 1000 beschreibbar sein. SFTPGo legt es
# zwar selbst an, das schlaegt aber fehl, wenn der Elternordner nicht
# beschreibbar ist — und genau dann scheitert der erste echte Upload.
mkdir -p "$RUNTIME_DIR/ftp/${FTP_USERNAME}" || fail "Home-Verzeichnis nicht anlegbar."
chmod 0777 "$RUNTIME_DIR/ftp/${FTP_USERNAME}" 2>/dev/null || true
ok "Home-Verzeichnis bereit: $RUNTIME_DIR/ftp/${FTP_USERNAME} -> ${FTP_HOMEDIR}"

head1 "8/9 Credentials schreiben"
umask 077
cat > "$CREDENTIALS_FILE" <<EOF
# Vom Harness erzeugt — lokale Testdaten, niemals committen.
FTP_HARNESS_API_URL=http://127.0.0.1:${API_PORT}
FTP_HARNESS_API_BASE=${API}
FTP_HARNESS_ADMIN_USERNAME=${ADMIN_USERNAME}
FTP_HARNESS_ADMIN_PASSWORD=${ADMIN_PASSWORD}
FTP_HARNESS_FTP_USERNAME=${FTP_USERNAME}
FTP_HARNESS_FTP_PASSWORD=${FTP_PASSWORD}
FTP_HARNESS_FTP_HOMEDIR=${FTP_HOMEDIR}
FTP_HARNESS_FTP_HOST_DIR=${RUNTIME_DIR}/ftp/${FTP_USERNAME}
FTP_HARNESS_SFTP_PORT=${SFTP_PORT}
FTP_HARNESS_FTPS_PORT=${FTPS_PORT}
FTP_HARNESS_FTPS_IMPLICIT_PORT=${FTPS_IMPLICIT_PORT}
FTP_HARNESS_PASV_PORT_START=${PASV_PORT_START}
FTP_HARNESS_PASV_PORT_END=${PASV_PORT_END}
FTP_HARNESS_RUNTIME_DIR=${RUNTIME_DIR}
EOF
chmod 0600 "$CREDENTIALS_FILE"
ok "Credentials: $CREDENTIALS_FILE (mode 0600, gitignored)"

head1 "9/9 Ergebnis"
log "SFTPGo-Version: $(sed -nE 's/.*"message":"starting SFTPGo ([^"]+)".*/\1/p' "$LOG_SNAPSHOT" | head -1 | cut -c1-60)"
log "Compose-Projekt: ${COMPOSE_PROJECT}"
log "Abbauen mit: bash ${HARNESS_DIR}/down.sh"

if [ "$SKIP_VERIFY" -eq 1 ]; then
    log "--skip-verify: Upload-Verifikation und Cipher-Report wurden uebersprungen."
    printf '\n%sHarness laeuft. Naechster Schritt: bash %s/verify.sh%s\n' "$C_HEAD" "$HARNESS_DIR" "$C_RESET"
    exit 0
fi

printf '\n'
"$HARNESS_DIR/verify.sh"
