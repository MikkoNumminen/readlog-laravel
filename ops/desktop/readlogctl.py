#!/usr/bin/env python3
"""readlogctl: the desktop control for the locally hosted ReadLog.

One place to turn the app on and off, see whether it is up, put it on a public
URL for a demo and take it off again. The same idea as ragctl for the RAG chat
stack, sized for a much smaller thing: two containers and, on demand, a tunnel.

Runs on the Windows side with the stock python.exe (stdlib only, no venv), because
that is where Docker Desktop and the browser are. "ReadLog Control.bat" next to
this file opens it as a console; ops/desktop/install.ps1 puts a shortcut to that
on the desktop.

    python ops/desktop/readlogctl.py              interactive: board + menu
    python ops/desktop/readlogctl.py status       one-shot board
    python ops/desktop/readlogctl.py watch        live board, Ctrl-C leaves
    python ops/desktop/readlogctl.py on           docker compose up, wait until healthy
    python ops/desktop/readlogctl.py off          docker compose down (data stays)
    python ops/desktop/readlogctl.py open         open the app in the browser
    python ops/desktop/readlogctl.py tunnel on    public URL through a Cloudflare quick tunnel
    python ops/desktop/readlogctl.py tunnel off
    python ops/desktop/readlogctl.py logs         follow the app log
    python ops/desktop/readlogctl.py smoke        readlog:smoke inside the container
    python ops/desktop/readlogctl.py embed        readlog:embed (the AI search index)
    python ops/desktop/readlogctl.py warm         load both Ollama models so the first question is fast
    python ops/desktop/readlogctl.py ask "..."    ask the library from here
    python ops/desktop/readlogctl.py doctor       board plus versions and paths

Settings come from the repo's .env, the same file compose reads: APP_PORT,
OLLAMA_URL, and OLLAMA_DOCKER_NETWORK when Ollama runs as another compose
project's container (see compose.ollama.yaml).
"""

from __future__ import annotations

import json
import os
import re
import shutil
import subprocess
import sys
import time
import urllib.error
import urllib.request
import webbrowser
from pathlib import Path

REPO = Path(__file__).resolve().parents[2]
ENV_FILE = REPO / ".env"
COMPOSE = ["docker", "compose"]
TUNNEL_URL_FILE = REPO / ".tunnel-url"      # same file scripts/tunnel-up.sh writes
TUNNEL_PID_FILE = REPO / ".tunnel.pid"      # only for a natively run cloudflared
TUNNEL_LOG_FILE = REPO / ".tunnel.log"
CLOUDFLARED_IMAGE = "cloudflare/cloudflared"
CLOUDFLARED_LOCAL = Path(os.environ.get("LOCALAPPDATA", "")) / "Programs" / "cloudflared" / "cloudflared.exe"
LIVE_SNAPSHOT = "https://mikkonumminen.dev/readlog-laravel"
IS_WINDOWS = os.name == "nt"


# --- settings ---------------------------------------------------------------


def dotenv() -> dict[str, str]:
    """KEY=value pairs from the repo .env, if there is one. Compose reads the
    same file, so this is what the containers were started with."""
    values: dict[str, str] = {}
    if not ENV_FILE.exists():
        return values
    for line in ENV_FILE.read_text(encoding="utf-8", errors="ignore").splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, _, value = line.partition("=")
        values[key.strip()] = value.strip().strip('"').strip("'")
    return values


ENV = dotenv()
APP_PORT = ENV.get("APP_PORT", "8080")
APP_URL = f"http://127.0.0.1:{APP_PORT}"
OLLAMA_NETWORK = ENV.get("OLLAMA_DOCKER_NETWORK", "")


def compose_files() -> list[str]:
    """-f flags for every compose invocation. compose.ollama.yaml joins the app
    to another project's network, which is how the author's Ollama container is
    reached; it is only added when that network is named in .env."""
    files = ["-f", str(REPO / "compose.yaml")]
    if OLLAMA_NETWORK:
        files += ["-f", str(REPO / "compose.ollama.yaml")]
    return files


def compose(*args: str, timeout: int = 60, capture: bool = True) -> tuple[int, str]:
    return run(COMPOSE + compose_files() + list(args), timeout=timeout, capture=capture)


# --- terminal ---------------------------------------------------------------

if IS_WINDOWS:
    os.system("")  # turns on ANSI colours in the classic console host
# The console host defaults to a legacy code page that has no dots or triangles;
# print UTF-8, and if even that is refused fall back to ASCII glyphs.
try:
    # line_buffering: our own lines must land before a child process's output
    sys.stdout.reconfigure(encoding="utf-8", errors="replace", line_buffering=True)  # type: ignore[union-attr]
    UNICODE = True
