#!/usr/bin/env bash
# ===========================================================================
# self-test.sh — hermetischer Regressionstest fuer die Messwaechter.
#
# Dieser Test braucht KEIN Docker und laeuft in unter einer Sekunde. Er
# sichert genau die Eigenschaft ab, die in P1-M35 dreimal gekippt hat:
# eine unvollstaendige TLS-Messung darf NIE als Ergebnis durchgehen.
#
# Getestet wird gegen eingefrorene Protokolltranskripte, nicht gegen einen
# laufenden Dienst. Ein Test, der einen laufenden Dienst braucht, um eine
# Regressionserkennung zu pruefen, ist bei der ersten Timing-Abweichung
# weg — genau das will P1-M38 vermeiden.
#
# Aufruf: bash self-test.sh
# ===========================================================================
set -euo pipefail

# shellcheck source=lib/common.sh
. "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib/common.sh"

FIXTURES="$HARNESS_DIR/fixtures"
# Die ausfuehrenden Skripte sind das Scan-Ziel der Quelltext-Invarianten.
SCAN_TARGETS="$HARNESS_DIR/up.sh $HARNESS_DIR/verify.sh $HARNESS_DIR/down.sh $HARNESS_DIR/lib/common.sh"
FAILURES=0
pass() { printf '  [ok]    %s\n' "$*"; }
fail_case() { FAILURES=$((FAILURES + 1)); printf '  [FAIL]  %s\n' "$*"; }

[ -d "$FIXTURES" ] || fail "Fixture-Verzeichnis fehlt: $FIXTURES"

# --- Werterzeugung ohne Farbausgabe -----------------------------------------
NO_COLOR=1

head1 "tls_assert_complete — Wetterkennung"

# Fall 1: Dienst nicht erreichbar. Das ist die klassische Fehlschluss-Quelle:
# 0 Bytes gelesen, leere Cipher-Liste, sieht wie "Cipher nicht angeboten" aus.
if tls_assert_complete "$FIXTURES/handshake-tot.txt" "toter Dienst" >/dev/null 2>&1; then
    fail_case "toter Dienst (0 Bytes gelesen) wurde als vollstaendig akzeptiert"
else
    pass "toter Dienst (0 Bytes gelesen) wird abgelehnt"
fi

# Fall 2: Klartext-Kanal (explizites FTPS ohne -starttls ftp). Der FTP-Greeting
# landet im TLS-Client; Ergebnis ist ebenfalls eine leere Cipher-Liste.
if tls_assert_complete "$FIXTURES/handshake-klartext.txt" "Klartext" >/dev/null 2>&1; then
    fail_case "Klartext-Kanal (wrong version number) wurde als vollstaendig akzeptiert"
else
    pass "Klartext-Kanal (wrong version number) wird abgelehnt"
fi

# Fall 3: Alert vom Server (handshake failure). Auch das ist ein Messwert,
# aber kein vollstaendiger Handshake.
if tls_assert_complete "$FIXTURES/handshake-alert.txt" "Alert" >/dev/null 2>&1; then
    fail_case "Alert-Antwort wurde als vollstaendiger Handshake akzeptiert"
else
    pass "Alert-Antwort (handshake failure) wird abgelehnt"
fi

# Fall 4: Cipher als (NONE) benannt, Protokoll gesetzt. Formal fehlt die Suite.
if tls_assert_complete "$FIXTURES/handshake-none.txt" "Cipher NONE" >/dev/null 2>&1; then
    fail_case "'Cipher is (NONE)' wurde als vollstaendiger Handshake akzeptiert"
else
    pass "'Cipher is (NONE)' wird abgelehnt"
fi

# Fall 5: Der gueltige Fall muss weiterhin durchgehen.
if tls_assert_complete "$FIXTURES/handshake-gueltig.txt" "gueltig" >/dev/null 2>&1; then
    pass "vollstaendiger Handshake wird akzeptiert"
else
    fail_case "vollstaendiger Handshake wurde faelschlich abgelehnt (Wetter zu streng)"
fi

# --- Auslesen nur nach bestandenem Wetter ----------------------------------
head1 "tls_protocol / tls_cipher — Auslesen"
if tls_assert_complete "$FIXTURES/handshake-gueltig.txt" "gueltig" >/dev/null 2>&1; then
    p="$(tls_protocol "$FIXTURES/handshake-gueltig.txt")"
    k="$(tls_cipher "$FIXTURES/handshake-gueltig.txt")"
    [ "$p" = "TLSv1.3" ] || fail_case "tls_protocol liest '$p', erwartet TLSv1.3"
    [ "$k" = "TLS_AES_128_GCM_SHA256" ] || fail_case "tls_cipher liest '$k', erwartet TLS_AES_128_GCM_SHA256"
    [ "$p" = "TLSv1.3" ] && [ "$k" = "TLS_AES_128_GCM_SHA256" ] \
        && pass "Protokoll und Cipher werden korrekt ausgelesen"
else
    fail_case "Vorbedingung verletzt: Fixture 'handshake-gueltig' nicht akzeptiert"
fi

# --- Passivport aus dem curl-Trace ------------------------------------------
head1 "pasv_port_from_trace — EPSV (229) und PASV (227)"
# 229: Extended Passive Mode, Port steht direkt drin.
got="$(pasv_port_from_trace "$FIXTURES/trace-epsv.txt")"
[ "$got" = "20014" ] \
    && pass "EPSV (229) -> 20014" \
    || fail_case "EPSV (229) -> '$got', erwartet 20014"
