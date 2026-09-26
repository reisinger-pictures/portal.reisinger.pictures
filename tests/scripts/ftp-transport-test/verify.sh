#!/usr/bin/env bash
# ===========================================================================
# verify.sh — Upload-Verifikation ueber BEIDE Protokolle + Cipher-Report.
#
# Board: P1-M38 (Integrationstest) und P1-M27 (Kamera-Cipher-Frage).
# Feature-Doc: 19-ftp 7.13 (Port-Erreichbarkeit), 7.14 (Teststrategie), 7.11 (Messfalle)
#
# Was dieses Skript BEWEIST:
#   * FTPS (explizites AUTH TLS) mit `curl` kann sich einloggen und hochladen,
#     und der passive Datenkanal ist benutzbar.
#   * SFTP mit `sftp` im Batch-Modus kann sich einloggen und hochladen.
#   * Ein Show-once-Passwort ohne Sonderzeichen kommt durch Provisionierung
#     und Login (M38 (c)).
#   * Die Datei liegt hinterher im erwarteten Verzeichnis, mit erwarteter
#     Groesse und identischer Pruefsumme.
#   * Welche TLS-Cipher-Suites der Dienst tatsaechlich anbietet.
#
# Was dieses Skript ausdruecklich NICHT beweist:
#   * Dass die echte Kamera den Server erreicht. Die Kamera ist kein curl,
#     sie hat eine eigene TLS-Bibliothek und ein Passwortfeld, das nur
#     alphanumerisch akzeptiert. Das bleibt P1-M32, ein operativer Schritt.
# ===========================================================================
set -euo pipefail

# shellcheck source=lib/common.sh
. "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib/common.sh"

REQUIRE_CAMERA_CIPHERS=0
for arg in "$@"; do
    case "$arg" in
        --require-camera-ciphers) REQUIRE_CAMERA_CIPHERS=1 ;;
        -h|--help) sed -n '2,21p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) fail "Unbekanntes Argument: $arg" ;;
    esac
done

[ -f "$CREDENTIALS_FILE" ] \
    || fail "Keine Credentials. Erst 'bash $HARNESS_DIR/up.sh' aufrufen."
# Credentials sind absichtlich 0600; das Skript laeuft im selben Benutzer.
# shellcheck disable=SC1090
. "$CREDENTIALS_FILE"

API_BASE="${FTP_HARNESS_API_BASE}"
FTPS_HOST="127.0.0.1:${FTPS_PORT}"
SFTP_HOST="127.0.0.1:${SFTP_PORT}"
HOST_HOME="${FTP_HARNESS_FTP_HOST_DIR}"
REPORT_TXT="$REPORT_DIR/cipher-report.txt"
REPORT_JSON="$REPORT_DIR/cipher-report.json"
PAYLOAD_DIR="$RUNTIME_DIR/payload"

# Zwei getrennte Zaehler — das ist der wichtigste Unterschied in diesem
# Harness und der Grund, warum er ueberhaupt existiert (19-ftp 7.11):
#
#   FAILURES  = der Harness oder der Dienst hat nicht getan, was er tun
#               soll. Dafuer gibt es keinen gruenen Lauf.
#   FINDINGS  = der Harness hat sauber gemessen und etwas gefunden, das
#               eine Entscheidung verlangt. Das ist kein Defekt und darf
#               den Exit-Code nicht verraeten, sonst wird der Befund
#               "repariert", indem man die Messung abschaltet.
# Nur mit --require-camera-ciphers wird ein Befund zum Fehlschlag, weil dann
# jemand ausdruecklich gesagt hat: ohne diese Suites ist der Stack nicht
# cutover-faehig.
FAILURES=0
FINDINGS=0
note_failure() { FAILURES=$((FAILURES + 1)); printf '%s  [FAIL]%s %s\n' "$C_ERR" "$C_RESET" "$*"; }
note_finding() { FINDINGS=$((FINDINGS + 1)); printf '%s  [BEFUND]%s %s\n' "$C_WARN" "$C_RESET" "$*"; }

# Deterministische Payloads: Inhalt ist als Text lesbar, damit ein
# Inhaltsfehler sofort auffaellt und nicht nur eine Groessenabweichung.
make_payload() {
    local name="$1" path="$2" lines="$3"
    : > "$path"
    local i
    for i in $(seq 1 "$lines"); do
        printf 'ftp-transport-harness %s zeile %04d %s\n' "$name" "$i" \
            "$(printf 'payload-%s-%04d' "$name" "$i" | shasum -a 256 | cut -c1-16)" >> "$path"
    done
}

check_file_landed() {
    # check_file_landed LABEL HOST-Pfad SOLL-GROESSE SOLL-SHA
    local label="$1" host_path="$2" want_size="$3" want_sha="$4"
    if [ ! -f "$host_path" ]; then
        note_failure "${label}: Datei fehlt auf dem Host unter ${host_path}"
        return 1
    fi
    local got_size got_sha
    got_size="$(file_size_of "$host_path")"
    got_sha="$(sha256_of "$host_path")"
    if [ "$got_size" != "$want_size" ]; then
        note_failure "${label}: Groesse ${got_size} != erwartet ${want_size}"
        return 1
    fi
    if [ "$got_sha" != "$want_sha" ]; then
        note_failure "${label}: SHA-256 ${got_sha} != erwartet ${want_sha} (Inhalt weicht ab)"
        return 1
    fi
    ok "${label}: ${got_size} Bytes, SHA-256 ${got_sha:0:16}… stimmt"
    return 0
}

