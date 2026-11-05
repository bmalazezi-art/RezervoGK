#!/usr/bin/env bash
# Destructive only to synthetic accounts/files created by this test. Run as root
# on a disposable Ubuntu runner; sshd is bound to loopback and removed on exit.
set -Eeuo pipefail
umask 077

DEPLOY_USER=gjej-deploy
ADMIN_USER=gjej-sftp-admin
STATE=""
SSHD_PID=""
SSHD_PGID=""
SSHD_READY=0
SSHD_RUNTIME_DIR_CREATED=0
AGENT_PID=""
FORWARD_PID=""
DEPLOY_CREATED=0
ADMIN_CREATED=0

fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
pass() { printf 'PASS: %s\n' "$*"; }

cleanup() {
    local status=$?
    trap - EXIT INT TERM
    if [[ -n "$FORWARD_PID" ]]; then
        pkill -TERM -P "$FORWARD_PID" 2>/dev/null || true
    fi
    if [[ -n "$SSHD_PID" ]]; then
        if [[ -n "$SSHD_PGID" ]]; then
            kill -TERM -- "-$SSHD_PGID" 2>/dev/null || true
        else
            kill -TERM "$SSHD_PID" 2>/dev/null || true
        fi
        wait "$SSHD_PID" 2>/dev/null || true
    fi
    if [[ -n "$AGENT_PID" ]]; then
        kill -TERM "$AGENT_PID" 2>/dev/null || true
        wait "$AGENT_PID" 2>/dev/null || true
    fi
    if [[ -n "$FORWARD_PID" ]]; then
        kill "$FORWARD_PID" 2>/dev/null || true
        wait "$FORWARD_PID" 2>/dev/null || true
    fi
    if (( ADMIN_CREATED )); then
        userdel "$ADMIN_USER" 2>/dev/null || true
        if getent passwd "$ADMIN_USER" >/dev/null; then printf 'FAIL: synthetic admin account cleanup failed\n' >&2; status=1; fi
        if getent group "$ADMIN_USER" >/dev/null; then groupdel "$ADMIN_USER" 2>/dev/null || true; fi
        if getent group "$ADMIN_USER" >/dev/null; then printf 'FAIL: synthetic admin group cleanup failed\n' >&2; status=1; fi
    fi
    if (( DEPLOY_CREATED )); then
        userdel "$DEPLOY_USER" 2>/dev/null || true
        if getent passwd "$DEPLOY_USER" >/dev/null; then printf 'FAIL: synthetic deploy account cleanup failed\n' >&2; status=1; fi
        if getent group "$DEPLOY_USER" >/dev/null; then groupdel "$DEPLOY_USER" 2>/dev/null || true; fi
        if getent group "$DEPLOY_USER" >/dev/null; then printf 'FAIL: synthetic deploy group cleanup failed\n' >&2; status=1; fi
    fi
    if (( SSHD_RUNTIME_DIR_CREATED )); then
        rmdir /run/sshd 2>/dev/null || { printf 'FAIL: temporary sshd runtime directory cleanup failed\n' >&2; status=1; }
    fi
    if [[ -n "$STATE" && "$STATE" == /var/lib/gjej-sftp-it.* ]]; then
        rm -rf -- "$STATE" || { printf 'FAIL: temporary state cleanup failed\n' >&2; status=1; }
        if [[ -e "$STATE" ]]; then printf 'FAIL: temporary state remains after cleanup\n' >&2; status=1; fi
    fi
    port_closed=0
    if (( SSHD_READY )) && [[ -n "${PORT:-}" ]]; then
        for _ in $(seq 1 30); do
            if python3 - "$PORT" <<'PY' >/dev/null 2>&1
import socket, sys
try:
    s = socket.create_connection(("127.0.0.1", int(sys.argv[1])), timeout=.2)
except OSError:
    raise SystemExit(0)
s.close()
raise SystemExit(1)
PY
            then port_closed=1; break; fi
            sleep 0.1
        done
    else
        port_closed=1
    fi
    if (( ! port_closed )); then
        printf 'FAIL: temporary SSH port remains open after cleanup\n' >&2
        if command -v ss >/dev/null 2>&1; then ss -ltnp "sport = :$PORT" >&2 || true; fi
        ps -eo pid,ppid,pgid,args | grep -E '[s]shd|[s]etsid' >&2 || true
        status=1
    fi
    exit "$status"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

[[ "$(id -u)" == 0 ]] || fail 'Run this integration test as root inside its disposable Ubuntu runner.'
for executable in /usr/sbin/sshd ssh sftp ssh-keygen openssl timeout seq stat install chmod chown mktemp setsid \
    useradd usermod userdel groupdel getent pkill python3; do
    command -v "$executable" >/dev/null 2>&1 || [[ -x "$executable" ]] || fail "Required executable is missing: $executable"
done
getent passwd "$DEPLOY_USER" >/dev/null && fail "Refusing to alter pre-existing account $DEPLOY_USER"
getent passwd "$ADMIN_USER" >/dev/null && fail "Refusing to alter pre-existing account $ADMIN_USER"
getent group "$DEPLOY_USER" >/dev/null && fail "Refusing to alter pre-existing group $DEPLOY_USER"
getent group "$ADMIN_USER" >/dev/null && fail "Refusing to alter pre-existing group $ADMIN_USER"

STATE="$(mktemp -d /var/lib/gjej-sftp-it.XXXXXX)"
chmod 0711 "$STATE"
JAIL="$STATE/jail"
AUTH_DIR="$STATE/authorized_keys"
OUTSIDE="$STATE/synthetic-host-files"
LOG="$STATE/sshd.log"
CONFIG="$STATE/sshd_config"
KNOWN_HOSTS="$STATE/known_hosts"
DEPLOY_KEY="$STATE/deploy-test-key"
WRONG_KEY="$STATE/wrong-test-key"
ADMIN_KEY="$STATE/admin-test-key"
UPLOAD="$STATE/synthetic-release.bin"
LOCAL_COPY="$STATE/downloaded-release.bin"

install -d -o root -g root -m 0711 "$JAIL"
# The account does not exist yet, so create it before assigning upload ownership.
DEPLOY_CREATED=1
useradd --no-create-home --home-dir / --shell /usr/sbin/nologin --user-group "$DEPLOY_USER"
ADMIN_CREATED=1
useradd --create-home --home-dir "$STATE/admin-home" --shell /bin/bash --user-group "$ADMIN_USER"
chown "$ADMIN_USER:$ADMIN_USER" "$STATE/admin-home"
chmod 0700 "$STATE/admin-home"
install -d -o "$DEPLOY_USER" -g "$DEPLOY_USER" -m 0700 "$JAIL/incoming"
install -d -o "$DEPLOY_USER" -g "$DEPLOY_USER" -m 0700 "$JAIL/.ssh"
cat > "$JAIL/.ssh/rc" <<'EOF'
#!/bin/sh
: > /incoming/ssh-user-rc-executed
EOF
chown "$DEPLOY_USER:$DEPLOY_USER" "$JAIL/.ssh/rc"
chmod 0600 "$JAIL/.ssh/rc"
install -d -o root -g root -m 0711 "$AUTH_DIR"
# These synthetic fixtures are deliberately accessible to the deploy user on
# the host. Escape tests must fail because of the jail, not host permissions.
install -d -o "$DEPLOY_USER" -g "$DEPLOY_USER" -m 0700 "$OUTSIDE"

# Give the synthetic accounts random unusable passwords so OpenSSH account
# checks pass; password and keyboard-interactive authentication stay disabled.
SYNTHETIC_PASSWORD="$(openssl rand -hex 32)"
for account in "$DEPLOY_USER" "$ADMIN_USER"; do
    password_hash="$(printf '%s\n' "$SYNTHETIC_PASSWORD" | openssl passwd -6 -stdin)"
    usermod --password "$password_hash" "$account"
    unset password_hash
done
printf '%s\n' "$SYNTHETIC_PASSWORD" > "$STATE/synthetic-password"
unset SYNTHETIC_PASSWORD
cat > "$STATE/askpass" <<EOF
#!/bin/sh
cat '$STATE/synthetic-password'
EOF
chmod 0700 "$STATE/askpass"

ssh-keygen -q -t ed25519 -N '' -C synthetic-deploy-test -f "$DEPLOY_KEY"
ssh-keygen -q -t ed25519 -N '' -C synthetic-wrong-key -f "$WRONG_KEY"
ssh-keygen -q -t ed25519 -N '' -C synthetic-admin-test -f "$ADMIN_KEY"
ssh-keygen -q -t ed25519 -N '' -C synthetic-sshd-host -f "$STATE/host_ed25519"
# Do not add authorized_keys restrictions here: forwarding and PTY checks must
# prove the sshd Match policy itself denies those requests.
cat "$DEPLOY_KEY.pub" > "$AUTH_DIR/$DEPLOY_USER"
cat "$ADMIN_KEY.pub" > "$AUTH_DIR/$ADMIN_USER"
chown root:root "$AUTH_DIR/$DEPLOY_USER" "$AUTH_DIR/$ADMIN_USER"
chmod 0644 "$AUTH_DIR/$DEPLOY_USER" "$AUTH_DIR/$ADMIN_USER"

printf 'synthetic release payload only\n' > "$UPLOAD"
printf 'synthetic Gjej environment secret\n' > "$OUTSIDE/gjej.env"
printf 'synthetic Gjej source file\n' > "$OUTSIDE/gjej-source.php"
printf 'synthetic private receipt\n' > "$OUTSIDE/private-receipt.pdf"
printf 'synthetic Lidhje private file\n' > "$OUTSIDE/lidhje-private.txt"
chmod 0644 "$OUTSIDE"/*
chmod 0600 "$UPLOAD"

PORT="$(python3 -c 'import socket; s=socket.socket(); s.bind(("127.0.0.1",0)); print(s.getsockname()[1]); s.close()')"
cat > "$CONFIG" <<EOF
Port $PORT
ListenAddress 127.0.0.1
HostKey $STATE/host_ed25519
PidFile $STATE/sshd.pid
AuthorizedKeysFile $AUTH_DIR/%u
AuthorizedKeysCommand none
TrustedUserCAKeys none
AllowUsers $DEPLOY_USER $ADMIN_USER
UsePAM no
StrictModes yes
PubkeyAuthentication yes
AuthenticationMethods publickey
PasswordAuthentication no
KbdInteractiveAuthentication no
PermitEmptyPasswords no
PermitRootLogin no
PermitTTY yes
PermitUserRC yes
AllowTcpForwarding yes
AllowAgentForwarding yes
AllowStreamLocalForwarding yes
X11Forwarding yes
PermitTunnel yes
Subsystem sftp internal-sftp
LogLevel VERBOSE

Match User $DEPLOY_USER
    AuthenticationMethods publickey
    PubkeyAuthentication yes
    PasswordAuthentication no
    KbdInteractiveAuthentication no
    AuthorizedKeysFile $AUTH_DIR/%u
    AuthorizedKeysCommand none
    TrustedUserCAKeys none
    ChrootDirectory $JAIL
    ForceCommand internal-sftp -u 0077 -P symlink,hardlink
    PermitTTY no
    PermitUserRC no
    DisableForwarding yes
    AllowTcpForwarding no
    AllowAgentForwarding no
    AllowStreamLocalForwarding no
    X11Forwarding no
    PermitTunnel no
EOF
touch "$LOG"
chown root:root "$CONFIG" "$LOG"
chmod 0600 "$CONFIG" "$LOG" "$STATE/host_ed25519"

# On runners where the package service was never started, sshd's runtime
# privilege-separation directory may not exist. Create only this empty runtime
# directory and remove it during cleanup if this harness created it.
if [[ ! -d /run/sshd ]]; then
    [[ ! -e /run/sshd ]] || fail '/run/sshd exists but is not a directory'
    SSHD_RUNTIME_DIR_CREATED=1
    install -d -o root -g root -m 0755 /run/sshd
fi

/usr/sbin/sshd -t -f "$CONFIG"
pass 'sshd configuration syntax validates'

EFFECTIVE_DEPLOY="$(/usr/sbin/sshd -T -f "$CONFIG" -C "user=$DEPLOY_USER,host=localhost,addr=127.0.0.1")"
grep -Fxiq "listenaddress 127.0.0.1:$PORT" <<< "$EFFECTIVE_DEPLOY" || fail 'Effective sshd listener is not the selected IPv4 loopback address'
for expected in \
    'authenticationmethods publickey' \
    'passwordauthentication no' \
    'kbdinteractiveauthentication no' \
    'permittty no' \
    'permituserrc no' \
    'disableforwarding yes' \
    'allowtcpforwarding no' \
    'allowagentforwarding no' \
    'allowstreamlocalforwarding no' \
    'x11forwarding no' \
    'permittunnel no' \
    'forcecommand internal-sftp -u 0077 -p symlink,hardlink'; do
    grep -Fxiq "$expected" <<< "$EFFECTIVE_DEPLOY" || fail "Effective deploy policy missing: $expected"
done
grep -F "chrootdirectory $JAIL" <<< "$EFFECTIVE_DEPLOY" >/dev/null || fail 'Effective deploy chroot path differs'
pass 'sshd effective policy confirms key-only SFTP chroot and disabled shell/forwarding features'

EFFECTIVE_ADMIN="$(/usr/sbin/sshd -T -f "$CONFIG" -C "user=$ADMIN_USER,host=localhost,addr=127.0.0.1")"
for expected in 'permittty yes' 'allowtcpforwarding yes' 'allowagentforwarding yes' 'x11forwarding yes' 'permittunnel yes'; do
    grep -Fxiq "$expected" <<< "$EFFECTIVE_ADMIN" || fail "Unmatched admin policy unexpectedly changed: $expected"
done
pass 'synthetic admin retains normal global SSH policy outside the Match block'

PORT_OWNER="$(stat -c '%U:%G:%a' "$JAIL")"
[[ "$PORT_OWNER" == root:root:711 ]] || fail "Unsafe chroot owner/mode: $PORT_OWNER"
[[ "$(stat -c '%U:%G:%a' "$JAIL/incoming")" == "$DEPLOY_USER:$DEPLOY_USER:700" ]] || fail 'Incoming directory ownership/mode is incorrect'
pass 'chroot is root-owned 0711 and only /incoming is writable by gjej-deploy'

setsid /usr/sbin/sshd -D -f "$CONFIG" -E "$LOG" &
SSHD_PID=$!
SSHD_PGID="$SSHD_PID"
ready=0
for _ in $(seq 1 50); do
    if python3 - "$PORT" <<'PY' >/dev/null 2>&1
import socket, sys
s = socket.create_connection(("127.0.0.1", int(sys.argv[1])), timeout=0.2)
banner = s.makefile('rb').readline(255)
s.close()
if not banner.startswith(b'SSH-2.0-'):
    raise SystemExit(1)
PY
    then ready=1; break; fi
    sleep 0.1
done
(( ready == 1 )) || fail 'Temporary loopback sshd did not start'
# Verify the actual kernel listening sockets as well as the config directive.
python3 - "$PORT" <<'PY' || fail 'Temporary sshd has a non-loopback listening socket'
import sys
port = int(sys.argv[1])
found = False
with open('/proc/net/tcp', encoding='ascii') as f:
    next(f)
    for row in f:
        fields = row.split()
        address, state = fields[1], fields[3]
        local_port = int(address.rsplit(':', 1)[1], 16)
        if local_port == port and state == '0A':
            found = True
            if address.rsplit(':', 1)[0] != '0100007F':
                raise SystemExit(1)
with open('/proc/net/tcp6', encoding='ascii') as f:
    next(f)
    for row in f:
        fields = row.split()
        if int(fields[1].rsplit(':', 1)[1], 16) == port and fields[3] == '0A':
            raise SystemExit(1)
if not found:
    raise SystemExit(1)
PY
pass 'runtime sshd listener is exclusively IPv4 loopback'
SSHD_READY=1
host_key_line="$(cat "$STATE/host_ed25519.pub")"
printf '[127.0.0.1]:%s %s\n' "$PORT" "$host_key_line" > "$KNOWN_HOSTS"
chmod 0600 "$KNOWN_HOSTS"

SSH_COMMON=(-F /dev/null -p "$PORT" -o "UserKnownHostsFile=$KNOWN_HOSTS" -o StrictHostKeyChecking=yes -o BatchMode=yes -o IdentitiesOnly=yes -o PreferredAuthentications=publickey -o PubkeyAuthentication=yes -o ConnectTimeout=3)
DEPLOY_SSH=(-i "$DEPLOY_KEY" -o IdentityAgent=none)
ADMIN_SSH=(-i "$ADMIN_KEY" -o IdentityAgent=none)

sftp_batch() {
    local key="$1" batch="$2"
    sftp -F /dev/null -P "$PORT" -i "$key" -o IdentityAgent=none -o IdentitiesOnly=yes \
        -o UserKnownHostsFile="$KNOWN_HOSTS" -o StrictHostKeyChecking=yes -o BatchMode=yes \
        -o PreferredAuthentications=publickey -o PubkeyAuthentication=yes -o ConnectTimeout=3 \
        -b "$batch" "$DEPLOY_USER@127.0.0.1"
}

cat > "$STATE/upload.batch" <<EOF
put $UPLOAD /incoming/release.bin
ls /incoming
get /incoming/release.bin $LOCAL_COPY
EOF
if ! sftp_batch "$DEPLOY_KEY" "$STATE/upload.batch" > "$STATE/sftp-upload.log" 2>&1; then
    cat "$STATE/sftp-upload.log" "$LOG" >&2
    fail 'SFTP upload/list/download inside /incoming failed'
fi
cmp -s "$UPLOAD" "$JAIL/incoming/release.bin" || fail 'Uploaded file bytes differ from synthetic source'
cmp -s "$UPLOAD" "$LOCAL_COPY" || fail 'Downloaded file bytes differ from synthetic upload'
[[ "$(stat -c '%a' "$JAIL/incoming/release.bin")" == 600 ]] || fail 'SFTP umask did not keep uploaded file private'
pass 'SFTP upload, listing and retrieval inside /incoming succeed with mode 0600'

expect_sftp_failure() {
    local label="$1" batch="$2"
    if sftp_batch "$DEPLOY_KEY" "$batch" > "$STATE/sftp-denial.log" 2>&1; then
        fail "$label unexpectedly succeeded"
    fi
    pass "$label is denied"
}

printf 'ls /\n' > "$STATE/list-root.batch"
expect_sftp_failure 'Listing chroot root outside /incoming' "$STATE/list-root.batch"
printf 'put %s %s/escaped-upload.bin\n' "$UPLOAD" "$OUTSIDE" > "$STATE/outside-upload.batch"
expect_sftp_failure 'Upload outside /incoming' "$STATE/outside-upload.batch"
[[ ! -e "$OUTSIDE/escaped-upload.bin" ]] || fail 'SFTP upload escaped the chroot into the synthetic host directory'
printf 'cd ..\nget ../../var/lib/%s/synthetic-host-files/gjej.env %s/stolen.env\n' "${STATE##*/}" "$STATE" > "$STATE/traversal.batch"
expect_sftp_failure 'Traversal/download outside the chroot' "$STATE/traversal.batch"
printf 'symlink /etc/passwd /incoming/host-passwd-link\n' > "$STATE/symlink.batch"
expect_sftp_failure 'SFTP symlink creation' "$STATE/symlink.batch"
printf 'ln /incoming/release.bin /incoming/hardlink-copy.bin\n' > "$STATE/hardlink.batch"
expect_sftp_failure 'SFTP hardlink creation' "$STATE/hardlink.batch"
[[ ! -e "$JAIL/incoming/hardlink-copy.bin" ]] || fail 'SFTP hardlink was created despite the server restriction'

