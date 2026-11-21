#!/bin/bash
# Runs inside an odoo container (bin/odoo seed, or init.sh on a fresh volume). Loads the base seed, and the case
# named by INBOUND_CASE_FILE on top of it, in one odoo shell so Odoo starts once. The server may keep running.
#
# The seed writes its API keys to data/ under the working directory, which is /var/lib/odoo/seed here. bin/odoo
# copies that directory to odoo/data on the host.
set -euo pipefail

WORK=/var/lib/odoo/seed
mkdir -p "$WORK/data"
cd "$WORK"

scripts=(/seed/inbound-seed.py)
if [ -n "${INBOUND_CASE_FILE:-}" ]; then
  [ -f "$INBOUND_CASE_FILE" ] || { echo "seed: no case file at $INBOUND_CASE_FILE" >&2; exit 1; }
  scripts+=(/seed/inbound-case.py)
fi

# A newline between the scripts, so the last line of one never joins the first line of the next.
status=0
for script in "${scripts[@]}"; do cat "$script"; echo; done \
  | odoo shell -d inbound --no-http > shell.out 2> shell.err || status=$?

grep '^SEED' shell.out || true

fail() {
  echo "seed: $1" >&2
  tail -n 30 shell.err >&2
  exit 1
}

[ "$status" -eq 0 ] || fail "odoo shell exited with $status"
grep -qx 'SEED committed' shell.out || fail "the base seed did not commit"

rm -f data/inbound-case-map.json
if [ -n "${INBOUND_CASE_FILE:-}" ]; then
  grep -qx 'SEED case committed' shell.out || fail "the case did not commit"
  grep '^SEED case-map ' shell.out | sed 's/^SEED case-map //' | python3 -m json.tool > data/inbound-case-map.json
  [ -s data/inbound-case-map.json ] || fail "the case load printed no case map"
fi