except (AttributeError, ValueError):
    UNICODE = False
USE_COLOR = sys.stdout.isatty() and os.environ.get("TERM") != "dumb"


def c(text: str, code: str) -> str:
    return f"\033[{code}m{text}\033[0m" if USE_COLOR else text


GLYPH = (
    {"ok": ("●", "32"), "busy": ("◐", "33"), "warn": ("▲", "33"), "down": ("○", "31"), "off": ("○", "90"), "info": ("·", "0")}
    if UNICODE
    else {"ok": ("*", "32"), "busy": ("~", "33"), "warn": ("!", "33"), "down": ("x", "31"), "off": ("-", "90"), "info": (".", "0")}
)


def line(label: str, state: str, detail: str) -> str:
    glyph, code = GLYPH.get(state, ("?", "0"))
    return f"  {c(glyph, code)} {label:<18} {c(detail, code)}"


def clear() -> None:
    os.system("cls" if IS_WINDOWS else "clear")


# --- subprocess -------------------------------------------------------------


def run(cmd: list[str], timeout: int = 60, capture: bool = True, cwd: Path = REPO) -> tuple[int, str]:
    try:
        p = subprocess.run(
            cmd,
            cwd=cwd,
            capture_output=capture,
            text=True,
            errors="replace",
            timeout=timeout,
        )
        return p.returncode, ((p.stdout or "") + (p.stderr or "")) if capture else ""
    except FileNotFoundError:
        return 127, f"{cmd[0]}: not found"
    except subprocess.TimeoutExpired:
        return 124, "timed out"


def http_get(url: str, timeout: float = 4.0) -> tuple[int | None, float, str]:
    """(status, milliseconds, body head). status None means no answer."""
    started = time.monotonic()
    try:
        with urllib.request.urlopen(url, timeout=timeout) as r:
            body = r.read(400).decode("utf-8", errors="replace")
            return r.status, (time.monotonic() - started) * 1000, body
    except urllib.error.HTTPError as e:
        return e.code, (time.monotonic() - started) * 1000, ""
    except Exception:
        return None, (time.monotonic() - started) * 1000, ""


# --- checks (each returns (state, detail)) -----------------------------------


def check_docker() -> tuple[str, str]:
    rc, _ = run(["docker", "info", "--format", "{{.ServerVersion}}"], timeout=10)
    return ("ok", "engine running") if rc == 0 else ("down", "not running: start Docker Desktop")


def compose_services() -> dict[str, str]:
    """service name -> health or state, tolerant of array and NDJSON output."""
    rc, out = compose("ps", "--all", "--format", "json", timeout=20)
    if rc != 0:
        return {}
    rows: list[dict] = []
    text = out.strip()
    try:
        parsed = json.loads(text)
        rows = parsed if isinstance(parsed, list) else [parsed]
    except json.JSONDecodeError:
        for ln in text.splitlines():
            try:
                rows.append(json.loads(ln))
            except json.JSONDecodeError:
                continue
    return {r.get("Service", "?"): (r.get("Health") or r.get("State") or "?") for r in rows}


def check_service(services: dict[str, str], name: str) -> tuple[str, str]:
    s = services.get(name)
    if s is None:
        return ("off", "not running")
    low = s.lower()
    if "healthy" in low and "unhealthy" not in low:
        return ("ok", s)
    if low == "running":
        return ("ok", s)
    if "starting" in low or "created" in low or "restart" in low:
        return ("busy", s)
    return ("down", s)


def check_app() -> tuple[str, str]:
    status, ms, _ = http_get(APP_URL + "/up")
    if status == 200:
        return ("ok", f"{APP_URL}  ({max(1, round(ms))} ms)")
    if status is None:
        return ("off", f"{APP_URL} not answering")
    return ("down", f"{APP_URL}/up answered {status}")


def cloudflared_exe() -> str | None:
    found = shutil.which("cloudflared")
    if found:
        return found
    return str(CLOUDFLARED_LOCAL) if CLOUDFLARED_LOCAL.exists() else None


def cloudflared_image_present() -> bool:
    rc, out = run(["docker", "image", "inspect", CLOUDFLARED_IMAGE + ":latest"], timeout=10)
    return rc == 0


