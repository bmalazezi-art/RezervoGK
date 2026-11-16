#!/usr/bin/env bash
# Deploy Gjej Portier from GitHub, the simple way (like PitchFlow).
#
#   sudo deploy-gjej              pull latest main, build, switch
#   sudo deploy-gjej <commit>     deploy a specific commit (use this to roll back)
#   sudo deploy-gjej old-release  emergency: point the site back at the old archive release
set -Eeuo pipefail

BASE=/var/www/rezervogk
APP=$BASE/app
SITE=https://gjejportier.online

fail() { echo "ERROR: $*" >&2; exit 1; }
[[ $EUID -eq 0 ]] || exec sudo "$0" "$@"
[[ -d "$APP/.git" ]] || fail "$APP is not set up; run setup-server.sh first."

OWNER="$(stat -c %U "$APP")"
as_owner() { sudo -u "$OWNER" -H "$@"; }
as_app() { sudo -u gjej -H "$@"; }
artisan() { as_app php "$APP/artisan" "$@"; }

switch_to() {
  ln -sfn "$1" "$BASE/current.tmp"
  mv -T "$BASE/current.tmp" "$BASE/current"
  systemctl reload php8.4-fpm
}

if [[ "${1:-}" == old-release ]]; then
  old="$(cat "$BASE/archive-release")"
  [[ -d "$old" ]] || fail "Old release $old not found."
  switch_to "$old"
  echo "Site points to the old release again: $old"
  exit 0
fi

cd "$APP"
previous="$(as_owner git rev-parse --short HEAD)"
trap 'echo "Deploy stopped: a step above failed (line $LINENO). The live site was not switched." >&2' ERR

echo "== Fetching from GitHub"
as_owner git fetch --quiet origin main
target="${1:-origin/main}"
as_owner git rev-parse --verify --quiet "$target^{commit}" >/dev/null || fail "Unknown commit: $target"
echo "   Current: $previous"
echo "   Target:  $(as_owner git rev-parse --short "$target")"
as_owner git log --oneline "HEAD..$target" | sed 's/^/   + /' || true

live="$(readlink -f "$BASE/current")"
if [[ "$live" == "$(readlink -f "$APP")" ]]; then
  artisan down --retry=15 >/dev/null || true
  trap 'artisan up >/dev/null || true; echo "Deploy failed. Site is back up on partially updated code. Roll back with: sudo deploy-gjej '"$previous"'" >&2' ERR
fi

echo "== Updating code"
as_owner git checkout --quiet --detach "$target"

echo "== PHP dependencies"
as_owner composer install --no-dev --optimize-autoloader --no-interaction --no-progress --no-scripts
artisan package:discover --ansi >/dev/null

echo "== Frontend build"
as_owner npm ci --no-audit --no-fund --loglevel=error
as_owner npm run build --silent

echo "== Database migrations"
pending="$(artisan migrate:status --no-ansi | grep Pending || true)"
if [[ -n "$pending" ]]; then
  echo "$pending"
  read -r -p "Run these migrations on PRODUCTION now? [y/N] " answer
  if [[ "$answer" == [yY] ]]; then
    artisan migrate --force
  else
    echo "   Skipped. Code that needs them may fail until they run."
  fi
else
  echo "   None pending."
fi

echo "== Caches"
# public/ belongs to the code owner, so the owner creates the public storage link.
[[ -L "$APP/public/storage" ]] || as_owner ln -s "$APP/storage/app/public" "$APP/public/storage"
artisan optimize

if [[ "$live" != "$(readlink -f "$APP")" ]]; then
  echo "== Switching the live site to $APP"
  switch_to "$APP"
else
  systemctl reload php8.4-fpm
  artisan up >/dev/null
fi
trap - ERR

status="$(curl -s -o /dev/null -w '%{http_code}' "$SITE/up" || true)"
echo
echo "Deployed $(as_owner git rev-parse --short HEAD). Health check: $status"
if [[ "$live" == "$(readlink -f "$APP")" ]]; then
  echo "Roll back with: sudo deploy-gjej $previous"
else
  echo "Roll back with: sudo deploy-gjej old-release"
fi
[[ "$status" == 200 ]] || echo "WARNING: health check did not return 200. Check the site." >&2
