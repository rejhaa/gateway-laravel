#!/bin/bash
# Runs in the odoo-init container of compose.yaml. Creates the inbound database with the modules the demo needs and
# loads the base seed. Each step runs only when its result is missing, so a start on an initialized volume does
# nothing and a start after a failed step repeats that step.
set -euo pipefail

MODULES=purchase,stock,mrp,sale_management,account,purchase_stock,purchase_mrp
SEED_OUTPUT=/var/lib/odoo/seed/data/inbound-users.json

wait-for-psql.py --db_host db --db_port 5432 --db_user odoo --db_password odoo --timeout 60

modules_installed() {
  python3 - <<'PY'
import sys
import psycopg2

try:
    connection = psycopg2.connect(host='db', port=5432, user='odoo', password='odoo', dbname='inbound')
except psycopg2.OperationalError:
    sys.exit(1)
with connection, connection.cursor() as cursor:
    cursor.execute("SELECT to_regclass('ir_module_module')")
    if cursor.fetchone()[0] is None:
        sys.exit(1)
    cursor.execute("SELECT count(*) FROM ir_module_module WHERE name = 'purchase_mrp' AND state = 'installed'")
    sys.exit(0 if cursor.fetchone()[0] == 1 else 1)
PY
}

if modules_installed; then
  echo "init: the inbound database has its modules"
else
  echo "init: creating the inbound database with $MODULES"
  odoo -d inbound -i "$MODULES" --without-demo=True --stop-after-init
  modules_installed || { echo "init: the module installation did not finish" >&2; exit 1; }
fi

if [ -f "$SEED_OUTPUT" ]; then
  echo "init: the base seed is loaded"
else
  bash /seed/seed.sh
fi
