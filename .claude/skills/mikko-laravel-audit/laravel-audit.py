#!/usr/bin/env python3
"""Deterministic pre-pass for mikko-laravel-audit: pre-flight + candidate gathering.

Runs the two phases of the audit that need no judgement, so the model spends
tokens only on judging. One invocation replaces the hand-composed globs and
git-grep passes of Phases 0 and 1.5:

    python .claude/skills/mikko-laravel-audit/laravel-audit.py \\
        [--source PATH] [--json] [--force] [--out FILE]

Requires Python 3.9+. Exit codes:
    0   proceed; candidate map produced
    2   pre-flight bail (the output line starts with "pre-flight: aborting")
    3   one or more seed greps ERRORED (healthy checks' candidates still print)
    64  bad invocation (unknown flag, --source path missing)
A bare interpreter failure (wrong script path) also exits 2 but prints no
"pre-flight:" line, so key on the line, not only on the code.

Design rules, all learned the hard way (DECISIONS.md 141 and the PR 27 review):
- git grep runs as an argument vector with ``-e`` and ``--``, never through a
  shell, with ``-I`` (skip binaries) and color forced off, and every row is
  validated as path:digits:text. Malformed rows are dropped, not trusted.
- Nothing is allowed to look like a clean zero unless the search really ran:
  a failed grep is an ERROR on that check, a check whose scope directories are
  absent is SKIPPED (named, with the missing paths), and a tree whose PHP is
  not git-tracked is a pre-flight bail, because git grep reads tracked files.
- The seed dict below is the executable source of truth; the fenced block in
  SKILL.md documents it and is the python-less fallback. Change them together.
"""

import argparse
import json
import subprocess
import sys
from pathlib import Path

DESCRIPTION = "Deterministic pre-pass for mikko-laravel-audit (pre-flight + candidates)."

# check id -> (ERE pattern, [pathspecs]).  Merged ids share one seed between two
# checks: B12 = B1+B2, C12 = C1+C2, E12 = E1+E2; B1_blade feeds B1.  Judges tag
# findings with the specific check id.
SEEDS = {
    "A1":       (r"\benv\(",                                              ["app", "routes", "resources", "database"]),
    "A2":       (r"->singleton\(|->scoped\(|->instance\(",                ["app"]),
    "A3":       (r"\bsession\(|\brequest\(|\bauth\(|Session::|Auth::",    ["app/Services", "app/Support", "app/Models"]),
    "A4":       (r"Config::set|config\(\[",                               ["app"]),
    "B12":      (r"->get\(\)|::all\(\)",                                  ["app"]),
    "B1_blade": (r"->[a-zA-Z_]+->",                                       ["resources/views"]),
    "B3":       (r"->all\(\)|guarded|forceFill",                          ["app"]),
    "B4":       (r"DB::raw|whereRaw|selectRaw|orderByRaw|havingRaw|DB::statement\(|->statement\(", ["app", "database"]),
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
    "E12":      (r"catch[[:space:]]*\(",                                  ["app", "tests"]),
    "E3":       (r"storage_path\(|sys_get_temp_dir\(|tempnam\(",          ["app", "tests"]),
    "E4":       (r"Cache::|\bcache\(",                                    ["app"]),
    "E5":       (r"\bnow\(\)|\btoday\(\)|Carbon::now|CarbonImmutable::now", ["app", "database"]),
}

TEXT_CAP = 110  # chars of matched line kept per candidate


def run_git(root: Path, *args: str):
    """git with color off, or None when git itself is not on PATH."""
    try:
        return subprocess.run(
            ["git", "-C", str(root), "-c", "color.ui=never", "-c", "color.grep=never", *args],
            capture_output=True, text=True, encoding="utf-8", errors="replace")
    except FileNotFoundError:
        return None