# ===========================================================================
# Teil 1 — FTPS-Upload (curl, explizites AUTH TLS, Passivmodus)
# ===========================================================================
ftps_upload() {
    local label="$1" local_file="$2" remote_name="$3" mode="${4:-epsv}"
    local log_out="$REPORT_DIR/ftps-${remote_name}-${mode}.log"
    local trace="$REPORT_DIR/ftps-${remote_name}-${mode}.trace"
    # --ftp-ssl-reqd = AUTH TLS ist Pflicht (explizites FTPS, TLSMode 1).
    # Ohne die Option wuerde der Upload auch ueber den Klartext-Kanal gehen
    # und damit nichts ueber TLS beweisen.
    # --ftp-pasv ist Default, wird aber explizit gesetzt: der Passivbereich
    # ist der zweite TCP-Pfad, um den es hier geht (19-ftp 7.13).
    local -a args=(
        --silent --show-error
        --ftp-ssl-reqd
        --ftp-pasv
        --insecure
        --connect-timeout 20 --max-time 120
        --user "${FTP_USERNAME}:${FTP_PASSWORD}"
        --trace-ascii "$trace"
        --upload-file "$local_file"
        "ftp://${FTPS_HOST}/${remote_name}"
    )
    # Aeltere Kameras kennen Extended Passive Mode (EPSV) nicht. Der Harness
    # fährt beide Wege, weil der haeufigste FTPS-Stolperstein genau darin
    # besteht, dass nur einer davon funktioniert.
    [ "$mode" = "pasv" ] && args+=(--disable-epsv)
    local code=0
    curl "${args[@]}" >"$log_out" 2>&1 || code=$?
    if [ "$code" -ne 0 ]; then
        note_failure "FTPS-Upload ${remote_name} (${mode}): curl exit ${code}"
        sed 's/^/        /' "$log_out" >&2 || true
        return 1
    fi
    local used
    used="$(pasv_port_from_trace "$trace")"
    if [ -n "$used" ]; then
        printf '%s' "$used" > "${used_port_file:-$REPORT_DIR}/ftps-${remote_name}-${mode}.pasvport"
        ok "FTPS-Upload ${remote_name} (${mode}): curl exit 0, Passiv-Datenport ${used}"
    else
        ok "FTPS-Upload ${remote_name} (${mode}): curl exit 0 (Passivport im Trace nicht lesbar)"
    fi
    return 0
}

# ===========================================================================
# Teil 2 — SFTP-Upload (sftp, Batch-Modus, keine Interaktion)
# ===========================================================================
sftp_upload() {
    local label="$1" local_file="$2" remote_name="$3"
    local batch="$REPORT_DIR/sftp-batch-${remote_name}.txt"
    local log_out="$REPORT_DIR/sftp-${remote_name}.log"
    local askpass="$RUNTIME_DIR/sftp-askpass.sh"

    # SSH_ASKPASS + SSH_ASKPASS_REQUIRE=force: sftp -b ist nicht interaktiv,
    # ein Passwort-Prompt wuerde dort abbrechen. `force` unterdrueckt die
    # TTY-Pruefung, damit es auch ohne setsid/expect funktioniert.
    cat > "$askpass" <<EOF
#!/usr/bin/env bash
printf '%s\n' '${FTP_PASSWORD}'
EOF
    chmod 0700 "$askpass"

    # WICHTIG, verifiziert am 2026-09-26: `sftp -b` setzt implizit
    # BatchMode=yes, und BatchMode schaltet die Passwort-Authentifizierung
    # komplett ab. Mit `-b` und Passwort bekommt man reproduzierbar
    # "Permission denied (password,publickey,keyboard-interactive)".
    # Der nicht-interaktive Weg ist deshalb: Kommandos ueber stdin,
    # Passwort ueber SSH_ASKPASS mit SSH_ASKPASS_REQUIRE=force. Kein TTY,
    # kein Prompt — abbrechbar wie ein Batch-Lauf.
    printf 'put %s %s\nquit\n' "$local_file" "$remote_name" > "$batch"

    local -a env_prefix=(
        "SSH_ASKPASS=$askpass"
        "SSH_ASKPASS_REQUIRE=force"
        "DISPLAY=:0"
    )
    local -a sftp_args=(
        -P "$SFTP_PORT"
        -o StrictHostKeyChecking=no
        -o UserKnownHostsFile=/dev/null
        -o LogLevel=ERROR
        -o PreferredAuthentications=password
        -o PubkeyAuthentication=no
        -o NumberOfPasswordPrompts=1
        -o BatchMode=no
        -vv
        "${FTP_USERNAME}@127.0.0.1"
    )
    local code=0
    env "${env_prefix[@]}" sftp "${sftp_args[@]}" < "$batch" >"$log_out" 2>&1 || code=$?
    if [ "$code" -ne 0 ]; then
        note_failure "SFTP-Upload ${remote_name}: sftp exit ${code}"
        tail -15 "$log_out" | sed 's/^/        /' >&2 || true
        return 1
    fi
    if ! grep -qE "^Uploading .* to /$remote_name" "$log_out"; then
        note_failure "SFTP-Upload ${remote_name}: sftp meldet Erfolg, aber ohne Transfer-Protokoll."
        tail -10 "$log_out" | sed 's/^/        /' >&2 || true
        return 1
    fi
    ok "SFTP-Upload ${remote_name}: sftp exit 0 (nicht-interaktiv, kein Prompt)"
    # Der ausgehandelte KEX/Cipher gehoert in den Report, er ist der
    # SFTP-Pendant zur FTPS-Cipher-Liste.
    if grep -oE 'kex: algorithm: [^ ]+' "$log_out" | head -1 | grep -q .; then
        log "SFTP-Verhandlung: $(grep -oE 'kex: algorithm: [^ ]+' "$log_out" | head -1 | cut -d' ' -f3), $(grep -oE 'kex: server->client cipher: [^ ]+' "$log_out" | head -1 | cut -d' ' -f4)"
    fi
    rm -f "$askpass"
    return 0
}

