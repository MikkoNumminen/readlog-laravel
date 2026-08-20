<!--
Every pull request in this repository carries a self-review. They are the most
useful thing in it, so this template asks for the parts that turned out to matter.
Delete any section that genuinely does not apply, rather than writing "n/a".

House style: no em dashes. `readlog:docs-check` fails the build on one.
-->

## What changed

<!-- One or two sentences. What a reader would see differently. -->

## Why

<!-- Skip if it is obvious from the title. If this changes ported behaviour, say
     which DECISIONS.md entry it supersedes and why that reasoning no longer holds. -->

## Verification

<!-- The actual output, not "tests pass". Paste the numbers. -->

```
composer verify
```

- [ ] `composer verify` is green (Pint, PHPStan level 6, the suite, docs-check)
- [ ] New behaviour has a test, in the existing style
- [ ] Documentation updated in this change, not deferred
- [ ] A decision entry appended for anything a reviewer could have decided differently

## Self-review

<!-- What you found reviewing your own work, including anything you got wrong the
     first time. A pull request with an empty self-review reads as one nobody
     reviewed. -->

## Deliberately not done

<!-- Scope you considered and skipped, and why. -->