for fixture in gjej.env gjej-source.php private-receipt.pdf lidhje-private.txt; do
    printf 'get %s/%s %s/stolen-%s\n' "$OUTSIDE" "$fixture" "$STATE" "$fixture" > "$STATE/private-read.batch"
    expect_sftp_failure "Reading synthetic external file $fixture" "$STATE/private-read.batch"
done

cat > "$STATE/no-key.batch" <<'EOF'
ls /incoming
EOF
if sftp -F /dev/null -P "$PORT" -o IdentityFile=/dev/null -o IdentityAgent=none -o IdentitiesOnly=yes \
    -o UserKnownHostsFile="$KNOWN_HOSTS" -o StrictHostKeyChecking=yes -o BatchMode=yes \
    -o PreferredAuthentications=publickey -o PubkeyAuthentication=yes -b "$STATE/no-key.batch" \
    "$DEPLOY_USER@127.0.0.1" > "$STATE/no-key.log" 2>&1; then
    fail 'Unauthenticated SFTP unexpectedly succeeded'
fi
pass 'Unauthenticated SFTP access is denied'

if SSH_ASKPASS="$STATE/askpass" SSH_ASKPASS_REQUIRE=force DISPLAY=:0 sftp -F /dev/null -P "$PORT" \
    -o IdentityFile=/dev/null -o IdentityAgent=none -o IdentitiesOnly=yes \
    -o UserKnownHostsFile="$KNOWN_HOSTS" -o StrictHostKeyChecking=yes -o BatchMode=no \
    -o NumberOfPasswordPrompts=1 -o PreferredAuthentications=password -o PubkeyAuthentication=no \
    -b "$STATE/no-key.batch" "$DEPLOY_USER@127.0.0.1" > "$STATE/password-auth.log" 2>&1; then
    fail 'Password-authenticated SFTP unexpectedly succeeded'
