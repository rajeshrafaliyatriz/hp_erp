#!/usr/bin/env bash
# TEMPORARY: make the 13 and 14 Oct 2026 signals (copied exactly from the local data) appear on this server's Research date dropdown.
# Run from the backend project root (the folder with `artisan`):   bash scripts/show-demo-signals-live.sh <tenant_id>
# Safe to re-run: it stops at the first problem, never overwrites a live signal, and `rollback` removes exactly what it added.
set -euo pipefail

TENANT="${1:-}"
[ -n "$TENANT" ] || { echo "Usage: bash scripts/show-demo-signals-live.sh <tenant_id>   (add 'rollback' as the 2nd argument to remove it)"; exit 1; }
[ -f artisan ] || { echo "Run this from the backend project root (where artisan is)."; exit 1; }
[ -f database/fixtures/demo_signals_2026-10-13_14.json ] || { echo "Fixture file missing: deploy the scholarclone-signals branch first."; exit 1; }

if [ "${2:-}" = "rollback" ]; then
  php artisan signals:demo-fixture rollback --tenant="$TENANT" --confirm=ROLLBACK-DEMO-FIXTURE
  echo "Rolled back. Now set SIGNALS_DEMO_FIXTURE_ENABLED=false in .env and run: php artisan config:clear"
  exit 0
fi

echo "== 1. Migration status (read-only). Any 'Pending' line below must be run first (see the migration commands)."
php artisan migrate:status | grep -E "2026_12_01_090000|2026_11_29_090000|2026_11_29_100000|2026_11_29_110000" || true

echo "== 2. Dry run (read-only). It stops here if the schema is not ready."
php artisan signals:demo-fixture plan --tenant="$TENANT"

read -r -p "Dry run looks right (11 signals on 13 Oct, 5 on 14 Oct)? Type IMPORT to write them: " ANSWER
[ "$ANSWER" = "IMPORT" ] || { echo "Cancelled. Nothing was written."; exit 1; }

[ -f bootstrap/cache/config.php ] && { echo "Config is cached, so the one-command switch would be ignored. Run: php artisan config:clear   then run this script again (re-cache afterwards with php artisan config:cache)."; exit 1; }

echo "== 3. Importing (switch is turned on for this command only)"
SIGNALS_DEMO_FIXTURE_ENABLED=true php artisan signals:demo-fixture import --tenant="$TENANT" --confirm=IMPORT-DEMO-FIXTURE

echo "== Done. Reload the Company Opportunities screen: 13 Oct and 14 Oct should be in the Research date dropdown."
echo "   Remove later with: bash scripts/show-demo-signals-live.sh $TENANT rollback"
