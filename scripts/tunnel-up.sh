#!/usr/bin/env sh
# Put the locally running app on a temporary public URL.
#
#   scripts/tunnel-up.sh            # start app (if needed) + quick tunnel, print URL
#   scripts/tunnel-down.sh          # close the tunnel; the app keeps running
#
# Uses the `tunnel` profile in compose.yaml: Cloudflare's cloudflared in quick-tunnel
# mode, which needs no account and gives a random *.trycloudflare.com hostname that
# lives exactly as long as the container. Works from Git Bash on Windows, and from
# any sh on macOS or Linux. Docker is the only requirement.
#
# The URL is printed and also written to .tunnel-url (gitignored) so other tools
# can read it. Optional: pass --smoke to run readlog:smoke against the public URL
# from inside the app container, which proves the round trip out through
# Cloudflare and back in through nginx.
set -eu

cd "$(dirname "$0")/.."

SMOKE=0
for arg in "$@"; do
    case "$arg" in
        --smoke) SMOKE=1 ;;
        *) echo "unknown option: $arg" >&2; exit 2 ;;
    esac
done

echo "[readlog] Making sure the app is up..."
docker compose up -d --wait --wait-timeout 180 >/dev/null

echo "[readlog] Opening a quick tunnel..."
docker compose --profile tunnel up -d tunnel >/dev/null

# cloudflared prints the hostname once it has one. Give it up to 60 seconds.
URL=""
i=0
while [ $i -lt 60 ]; do
    URL="$(docker compose --profile tunnel logs tunnel 2>/dev/null \
        | grep -o 'https://[a-z0-9-]*\.trycloudflare\.com' | head -n 1 || true)"
    [ -n "$URL" ] && break
    i=$((i + 1))
    sleep 1
done

if [ -z "$URL" ]; then
    echo "[readlog] No tunnel URL after 60s. Last log lines:" >&2
    docker compose --profile tunnel logs --tail 20 tunnel >&2
    exit 1
fi

printf '%s\n' "$URL" > .tunnel-url
echo
echo "  $URL"
echo
echo "[readlog] Anyone with that address can use the app until you run scripts/tunnel-down.sh."
echo "[readlog] It can take a few seconds to become reachable the first time."

if [ "$SMOKE" -eq 1 ]; then
    echo
    echo "[readlog] Smoke check through the tunnel (out via Cloudflare, back via nginx):"
    # The hostname is brand new; give DNS and the edge a moment before judging.
    sleep 5
    docker compose exec -T app php artisan readlog:smoke --url="$URL" --timeout=20
fi
