#!/bin/sh
set -e

echo "[entrypoint] waiting for database ${DB_HOST}..."
tries=0
until php /var/www/html/app/wait_db.php; do
    tries=$((tries + 1))
    if [ "$tries" -ge 60 ]; then
        echo "[entrypoint] database not reachable after 60 tries, giving up"
        exit 1
    fi
    sleep 2
done

echo "[entrypoint] running migrations/seed..."
php /var/www/html/app/migrate.php

echo "[entrypoint] starting apache"
exec "$@"
