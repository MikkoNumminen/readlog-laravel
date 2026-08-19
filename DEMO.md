# Demo in five minutes

You are about to share a screen. This gets the app running on your machine and,
if you want it, on a public URL that anyone can open. Nothing here costs money or
needs an account. Docker Desktop must be running; that is the only requirement.

All commands are run from the repository root. On Windows use Git Bash for the
`scripts/*.sh` lines; every `docker compose` line works in PowerShell too.

## 1. Start the app (about 30 seconds the first time, 5 after that)

```bash
docker compose up -d --wait
```

Open <http://localhost:8080>. You should see "Recently Read" with twelve books.
If this is the very first start it also built the image, generated an app key,
migrated and seeded; every later start just comes up.

If port 8080 is taken (Docker Desktop's own backend sometimes sits on 8000, other
things on 8080), pick another: `APP_PORT=8090 docker compose up -d --wait`.

## 2. Check it before anyone else does

```bash
docker compose exec app php artisan readlog:smoke
```

Seven rows, all PASS except a WARN on Google Books if you have not set a key
(search still works through Open Library; the book detail page shows no
description). Anything FAIL: read the Detail column, it says what to run.

## 3. Put it on a public URL (only if you need to)

```bash
scripts/tunnel-up.sh --smoke
```

Prints something like `https://curved-poem-fabric-1234.trycloudflare.com`, then
runs the smoke check *through* that URL from inside the container, which proves
the whole loop: out through Cloudflare, back in through nginx, database, migrations,
demo data. Give it a few seconds; the first request to a brand new hostname is
slower.

Paste the URL into the chat. That is the demo.

What is happening: the `tunnel` profile in `compose.yaml` runs Cloudflare's
`cloudflared` in quick-tunnel mode. It dials out from your machine, so no port is
opened on your router, and Cloudflare terminates HTTPS. The hostname is random,
lives as long as the container, and is not linked to any account. The app sees
`X-Forwarded-Proto: https` and the public host, trusts it (`TRUSTED_PROXIES=*` in
compose, see `config/trustedproxy.php`), and generates https links and a Secure
session cookie accordingly.

Without the wrapper script, the same thing by hand:

```bash
docker compose --profile tunnel up tunnel      # foreground; the URL is in the output; Ctrl-C closes it
```

## 4. During the demo

- Reader switcher, top right: flip between Mikko and Sam Reader and watch the
  library, stats and edit rights change. Both have logged Dune: one catalogue row,
  two entries.
- Log a book: search hits the real Open Library live. "dune herbert" is a safe
  query. Google Books joins in only if `GOOGLE_BOOKS_API_KEY` is set in a `.env`
  next to `compose.yaml` before `docker compose up`.
- Anyone on the URL is acting as whichever reader they pick. There is no login;
  that is a known, recorded gap (STATUS.md), and it is why the tunnel is closed
  the moment the demo ends.

## 5. Shut it down

```bash
scripts/tunnel-down.sh        # closes the public URL; the app stays up on localhost
docker compose down           # stops the app too; the database is kept in a volume
docker compose down -v        # ...and deletes the database, back to a clean seed next start
```

Closing the tunnel is the one that matters when the call ends. The random hostname
stops resolving within seconds of the container going away.

## If something is off

| Symptom | Likely cause | Do this |
| --- | --- | --- |
| `docker compose up` sits at "Waiting" | first image build, or Docker Hub slow | `docker compose logs app` and wait; a build is 1 to 2 minutes cold |
| `localhost:8080` refuses | port in use | `APP_PORT=8090 docker compose up -d --wait` |
| Home page shows "No books logged yet" | seed did not run | `docker compose exec app php artisan db:seed --force` |
| Tunnel URL prints but a browser gets a Cloudflare error page | new hostname still propagating | wait 10 seconds and reload |
| Links on the public URL say `http://localhost` | `TRUSTED_PROXIES` missing | it is set in `compose.yaml`; if you run the app outside compose, set `TRUSTED_PROXIES=127.0.0.1` in `.env` |
| Search says "No books found." for everything | the container cannot reach openlibrary.org | `docker compose exec app wget -qO- https://openlibrary.org/ >/dev/null && echo ok` |
| `scripts/tunnel-up.sh` gives no URL after 60s | image pull or Cloudflare unreachable | `docker compose --profile tunnel logs tunnel` |

## Running the app without Docker

`php artisan serve` on port 8000 with `cloudflared` installed natively:

```bash
winget install Cloudflare.cloudflared          # Windows; brew install cloudflared on macOS
cloudflared tunnel --url http://localhost:8000
```

and set `TRUSTED_PROXIES=127.0.0.1` in `.env` first, so the app believes the
forwarded scheme from the local cloudflared. Everything else is the same.

## A stable hostname instead of a random one (optional, still free)

A *named* tunnel gives you `readlog.yourdomain.example` every time instead of a
fresh random hostname. It needs a free Cloudflare account and a domain whose DNS
is on Cloudflare; the tunnel itself is free.

1. In the Cloudflare dashboard: **Zero Trust > Networks > Tunnels > Create a
   tunnel**, type Cloudflared, name it `readlog`. Copy the token it shows.
2. On the tunnel's **Public Hostname** tab add one: subdomain `readlog`, your
   domain, service type **HTTP**, URL `web:80`. (That is the compose service name;
   cloudflared runs inside the compose network.)
3. Put the token in a `.env` next to `compose.yaml`: `CLOUDFLARE_TUNNEL_TOKEN=...`
4. Start it: `docker compose --profile tunnel-named up -d tunnel-named`. Stop it
   with `docker compose --profile tunnel-named rm --stop --force tunnel-named`.

The hostname is yours for as long as the tunnel exists in the dashboard, and it
only answers while the container is running.

## What was checked, and what you must check yourself

Everything about the app behind a proxy is verified in the repository: five tests
in `tests/Feature/Http/BehindProxyTest.php`, and the compose stack was driven
locally with the exact headers cloudflared sends (`Host` plus
`X-Forwarded-Proto: https`), producing https links and Secure cookies. What could
not be verified from where this was written is the tunnel itself, because that
environment cannot open one. Before the first real demo, run through this once:

1. `scripts/tunnel-up.sh --smoke` prints a URL and every row is PASS or WARN.
2. Open the URL in a private browser window. Home page renders with covers.
3. Switch reader in the top-right; the library changes. (This proves the session
   cookie works on the https host.)
4. Log a book through search; it appears in the library. (This proves POST and
   CSRF work through the tunnel.)
5. `scripts/tunnel-down.sh`; reload the public URL; it should fail within seconds.