fi
grep -Eiq 'Permission denied|no supported authentication methods' "$STATE/password-auth.log" || fail 'Password authentication denial was not observed'
pass 'Password-authenticated SFTP access is denied despite a valid synthetic password'

if sftp_batch "$WRONG_KEY" "$STATE/no-key.batch" > "$STATE/wrong-key.log" 2>&1; then
    fail 'Incorrect-key SFTP unexpectedly succeeded'
fi
pass 'Incorrect-key SFTP access is denied'

COMMAND_MARKER="$JAIL/incoming/ssh-command-executed"
USER_RC_MARKER="$JAIL/incoming/ssh-user-rc-executed"
timeout 5 ssh "${SSH_COMMON[@]}" "${DEPLOY_SSH[@]}" "$DEPLOY_USER@127.0.0.1" "touch /incoming/ssh-command-executed" > "$STATE/ssh-command.log" 2>&1 || true
[[ ! -e "$COMMAND_MARKER" ]] || fail 'SSH command executed inside the upload jail'
[[ ! -e "$USER_RC_MARKER" ]] || fail 'Per-user SSH rc ran despite PermitUserRC no'
grep -F 'forced-command (config)' "$LOG" | grep -F 'internal-sftp' >/dev/null || fail 'sshd log did not confirm forced internal-sftp command'
pass 'Shell/SSH command is forced to internal-sftp and per-user rc does not run'