def preflight(root: Path) -> tuple[dict, int, bool]:
    """Fit check. Returns (facts, exit_code, forceable).

    forceable: --force may override a fit-matrix bail, never a structural one
    (git missing, not a work tree) because the candidate pass would be
    impossible anyway.
    """
    facts = {"laravel": None, "php": None, "artisan": (root / "artisan").is_file(),
             "app_php_tracked": 0, "app_php_worktree": 0, "blade": 0,
             "untracked_php": None, "verdict": "", "note": ""}

    probe = run_git(root, "rev-parse", "--is-inside-work-tree")
    if probe is None:
        facts["verdict"] = "bail: git is not on PATH, and the candidate pass is git grep"
        return facts, 2, False
    if probe.returncode != 0 or probe.stdout.strip() != "true":
        facts["verdict"] = ("bail: not a git work tree; the candidate pass reads "
                            "tracked files. Run inside the repo, or git init first")
        return facts, 2, False

    composer = root / "composer.json"
    if composer.is_file():
        try:
            # utf-8-sig: a BOM from Notepad/PowerShell must not turn a real
            # Laravel repo into a JSONDecodeError and a wrong bail.
            data = json.loads(composer.read_text(encoding="utf-8-sig"))
            req = data.get("require")
            req = req if isinstance(req, dict) else {}
            facts["laravel"] = req.get("laravel/framework")
            facts["php"] = req.get("php")
        except (OSError, ValueError) as exc:  # ValueError covers JSON + decode errors
            facts["note"] = f"composer.json unreadable: {exc}"

    app = root / "app"
    if app.is_dir():
        facts["app_php_worktree"] = sum(1 for _ in app.rglob("*.php"))
    tracked = run_git(root, "ls-files", "-z", "--", "app")
    if tracked and tracked.returncode == 0:
        facts["app_php_tracked"] = sum(
            1 for f in tracked.stdout.split("\0") if f.endswith(".php"))
    views = root / "resources" / "views"
    if views.is_dir():
        facts["blade"] = sum(1 for _ in views.rglob("*.blade.php"))

    # -z disables core.quotePath, so a non-ASCII filename still ends in .php;
    # --others --exclude-standard is exactly "untracked and not ignored", the
    # one file class git grep cannot see.
    others = run_git(root, "ls-files", "--others", "--exclude-standard", "-z")
    if others and others.returncode == 0:
        facts["untracked_php"] = sum(
            1 for f in others.stdout.split("\0") if f.endswith(".php"))

    php_anywhere = (facts["app_php_worktree"]
                    or any(root.glob("*.php")) or any(root.glob("*/*.php"))
                    or any(root.glob("*/*/*.php")))

    if facts["laravel"] and facts["app_php_tracked"] >= 10:
        facts["verdict"] = "proceed"
        return facts, 0, False
    if facts["laravel"] and facts["app_php_tracked"] >= 1:
        facts["verdict"] = "proceed"
        facts["note"] = (facts["note"] + "; " if facts["note"] else "") + \
            "small tracked surface, patterns may not show at scale"
        return facts, 0, False
    if facts["laravel"] and facts["app_php_worktree"] > 0:
        facts["verdict"] = (f"bail: app/ holds {facts['app_php_worktree']} PHP files but none are "
                            "git-tracked, and the candidate pass reads tracked files; git add first")
        return facts, 2, True
    if facts["laravel"]:
        facts["verdict"] = "bail: framework installed but no app code found; pass --source"
        return facts, 2, True
    if php_anywhere:
        facts["verdict"] = "bail: PHP but not Laravel; try /mikko-audit"
        return facts, 2, True
    facts["verdict"] = "bail: not a PHP codebase; try /mikko-audit or /mikko-ai-codegen-smell-audit"
    return facts, 2, True


def gather(root: Path):
    """Run every seed. Returns (candidates, counts, errors, skipped, partial)."""
    candidates, counts, errors, skipped, partial = {}, {}, {}, {}, {}
    for check, (pattern, paths) in SEEDS.items():
        existing = [p for p in paths if (root / p).exists()]
        missing = [p for p in paths if not (root / p).exists()]
        candidates[check] = []
        counts[check] = 0
        if not existing:
            # A named skip, never an ordinary zero: "searched and clean" and
            # "never searched" must stay distinguishable.
            skipped[check] = f"paths missing: {', '.join(missing)}"
            continue
        if missing:
            partial[check] = f"not searched: {', '.join(missing)}"
        # Argument vector, explicit -e and -- : dash-leading and quote-bearing
        # patterns are data here.  -I skips binaries, so no "Binary file ..."
        # rows can masquerade as candidates.
        proc = run_git(root, "grep", "-I", "-nE", "-e", pattern, "--", *existing)
        if proc is None or proc.returncode not in (0, 1):
            stderr = "" if proc is None else proc.stderr.strip()
            errors[check] = stderr or "git grep failed"
            continue
        for line in proc.stdout.splitlines():
            path, _, rest = line.partition(":")
            lineno, _, text = rest.partition(":")
            if not lineno.isdigit():
                continue  # never trust a row that is not path:digits:text
            candidates[check].append(
                {"file": path, "line": lineno, "text": text.strip()[:TEXT_CAP]})
        counts[check] = len(candidates[check])
    return candidates, counts, errors, skipped, partial


