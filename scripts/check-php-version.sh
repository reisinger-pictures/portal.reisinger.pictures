#!/usr/bin/env bash
# ==========================================================================
# check-php-version.sh — bricht mit klarer Meldung ab, wenn `php` die von
# backend/composer.json verlangte Version nicht erfüllt.
# --------------------------------------------------------------------------
# Warum es das gibt: composer erzeugt `vendor/composer/platform_check.php`, das
# bei jeder autoload.php-Einbindung abbricht, wenn die laufende PHP-Version zu
# alt ist. Der Fehler landet dann in `vendor/composer/autoload_real.php` und
# lautet `Composer detected issues in your platform` — er liest sich wie ein
# kaputtes vendor/ oder eine fehlgeschlagene Installation und ist keines.
#
# Gemessen am 2026-09-28: mit PHP 8.4 statt 8.5 starte scripts/e2e-up.sh nicht,
# es kam kein Backend auf Port 8001 hoch, und der Playwright-Smoke-Lauf meldete
# 18 Fehlschläge mit `net::ERR_CONNECTION_REFUSED` — 18 Specs, die nicht defekt
# waren, sondern keinen Server fanden. Ein zweiter Durchlauf mit laufendem
# Server, aber weiterhin falschem PHP, ergab 44 Fehlschläge mit
# "Admin login response did not contain an auth cookie", weil /api/auth/login
# HTTP 200 mit einer HTML-Fehlerseite antwortete. Zwei Umgebungswellen, beide
# als Codefehler gemeldet, eine Ursache.
#
# Verwendung:
#   scripts/check-php-version.sh
#
# Überschreibbar für Tests:
#   PHP_BIN=...  COMPOSER_JSON=...  scripts/check-php-version.sh
#
# Exit-Codes: 0 = Version passt, 1 = Version zu alt, 2 = Setup unlesbar.
# ==========================================================================
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
COMPOSER_JSON="${COMPOSER_JSON:-$ROOT/backend/composer.json}"
PHP_BIN="${PHP_BIN:-php}"

die() {
    printf '%s\n' "$@" >&2
    exit "${EXIT_CODE:-1}"
}

# --- 1. PHP-Binary erreichbar? ------------------------------------------------
if ! command -v "$PHP_BIN" >/dev/null 2>&1; then
    EXIT_CODE=2 die "FEHLER: '$PHP_BIN' ist nicht im PATH."
fi

# --- 2. Anforderung aus composer.json lesen -----------------------------------
# Bewusst nicht hartkodiert: composer.json ist die Quelle der Wahrheit, und eine
# zweite Kopie dieser Zahl hier würde genau die stille Abweichung erzeugen, die
# dieses Skript verhindern soll.
if [ ! -f "$COMPOSER_JSON" ]; then
    EXIT_CODE=2 die "FEHLER: composer.json nicht gefunden: $COMPOSER_JSON"
fi

constraint="$("$PHP_BIN" -r '
    $file = $argv[1];
    $json = json_decode(file_get_contents($file), true);
    if (!is_array($json) || !isset($json["require"]["php"])) {
        fwrite(STDERR, "FEHLER: require.php fehlt oder ist unlesbar in $file\n");
        exit(2);
    }
    echo $json["require"]["php"];
' "$COMPOSER_JSON")"

# --- 3. Untergrenze aus der Caret-Constraint ziehen ---------------------------
# Bewusst in PHP und nicht in sed: BSD-sed (macOS) kennt in einem
# Basic-Regular-Expression keine `(`-Gruppen, sondern nur `\(` — dieselbe
# Constraint würde unter GNU-sed greifen und unter BSD-sed nicht. Das ist keine
# theoretische Sorge, sondern der Grund, warum diese Zeile so aussieht.
# Unterstützt "^8.5" und "^8.5.2"; Vergleichssicherheit entsteht durch
# nullgefüllte Felder: 8.5 -> 8.5.0, 8.5.2 -> 8.5.2.
minimum="$("$PHP_BIN" -r '
    $constraint = $argv[1];
    if (preg_match("/^\^(\d+)\.(\d+)(?:\.(\d+))?/", $constraint, $m) !== 1) {
        fwrite(STDERR, "UNPARSED\n");
        exit(3);
    }
    printf("%d.%d.%d", $m[1], $m[2], $m[3] ?? 0);
