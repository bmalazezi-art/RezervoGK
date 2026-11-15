#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

if (( EUID != 0 )); then
  echo 'Run this reviewed bootstrap through sudo on the Gjej Portier Droplet.' >&2
  exit 2
fi

SOURCE_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
TOOLS_ROOT=/usr/local/lib/gjej-release-tools
CONFIG=/etc/gjej/release-receiver.json
TRUSTED_KEY=/etc/gjej/gjej-release-v2.pub
LEGACY_TRUSTED_KEY=/etc/gjej-release/minisign.pub
RECEIVER_STATE=/var/lib/gjej-release-receiver
INCOMING=/var/lib/gjej-deploy/incoming

for account in gjej-deploy gjej; do
  getent passwd "$account" >/dev/null || { echo "Required account is missing: $account" >&2; exit 1; }
done
getent group gjej-public >/dev/null || { echo 'Required group is missing: gjej-public' >&2; exit 1; }

UPLOAD_UID="$(id -u gjej-deploy)"
OWNER_UID="$(id -u gjej)"
GROUP_GID="$(getent group gjej-public | cut -d: -f3)"
UPLOAD_SHELL="$(getent passwd gjej-deploy | cut -d: -f7)"
[[ "$UPLOAD_SHELL" == /usr/sbin/nologin ]] || { echo 'gjej-deploy must have /usr/sbin/nologin.' >&2; exit 1; }
MINISIGN_VERSION="$(/usr/local/bin/minisign -v 2>/dev/null || true)"
[[ "$MINISIGN_VERSION" =~ minisign\ 0\.(12|[1-9][3-9]|[1-9][0-9]) ]] || {
  echo 'Minisign 0.12 or newer must already be installed at /usr/local/bin/minisign.' >&2
  exit 1
}

[[ -d "$INCOMING" && ! -L "$INCOMING" ]] || { echo 'Expected real incoming directory is missing.' >&2; exit 1; }
[[ "$(stat -c '%u' "$INCOMING")" == "$UPLOAD_UID" ]] || { echo 'Incoming directory owner does not match gjej-deploy.' >&2; exit 1; }
[[ "$(stat -c '%a' "$INCOMING")" == 700 ]] || { echo 'Incoming directory must be mode 0700.' >&2; exit 1; }