# ===========================================================================
# Teil 3 — Cipher-Report
#
# Ablauf, in dieser Reihenfolge und nicht anders:
#   1. Referenz-Handshake. Erst danach wird ueberhaupt irgendetwas gelesen.
#   2. Protokollversion + verhandelte Cipher-Suite protokollieren.
#   3. TLS-1.2-Suites einzeln proben (`-tls1_2 -cipher NAME:@SECLEVEL=0`).
#   4. TLS-1.3-Suites einzeln proben (`-tls1_3 -ciphersuites NAME`).
# Jede Einzelprobe durchlaeuft dieselbe Vollstaendigkeitspruefung. Ein
# Timeout oder eine Alert-Antwort gilt als "nicht angeboten" NUR mit
# ausdruecklichem Hinweis, nicht stillschweigend.
# ===========================================================================
CAMERA_CIPHERS=(
    AES128-SHA
    AES256-SHA
    ECDHE-RSA-AES128-SHA
    AES128-GCM-SHA256
)
readonly CAMERA_CIPHERS

openssl_known_suites() {
    # TLS-1.2-Suites, die dieser OpenSSL-Client anbieten kann. Gefiltert wird:
    #   * TLS_1_3-Namen — die gehoeren nicht in eine TLS-1.2-Liste und werden
    #     separat unter `-ciphersuites` geprueft
    #   * PSK/anon/SRP/ADH — benutzt keine Kamera, und SECLEVEL=0 allein
    #     reicht nicht aus, um sie auszuschliessen
    #   * Camellia/ARIA/GOST/SEED/SM4/3DES/RC4/IDEA — kein realistischer
    #     Kandidatenkreis fuer den Ersatz von pure-ftpd
    # DHE bleibt drin: die Suiten werden schlicht abgelehnt, und das ist eine
    # Antwort, keine Vermutung.
    openssl ciphers -s -v 'ALL:eNULL' 2>/dev/null \
        | awk 'NR>1 {print $1}' \
        | grep -vE '^TLS_|PSK|ADH|AECDH|anon|SRP|SEED|CAMELLIA|ARIA|GOST|SM4|DES-CBC3|RC4|IDEA' \
        | sort -u
}

probe_tls12_suite() {
    # probe_tls12_suite NAME -> 0 = angeboten, 1 = nicht angeboten, 2 = Messfehler
    local suite="$1" out="$REPORT_DIR/probe-${suite}.txt"
    : > "$out"
    local rc=0
    run_with_timeout 15 openssl s_client -connect "$FTPS_HOST" -starttls ftp \
        -servername localhost -verify_quiet -tls1_2 -cipher "${suite}:@SECLEVEL=0" \
        < /dev/null >> "$out" 2>&1 || rc=$?
    if [ "$rc" -eq 124 ]; then
        printf 'TIMEOUT' > "${out}.status"
        return 2
    fi
    if tls_assert_complete "$out" "$suite" >/dev/null 2>&1; then
        printf 'ANGEDIETEN' > "${out}.status"
        return 0
    fi
    if grep -qE 'no shared cipher|no cipher match|handshake failure|unsupported protocol' "$out"; then
        printf 'NICHT_ANGEBOTEN' > "${out}.status"
        return 1
    fi
    if [ "$rc" -ne 0 ]; then
        printf 'FEHLER' > "${out}.status"
        return 2
    fi
    printf 'UNBEKANNT' > "${out}.status"
    return 2
}

