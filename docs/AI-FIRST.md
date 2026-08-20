# AI-first readiness

How ready this repository is for a coding agent to work in, scored against a fixed
rubric so the claim is checkable rather than a feeling.

## What "AI-first" means here

> An AI-first repository is one where a competent coding agent, dropped in cold with
> no conversation history, can understand the system, make a correct change, and
> prove it did not break anything, without asking a human.

That definition drives every dimension below. It is deliberately not "a repository
that uses AI": this app's own Ollama feature counts for nothing here. What counts
is whether the repository explains itself to someone who arrives with no context
and cannot ask a follow-up question.

## The rubric

Ten dimensions, each scored 0 to 10. The overall score is the mean.

| | Dimension | What a 10 looks like |
| --- | --- | --- |
| D1 | Agent entry point | A root contract stating what the repo is, the exact commands, the golden path, and the hard "do not" list. An agent needs no other file to start safely |
| D2 | Architecture legibility | Module boundaries, dependency direction, request lifecycle, and where a new thing goes. An agent places a feature correctly without reading all the source |
| D3 | Deterministic verification loop | One documented command giving unambiguous pass or fail for the whole repository, fast and hermetic |
| D4 | Invariants and contracts | What must stay true, written down and test-enforced, with every invariant citing its guarding test |
| D5 | Task recipes | Step-by-step playbooks for the recurring changes. Following one lands in the shape a maintainer would have written |
| D6 | Decision rationale | Every non-obvious choice has a findable, indexed reason, so an agent does not "fix" a deliberate oddity |
| D7 | Machine-readable metadata | Structured artifacts a script can parse without an LLM: repo map, routes, commands, invariants |
| D8 | Code-level signposting | Files and classes say what they are for and point at the docs. Comments explain why, not what |
| D9 | Environment reproducibility | One-command setup from a bare clone, pinned versions, offline-capable suite, no undocumented dependency |
| D10 | Drift control | Documentation claims are checked automatically, so an agent can trust what it reads |

## Scoring method

Three independent graders read the repository and score every dimension, each with
a different bias so the result is not one model's opinion:

- a **strict** grader, who scores the absence of an artifact as 0 to 2 rather than 5
- a **practical** grader, who credits excellent prose even under an unconventional
  file name
- a **tooling** grader, who weights machine-readability and what CI actually enforces

Each dimension's score is the mean of the three. The overall score is the mean of
the ten dimensions.

## Current score

**9.0 out of 10**, measured 2026-08-20. The baseline before this work was **4.8**.

| | Dimension | Score | Graders |
| --- | --- | --- | --- |
| D1 | Agent entry point | 9.3 | 9.5, 8.8, 9.5 |
| D2 | Architecture legibility | 9.5 | 9.5, 9.5, 9.5 |
| D3 | Deterministic verification loop | 9.0 | 9.5, 9.5, 8.0 |
| D4 | Invariants and contracts | 9.0 | 9.0, 9.0, 9.0 |
| D5 | Task recipes | 9.0 | 9.0, 9.0, 9.0 |
| D6 | Decision rationale | 9.4 | 9.5, 9.3, 9.5 |
| D7 | Machine-readable metadata | 8.7 | 9.5, 8.0, 8.5 |
| D8 | Code-level signposting | 9.3 | 9.5, 9.0, 9.5 |
| D9 | Environment reproducibility | 8.5 | 8.0, 8.5, 9.0 |
| D10 | Drift control | 8.7 | 8.0, 9.0, 9.0 |

### How it got there

Three rounds, and the interesting part is that each round's findings came from
graders running commands rather than reading claims.

**Round one scored 4.8.** The repository had excellent prose and almost none of the
structure an agent needs: no `ARCHITECTURE.md`, no agent contract, no invariants
list, no recipes, no machine-readable anything, and no check on any of it. Drift
control scored 1.3. Two documents disagreed with each other about the test count
and both were wrong.

**Round two scored 8.5, and found two real bugs.** The verification loop was
not deterministic: `readlog:snapshot` used a fixed path for its throwaway database,
so two overlapping suite runs destroyed each other, and two graders independently
measured the suite red on roughly a third of runs. Chasing the environment
dimension turned up that `composer.json` claimed PHP `^8.3` while 17 locked Symfony
packages require 8.4.1, so a fresh clone on 8.3 fails at `composer install`. Nobody
had run that combination. Decisions 121 and 122.

**Round three scored 9.0, and found four more.** A second flake in the same test
file, where an assertion on the bare string "405" collided with any process id
containing 405. A CI step that read the wrong XML node and would have failed every
run it was ever part of. Nine classes not following a convention the contributing
guide asserted as universal. And a count check that only matched digits, so it
passed a deliberately wrong number written as a word. Decisions 125 to 129.

None of those would have been found by reading the repository. All of them were
found by running it and comparing the result to what it says about itself, which is
the argument for D10 being worth more than its one-tenth weighting.

### Still open

- Invariant rows name a test file, not a test. A test can be rewritten inside an
  existing file and the row silently loses its guard.
- External URLs are not checked for rot, only relative links.
- The em dash and path checks cover markdown, not PHP docblocks or Blade templates.
- The prompt-injection boundary has no adversarial regression fixture. Recorded in
  [TODO.md](../TODO.md) and decision 117.
- `composer verify` cannot be typed literally in Git Bash on the author's machine,
  because only `composer.bat` and `composer.phar` are in the toolchain directory.
  [CLAUDE.md](../CLAUDE.md) documents the form that works there.

## What the score does not claim

- **It is not a code quality score.** It measures whether the repository explains
  itself, not whether the code is good.
- **It is not a security score.** The prompt-injection boundary in the AI search is
  documented honestly in [ARCHITECTURE.md](../ARCHITECTURE.md#the-containment-boundary),
  including what is *not* bounded, and that honesty helps D2 and D4 while fixing
  nothing.
- **It is a judgement, made by language models reading the repository.** The rubric
  and the method are written down so the judgement can be disagreed with
  specifically rather than in general.

## Re-scoring

The rubric is fixed; re-run the three graders against it after significant change.
The parts that can rot on their own are the ones `readlog:docs-check` already
guards, which is why D10 matters more than its one-tenth weighting suggests: it is
the dimension that keeps the other nine from quietly decaying.

## Why these ten

Each dimension exists because its absence has a specific, observable cost to an
agent:

- **No entry point** and the agent invents its own conventions.
- **No architecture** and it puts business logic in a controller.
- **No verification loop** and it reports success it did not check.
- **No invariants** and it silently removes an ownership rule.
- **No recipes** and a ten-file change lands as a three-file change.
- **No rationale** and it "fixes" a deliberate port of a source-app quirk.
- **No machine-readable metadata** and every tool has to spend a model call to
  learn the shape of the repository.
- **No code signposting** and reading one file in isolation teaches nothing.
- **No reproducibility** and the agent cannot get to a green suite at all.
- **No drift control** and every other dimension degrades to fiction, which is
  exactly what had already happened here: two documented test counts, both wrong,
  in two files, for two pull requests, with nothing to notice.