PTY_MARKER="$JAIL/incoming/pty-command-executed"
timeout 5 ssh -tt "${SSH_COMMON[@]}" "${DEPLOY_SSH[@]}" "$DEPLOY_USER@127.0.0.1" "touch /incoming/pty-command-executed" > "$STATE/pty.log" 2>&1 || true
[[ ! -e "$PTY_MARKER" ]] || fail 'PTY command executed'
grep -Eiq 'pty allocation request failed|pty allocation disabled' "$STATE/pty.log" || fail 'PTY request did not produce the expected denial evidence'
pass 'PTY allocation and command execution are denied'

free_port() { python3 -c 'import socket; s=socket.socket(); s.bind(("127.0.0.1",0)); print(s.getsockname()[1]); s.close()'; }
LOCAL_FORWARD_PORT="$(free_port)"
timeout 5 ssh -vv "${SSH_COMMON[@]}" "${DEPLOY_SSH[@]}" -N -L "127.0.0.1:$LOCAL_FORWARD_PORT:127.0.0.1:22" \
    "$DEPLOY_USER@127.0.0.1" > "$STATE/local-forward.log" 2>&1 &
FORWARD_PID=$!
for _ in $(seq 1 30); do
    python3 - "$LOCAL_FORWARD_PORT" <<'PY' >/dev/null 2>&1 && break || true