probe_tls13_suite() {
    local suite="$1" out="$REPORT_DIR/probe13-${suite}.txt"
    : > "$out"
    local rc=0
    run_with_timeout 15 openssl s_client -connect "$FTPS_HOST" -starttls ftp \
        -servername localhost -verify_quiet -tls1_3 -ciphersuites "$suite" \
        < /dev/null >> "$out" 2>&1 || rc=$?
    if [ "$rc" -eq 124 ]; then
        printf 'TIMEOUT' > "${out}.status"
        return 2
    fi
    if tls_assert_complete "$out" "$suite" >/dev/null 2>&1; then
        printf 'ANGEDIETEN' > "${out}.status"
        return 0
    fi
    if grep -qE 'no shared cipher|no cipher match|handshake failure' "$out"; then
        printf 'NICHT_ANGEBOTEN' > "${out}.status"
        return 1
    fi
    printf 'UNBEKANNT' > "${out}.status"
    return 2
}

cipher_report() {
    head1 "Cipher-Report (FTPS auf ${FTPS_HOST})"
    local LOG_ALL="$REPORT_DIR/container-during-verify.log"
    local SFTPGO_VERSION
    # Nur das Versionsfeld, nicht die ganze Logzeile (die bringt Build-Tags
    # und Logeinstellungen mit, die hier nichts zur Sache tun).
    SFTPGO_VERSION="$(sed -nE 's/.*"message":"starting SFTPGo ([^ ]+).*/\1/p' "$LOG_ALL" | head -1)"

    # --- 1. Vollstaendigkeitspruefung VOR dem Auslesen --------------------
    local ref="$REPORT_DIR/handshake-reference.txt"
    if ! tls_probe "Referenz-Handshake" "$ref"; then
        fail "Referenz-Handshake gegen ${FTPS_HOST} war unvollstaendig.
  Es wird KEIN Cipher-Ergebnis protokolliert. Ein leeres Ergebnis bei
  unvollstaendigem Handshake war die Ursache fuer drei fehlgeschlagene
  Messversuche (AGENTS.todo.md P1-M35, 19-ftp 7.11).
  Rohprotokoll: ${ref}"
    fi
    local ref_proto ref_cipher
    ref_proto="$(tls_protocol "$ref")"
    ref_cipher="$(tls_cipher "$ref")"
    [ -n "$ref_proto" ] && [ -n "$ref_cipher" ] \
        || fail "Handshake protokollierte zwar Bytes, aber weder Protokoll noch Cipher. Rohprotokoll: ${ref}"
    ok "Referenz-Handshake vollstaendig: ${ref_proto} / ${ref_cipher}"

    # Gegenprobe auf dem impliziten FTPS-Binding. Dort ist der Kanal von
    # Anfang an TLS, also reicht ein blanker s_client. Beide Bindings muessen
    # dieselbe Suite-Liste anbieten — wenn sie das nicht tun, ist die
    # Konfiguration des einen Bindings kaputt, nicht der Dienst.
    local implicit_probe="$REPORT_DIR/handshake-implicit.txt"
    local implicit_proto="-" implicit_cipher="-" implicit_state="UNVOLLSTAENDIG"
    if tls_probe_implicit "Impliziter FTPS-Handshake" "$implicit_probe"; then
        implicit_proto="$(tls_protocol "$implicit_probe")"
        implicit_cipher="$(tls_cipher "$implicit_probe")"
        implicit_state="VOLLSTAENDIG"
        ok "Implizites FTPS: Handshake vollstaendig, ${implicit_proto} / ${implicit_cipher}"
        if [ "$implicit_cipher" != "$ref_cipher" ] && [ "$implicit_proto" = "$ref_proto" ]; then
            warn "Implizites und explizites FTPS handeln unterschiedliche Ciphers aus"
            warn "  (${ref_cipher} vs ${implicit_cipher}) — die Bindings sind nicht identisch konfiguriert."
        fi
    else
        note_failure "Implizites FTPS (Port ${FTPS_IMPLICIT_PORT}): Handshake unvollstaendig. Rohprotokoll: ${implicit_probe}"
    fi

    # TLS-1.3 ist der Default in jedem aktuellen OpenSSL. Fuer eine Vergleichs-
    # aussage brauchen wir auch die maximal erreichbare TLS-1.2-Variante.
    local tls12_probe="$REPORT_DIR/handshake-tls12.txt"
    local tls12_proto="-" tls12_cipher="-"
    if tls_probe "TLS-1.2-Handshake" "$tls12_probe" -tls1_2 -cipher 'ALL:@SECLEVEL=0'; then
        tls12_proto="$(tls_protocol "$tls12_probe")"
        tls12_cipher="$(tls_cipher "$tls12_probe")"
        ok "TLS-1.2-Handshake vollstaendig: ${tls12_proto} / ${tls12_cipher}"
    else
        note_failure "TLS-1.2-Handshake unvollstaendig oder abgelehnt (Raw: ${tls12_probe})"
    fi

    # --- 2. TLS-1.2-Suites einzeln ----------------------------------------
    head1 "TLS 1.2 Cipher-Suites (Einzelprobe je Suite)"
    local offered12=() rejected12=() unknown12=() suite status
    while IFS= read -r suite; do
        [ -n "$suite" ] || continue
        set +e
        probe_tls12_suite "$suite"
        local rc=$?
        set -e
        status="$(cat "$REPORT_DIR/probe-${suite}.txt.status" 2>/dev/null || echo UNBEKANNT)"
        case "$rc" in
            0) offered12+=("$suite"); printf '  %sangeboten%s   %s\n' "$C_OK" "$C_RESET" "$suite" ;;
            1) rejected12+=("$suite"); printf '  %sabgelehnt%s   %s\n' "$C_WARN" "$C_RESET" "$suite" ;;
            *) unknown12+=("$suite"); printf '  %sunbekannt%s   %s (%s)\n' "$C_ERR" "$C_RESET" "$suite" "$status" ;;
        esac
    done < <(openssl_known_suites)

    # --- 3. TLS-1.3-Suites einzeln ----------------------------------------
    head1 "TLS 1.3 Cipher-Suites (Einzelprobe je Suite)"
    local offered13=() rejected13=() unknown13=()
    for suite in TLS_AES_128_GCM_SHA256 TLS_AES_256_GCM_SHA384 TLS_CHACHA20_POLY1305_SHA256; do
        set +e
        probe_tls13_suite "$suite"
        local rc13=$?
        set -e
        status="$(cat "$REPORT_DIR/probe13-${suite}.txt.status" 2>/dev/null || echo UNBEKANNT)"
        case "$rc13" in
            0) offered13+=("$suite"); printf '  %sangeboten%s   %s\n' "$C_OK" "$C_RESET" "$suite" ;;
            1) rejected13+=("$suite"); printf '  %sabgelehnt%s   %s\n' "$C_WARN" "$C_RESET" "$suite" ;;
            *) unknown13+=("$suite"); printf '  %sunbekannt%s   %s (%s)\n' "$C_ERR" "$C_RESET" "$suite" "$status" ;;
        esac
    done

    # --- 4. Kamerarelevante Suites ---------------------------------------
    head1 "Kamerarelevante Suites (19-ftp 7.11)"
    local cam_verdict=() cam_missing=0
    for suite in "${CAMERA_CIPHERS[@]}"; do
        set +e
        probe_tls12_suite "$suite"
        local cam_rc=$?
        set -e
        if [ "$cam_rc" -eq 0 ]; then
            cam_verdict+=("$suite=angeboten")
            printf '  %sJA   %-24s TLS 1.2%s\n' "$C_OK" "$suite" "$C_RESET"
        else
            cam_verdict+=("$suite=nicht_angeboten")
            cam_missing=$((cam_missing + 1))
            printf '  %sNEIN %-24s TLS 1.2  (Status %s)%s\n' "$C_ERR" "$suite" \
                "$(cat "$REPORT_DIR/probe-${suite}.txt.status" 2>/dev/null || echo UNBEKANNT)" "$C_RESET"
        fi
    done
    # AES128-GCM-SHA256 ist ein TLS-1.2-Name. Ueber TLS 1.3 heisst dieselbe
    # Funktionalitaet TLS_AES_128_GCM_SHA256. Beides wird getrennt
    # ausgewiesen, weil eine Kamera den einen oder den anderen Namen
    # akzeptiert — das ist der Unterschied zwischen "GCM geht" und
    # "GCM geht mit diesem Namen".
    printf '  -- dieselbe Funktionalitaet als TLS-1.3-Suite --\n'
    set +e
    probe_tls13_suite TLS_AES_128_GCM_SHA256
    local gcm13=$?
    set -e
    if [ "$gcm13" -eq 0 ]; then
        printf '  %sJA   %-24s TLS 1.3 unter dem Namen TLS_AES_128_GCM_SHA256%s\n' \
            "$C_OK" "AES128-GCM-SHA256" "$C_RESET"
    else
        printf '  %sNEIN %-24s TLS 1.3 unter dem Namen TLS_AES_128_GCM_SHA256%s\n' \
            "$C_ERR" "AES128-GCM-SHA256" "$C_RESET"
    fi

    # Kamera-Verdicts als JSON-Objekt, nicht als Text. Aus "suite=wert"
    # wird mit jq strukturiert gebaut, damit kein Shell-Splitting an
    # Sonderzeichen scheitern kann.
    local CAMERA_JSON
    CAMERA_JSON="$(printf '%s\n' "${cam_verdict[@]}" \
        | jq -R 'select(length > 0) | split("=") | {(.[0]): .[1]}' \
        | jq -sc 'add // {}')"
    # FINDINGS/FAILURES werden erst nach dem Schreiben des JSON-Report
    # weiter oben inkrementiert, deshalb hier der Zaehlstand der Messung.
    local CAM_MISSING_JSON="$cam_missing"
    local findings_at_report="$FINDINGS"
    local failures_at_report="$FAILURES"

    # --- 5. Dateien schreiben --------------------------------------------
    local json_off12 json_rej12 json_unk12 json_off13 json_rej13 json_unk13
    json_off12="$(printf '%s\n' "${offered12[@]:-}" | jq -R . | jq -sc 'map(select(length>0))')"
    json_rej12="$(printf '%s\n' "${rejected12[@]:-}" | jq -R . | jq -sc 'map(select(length>0))')"
    json_unk12="$(printf '%s\n' "${unknown12[@]:-}"   | jq -R . | jq -sc 'map(select(length>0))')"
    json_off13="$(printf '%s\n' "${offered13[@]:-}" | jq -R . | jq -sc 'map(select(length>0))')"
    json_rej13="$(printf '%s\n' "${rejected13[@]:-}" | jq -R . | jq -sc 'map(select(length>0))')"
    json_unk13="$(printf '%s\n' "${unknown13[@]:-}"   | jq -R . | jq -sc 'map(select(length>0))')"

    {
        printf 'Cipher-Report — FTP-Transport-Test-Harness\n'
        printf '=========================================\n'
        printf 'Instanz      : SFTPGo %s, FTPS-Container-Port %s (explizit), Host %s\n' \
            "$SFTPGO_VERSION" "$CONTAINER_FTPS_PORT" "$FTPS_HOST"
        printf 'Handshake    : VOLLSTAENDIG (Referenz-Handshake ok, Bytes gelesen, Cipher benannt)\n'
        printf 'Explizit (AUTH TLS, Host-Port %s): %s / %s\n' "$FTPS_PORT" "$ref_proto" "$ref_cipher"
        printf 'Implizit  (          Host-Port %s): %s / %s  [%s]\n' \
            "$FTPS_IMPLICIT_PORT" "$implicit_proto" "$implicit_cipher" "$implicit_state"
        printf 'TLS 1.2 (erzwungen)              : %s / %s\n' "$tls12_proto" "$tls12_cipher"
        printf '\nTLS-1.2-Suites angeboten (%d):\n' "${#offered12[@]}"
        [ "${#offered12[@]}" -gt 0 ] && printf '  - %s\n' "${offered12[@]}"
        printf '\nTLS-1.2-Suites abgelehnt (%d):\n' "${#rejected12[@]}"
        [ "${#rejected12[@]}" -gt 0 ] && printf '  - %s\n' "${rejected12[@]}"
        printf '\nTLS-1.3-Suites angeboten (%d):\n' "${#offered13[@]}"
        [ "${#offered13[@]}" -gt 0 ] && printf '  - %s\n' "${offered13[@]}"
        printf '\nTLS-1.3-Suites abgelehnt (%d):\n' "${#rejected13[@]}"
        [ "${#rejected13[@]}" -gt 0 ] && printf '  - %s\n' "${rejected13[@]}"
        printf '\nKamerarelevante Suites (TLS 1.2):\n'
        printf '%s\n' "${cam_verdict[@]}" | sed 's/^/  - /'
        printf '\nUnbekannte Messungen (%d TLS1.2 / %d TLS1.3):\n' "${#unknown12[@]}" "${#unknown13[@]}"
        [ "${#unknown12[@]}" -gt 0 ] && printf '  TLS1.2: %s\n' "${unknown12[*]}"
        [ "${#unknown13[@]}" -gt 0 ] && printf '  TLS1.3: %s\n' "${unknown13[*]}"
        printf '\nGrenze dieser Messung: sie sagt, was der SERVER anbietet.\n'
        printf 'Sie sagt nichts darueber, was die Kamera kann (19-ftp 7.14, P1-M32).\n'
    } > "$REPORT_TXT"

    jq -n \
        --arg instance "$SFTPGO_VERSION" \
        --arg host "$FTPS_HOST" \
        --arg tls13proto "$ref_proto" --arg tls13cipher "$ref_cipher" \
        --arg implicitproto "$implicit_proto" --arg implicitcipher "$implicit_cipher" \
        --arg implicitstate "$implicit_state" \
        --arg tls12proto "$tls12_proto" --arg tls12cipher "$tls12_cipher" \
        --argjson off12 "$json_off12" --argjson rej12 "$json_rej12" --argjson unk12 "$json_unk12" \
        --argjson off13 "$json_off13" --argjson rej13 "$json_rej13" --argjson unk13 "$json_unk13" \
        --argjson camera "$CAMERA_JSON" \
        --argjson cammissing "$CAM_MISSING_JSON" \
        --argjson findings "$findings_at_report" --argjson failures "$failures_at_report" \
        --arg require "$REQUIRE_CAMERA_CIPHERS" \
        '{
            instance: $instance,
            endpoint: $host,
            handshake_complete: true,
            negotiated: {
                explicit_ftps: {protocol: $tls13proto, cipher: $tls13cipher},
                implicit_ftps: {protocol: $implicitproto, cipher: $implicitcipher, handshake: $implicitstate},
                tls12: {protocol: $tls12proto, cipher: $tls12cipher}
            },
            tls12: { offered: $off12, rejected: $rej12, unknown: $unk12 },
            tls13: { offered: $off13, rejected: $rej13, unknown: $unk13 },
            camera_relevant: $camera,
            camera_suites_missing: $cammissing,
            findings: $findings,
            failures: $failures,
            requires_camera_ciphers: ($require == "1"),
            scope: "Server-Angebot. Beweist nicht, dass die Kamera den Dienst erreicht (P1-M32)."
        }' > "$REPORT_JSON"

    ok "Text-Report:  $REPORT_TXT"
    ok "JSON-Report:  $REPORT_JSON"
    if [ "$cam_missing" -gt 0 ]; then
        local msg="${cam_missing} von ${#CAMERA_CIPHERS[@]} kamerarelevanten TLS-1.2-Suites werden von SFTPGo NICHT angeboten.
  Der Handshake war dabei vollstaendig — das ist ein Messergebnis, kein Messfehler.
  Bedeutung: eine Kamera, die eines dieser Suites verlangt, erreicht die
  Standard-Instanz nicht.

  SFTPGo bietet sie nicht von sich aus an, sie sind aber konfigurierbar.
  VERIFIZIERT am 2026-09-26 mit diesem Harness: nach
    bash $HARNESS_DIR/down.sh
    FTP_HARNESS_FTPD_CIPHER_SUITES='TLS_RSA_WITH_AES_128_CBC_SHA,TLS_RSA_WITH_AES_256_CBC_SHA,TLS_ECDHE_RSA_WITH_AES_128_CBC_SHA' bash $HARNESS_DIR/up.sh
  werden AES128-SHA und AES256-SHA angeboten.

  Drei Stolperfallen in dieser Variablen:
    1. Die Namen sind die GO-Namen (crypto/tls), nicht die OpenSSL-Namen.
       'AES128-SHA' ist hier wirkungslos, 'TLS_RSA_WITH_AES_128_CBC_SHA' nicht.
       Unbekannte Namen werden stillschweigend ignoriert.
    2. Trennzeichen ist das KOMMA, nicht der Doppelpunkt.
    3. Setzt man die Liste, ersetzt sie die Go-Default-Liste vollstaendig —
       die ECDHE-GCM-Suiten muessen dann mit aufgefuehrt werden, sonst
       verlieren sie den Kameraweg UND den Desktop-Weg."
        if [ "${REQUIRE_CAMERA_CIPHERS:-0}" = "1" ]; then
            note_failure "${msg}
  --require-camera-ciphers war gesetzt, dieser Befund zaehlt daher als Fehlschlag."
        else
            note_finding "${msg}"
        fi
    else
        ok "Alle ${#CAMERA_CIPHERS[@]} kamerarelevanten TLS-1.2-Suites werden angeboten"
    fi
}

