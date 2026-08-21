#!/usr/bin/env python3
"""Deterministic pre-pass for mikko-laravel-audit: pre-flight + candidate gathering.

Runs the two phases of the audit that need no judgement, so the model spends
tokens only on judging. One invocation replaces the hand-composed globs and
git-grep passes of Phases 0 and 1.5:

    python .claude/skills/mikko-laravel-audit/laravel-audit.py [--source PATH] [--json]

Exit codes: 0 proceed, 2 pre-flight bail (not a Laravel codebase), 3 one or
more seed greps ERRORED (candidates for the healthy checks are still printed).

Design notes, both learned the hard way (DECISIONS.md 141, this repo):
- git grep is invoked as an argument vector with an explicit ``-e`` and ``--``,
  never through a shell. Five seed patterns begin with ``->`` and one contains
  a character a shell would eat; composing these by hand is how the first run
  silently zeroed six checks. Here the bug class cannot exist.
- A grep failure is reported as ERROR on that check, never as a silent zero.
  A zero from a tool is a claim to verify, not a fact.

The seed table below is the executable source of truth; the table in SKILL.md
is its documentation (and the manual fallback when python is unavailable).
Change them together.
"""

import argparse
import json
import subprocess
import sys
from pathlib import Path

# check id -> (ERE pattern, [pathspecs])
SEEDS = {
    "A1":       (r"\benv\(",                                              ["app", "routes", "resources", "database"]),
    "A2":       (r"->singleton\(|->scoped\(|->instance\(",                ["app"]),
    "A3":       (r"\bsession\(|\brequest\(|\bauth\(|Session::|Auth::",    ["app/Services", "app/Support", "app/Models"]),
    "A4":       (r"Config::set|config\(\[",                               ["app"]),
    "B12":      (r"->get\(\)|::all\(\)",                                  ["app"]),
    "B1_blade": (r"->[a-zA-Z_]+->",                                       ["resources/views"]),
    "B3":       (r"->all\(\)|\$guarded|forceFill",                        ["app"]),
    "B4":       (r"DB::raw|whereRaw|selectRaw|orderByRaw|havingRaw|->statement\(", ["app", "database"]),
    "B5":       (r"->exists\(\)|firstOrCreate|updateOrCreate",            ["app"]),
    "B6":       (r"strftime|ILIKE|json_extract|::text|::date|RANDOM\(\)", ["app", "database"]),
    "C12":      (r"Http::",                                               ["app"]),
    "C3":       (r"Log::",                                                ["app"]),
    "C4":       (r"file_get_contents\(.http|curl_init|new Client\(",     ["app", "tests"]),
    "D1":       (r"\{!!",                                                 ["resources/views"]),
    "D2":       (r"Route::get",                                           ["routes"]),
    "D3":       (r"::find\(|findOrFail\(",                                ["app"]),
    "D4":       (r"redirect\(|->away\(",                                  ["app"]),
    "D5":       (r"->query\(|->input\(",                                  ["app/Http"]),
    "D6":       (r"\$except|validateCsrfTokens",                          ["app", "bootstrap"]),
    "E12":      (r"catch[[:space:]]*\(",                                  ["app"]),
    "E3":       (r"storage_path\(|sys_get_temp_dir\(|tempnam\(",          ["app", "tests"]),
    "E4":       (r"Cache::|\bcache\(",                                    ["app"]),
    "E5":       (r"\bnow\(\)|\btoday\(\)|Carbon::now|CarbonImmutable::now", ["app", "database"]),
}

TEXT_CAP = 110  # chars of matched line kept per candidate