# 227: klassisches PASV, Port = p1*256 + p2 = 78*256+53 = 20021
got="$(pasv_port_from_trace "$FIXTURES/trace-pasv.txt")"
[ "$got" = "20021" ] \
    && pass "PASV (227) -> 20021 (p1*256+p2)" \
    || fail_case "PASV (227) -> '$got', erwartet 20021"
# Kein Passivbefehl im Trace darf keine Zahl erfinden.
got="$(pasv_port_from_trace "$FIXTURES/trace-ohne-pasv.txt")"
[ -z "$got" ] \
    && pass "ohne Passivbefehl wird keine Portnummer erfunden" \
    || fail_case "ohne Passivbefehl wurde '$got' zurueckgegeben"

# --- run_with_timeout ohne GNU-coreutils ------------------------------------
head1 "run_with_timeout — Timeout ohne GNU-coreutils"
rc=0
run_with_timeout 1 sh -c 'sleep 30' || rc=$?
[ "$rc" -eq 124 ] \
    && pass "langlaufender Befehl meldet Exit 124 nach dem Watchdog" \
    || fail_case "Watchdog meldete Exit $rc, erwartet 124"
rc=0
run_with_timeout 10 sh -c 'exit 0' || rc=$?
[ "$rc" -eq 0 ] \
    && pass "schneller Befehl meldet Exit 0" \
    || fail_case "schneller Befehl meldete Exit $rc, erwartet 0"

# --- Quelltext-Invarianten --------------------------------------------------
# Kein Ergebnis darf entstehen, bevor das Wetter bestanden ist. Geprueft wird
# das an der Quelle, weil es die Reihenfolge im Skript festnagelt.
head1 "Quelltext-Invarianten"
VERIFY="$HARNESS_DIR/verify.sh"

# `|| true` ist hier Pflicht: unter `set -e -o pipefail` beendet ein
# erfolgloses grep das Skript, bevor der Test eine Aussage treffen kann.
guard_line="$(grep -n 'Referenz-Handshake' "$VERIFY" 2>/dev/null | head -1 | cut -d: -f1 || true)"
report_line="$(grep -n 'Text-Report:' "$VERIFY" 2>/dev/null | head -1 | cut -d: -f1 || true)"
if [ -n "$guard_line" ] && [ -n "$report_line" ] && [ "$guard_line" -lt "$report_line" ]; then
    pass "Cipher-Wetter steht vor dem Report-Schreiben (Zeile $guard_line < $report_line)"
else
    fail_case "Reihenfolge nicht belegbar: Wetter in Zeile ${guard_line:-?}, Report in Zeile ${report_line:-?}"
fi

# Und die Wetterpruefung selbst darf nicht erst beim Auslesen aufgerufen werden.
if grep -q 'tls_assert_complete "\$out" "\$label"$' "$HARNESS_DIR/lib/common.sh" 2>/dev/null; then
    pass "tls_probe gibt das Ergebnis von tls_assert_complete unveraendert weiter"
else
    fail_case "tls_probe wertet das Wetter nicht mehr durch (common.sh)"
fi

if grep -q 'TLS-HANDSHAKE UNVOLLSTAENDIG' "$HARNESS_DIR/lib/common.sh"; then
    pass "Wetterfunktion meldet einen benannten Fehler statt stillzuschweigen"
else
    fail_case "Wetterfunktion hat keinen benannten Fehlerpfad"
fi

# `timeout` (GNU-coreutils) existiert auf macOS nicht. Wer es doch benutzt,
# liefert auf dem Entwicklerrechner ein "command not found" statt einer Messung.
# Scan-Ziel sind die ausfuehrenden Skripte. self-test.sh ist ausgenommen,
# weil es die Suchmuster selbst enthaelt — ein Scan, der sich selbst findet,
# beweist nichts. Zusaetzlich muss `timeout` ein eigener Befehl sein, sonst
# trifft das Muster auch `--connect-timeout 20`.
if grep -rnE '(^|[;&|(])timeout [0-9]' "$SCAN_TARGETS" 2>/dev/null >/dev/null 2>&1; then
    fail_case "GNU-Timeout wird irgendwo benutzt (fehlt auf macOS)"
else
    pass "kein GNU-Timeout im Harness (nur run_with_timeout)"
fi

# BSD-grep kennt kein \s. Wer es benutzt, bekommt auf macOS eine Fehlermeldung
# statt einer Pruefung.
if grep -rn '\\s' "$SCAN_TARGETS" 2>/dev/null >/dev/null 2>&1; then
    fail_case "Regex mit GNU-Whitespace-Klasse \\s gefunden (BSD-grep kann das nicht)"
else
    pass "keine GNU-Whitespace-Klasse \\s im Harness"
fi

# --- Ergebnis ----------------------------------------------------------------
head1 "Ergebnis"
if [ "$FAILURES" -eq 0 ]; then
    printf '%s  BESTANDEN%s  Alle Wetter- und Parserfaelle korrekt.\n' "$C_OK" "$C_RESET"
    exit 0
fi
printf '%s  %d FEHLSCHLAG(E)%s\n' "$C_ERR" "$FAILURES" "$C_RESET"
exit 1