# ===========================================================================
# Teil 4 — UID / Ownership
# ===========================================================================
uid_report() {
    head1 "UID und Ownership"
    local declared inside image_default cid
    cid="$(compose ps -q sftpgo 2>/dev/null | head -1 || true)"
    if [ -z "$cid" ]; then
        note_failure "Container-Id nicht ermittelbar — UID nicht messbar."
        return 1
    fi
    declared="$(docker inspect --format '{{.Config.User}}' "$cid" 2>/dev/null || echo unbekannt)"
    inside="$(compose exec -T sftpgo id 2>/dev/null | tr -s ' ' || echo unbekannt)"
    image_default="$(docker image inspect drakkan/sftpgo:2.7.x --format '{{.Config.User}}' 2>/dev/null || echo unbekannt)"
    printf '  Compose-Deklaration user: %s\n' "$declared"
    printf '  Image-Default      user: %s\n' "$image_default"
    printf '  Im Container      id   : %s\n' "${inside:-unbekannt}"
    printf '  Ziel-Ownership Produktion (19-ftp 7.9): %s\n' "$PRODUCTION_TARGET_OWNER"
    if [ "$inside" != "unbekannt" ] && ! printf '%s' "$inside" | grep -q 'uid=0'; then
        ok "SFTPGo laeuft non-root: ${inside}"
    else
        note_failure "SFTPProzess-UID ist root oder unbekannt: ${inside:-unbekannt}"
    fi
    if printf '%s' "$inside" | grep -qE 'uid=1000.*gid=1000'; then
        warn "Diskrepanz bestaetigt: der Dienst laeuft als 1000, der Host-Pfad gehoert ${PRODUCTION_TARGET_OWNER}."
        warn "Funktional auffaellig wird das erst bei 2755 statt 2777 (P1-I9); die Dateien mischen die Ownership-Modelle."
    fi
    if [ -f "$HOST_HOME" ]; then
        printf '  Kamera-Home auf dem Host: %s\n' "$HOST_HOME"
        ls -ldn "$HOST_HOME" 2>/dev/null | sed 's/^/    /'
    fi
}