def native_tunnel_pid() -> int | None:
    """PID of a cloudflared we started ourselves, if it is still alive."""
    if not TUNNEL_PID_FILE.exists():
        return None
    try:
        pid = int(TUNNEL_PID_FILE.read_text().strip())
    except ValueError:
        return None
    if IS_WINDOWS:
        rc, out = run(["tasklist", "/FI", f"PID eq {pid}", "/FO", "CSV", "/NH"], timeout=10)
        alive = rc == 0 and "cloudflared" in out.lower()
    else:
        try:
            os.kill(pid, 0)
            alive = True
        except OSError:
            alive = False
    return pid if alive else None


def tunnel_url() -> str:
    if TUNNEL_URL_FILE.exists():
        return TUNNEL_URL_FILE.read_text().strip()
    return ""


def check_tunnel(services: dict[str, str]) -> tuple[str, str]:
    url = tunnel_url()
    if native_tunnel_pid():
        return ("ok", url) if url else ("busy", "cloudflared starting, no URL yet")
    state = services.get("tunnel", "")
    if state and state.lower() in ("running", "healthy"):
        return ("ok", url) if url else ("busy", "tunnel container starting, no URL yet")
    return ("off", "off (private to this machine)")


def check_ollama(services: dict[str, str]) -> tuple[str, str]:
    """Asked from inside the app container, so the answer is what the app sees,
    including a container-name URL that this machine could not resolve."""
    if services.get("app") is None:
        return ("off", "unknown until the app is on")
    if ENV.get("AI_SEARCH_ENABLED", "true").lower() in ("0", "false", "no", "off"):
        return ("off", "AI search disabled (AI_SEARCH_ENABLED=false)")
    rc, out = compose(
        "exec", "-T", "app", "sh", "-c",
        'curl -s -m 3 "${OLLAMA_URL:-http://localhost:11434}/api/tags" && echo && echo "URL=$OLLAMA_URL"',
        timeout=15,
    )
    url_line = next((ln for ln in out.splitlines() if ln.startswith("URL=")), "URL=?")
    url = url_line[4:] or "?"
    if rc != 0 or '"models"' not in out:
        return ("warn", f"{url} not reachable: search falls back to title matching")
    try:
        names = [m.get("name", "") for m in json.loads(out.splitlines()[0]).get("models", [])]
    except (json.JSONDecodeError, IndexError):
        names = []
    embed = ENV.get("OLLAMA_EMBED_MODEL", "nomic-embed-text")
    chat = ENV.get("OLLAMA_CHAT_MODEL", "qwen2.5:7b")
    missing = [m for m in (embed, chat) if not any(n == m or n.startswith(m + ":") or n.split(":")[0] == m for n in names)]
    if missing:
        return ("warn", f"{url} up, model missing: {', '.join(missing)} (ollama pull ...)")
    return ("ok", f"{url}  ({embed}, {chat})")


def board(with_ollama: bool = True) -> tuple[bool, dict[str, str]]:
    """Print the status board. Returns (app is up, services)."""
    docker_state, docker_detail = check_docker()
    services = compose_services() if docker_state == "ok" else {}
    app_state, app_detail = check_app()
    print()
    print(f"  {c('ReadLog Control', '1')}    {REPO}")
    print()
    print(line("docker", docker_state, docker_detail))
    print(line("app (php-fpm)", *check_service(services, "app")))
    print(line("web (nginx)", *check_service(services, "web")))
    print(line("answers", app_state, app_detail))
    print(line("tunnel", *check_tunnel(services)))
    if with_ollama:
        print(line("ollama (AI search)", *check_ollama(services)))
    print(line("live snapshot", "info", LIVE_SNAPSHOT + "  (static, always up)"))
    print()
    if app_state == "ok":
        print(f"  readlog is {c('ON', '32')}  ->  {APP_URL}" + (f"   public: {tunnel_url()}" if check_tunnel(services)[0] == "ok" else ""))
    else:
        print(f"  readlog is {c('OFF', '31')}")
    print()
    return app_state == "ok", services


# --- actions ----------------------------------------------------------------


def do_on() -> int:
    if check_docker()[0] != "ok":
        print("  Docker Desktop is not running. Start it, wait for the whale to settle, then try again.")
        return 1
    print("  == docker compose up (first start builds the image; a few minutes) ==")
    rc, _ = compose("up", "-d", "--wait", "--wait-timeout", "240", timeout=900, capture=False)
    if rc != 0:
        print(c("  compose up failed; `docker compose logs app` has the reason.", "31"))
        return rc
    # Best effort, and only when Ollama answers: the first question on a cold
    # GPU took 47 s, the next 3 s; paying that here, once, is what a demo wants.
    if check_ollama(compose_services())[0] == "ok":
        print("  == warming the AI models (a minute at most; skip with Ctrl-C) ==")
        try:
            compose("exec", "-T", "app", "php", "artisan", "readlog:ask", "--warm", timeout=300, capture=False)
        except KeyboardInterrupt:
            print("  (warm-up skipped)")
    return 0