def render_map(candidates: dict) -> str:
    out, group = [], ""
    for check in SEEDS:
        if not candidates[check]:
            continue
        if check[0] != group:
            group = check[0]
            out.append(f"##### GROUP {group}")
        for row in candidates[check]:
            out.append(f"{check} {row['file']}:{row['line']}  {row['text']}")
    return "\n".join(out)


def main() -> int:
    # Matched lines can carry any text the codebase does; a cp1252 Windows
    # console would crash print() on the first non-ASCII character.
    if hasattr(sys.stdout, "reconfigure"):
        sys.stdout.reconfigure(encoding="utf-8", errors="replace")

    class Parser(argparse.ArgumentParser):
        def error(self, message):  # usage errors must not collide with exit 2
            self.print_usage(sys.stderr)
            print(f"error: {message}", file=sys.stderr)
            sys.exit(64)

    ap = Parser(description=DESCRIPTION)
    ap.add_argument("--source", default=".", help="repo root to audit (default: cwd)")
    ap.add_argument("--json", action="store_true", help="machine-readable output")
    ap.add_argument("--force", action="store_true",
                    help="override a fit-matrix bail (never a structural one) and gather anyway")
    ap.add_argument("--out", metavar="FILE",
                    help="write the full candidate map here; stdout then carries only the "
                         "summary, so a tool-output cap cannot silently truncate groups")
    args = ap.parse_args()

    root = Path(args.source).resolve()
    if not root.is_dir():
        print(f"error: --source path does not exist or is not a directory: {root}",
              file=sys.stderr)
        return 64

    facts, code, forceable = preflight(root)
    forced = False
    if code != 0 and args.force and forceable:
        forced, code = True, 0

    if code == 0 and not forced:
        line = (f"pre-flight: Laravel codebase confirmed (laravel/framework {facts['laravel']}, "
                f"{facts['app_php_tracked']} tracked app/ files, {facts['blade']} Blade templates, "
                f"{'unknown' if facts['untracked_php'] is None else facts['untracked_php']} untracked PHP).")
    elif forced:
        line = f"pre-flight: OVERRIDDEN by --force. Original verdict: {facts['verdict']}"
    else:
        line = f"pre-flight: aborting. {facts['verdict']}"
    if facts["note"]:
        line += f" Note: {facts['note']}"

    if code != 0:
        print(json.dumps({"preflight": facts}, indent=2) if args.json else line)
        return code

    candidates, counts, errors, skipped, partial = gather(root)
    if errors:
        code = 3

    if args.json:
        payload = {"preflight": facts, "forced": forced, "counts": counts,
                   "errors": errors, "skipped": skipped, "partial": partial,
                   "candidates": candidates}
        blob = json.dumps(payload, indent=2)
        if args.out:
            Path(args.out).write_text(blob, encoding="utf-8")
            print(json.dumps({"preflight": facts, "forced": forced, "counts": counts,
                              "errors": errors, "skipped": skipped, "partial": partial,
                              "map": args.out}, indent=2))
        else:
            print(blob)
        return code

    print(line + " Proceeding.")
    total = sum(counts.values())
    zero = [c for c, n in counts.items()
            if n == 0 and c not in errors and c not in skipped]
    print(f"candidates: {total} sites across {len(SEEDS)} checks; "
          f"zero-candidate checks: {', '.join(zero) if zero else 'none'}")
    print("counts: " + " ".join(f"{c}={counts[c]}" for c in SEEDS))
    for check, msg in skipped.items():
        print(f"SKIPPED {check}: {msg}")
    for check, msg in partial.items():
        print(f"PARTIAL {check}: {msg}")
    for check, msg in errors.items():
        print(f"ERROR {check}: {msg}")

    body = render_map(candidates)
    if args.out:
        Path(args.out).write_text(body + "\n", encoding="utf-8")
        print(f"map: {args.out} ({len(body)} chars, read per group when prompting judges)")
    else:
        print()
        print(body)
    return code


if __name__ == "__main__":
    sys.exit(main())
