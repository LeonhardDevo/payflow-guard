#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

set -a && . "$ROOT/scripts/project.env" && set +a

usage() {
  cat <<'USAGE'
usage: bash scripts/setup.sh [--fresh] [--reinstall] [--skip-docker] [--skip-build] [--skip-e2e]
  --fresh        drop all data (public, rag, worker schemas) and re-seed the demo workspace
  --reinstall    run npm ci even when node_modules already match package.json + lockfile
  --skip-docker  do not start docker compose (infra already running elsewhere)
  --skip-build   do not build web assets / worker bundle
  --skip-e2e     do not install the Playwright suite and browser
USAGE
}

FRESH=0
REINSTALL=0
SKIP_DOCKER=0
SKIP_BUILD=0
SKIP_E2E=0
for arg in "$@"; do
  case "$arg" in
    --fresh) FRESH=1 ;;
    --reinstall) REINSTALL=1 ;;
    --skip-docker) SKIP_DOCKER=1 ;;
    --skip-build) SKIP_BUILD=1 ;;
    --skip-e2e) SKIP_E2E=1 ;;
    -h | --help)
      usage
      exit 0
      ;;
    *)
      echo "unknown option: $arg (see --help)" >&2
      exit 2
      ;;
  esac
done

if [[ -t 1 && -z "${NO_COLOR:-}" ]]; then
  BOLD=$'\e[1m' CYAN=$'\e[36m' GREEN=$'\e[32m' YELLOW=$'\e[33m' RED=$'\e[31m' RESET=$'\e[0m'
else
  BOLD='' CYAN='' GREEN='' YELLOW='' RED='' RESET=''
fi
step() { printf '\n%s==>%s %s%s%s\n' "$BOLD$CYAN" "$RESET" "$BOLD" "$*" "$RESET"; }
ok() { printf '  %s+%s %s\n' "$GREEN" "$RESET" "$*"; }
warn() { printf '  %s!%s %s\n' "$YELLOW" "$RESET" "$*" >&2; }
die() {
  printf '  %sx%s %s\n' "$RED" "$RESET" "$*" >&2
  exit 1
}
trap 'die "setup failed at line $LINENO (exit $?) — fix the error above and re-run; every step is idempotent"' ERR

have() { command -v "$1" >/dev/null 2>&1; }
is_windows() { [[ "${OSTYPE:-}" == msys* || "${OSTYPE:-}" == cygwin* || "${OS:-}" == Windows_NT ]]; }
app_dir() { printf '%s/apps/%s' "$ROOT" "$1"; }

retry() {
  local attempts=$1 i
  shift
  for ((i = 1; i <= attempts; i++)); do
    if "$@"; then return 0; fi
    ((i < attempts)) && warn "attempt $i/$attempts failed: $* — retrying in $((i * 5))s" && sleep $((i * 5))
  done
  return 1
}

npm_install() {
  local dir=$1
  if ((!REINSTALL)) && node "$ROOT/scripts/deps-stamp.ts" check "$dir"; then
    ok "$(basename "$dir"): node_modules up to date (npm ci skipped; --reinstall forces it)"
    return 0
  fi
  if [[ -f "$dir/package-lock.json" ]]; then
    (cd "$dir" && npm ci --no-audit --no-fund)
  else
    (cd "$dir" && npm install --no-audit --no-fund)
  fi
  node "$ROOT/scripts/deps-stamp.ts" write "$dir"
}

has_npm_script() {
  [[ -f "$1/package.json" ]] || return 1
  (cd "$1" && node -e 'const s=require("./package.json").scripts??{};process.exit(s[process.argv[1]]?0:1)' "$2")
}

env_value() {
  [[ -f "$1" ]] || return 0
  sed -n -E "s/^[[:space:]]*$2[[:space:]]*=[[:space:]]*\"?([^\"]*)\"?[[:space:]]*$/\1/p" "$1" | tail -n 1
}

SECONDS=0

step "Preflight"
for tool in node npm php composer; do
  have "$tool" || die "$tool not found on PATH (see README → Prerequisites)"
done
((SKIP_DOCKER)) || have docker || die "docker not found — install Docker Desktop or pass --skip-docker"
NODE_MAJOR=$(node -p 'process.versions.node.split(".")[0]')
((NODE_MAJOR >= 24)) || die "Node $NODE_MAJOR found, Node 24+ required"
php -r 'exit(version_compare(PHP_VERSION, "8.4.0", ">=") ? 0 : 1);' || die "PHP 8.4+ required"
ok "node $(node -v), $(php -r 'echo "php ".PHP_VERSION;')"

step "Root tooling"
npm_install "$ROOT"
ok "root dependencies installed"

if ((SKIP_DOCKER)); then
  warn "skipping docker compose (--skip-docker)"