def do_warm() -> int:
    rc, _ = compose("exec", "-T", "app", "php", "artisan", "readlog:ask", "--warm", timeout=300, capture=False)
    return rc


def do_ask(question: str) -> int:
    if question.strip() == "":
        try:
            question = input("  question > ")
        except (EOFError, KeyboardInterrupt):
            print()
            return 0
    rc, _ = compose("exec", "-T", "app", "php", "artisan", "readlog:ask", question, timeout=300, capture=False)
    return rc


def do_off() -> int:
    if native_tunnel_pid():
        do_tunnel_off()
    print("  == docker compose down (the database volume stays) ==")
    rc, _ = compose("--profile", "tunnel", "down", timeout=180, capture=False)
    return rc


def do_open() -> int:
    if check_app()[0] != "ok":
        print("  The app is not answering; turn it on first.")
        return 1
    webbrowser.open(APP_URL)
    print(f"  opened {APP_URL}")
    return 0


def do_logs() -> int:
    print("  following the app log (Ctrl-C returns to the menu)")
    try:
        compose("logs", "-f", "--tail", "50", "app", "web", timeout=10**7, capture=False)
    except KeyboardInterrupt:
        print()
    return 0


def do_smoke() -> int:
    rc, _ = compose("exec", "-T", "app", "php", "artisan", "readlog:smoke", timeout=120, capture=False)
    return rc


def do_embed() -> int:
    print("  == readlog:embed: (re)computes the AI search index for every entry ==")
    rc, _ = compose("exec", "-T", "app", "php", "artisan", "readlog:embed", timeout=900, capture=False)
    return rc


def do_tunnel_on() -> int:
    if check_app()[0] != "ok":
        print("  The app is not answering; turn it on first.")
        return 1
    if native_tunnel_pid() or compose_services().get("tunnel", "").lower() in ("running", "healthy"):
        print(f"  a tunnel is already up: {tunnel_url() or '(URL pending)'}")
        return 0
    if TUNNEL_URL_FILE.exists():
        TUNNEL_URL_FILE.unlink()

    # Two ways to run cloudflared, same result. The compose profile is what
    # DEMO.md documents; the native binary is the way round a machine where the
    # image cannot be pulled (this one, some days), and needs no Docker network.
    if cloudflared_image_present():
        print("  == cloudflared quick tunnel (compose profile) ==")
        rc, out = compose("--profile", "tunnel", "up", "-d", "--force-recreate", "tunnel", timeout=120)
        if rc != 0:
            print(out)
            return rc
        url = wait_for_url(lambda: compose("--profile", "tunnel", "logs", "tunnel", timeout=20)[1])
    else:
        exe = cloudflared_exe()
        if not exe:
            print("  No cloudflared. Either `docker pull cloudflare/cloudflared` or put cloudflared.exe on PATH")
            print(f"  or at {CLOUDFLARED_LOCAL} (ops/desktop/install.ps1 does the latter).")
            return 1
        print(f"  == cloudflared quick tunnel (native: {exe}) ==")
        log = open(TUNNEL_LOG_FILE, "w", encoding="utf-8")
        flags = 0
        if IS_WINDOWS:
            flags = subprocess.CREATE_NO_WINDOW | subprocess.CREATE_NEW_PROCESS_GROUP  # type: ignore[attr-defined]
        proc = subprocess.Popen(
            [exe, "tunnel", "--no-autoupdate", "--url", APP_URL],
            stdout=log,
            stderr=subprocess.STDOUT,
            stdin=subprocess.DEVNULL,
            creationflags=flags,
            cwd=REPO,
        )
        TUNNEL_PID_FILE.write_text(str(proc.pid))
        url = wait_for_url(lambda: TUNNEL_LOG_FILE.read_text(encoding="utf-8", errors="ignore") if TUNNEL_LOG_FILE.exists() else "")
    if not url:
        print(c("  no tunnel URL after 60 s; see .tunnel.log or `docker compose --profile tunnel logs tunnel`", "31"))
        return 1
    TUNNEL_URL_FILE.write_text(url + "\n")
    print()
    print(f"    {c(url, '1;32')}")
    print()
    print("  Anyone with that address can use the app until you turn the tunnel off.")
    print("  It can take a few seconds to become reachable the first time.")
    return 0


