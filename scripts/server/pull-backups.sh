#!/usr/bin/env bash
# Copy the Gjej Portier server backups to this Mac.
#   bash scripts/server/pull-backups.sh
# Backups land in ~/Backups/GjejPortier. They contain the full database and
# the production .env (secrets), so keep that folder private.
set -Eeuo pipefail
DEST="$HOME/Backups/GjejPortier"
mkdir -p "$DEST"
chmod 700 "$DEST"
rsync -a bajram@164.92.199.146:/var/backups/gjej/ "$DEST/"
chmod 700 "$DEST"
chmod 600 "$DEST"/*
echo "Backups in $DEST:"
ls -lh "$DEST" | tail -n +2