# ===========================================================================
# Ablauf
# ===========================================================================
head1 "Vorbereitung"
preflight
compose ps sftpgo >/dev/null 2>&1 || fail "Container laeuft nicht. Erst 'bash $HARNESS_DIR/up.sh'."
wait_for_tcp 127.0.0.1 "$FTPS_PORT" 30 "FTPS"
wait_for_tcp 127.0.0.1 "$SFTP_PORT" 30 "SFTP"
ok "Dienst erreichbar auf FTPS ${FTPS_PORT} und SFTP ${SFTP_PORT}"

# Passwort-Form pruefen: 7.3 fordert ^[a-z0-9]{16,24}$ fuer die Kamera.
if printf '%s' "$FTP_PASSWORD" | grep -qE '^[a-z0-9]{16,24}$'; then
    ok "Show-once-Passwort entspricht der Kameraregel ^[a-z0-9]{16,24}$ (P1-M23 / M38 (c))"
else
    note_failure "Test-Passwort '${FTP_PASSWORD}' entspricht NICHT ^[a-z0-9]{16,24}\$.
  Damit waere der Show-once-Passwort-Nachweis aus M38 (c) nicht aussagekraeftig."
fi

mkdir -p "$PAYLOAD_DIR"
FTPS_PAYLOAD="$PAYLOAD_DIR/ftps-upload.txt"
SFTP_PAYLOAD="$PAYLOAD_DIR/sftp-upload.txt"
make_payload "ftps" "$FTPS_PAYLOAD" 250
make_payload "sftp" "$SFTP_PAYLOAD" 400
FTPS_SIZE="$(file_size_of "$FTPS_PAYLOAD")"; FTPS_SHA="$(sha256_of "$FTPS_PAYLOAD")"
SFTP_SIZE="$(file_size_of "$SFTP_PAYLOAD")"; SFTP_SHA="$(sha256_of "$SFTP_PAYLOAD")"
ok "Payloads: ftps ${FTPS_SIZE} B / sftp ${SFTP_SIZE} B"

