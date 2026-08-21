# CLAUDE.md

**Read [AGENTS.md](AGENTS.md) first.** It is the contract for any agent working
here: the stack, the verification loop, where code goes, and the rules that will
break a test if you ignore them. This file adds only what is specific to running
Claude Code in this repository.

## The one-line version

```bash
composer verify    # pint, phpstan level 6, pest, docs-check. All four must pass.
```

Nothing is done until that is green, and the output goes in your report as it
actually was.

## Before you change behaviour

This repository is a deliberate port of an ASP.NET Core app, and several of its
oddities are faithful copies rather than bugs. `DECISIONS.md` has 138 numbered
entries explaining them. Search it before you decide something is wrong:

```bash
grep -in "rating\|merge\|duplicate" DECISIONS.md
```

If a decision covers the behaviour, leave it alone. If you genuinely need to change
it, append a new decision entry saying why the old one no longer holds.

## Reading the codebase efficiently

Every class carries a docblock that names its .NET counterpart and explains its
reason for existing. Reading the docblock is usually faster than reading the class:

```bash
sed -n '1,40p' app/Services/ReadLogService.php
```

For structure without reading source at all, use the generated JSON:

```bash
cat docs/machine/repo-map.json      # directories and their rules
cat docs/machine/routes.json        # every route
cat docs/machine/commands.json      # every runnable command
cat docs/machine/invariants.json    # what must stay true, and the guarding test
```

## Running things on this machine

PHP is not on the PATH in every shell here, and Composer is not on it at all. Both
live in the same winget directory, so set it once and refer to it:

```bash
PHPDIR="$HOME/AppData/Local/Microsoft/WinGet/Packages/PHP.PHP.8.4_Microsoft.Winget.Source_8wekyb3d8bbwe"
export PATH="$PHPDIR:$PATH"

php vendor/bin/pest                 # php resolves now
php "$PHPDIR/composer.phar" verify  # composer does not, so name the phar
```

**`composer verify` cannot be typed literally in Git Bash here.** Only
`composer.bat` and `composer.phar` sit in that directory, and Git Bash runs
neither from the PATH: bare `composer` gives "command not found", and
`php composer.phar` from the repository root gives "Could not open input file",
because the phar is in the winget directory rather than the working directory. The
full path above is the form that works. Everywhere else, including CI and the
Dockerfile, plain `composer verify` is correct.

## Prose style

Documentation, README text, pull request bodies and any other user-facing writing
in this repository use no em dashes. Not one appears in any existing document, and
the check is quick:

```bash
grep -c $'\xe2\x80\x94' *.md docs/*.md      # every count must be 0
```

Match the surrounding voice: plain, specific, and willing to say what did not work.

## Delegating

When a task needs a fan-out (grep across many files, the same edit repeated, a
formatter pass, drafting a commit message), delegate it. When it touches the
ownership rules in `app/Services/`, the containment boundary in
`app/Services/Ai/`, or a migration, keep it on the main thread.

## What not to do

The full list is in [AGENTS.md](AGENTS.md#rules). The three that catch people:

- Do not add authentication. Its absence is a documented decision.
- Do not add npm, Vite, or any build step. `git clone` to a rendered page is one
  Composer command, and that is the point.
- Do not make the Ollama feature required. Every path through it must still work
  with Ollama switched off.
