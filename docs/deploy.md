# Deploying Gjej Portier

The live site runs from a git checkout of `main` at `/var/www/rezervogk/app` on the Gjej Portier Droplet (`gjej-portier-prod-fra1`). `/var/www/rezervogk/current` points to it, and Nginx serves `current/public`.

## Every change

1. On your Mac: commit and push to `main`.
2. On the server:

   ```bash
   ssh bajram@164.92.199.146
   sudo deploy-gjej
   ```

`deploy-gjej` pulls `main`, runs `composer install` and `npm run build`, asks before running any pending migrations, rebuilds Laravel caches, reloads PHP-FPM and checks `/up`. The site is in maintenance mode for the few seconds the update takes.

## Rolling back

- `sudo deploy-gjej <commit>` deploys any earlier commit. Each deploy prints the commit to roll back to.
- `sudo deploy-gjej old-release` points the site back at the last archive release from before git deploys (recorded in `/var/www/rezervogk/archive-release`).

Back up the database before a deploy that runs migrations:

```bash
sudo mysqldump --single-transaction --all-databases | gzip > ~/gjej-db-$(date +%F).sql.gz
```

## Layout

| Path | What it is |
| --- | --- |
| `/var/www/rezervogk/app` | git checkout, owned by `bajram` |
| `app/.env` | link to `/etc/gjej/rezervogk.env` |
| `app/storage` | link to `/var/www/rezervogk/shared/storage` (uploads, sessions, logs) |
| `app/bootstrap/cache` | owned by `gjej`, written by Artisan |
| `/usr/local/bin/deploy-gjej` | installed copy of `scripts/deploy/deploy.sh` |

`scripts/deploy/setup-server.sh` is the one-time setup that created this. After changing `scripts/deploy/deploy.sh`, reinstall it on the server:

```bash
sudo install -m 0755 /var/www/rezervogk/app/scripts/deploy/deploy.sh /usr/local/bin/deploy-gjej
```