head1 "1/4 FTPS-Upload (curl, explizites AUTH TLS)"
# Zwei Durchlaeufe: ueber Extended Passive Mode (EPSV, 229) und ueber das
# klassische PASV (227). Aeltere Kameras sprechen nur PASV, neuere nur EPSV —
# der passive Datenkanal ist der zweite TCP-Pfad, und genau an ihm scheitert
# ein FTPS-Client lautlos, wenn die Range nicht publiziert ist (19-ftp 7.13).
ftps_upload "FTPS" "$FTPS_PAYLOAD" "ftps-upload.txt" epsv || true
ftps_upload "FTPS" "$FTPS_PAYLOAD" "ftps-upload.txt" pasv || true

head1 "2/4 SFTP-Upload (sftp, nicht-interaktiv)"
sftp_upload "SFTP" "$SFTP_PAYLOAD" "sftp-upload.txt" || true

head1 "3/4 Dateien auf dem Host pruefen"
check_file_landed "FTPS-Datei" "$HOST_HOME/ftps-upload.txt" "$FTPS_SIZE" "$FTPS_SHA" || true
check_file_landed "SFTP-Datei" "$HOST_HOME/sftp-upload.txt" "$SFTP_SIZE" "$SFTP_SHA" || true

head1 "4/4 Passivbereich und Cipher"
# Passiver Datenkanal: der tatsaechlich benutzte Port steht im curl-Trace
# (227 oder 229). Wir pruefen, dass er INNERHALB des publizierten Bereichs
# liegt. Das ist der direkte Nachweis fuer 19-ftp 7.13 — ein blosser
# gelungener Upload wuerde nur einen Port pruefen, nicht die Range.
# Log-Datei einmalig lesen und weiterreichen; `compose logs | grep` unter
# `set -o pipefail` liefert sonst bei jedem fruehen Treffer SIGPIPE-Fehler.
LOG_ALL="$REPORT_DIR/container-during-verify.log"
fetch_log "$LOG_ALL" sftpgo
for mode in epsv pasv; do
    portfile="$REPORT_DIR/ftps-ftps-upload.txt-${mode}.pasvport"
    if [ ! -f "$portfile" ]; then
        note_failure "Passivport fuer ${mode} nicht ermittelbar (kein Trace/kein Upload)"
        continue
    fi
    p="$(cat "$portfile")"
    if [ "$p" -ge "$PASV_PORT_START" ] && [ "$p" -le "$PASV_PORT_END" ]; then
        ok "Passiver Datenport (${mode}) ${p} liegt im publizierten Bereich ${PASV_PORT_START}-${PASV_PORT_END}"
    else
        note_failure "Passiver Datenport (${mode}) ${p} liegt ausserhalb ${PASV_PORT_START}-${PASV_PORT_END}"
    fi