import socket, sys
s=socket.socket(); s.settimeout(.1); ok=s.connect_ex(("127.0.0.1",int(sys.argv[1]))) == 0; s.close(); sys.exit(0 if ok else 1)
PY
    sleep 0.1
done
python3 - "$LOCAL_FORWARD_PORT" <<'PY' >/dev/null 2>&1 || true
import socket, sys
try:
 s=socket.create_connection(("127.0.0.1",int(sys.argv[1])),timeout=.5); s.close()
except OSError:
 pass
PY
wait "$FORWARD_PID" 2>/dev/null || true
FORWARD_PID=""
grep -Eiq 'administratively prohibited' "$STATE/local-forward.log" || fail 'Local TCP forwarding denial was not observed'
pass 'Local TCP forwarding request is denied'

LOCAL_SOCKET="$STATE/forward.sock"
timeout 5 ssh -vv "${SSH_COMMON[@]}" "${DEPLOY_SSH[@]}" -N -L "$LOCAL_SOCKET:/tmp/gjej-sftp-test.sock" \
    "$DEPLOY_USER@127.0.0.1" > "$STATE/stream-local-forward.log" 2>&1 &
FORWARD_PID=$!
for _ in $(seq 1 30); do
    [[ -S "$LOCAL_SOCKET" ]] && break
    sleep 0.1
