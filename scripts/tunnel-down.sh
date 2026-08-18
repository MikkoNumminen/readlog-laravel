#!/usr/bin/env sh
# Close the public URL. The app itself keeps running on localhost.
#
#   scripts/tunnel-down.sh          # close the tunnel only
#   docker compose down             # ...and stop the app too (data is kept)
#   docker compose down -v          # ...and throw the data away as well
set -eu

cd "$(dirname "$0")/.."

echo "[readlog] Closing the tunnel..."
# `down` on the profile removes the tunnel container and nothing else: the app,
# nginx and the storage volume are untouched. Once the container is gone the
# trycloudflare.com hostname stops resolving within seconds.
docker compose --profile tunnel rm --stop --force tunnel >/dev/null 2>&1 || true
rm -f .tunnel-url

echo "[readlog] Tunnel closed. The app is still at http://localhost:${APP_PORT:-8080}."