def wait_for_url(read_log, seconds: int = 60) -> str:
    for _ in range(seconds):
        m = re.search(r"https://[a-z0-9-]+\.trycloudflare\.com", read_log())
        if m:
            return m.group(0)
        time.sleep(1)
    return ""


def do_tunnel_off() -> int:
    pid = native_tunnel_pid()
    if pid:
        if IS_WINDOWS:
            run(["taskkill", "/PID", str(pid), "/T", "/F"], timeout=20)
        else:
            os.kill(pid, 15)
        print(f"  cloudflared (pid {pid}) stopped")
    if compose_services().get("tunnel"):
        compose("--profile", "tunnel", "rm", "-sf", "tunnel", timeout=60)
        print("  tunnel container removed")
    for f in (TUNNEL_URL_FILE, TUNNEL_PID_FILE):
        if f.exists():
            f.unlink()
    print("  the app is private to this machine again")
    return 0


def do_doctor() -> int:
    print("  == versions ==")
    for cmd in (["docker", "--version"], ["docker", "compose", "version", "--short"]):
        rc, out = run(cmd, timeout=15)
        print(f"  {' '.join(cmd)}: {out.strip() if rc == 0 else 'not available'}")
    exe = cloudflared_exe()
    print(f"  cloudflared.exe: {exe or 'not found (PATH or ' + str(CLOUDFLARED_LOCAL) + ')'}")
    print(f"  cloudflared image: {'present' if cloudflared_image_present() else 'not pulled'}")
    print(f"  compose files: {' '.join(compose_files()[1::2])}")
    print(f"  .env: {ENV_FILE if ENV_FILE.exists() else 'none (compose defaults)'}")
    if OLLAMA_NETWORK:
        print(f"  ollama network: {OLLAMA_NETWORK}")
    status, ms, _ = http_get(LIVE_SNAPSHOT + "/", timeout=8)
    print(f"  live snapshot: {LIVE_SNAPSHOT} -> {status if status else 'no answer'} ({ms:.0f} ms)")
    print()
    return 0


def do_watch() -> int:
    print("  live board, Ctrl-C leaves")
    try:
        while True:
            clear()
            board()
            time.sleep(4)
    except KeyboardInterrupt:
        print()
    return 0


MENU = """  1) on        start the app                6) tunnel off  private again
  2) off       stop it (data stays)          7) smoke       readlog:smoke in the container
  3) open      app in the browser            8) embed       rebuild the AI search index
  4) logs      follow the app log            9) warm        load the AI models now
  5) tunnel on public URL for a demo         a) ask         ask the library a question
  w) watch     live board                    d) doctor      versions and paths
  q) quit"""

ALIASES = {
    "1": "on", "2": "off", "3": "open", "4": "logs", "5": "tunnel on", "6": "tunnel off",
    "7": "smoke", "8": "embed", "9": "warm", "a": "ask", "w": "watch", "d": "doctor", "s": "status", "q": "quit",
    "up": "on", "down": "off", "start": "on", "stop": "off", "exit": "quit",
}


def dispatch(command: str) -> int:
    word, _, rest = command.strip().partition(" ")
    if word.lower() == "ask":
        return do_ask(rest.strip().strip('"'))
    command = ALIASES.get(command.strip().lower(), command.strip().lower())
    rc = act(command)
    if command in ("on", "off", "tunnel on", "tunnel off", "doctor"):
        board(with_ollama=command != "off")  # show what the action left behind
    return rc


def act(command: str) -> int:
    match command:
        case "on":
            return do_on()
        case "off":
            return do_off()
        case "open":
            return do_open()
        case "logs":
            return do_logs()
        case "smoke":
            return do_smoke()
        case "embed":
            return do_embed()
        case "warm":
            return do_warm()
        case "ask":
            return do_ask("")
        case "tunnel on":
            return do_tunnel_on()
        case "tunnel off":
            return do_tunnel_off()
        case "watch":
            return do_watch()
        case "doctor":
            return do_doctor()
        case "status" | "":
            board()
            return 0
        case _:
            print(f"  ? {command}")
            return 2


def repl() -> int:
    board()
    while True:
        print(MENU)
        try:
            choice = input("  > ")
        except (EOFError, KeyboardInterrupt):
            print()
            return 0
        if ALIASES.get(choice.strip().lower(), choice.strip().lower()) == "quit":
            return 0
        print()
        dispatch(choice)


def main(argv: list[str]) -> int:
    if not argv:
        return repl() if sys.stdin.isatty() else dispatch("status")
    return dispatch(" ".join(argv))


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
