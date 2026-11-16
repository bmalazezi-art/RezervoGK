#!/usr/bin/env bash
# One-time maintenance setup on the Gjej Portier Droplet.
# Run once:  sudo bash setup-maintenance.sh
#
#   1. Nightly backup (database + uploaded files + .env), 14 days kept,
#      in /var/backups/gjej. Pull them to your Mac with pull-backups.sh.
#   2. Laravel scheduler (cron, every minute, as the app user).
#   3. Nginx: gzip for CSS/JS/SVG/JSON.
#   4. Nginx: long browser cache for the hashed /build/assets files.
# Safe to run again. Nginx changes are tested first and rolled back if invalid.
set -Eeuo pipefail

fail() { echo "ERROR: $*" >&2; exit 1; }
[[ $EUID -eq 0 ]] || fail "Run with sudo."
[[ "$(hostname)" == gjej-portier-prod-fra1 ]] || fail "This is not the Gjej Portier server."

SITE=/etc/nginx/sites-enabled/gjej
[[ -f "$SITE" ]] || fail "Missing $SITE"

echo "== 1. Nightly backup script"
cat > /usr/local/sbin/gjej-backup <<'EOF'
#!/usr/bin/env bash
# Nightly Gjej Portier backup: database, uploaded files and .env. Keeps 14 days.
set -Eeuo pipefail
umask 027
DIR=/var/backups/gjej
install -d -m 0750 -o root -g sudo "$DIR"
stamp="$(date +%F)"
mysqldump --single-transaction --routines --events --databases rezervogk_prod | gzip > "$DIR/.db.tmp"
mv "$DIR/.db.tmp" "$DIR/db-$stamp.sql.gz"
tar -czf "$DIR/.files.tmp" -C /var/www/rezervogk/shared storage/app
mv "$DIR/.files.tmp" "$DIR/files-$stamp.tar.gz"
cp /etc/gjej/rezervogk.env "$DIR/env-$stamp.env"
chown root:sudo "$DIR"/*-"$stamp".*
chmod 0640 "$DIR"/*-"$stamp".*
find "$DIR" -maxdepth 1 -type f -mtime +14 -delete
total="$(du -cb "$DIR"/*-"$stamp".* | tail -1 | cut -f1)"
count="$(find "$DIR" -maxdepth 1 -type f -name 'db-*.sql.gz' | wc -l)"
# Non-secret status for Admin > System health (no paths to data, no contents).
printf '{"finished_at":"%s","bytes":%s,"kept_days":%s}\n' "$(date -Is)" "$total" "$count" > /var/lib/gjej-backup-status.json.tmp
chmod 0644 /var/lib/gjej-backup-status.json.tmp
mv /var/lib/gjej-backup-status.json.tmp /var/lib/gjej-backup-status.json
echo "$(date -Is) backup ok: $(du -ch "$DIR"/*-"$stamp".* | tail -1 | cut -f1)"
EOF
chmod 0750 /usr/local/sbin/gjej-backup

echo "== 2. Cron: Laravel scheduler + nightly backup"
cat > /etc/cron.d/gjej <<'EOF'
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
# Laravel scheduler, every minute, as the app user
* * * * * gjej cd /var/www/rezervogk/current && php artisan schedule:run >> /dev/null 2>&1
# Nightly backup at 03:15 server time
15 3 * * * root /usr/local/sbin/gjej-backup >> /var/log/gjej-backup.log 2>&1
EOF
chmod 0644 /etc/cron.d/gjej

echo "== 3+4. Nginx gzip and asset caching"
stamp="$(date +%Y%m%d%H%M%S)"
cp -p "$SITE" "/root/nginx-gjej.$stamp.bak"
cat > /etc/nginx/conf.d/gjej-gzip.conf <<'EOF'
gzip_vary on;
gzip_proxied any;
gzip_comp_level 6;
gzip_min_length 1024;
gzip_types text/plain text/css text/xml application/javascript application/json application/xml image/svg+xml;
EOF
cat > /etc/nginx/snippets/gjej-static-cache.conf <<'EOF'
# Vite gives every built file a content hash, so it can be cached for a year.
location /build/assets/ {
    try_files $uri =404;
    expires 1y;
    add_header Cache-Control "public, max-age=31536000, immutable";
    access_log off;
}
EOF
if ! grep -q 'snippets/gjej-static-cache.conf' "$SITE"; then
  sed -i 's#^\(\s*\)root /var/www/rezervogk/current/public;#&\n\1include snippets/gjej-static-cache.conf;#' "$SITE"
fi
grep -q 'snippets/gjej-static-cache.conf' "$SITE" || fail "Could not add the cache include to $SITE"
if nginx -t 2>/tmp/nginx-test.log; then
  systemctl reload nginx
  echo "   Nginx reloaded."
else
  cat /tmp/nginx-test.log >&2
  cp -p "/root/nginx-gjej.$stamp.bak" "$SITE"
  rm -f /etc/nginx/conf.d/gjej-gzip.conf /etc/nginx/snippets/gjej-static-cache.conf
  nginx -t && systemctl reload nginx
  fail "Nginx config test failed; previous config restored."
fi

echo "== Running the first backup now"
/usr/local/sbin/gjej-backup | tee -a /var/log/gjej-backup.log
ls -lh /var/backups/gjej

echo
echo "Done. Site check: $(curl -s -o /dev/null -w '%{http_code}' https://gjejportier.online/up)"
if [[ -f /var/run/reboot-required ]]; then
  echo "A reboot is still needed for security updates: sudo reboot"
fi