done
if [[ -S "$LOCAL_SOCKET" ]]; then
    python3 - "$LOCAL_SOCKET" <<'PY' >/dev/null 2>&1 || true
import socket, sys
s = socket.socket(socket.AF_UNIX)
s.settimeout(.5)
try:
    s.connect(sys.argv[1])
except OSError:
    pass
s.close()
PY
fi
wait "$FORWARD_PID" 2>/dev/null || true
FORWARD_PID=""
if ! grep -Fqi 'refused streamlocal port forward' "$LOG"; then
    cat "$STATE/stream-local-forward.log" "$LOG" >&2
    fail 'Stream-local forwarding denial was not observed'
fi
rm -f -- "$LOCAL_SOCKET"
[[ ! -S "$LOCAL_SOCKET" ]] || fail 'SSH client left its temporary stream-local socket behind'
pass 'Stream-local forwarding request is denied'

REMOTE_FORWARD_PORT="$(free_port)"
if timeout 5 ssh -vv "${SSH_COMMON[@]}" "${DEPLOY_SSH[@]}" -N -R "127.0.0.1:$REMOTE_FORWARD_PORT:127.0.0.1:22" \
    -o ExitOnForwardFailure=yes "$DEPLOY_USER@127.0.0.1" > "$STATE/remote-forward.log" 2>&1; then
    fail 'Remote TCP forwarding unexpectedly succeeded'
