#!/usr/bin/env bash
# =============================================================================
# check-seeded-settings.sh — was würde ein Seed überschreiben?
# -----------------------------------------------------------------------------
# Die "Database Setup Policy" verlangt, vor einem `db:seed` in der Produktion
# zu pruefen, ob die betroffenen Werte abweichen — der Seeder ist fuer die
# Keys, die er deklariert, autoritativ und schreibt sie per `upsert`. Dieser
# Aufruf beantwortet das mechanisch: er vergleicht die Werte des Seeders
# (derselbe Constant, den der Seeder schreibt) mit den gespeicherten Werten
# und nennt jeden Key, der sich aendern wuerde.
#
# Aufruf:
#   ./scripts/check-seeded-settings.sh
#
# Exit 0 = die gespeicherten Werte sind die des Seeders, ein Seed aendert
# nichts. Exit 1 = mindestens ein Wert weicht ab; ein Seed wuerde ihn
# ueberschreiben. Die Entscheidung, ob geseedet wird, bleibt beim Menschen —
# der Befehl macht die Folgen vorher sichtbar.
#
# Aufrufvarianten (fuer Tests und Schattenbetrieb):
#   PORTAL_SSH_TARGET=<ziel>              das Ziel (Default: die Produktion)
#   PORTAL_BACKEND_CONTAINER=<name>        der Backend-Container
# =============================================================================
set -euo pipefail
cd "$(dirname "$0")/.."

SSH_TARGET="${PORTAL_SSH_TARGET:-root@reisinger.pictures}"
BACKEND_CONTAINER="${PORTAL_BACKEND_CONTAINER:-portal_backend}"

# Die Argumente muessen die entfernte Shell als getrennte Worte sehen; %q
# quotet sie portabel fuer bash.
remote_cmd="docker exec ${BACKEND_CONTAINER} php artisan ops:check-seeded-settings"
for argument in "$@"; do
    remote_cmd+=" $(printf '%q' "$argument")"
done

ssh -o ConnectTimeout=25 "$SSH_TARGET" "$remote_cmd"