for path in /etc/gjej /usr/local/lib /usr/local/sbin /var/lib /var/www/rezervogk/releases; do
  [[ -d "$path" && ! -L "$path" ]] || { echo "Required protected directory is missing or unsafe: $path" >&2; exit 1; }
  [[ "$(stat -c '%u' "$path")" == 0 ]] || { echo "Required directory is not root-owned: $path" >&2; exit 1; }
  mode="$(stat -c '%a' "$path")"
  (( (8#$mode & 0022) == 0 )) || { echo "Required directory is group/other-writable: $path" >&2; exit 1; }
done

if [[ -e "$LEGACY_TRUSTED_KEY" || -L "$LEGACY_TRUSTED_KEY" ]]; then
  LEGACY_KEY_DIR="$(dirname "$LEGACY_TRUSTED_KEY")"
  [[ -d "$LEGACY_KEY_DIR" && ! -L "$LEGACY_KEY_DIR" && "$(stat -c '%u' "$LEGACY_KEY_DIR")" == 0 ]] || {
    echo 'Legacy trusted-key directory is not a protected real root-owned directory.' >&2
    exit 1
  }
  legacy_dir_mode="$(stat -c '%a' "$LEGACY_KEY_DIR")"
  (( (8#$legacy_dir_mode & 0022) == 0 )) || {
    echo 'Legacy trusted-key directory is group/other-writable.' >&2
    exit 1
  }
  [[ -f "$LEGACY_TRUSTED_KEY" && ! -L "$LEGACY_TRUSTED_KEY" && "$(stat -c '%u:%g:%a' "$LEGACY_TRUSTED_KEY")" == '0:0:644' ]] || {
    echo 'Legacy trusted key has an unsafe type, owner, or mode; stop for manual review.' >&2
    exit 1
  }
  cmp -s "$SOURCE_ROOT/config/gjej-release-v2.pub" "$LEGACY_TRUSTED_KEY" || {
    echo 'Legacy trusted key differs from the repository v2 pin; an explicit key rotation is required. No trust key was changed.' >&2
    exit 1
  }
fi

[[ -e "$TRUSTED_KEY" || -L "$TRUSTED_KEY" ]] || {
  echo 'Fixed receiver trust key is not preinstalled. Manually provision and verify the approved public key before bootstrap; this script never creates a trust root.' >&2
  exit 1
}
[[ -f "$TRUSTED_KEY" && ! -L "$TRUSTED_KEY" && "$(stat -c '%u:%g:%a' "$TRUSTED_KEY")" == '0:0:644' ]] || {
  echo 'Existing trusted key has an unsafe type, owner, or mode.' >&2
  exit 1
}
cmp -s "$SOURCE_ROOT/config/gjej-release-v2.pub" "$TRUSTED_KEY" || {
  echo 'Existing trusted key differs from the reviewed repository pin; stop for key-rotation review. No trust key was changed.' >&2
  exit 1
}

[[ ! -L "$RECEIVER_STATE" ]] || { echo 'Receiver state path may not be a symlink.' >&2; exit 1; }
mkdir -p "$RECEIVER_STATE"
[[ -d "$RECEIVER_STATE" && ! -L "$RECEIVER_STATE" ]] || { echo 'Receiver state path is not a real directory.' >&2; exit 1; }
if [[ "$(stat -c '%u:%g:%a' "$RECEIVER_STATE")" != '0:0:700' ]]; then
  echo 'Receiver state directory exists with unexpected owner/group/mode.' >&2
  exit 1
fi
[[ ! -L "$RECEIVER_STATE/quarantine" ]] || { echo 'Receiver quarantine path may not be a symlink.' >&2; exit 1; }
mkdir -p "$RECEIVER_STATE/quarantine"
[[ -d "$RECEIVER_STATE/quarantine" && ! -L "$RECEIVER_STATE/quarantine" ]] || { echo 'Receiver quarantine path is not a real directory.' >&2; exit 1; }
if [[ "$(stat -c '%u:%g:%a' "$RECEIVER_STATE/quarantine")" != '0:0:700' ]]; then
  echo 'Receiver quarantine exists with unexpected owner/group/mode.' >&2
  exit 1
fi

[[ ! -L "$TOOLS_ROOT" ]] || { echo 'Release tools path may not be a symlink.' >&2; exit 1; }
mkdir -p "$TOOLS_ROOT"
[[ -d "$TOOLS_ROOT" && ! -L "$TOOLS_ROOT" ]] || { echo 'Release tools path is not a real directory.' >&2; exit 1; }
if [[ "$(stat -c '%u:%g' "$TOOLS_ROOT")" != '0:0' ]] || (( (8#$(stat -c '%a' "$TOOLS_ROOT") & 0022) != 0 )); then
  echo 'Release tools destination is not protected root-owned storage.' >&2
  exit 1
fi
for path in "$TOOLS_ROOT/scripts" "$TOOLS_ROOT/scripts/release"; do
  [[ ! -L "$path" ]] || { echo "Release tool directory may not be a symlink: $path" >&2; exit 1; }
  if [[ ! -e "$path" ]]; then
    mkdir -m 0700 "$path"
  fi
  [[ -d "$path" && ! -L "$path" && "$(stat -c '%u:%g' "$path")" == '0:0' ]] || {
    echo "Release tool directory is not a real root-owned directory: $path" >&2; exit 1;
  }
  mode="$(stat -c '%a' "$path")"
  (( (8#$mode & 0022) == 0 )) || { echo "Release tool directory is group/other-writable: $path" >&2; exit 1; }
done
for file in __init__.py common.py validate_artifact.py sign_artifact.py install_release.py receive_release.py; do
  source="$SOURCE_ROOT/scripts/release/$file"
  destination="$TOOLS_ROOT/scripts/release/$file"
  [[ -f "$source" && ! -L "$source" ]] || { echo "Missing reviewed release tool: $file" >&2; exit 1; }
  if [[ -e "$destination" ]]; then
    [[ -f "$destination" && ! -L "$destination" && "$(stat -c '%u:%g:%a' "$destination")" == '0:0:644' ]] || {
      echo "Existing release tool has an unsafe type, owner, or mode: $destination" >&2; exit 1;
    }
    cmp -s "$source" "$destination" || { echo "Refusing to overwrite a different installed tool: $destination" >&2; exit 1; }
  else
    install -o root -g root -m 0644 "$source" "$destination"
  fi
done
EXPECTED_CONFIG="$(printf '{\n  "incoming_root": "/var/lib/gjej-deploy/incoming",\n  "quarantine_root": "/var/lib/gjej-release-receiver/quarantine",\n  "releases_root": "/var/www/rezervogk/releases",\n  "trusted_public_key": "/etc/gjej/gjej-release-v2.pub",\n  "expected_identity": "gjej-production-release",\n  "uploader_uid": %s,\n  "owner_uid": %s,\n  "group_gid": %s\n}\n' "$UPLOAD_UID" "$OWNER_UID" "$GROUP_GID")"
if [[ -e "$CONFIG" || -L "$CONFIG" ]]; then
  [[ -f "$CONFIG" && ! -L "$CONFIG" && "$(stat -c '%u:%g:%a' "$CONFIG")" == '0:0:600' ]] || {
    echo 'Existing receiver configuration has unsafe type or permissions.' >&2; exit 1;
  }
  [[ "$(cat "$CONFIG")" == "$EXPECTED_CONFIG" ]] || { echo 'Existing receiver configuration differs; stop for review.' >&2; exit 1; }
else
  temp_config="$(mktemp /etc/gjej/.release-receiver.XXXXXX)"
  chmod 0600 "$temp_config"
  printf '%s' "$EXPECTED_CONFIG" > "$temp_config"
  chown root:root "$temp_config"
  mv -T "$temp_config" "$CONFIG"
fi

WRAPPER=/usr/local/sbin/gjej-receive
WRAPPER_CONTENT='#!/bin/sh
exec /usr/bin/env PYTHONPATH=/usr/local/lib/gjej-release-tools /usr/bin/python3 -m scripts.release.receive_release "$@"
'
if [[ -e "$WRAPPER" || -L "$WRAPPER" ]]; then
  [[ -f "$WRAPPER" && ! -L "$WRAPPER" && "$(stat -c '%u:%g:%a' "$WRAPPER")" == '0:0:755' ]] || {
    echo 'Existing receiver command has unsafe type or permissions.' >&2; exit 1;
  }
  [[ "$(cat "$WRAPPER")" == "$WRAPPER_CONTENT" ]] || { echo 'Existing receiver command differs; stop for review.' >&2; exit 1; }
else
  temp_wrapper="$(mktemp /usr/local/sbin/.gjej-receive.XXXXXX)"
  printf '%s' "$WRAPPER_CONTENT" > "$temp_wrapper"
  chown root:root "$temp_wrapper"
  chmod 0755 "$temp_wrapper"
  mv -T "$temp_wrapper" "$WRAPPER"
fi

LOGROTATE=/etc/logrotate.d/gjej-release-receiver
if [[ -e "$LOGROTATE" || -L "$LOGROTATE" ]]; then
  [[ -f "$LOGROTATE" && ! -L "$LOGROTATE" && "$(stat -c '%u:%g:%a' "$LOGROTATE")" == '0:0:644' ]] || {
    echo 'Existing receiver logrotate configuration has unsafe type or permissions.' >&2; exit 1;
  }
  cmp -s "$SOURCE_ROOT/config/gjej-release-receiver.logrotate" "$LOGROTATE" || {
    echo 'Existing receiver logrotate configuration differs; stop for review.' >&2; exit 1;
  }
else
  install -o root -g root -m 0644 "$SOURCE_ROOT/config/gjej-release-receiver.logrotate" "$LOGROTATE"
fi

echo 'Gjej inactive-release receiver bootstrap completed.'
echo 'No release was received or installed; no services, database, or current symlink were changed.'
echo "Configured account IDs: uploader=$UPLOAD_UID owner=$OWNER_UID group=$GROUP_GID"