else
  step "Infrastructure: postgres(pgvector) :$PG_PORT, redis :$REDIS_PORT, mailpit :$MAIL_UI_PORT"
  docker info >/dev/null 2>&1 || die "Docker daemon is not running — start Docker Desktop"
  retry 3 docker compose --env-file "$ROOT/scripts/project.env" up -d --wait --wait-timeout 180
  ok "containers healthy"
  docker compose --env-file "$ROOT/scripts/project.env" exec -T postgres sh /docker-entrypoint-initdb.d/01-init.sh >/dev/null
  ok "databases ready"
fi

step "Environment files & shared secrets"
node scripts/env-sync.ts

WEB=$(app_dir web)
WORKER=$(app_dir worker)

if [[ -f "$WEB/composer.json" ]]; then
  step "web: composer + npm"
  (cd "$WEB" && composer install --no-interaction --prefer-dist --no-progress)
  npm_install "$WEB"
  if is_windows && [[ -d "$WEB/node_modules/vite-plus" && ! -d "$WEB/node_modules/@voidzero-dev/vite-plus-win32-x64-msvc" ]]; then
    VP_VERSION=$(cd "$WEB" && node -p 'require("./node_modules/vite-plus/package.json").version')
    warn "vite-plus Windows binding missing — installing @voidzero-dev/vite-plus-win32-x64-msvc@$VP_VERSION (no-save)"
    (cd "$WEB" && npm install --no-save --no-audit --no-fund "@voidzero-dev/vite-plus-win32-x64-msvc@$VP_VERSION")
  fi
  ok "web dependencies installed"
else
  warn "apps/web not present — skipped"
fi

if [[ -f "$WORKER/package.json" ]]; then
  step "worker: npm"
  npm_install "$WORKER"
  ok "worker dependencies installed"
else
  warn "apps/worker not present — skipped"
fi

if ((SKIP_E2E)); then
  warn "skipping e2e install (--skip-e2e)"
elif [[ -f "$ROOT/e2e/package.json" ]]; then
  step "e2e: Playwright"
  npm_install "$ROOT/e2e"
  (cd "$ROOT/e2e" && npm run browsers) || warn "Playwright browser download failed — run \`npm run e2e:install\` later"
  ok "e2e suite ready"
fi

step "Database migrations"
if ((FRESH)); then
  ((SKIP_DOCKER)) && die "--fresh needs the docker postgres (drop schemas via docker compose exec)"
  warn "--fresh: dropping schemas rag and worker, then migrate:fresh --seed"
  docker compose --env-file "$ROOT/scripts/project.env" exec -T postgres psql -v ON_ERROR_STOP=1 -U "$DB_USER" -d "$DB_NAME" -c \
    "DROP SCHEMA IF EXISTS rag CASCADE; DROP SCHEMA IF EXISTS worker CASCADE; CREATE SCHEMA rag AUTHORIZATION $DB_USER; CREATE SCHEMA worker AUTHORIZATION $DB_USER;"
fi
if [[ -f "$WEB/artisan" ]]; then
  if ((FRESH)); then
    (cd "$WEB" && php artisan migrate:fresh --seed --force)
  elif (cd "$WEB" && php artisan migrate:status >/dev/null 2>&1); then
    (cd "$WEB" && php artisan migrate --force)
  else
    (cd "$WEB" && php artisan migrate --seed --force)
  fi
  ok "web (public schema) migrated"
fi
if has_npm_script "$WORKER" migrate; then
  (cd "$WORKER" && npm run migrate)
  ok "worker (worker schema) migrated"
elif [[ -f "$WORKER/package.json" ]]; then
  warn "apps/worker has no \"migrate\" script — the worker migrates its schema on boot"
fi

if ((SKIP_BUILD)); then
  warn "skipping builds (--skip-build)"
else
  step "Build"
  if has_npm_script "$WEB" build; then
    (cd "$WEB" && npm run build)
    ok "web assets built (apps/web/public/build)"
  fi
  if has_npm_script "$WORKER" build; then
    (cd "$WORKER" && npm run build)
    ok "worker compiled (apps/worker/dist)"
  fi
fi

SLUG=$(env_value "$WEB/.env" PRODUCT_SLUG)
SLUG=${SLUG:-$PRODUCT_SLUG}
step "Done in ${SECONDS}s"
cat <<EOF
  ${PRODUCT_NAME} is ready. Start everything with:  ${BOLD}npm run dev${RESET}

  web       http://localhost:${WEB_PORT}
  worker    http://localhost:${WORKER_PORT}/healthz   (queues: /admin/queues)
  mailpit   http://localhost:${MAIL_UI_PORT}

  demo login     demo@${SLUG}.test / password
  platform admin admin@${SLUG}.test / password
EOF
