#!/bin/sh
# Entrypoint of the web image (web, scheduler and migrate roles share it).
# Laravel caches depend on runtime env (APP_KEY, DB, URLs) → built at start, not at image build.
set -eu
cd /app

if [ -z "${APP_KEY:-}" ]; then
  echo "app-entrypoint: APP_KEY is empty — run \`npm run env:sync -- --prod\` and pass infra/env/.env.web" >&2
  exit 64
fi

if [ "${LARAVEL_OPTIMIZE:-1}" = "1" ]; then
  php artisan optimize --no-interaction >/dev/null
fi

if [ "${RUN_MIGRATIONS:-0}" = "1" ]; then
  php artisan migrate --force --no-interaction
fi

exec "$@"