def preflight(root: Path) -> tuple[dict, int]:
    """The fit-check decision matrix from SKILL.md. Returns (facts, exit_code)."""
    composer = root / "composer.json"
    facts = {"laravel": None, "php": None, "artisan": (root / "artisan").is_file(),
             "app_php": 0, "blade": 0, "untracked_php": 0, "verdict": "", "note": ""}

    if composer.is_file():
        try:
            req = json.loads(composer.read_text(encoding="utf-8")).get("require", {})
            facts["laravel"] = req.get("laravel/framework")
            facts["php"] = req.get("php")
        except (OSError, json.JSONDecodeError) as exc:
            facts["note"] = f"composer.json unreadable: {exc}"

    app = root / "app"
    if app.is_dir():
        facts["app_php"] = sum(1 for _ in app.rglob("*.php"))
    views = root / "resources" / "views"
    if views.is_dir():
        facts["blade"] = sum(1 for _ in views.rglob("*.blade.php"))

    status = subprocess.run(
        ["git", "-C", str(root), "status", "--porcelain", "--untracked-files=all"],
        capture_output=True, text=True, encoding="utf-8", errors="replace")
    if status.returncode == 0:
        # Only '??' lines: modified tracked files are still searched by git grep
        # (it reads the worktree); untracked files are the one category the
        # candidate pass cannot see, so that is the number worth reporting.
        facts["untracked_php"] = sum(
            1 for line in status.stdout.splitlines()
            if line.startswith("??") and line.strip().endswith(".php"))

    php_anywhere = facts["app_php"] or any(root.glob("*.php")) or any(root.glob("*/*.php"))
    if facts["laravel"] and facts["app_php"] >= 10:
        facts["verdict"] = "proceed"
        return facts, 0
    if facts["laravel"] and facts["app_php"] >= 1:
        facts["verdict"] = "proceed"
        facts["note"] = "small surface, patterns may not show at scale"
        return facts, 0
    if facts["laravel"]:
        facts["verdict"] = "bail: framework installed but no app code found; pass --source"
    elif php_anywhere:
        facts["verdict"] = "bail: PHP but not Laravel; try /mikko-audit"
    else:
        facts["verdict"] = "bail: not a PHP codebase; try /mikko-audit or /mikko-ai-codegen-smell-audit"
    return facts, 2


def gather(root: Path) -> tuple[dict, dict, dict]:
    """Run every seed. Returns (candidates_by_check, counts, errors)."""
    candidates, counts, errors = {}, {}, {}
    for check, (pattern, paths) in SEEDS.items():
        existing = [p for p in paths if (root / p).exists()]
        if not existing:
            counts[check] = 0
            candidates[check] = []
            continue
        # Argument vector, explicit -e and -- : dash-leading and quote-bearing
        # patterns are data here, never options and never shell syntax.
        proc = subprocess.run(
            ["git", "-C", str(root), "grep", "-nE", "-e", pattern, "--", *existing],
            capture_output=True, text=True, encoding="utf-8", errors="replace")
        if proc.returncode not in (0, 1):
            errors[check] = proc.stderr.strip() or f"git grep exited {proc.returncode}"
            counts[check] = 0
            candidates[check] = []
            continue
        rows = []
        for line in proc.stdout.splitlines():
            path, _, rest = line.partition(":")
            lineno, _, text = rest.partition(":")
            rows.append({"file": path, "line": lineno, "text": text.strip()[:TEXT_CAP]})
        candidates[check] = rows
        counts[check] = len(rows)
    return candidates, counts, errors


def main() -> int:
    # Matched lines can carry any text the codebase does; a cp1252 Windows
    # console would crash print() on the first non-ASCII character.
    if hasattr(sys.stdout, "reconfigure"):
        sys.stdout.reconfigure(encoding="utf-8", errors="replace")
    ap = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    ap.add_argument("--source", default=".", help="repo root to audit (default: cwd)")
    ap.add_argument("--json", action="store_true", help="machine-readable output")
    args = ap.parse_args()
    root = Path(args.source).resolve()

    facts, code = preflight(root)
    line = (f"pre-flight: Laravel codebase confirmed (laravel/framework {facts['laravel']}, "
            f"{facts['app_php']} app/ files, {facts['blade']} Blade templates, "
            f"{facts['untracked_php']} untracked PHP)."
            if code == 0 else f"pre-flight: aborting. {facts['verdict']}")
    if facts["note"]:
        line += f" Note: {facts['note']}"

    if code != 0:
        print(json.dumps({"preflight": facts}, indent=2) if args.json else line)
        return code

    candidates, counts, errors = gather(root)
    if errors:
        code = 3

    if args.json:
        print(json.dumps({"preflight": facts, "counts": counts,
                          "errors": errors, "candidates": candidates}, indent=2))
        return code

    print(line + " Proceeding.")
    total = sum(counts.values())
    zero = [c for c, n in counts.items() if n == 0 and c not in errors]
    print(f"candidates: {total} sites across {len(SEEDS)} checks; "
          f"zero-candidate checks: {', '.join(zero) if zero else 'none'}")
    for check, msg in errors.items():
        print(f"ERROR {check}: {msg}")
    print()
    group = ""
    for check in SEEDS:
        if not candidates[check]:
            continue
        if check[0] != group:
            group = check[0]
            print(f"##### GROUP {group}")
        for row in candidates[check]:
            print(f"{check} {row['file']}:{row['line']}  {row['text']}")
    return code


if __name__ == "__main__":
    sys.exit(main())