' "$constraint")" || minimum=""

if [ "$minimum" = "UNPARSED" ] || [ -z "$minimum" ]; then
    EXIT_CODE=2 die "FEHLER: require.php-Constraint '$constraint' wird von diesem Skript nicht verstanden (erwartet ^MAJOR.MINOR[.PATCH]). Bitte scripts/check-php-version.sh anpassen — nicht die Prüfung überspringen."
fi

# --- 4. Laufende Version ermitteln und vergleichen ----------------------------
running="$("$PHP_BIN" -r 'echo PHP_VERSION;')"

version_lt() {
    # $1 < $2  ->  exit 0. Vergleich in PHP, nicht mit `sort -V`: das ist eine
    # GNU-Erweiterung und fehlt je nach BSD-Version. PHP ist ohnehin Voraussetzung
    # dieses Skripts, also kostet der Umweg nichts und die Prüfung wird portabel.
    "$PHP_BIN" -r '
        [$a, $b] = [$argv[1], $argv[2]];
        $norm = static function (string $v): string {
            return implode(".", array_pad(array_map("intval", explode(".", $v)), 3, 0));
        };
        exit(version_compare($norm($a), $norm($b), "<") ? 0 : 1);
    ' "$1" "$2"
}

if version_lt "$running" "$minimum"; then
    EXIT_CODE=1 die \
"FEHLER: PHP $running ist zu alt fuer dieses Repository.

  composer.json verlangt  '$constraint'  (>= $minimum)
  laufendes PHP            '$running'   ($("$PHP_BIN" -r 'echo PHP_BINARY;'))

Das ist KEIN Abhaengigkeitsproblem. 'composer install' loest es nicht — es
erzeugt den Fehler erst, denn vendor/composer/platform_check.php bricht bei
jedem php-artisan-Aufruf ab, noch bevor eine Zeile Laravel laeuft.

Behoben wird es durch eine neuere PHP auf dem PATH, nicht durch ein Repack.
Nach dem Wechsel laeuft 'php artisan --version' und meldet die Framework-Version."
fi

# --- 5. Verwaiste auto_prepend_file ------------------------------------------
# Gemessen am 2026-09-28: eine Ini zeigte auf
# /Applications/Herd.app/Contents/Resources/valet/dump-loader.php, obwohl Herd
# nicht (mehr) installiert war. Jeder HTTP-Request fatale dann, während der
# Prozess weiter laeuft und weiter 200 antwortet — mit einer HTML-Seite statt
# JSON. Das ist der Grund, warum 'ein 200' hier nicht als Beweis fuer eine
# gesunde Umgebung taugt.
prepend="$("$PHP_BIN" -r 'echo (string) ini_get("auto_prepend_file");')"
if [ -n "$prepend" ] && [ ! -r "$prepend" ]; then
    EXIT_CODE=1 die \
"FEHLER: PHP $running laedt auto_prepend_file von einer nicht lesbaren Datei:

  $prepend

Jeder Request fatale damit, waehrend der Server weiter HTTP 200 antwortet — mit
einer HTML-Fehlerseite statt mit JSON. Ein Aufruf, der auf '200' prueft, haelt
das fuer einen Erfolg.

Die Einstellung steht in der php.ini dieser PHP-Installation. Zeile 695 dort
sollte 'auto_prepend_file =' (leer) sein, nicht der Pfad eines Managers, der
nicht mehr installiert ist."
fi

printf 'PHP %s erfuellt %s (>= %s), auto_prepend_file leer.\n' "$running" "$constraint" "$minimum"