fi
if ! grep -Fqi 'remote port forwarding failed for listen port' "$STATE/remote-forward.log"; then
    cat "$STATE/remote-forward.log" "$LOG" >&2
    fail 'SSH client did not report policy refusal of remote TCP forwarding'
fi
pass 'Remote TCP forwarding request is denied'

SSH_AUTH_SOCK="$STATE/agent.sock" ssh-agent -D -a "$STATE/agent.sock" > "$STATE/agent.log" 2>&1 &
AGENT_PID=$!
for _ in $(seq 1 30); do
    [[ -S "$STATE/agent.sock" ]] && break
    sleep 0.1
done
[[ -S "$STATE/agent.sock" ]] || fail 'Synthetic ssh-agent did not start'
export SSH_AUTH_SOCK="$STATE/agent.sock"
ssh-add "$ADMIN_KEY" >/dev/null 2>&1
timeout 5 ssh -vv -A "${SSH_COMMON[@]}" -i "$DEPLOY_KEY" "$DEPLOY_USER@127.0.0.1" true > "$STATE/agent-forward.log" 2>&1 || true
grep -Eiq 'agent forwarding disabled|administratively prohibited|agent forwarding request failed' "$STATE/agent-forward.log" "$LOG" || fail 'Agent forwarding denial was not observed'
pass 'Agent forwarding request is denied'

DISPLAY=localhost:10.0 timeout 5 ssh -vv -X "${SSH_COMMON[@]}" "${DEPLOY_SSH[@]}" "$DEPLOY_USER@127.0.0.1" true > "$STATE/x11-forward.log" 2>&1 || true
grep -Eiq 'X11 forwarding request failed|X11 forwarding disabled|administratively prohibited' "$STATE/x11-forward.log" "$LOG" || fail 'X11 forwarding denial was not observed'
pass 'X11 forwarding request is denied'

if [[ ! -c /dev/net/tun ]]; then fail 'Runner has no /dev/net/tun; cannot exercise the SSH tunnel request'; fi
pass 'runner provides /dev/net/tun for the SSH tunnel test'
if timeout 5 ssh -vv "${SSH_COMMON[@]}" "${DEPLOY_SSH[@]}" -w any:any -N "$DEPLOY_USER@127.0.0.1" > "$STATE/tunnel.log" 2>&1; then
    fail 'SSH tunnel request unexpectedly succeeded'
fi
grep -Eiq 'tunnel forwarding failed|administratively prohibited|Tunnel forwarding disabled' "$STATE/tunnel.log" || fail 'SSH tunnel denial was not observed'
pass 'SSH tunnel request is denied'

ADMIN_OUTPUT="$(ssh "${SSH_COMMON[@]}" "${ADMIN_SSH[@]}" "$ADMIN_USER@127.0.0.1" 'printf ADMIN_SSH_OK')"
[[ "$ADMIN_OUTPUT" == *ADMIN_SSH_OK* ]] || fail 'Synthetic administrative SSH session did not run its command'
pass 'Unmatched synthetic administrative SSH user can authenticate and run a normal command'

printf '\nAll restricted SFTP integration checks passed.\n'
