#!/bin/sh
# Starts the app on a throwaway SQLite database, runs the browser tests against it, and stops the app.
# Usage:  tests/e2e/run.sh [script.mjs]      (default: till.e2e.mjs)
#
# Needs: PHP, Node 18+, and a Chrome - either yours (CHROME_PATH=/path/to/chrome) or the one that
# `npm install` fetches in this folder (@sparticuz/chromium).
set -e

HERE="$(cd "$(dirname "$0")" && pwd)"
APP="$(cd "$HERE/../.." && pwd)"
PORT="${E2E_PORT:-8765}"

# Real environment variables beat .env, so this can never touch the database your .env points at.
export DB_CONNECTION=sqlite
export DB_DATABASE="${E2E_DB:-/tmp/till-e2e.sqlite}"
export SESSION_DRIVER=file CACHE_STORE=file QUEUE_CONNECTION=sync
export DEBUGBAR_ENABLED="${DEBUGBAR_ENABLED:-false}"   # Debugbar adds a request after every AJAX call and skews timings
export TILL_URL="http://127.0.0.1:$PORT"

cd "$APP"
rm -f "$DB_DATABASE" && touch "$DB_DATABASE"
php artisan migrate:fresh --seed --force >/dev/null

# The browser tests need the built assets (npm run build) because they load the real page.
[ -f public/build/manifest.json ] || { echo "Run 'npm run build' first."; exit 1; }

php artisan serve --host=127.0.0.1 --port="$PORT" >/tmp/till-e2e-server.log 2>&1 &
SERVER=$!
trap 'kill $SERVER 2>/dev/null' EXIT

for i in 1 2 3 4 5 6 7 8 9 10; do
  curl -s -o /dev/null -m 2 "$TILL_URL/login" && break
  sleep 1
done

cd "$HERE"
[ -d node_modules ] || npm install --no-audit --no-fund
node "${1:-till.e2e.mjs}"
