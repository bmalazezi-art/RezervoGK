#!/usr/bin/env bash
# One-time setup on the Gjej Portier Droplet: switch to simple git-pull deploys.
# Run once from your own account:  sudo bash setup-server.sh
#
# What it does:
#   1. Installs Composer and Node.js 22 (free, from their official sources).
#   2. Clones the GitHub repo into /var/www/rezervogk/app, owned by you.
#   3. Links the existing .env and shared storage into it (no data is copied or moved).
#   4. Installs the `deploy-gjej` command.
# It does NOT switch the live site. The first `sudo deploy-gjej` does that,
# and the old release directory is kept for rollback.
set -Eeuo pipefail

fail() { echo "ERROR: $*" >&2; exit 1; }

[[ $EUID -eq 0 ]] || fail "Run with sudo."
[[ "$(hostname)" == gjej-portier-prod-fra1 ]] || fail "This is not the Gjej Portier server."
DEPLOY_USER="${SUDO_USER:-}"
[[ -n "$DEPLOY_USER" && "$DEPLOY_USER" != root ]] || fail "Run via sudo from your own account, not as root."

BASE=/var/www/rezervogk
APP=$BASE/app
ENV_FILE=/etc/gjej/rezervogk.env
STORAGE=$BASE/shared/storage
REPO=https://github.com/bmalazezi-art/RezervoGK.git
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

[[ -f "$ENV_FILE" ]] || fail "Missing $ENV_FILE"
[[ -d "$STORAGE" ]] || fail "Missing $STORAGE"
[[ -L "$BASE/current" ]] || fail "$BASE/current is not a symlink; stopping."
[[ -f "$HERE/deploy.sh" ]] || fail "deploy.sh must be next to this script."
getent group gjej-public >/dev/null || fail "Group gjej-public is missing."

echo "== Recording the live release for rollback"
if [[ ! -f "$BASE/archive-release" ]]; then
  readlink -f "$BASE/current" > "$BASE/archive-release"
fi
echo "   $(cat "$BASE/archive-release")"

echo "== Installing Composer"
if ! command -v composer >/dev/null; then
  tmp="$(mktemp -d)"
  curl -fsSL https://getcomposer.org/download/latest-stable/composer.phar -o "$tmp/composer.phar"
  curl -fsSL https://getcomposer.org/download/latest-stable/composer.phar.sha256sum -o "$tmp/composer.sha256"
  (cd "$tmp" && echo "$(cut -d' ' -f1 composer.sha256)  composer.phar" | sha256sum -c -)
  install -m 0755 "$tmp/composer.phar" /usr/local/bin/composer
  rm -rf "$tmp"
fi
sudo -u "$DEPLOY_USER" -H composer --version

echo "== Installing Node.js 22"
if ! node -v 2>/dev/null | grep -q '^v22\.'; then
  apt-get update -qq
  apt-get install -y -qq ca-certificates curl gnupg
  install -d -m 0755 /etc/apt/keyrings
  curl -fsSL https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key \
    | gpg --dearmor --yes -o /etc/apt/keyrings/nodesource.gpg
  echo "deb [signed-by=/etc/apt/keyrings/nodesource.gpg] https://deb.nodesource.com/node_22.x nodistro main" \
    > /etc/apt/sources.list.d/nodesource.list
  apt-get update -qq
  apt-get install -y -qq nodejs
fi
node -v

echo "== Letting $DEPLOY_USER reach $BASE (group gjej-public)"
# $BASE is root:gjej-public mode 0710, so only group members can enter it.
id -nG "$DEPLOY_USER" | tr ' ' '\n' | grep -qx gjej-public || usermod -aG gjej-public "$DEPLOY_USER"

echo "== Cloning the repository into $APP"
if [[ ! -d "$APP/.git" ]]; then
  if [[ -e "$APP" ]]; then
    # Left behind by an earlier failed run: reuse it only if it is empty.
    [[ -d "$APP" && ! -L "$APP" && -z "$(ls -A "$APP")" ]] || fail "$APP exists and is not an empty folder; stopping."
    chown "$DEPLOY_USER":gjej-public "$APP"
    chmod 0755 "$APP"
  else
    install -d -o "$DEPLOY_USER" -g gjej-public -m 0755 "$APP"
  fi
  sudo -u "$DEPLOY_USER" -H git clone --branch main "$REPO" "$APP"
fi

echo "== Linking .env and shared storage"
cd "$APP"
ln -sfn "$ENV_FILE" "$APP/.env"
if [[ ! -L "$APP/storage" ]]; then
  # The repo only tracks empty placeholder files under storage/. Tell git to
  # leave them alone, then replace the folder with a link to the real storage.
  sudo -u "$DEPLOY_USER" -H git -C "$APP" ls-files -z storage bootstrap/cache \
    | sudo -u "$DEPLOY_USER" -H xargs -0 git -C "$APP" update-index --skip-worktree
  rm -rf "$APP/storage"
  ln -s "$STORAGE" "$APP/storage"
fi
# Laravel writes compiled caches here as the app user.
chown gjej:gjej-public "$APP/bootstrap/cache"
chmod 2775 "$APP/bootstrap/cache"

echo "== Installing the deploy-gjej command"
install -m 0755 "$HERE/deploy.sh" /usr/local/bin/deploy-gjej

echo
echo "Setup finished. The live site has NOT been switched yet."
echo "Next: run  sudo deploy-gjej  to build and switch the site to $APP."
