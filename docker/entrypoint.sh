#!/bin/sh
# Runs at every container start: checks /data, creates or upgrades the database,
# prints the first-run setup code if setup isn't done yet, starts the digest
# scheduler in the background, then starts Apache.
set -e

if [ ! -w /data ]; then
    echo "ERROR: /data is not writable by $(id -un) (uid $(id -u))." >&2
    echo "With a bind mount, run on the host:  sudo chown -R 33:33 <your data folder>" >&2
    exit 1
fi
php /app/bin/migrate.php

# Only alongside the web server, not for one-off `docker compose run` commands.
# If the scheduler ever crashes, it's restarted a minute later.
if [ "$1" = "apache2-foreground" ]; then
    (
        while true; do
            php /app/bin/scheduler.php || true
            echo "Digest scheduler stopped; restarting in 60 seconds." >&2
            sleep 60
        done
    ) &
fi

exec "$@"
