#!/bin/sh
# Container entrypoint: get the app from "image started" to "serving a seeded
# database", then hand over to php-fpm.
#
# .NET counterpart: the block in Program.cs that creates the SQLite directory and
# runs Database.Migrate() with a retry loop before the HTTP pipeline starts. That
# lives inside the application because ASP.NET Core owns its own process; here it
# lives outside the application because php-fpm does not run application code
# until a request arrives, so something has to run first.
set -eu

cd /var/www/html

# The storage volume starts life as a copy of the image's storage/ directory, but
# be explicit: a wiped or hand-made volume must still work.
for dir in app framework/cache framework/sessions framework/views logs; do
    mkdir -p "storage/$dir"
done
chown -R www-data:www-data storage

# APP_KEY. Nothing in the app can encrypt or sign without one, and asking the
# author to generate and paste a key would turn "one command from a fresh clone"
# into three. If none is provided through the environment, one is generated on the
# first start and kept in the storage volume, so sessions and signed URLs survive
# a container rebuild. Providing APP_KEY explicitly still wins.
KEY_FILE="storage/app/.app_key"
if [ -z "${APP_KEY:-}" ]; then
    if [ ! -s "$KEY_FILE" ]; then
        su-exec www-data php artisan key:generate --show > "$KEY_FILE"
        chmod 600 "$KEY_FILE"
        chown www-data:www-data "$KEY_FILE"
        echo "[readlog] Generated a new APP_KEY and stored it in the storage volume."
    fi
    APP_KEY="$(cat "$KEY_FILE")"
    export APP_KEY
fi

# Migrate, then seed. DatabaseSeeder seeds only into an empty catalogue, so on
# every start after the first this is a no-op. Retried because the opt-in Postgres
# service reports healthy a moment before it accepts connections reliably.
attempt=1
until su-exec www-data php artisan migrate --force --no-interaction; do
    if [ "$attempt" -ge 10 ]; then
        echo "[readlog] Database not reachable after $attempt attempts; giving up." >&2
        exit 1
    fi
    echo "[readlog] Database not ready (attempt $attempt); retrying in 3s."
    attempt=$((attempt + 1))
    sleep 3
done
su-exec www-data php artisan db:seed --force --no-interaction

# Cache config, routes and views for this container's lifetime. Safe here because
# the whole configuration is environment-driven and the environment is fixed for
# the life of the process; the cache lives in bootstrap/cache inside the container,
# not in the volume, so a restart with new variables rebuilds it.
su-exec www-data php artisan optimize --no-interaction

exec "$@"