done
log "Produktions-Passivbereich: ${PRODUCTION_PASV_PORT_START}-${PRODUCTION_PASV_PORT_END} (deployment/docker-compose.yml, unveraendert)"

cipher_report
uid_report

head1 "Gesamtergebnis"
printf '  Fehlschlaege (Harness oder Dienst): %d\n' "$FAILURES"
printf '  Befunde (sauber gemessen, Entscheidung noetig): %d\n' "$FINDINGS"
if [ "$FAILURES" -eq 0 ]; then
    printf '%s  BESTANDEN%s  Upload ueber FTPS (EPSV und PASV) und SFTP verifiziert,\n' "$C_OK" "$C_RESET"
    printf '              Dateigroesse und SHA-256 bestaetigt, Cipher-Report erstellt.\n'
    if [ "$FINDINGS" -gt 0 ]; then
        printf '%s  %d BEFUND(E)%s  Messung war vollstaendig, das Ergebnis ist aber nicht das gewuenschte.\n' \
            "$C_WARN" "$FINDINGS" "$C_RESET"
        printf '              Siehe oben und %s\n' "$REPORT_TXT"
    fi
    printf '  Erinnerung: das ist ein Server-Seiten-Nachweis. Die Kamera bleibt P1-M32.\n'
    exit 0
fi
printf '%s  %d FEHLSCHLAG(E)%s  Details oben und in %s\n' "$C_ERR" "$FAILURES" "$C_RESET" "$REPORT_DIR"
exit 1
