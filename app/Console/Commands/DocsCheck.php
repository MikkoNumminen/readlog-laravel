<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Checks that what the documentation claims still matches the repository.
 *
 *     php artisan readlog:docs-check           # report drift, exit 1 if any
 *     php artisan readlog:docs-check --write   # regenerate docs/machine/*.json
 *
 * .NET counterpart: none. This exists because the documentation here is the
 * product as much as the code is, and prose rots silently. Two of the counts in
 * README.md and STATUS.md were already wrong by two pull requests when this
 * command was written, and nothing had noticed.
 *
 * Every check is deterministic and reads only the filesystem and the route table,
 * so it runs in the same second as Pint and stays in `composer verify`. It
 * deliberately does not run the test suite: a suite that counts itself proves
 * nothing. The suite's own numbers are checked by CI, which runs it and compares
 * the result against docs/machine/test-counts.json.
 *
 * A failure here is not an obstacle to route around. It means an agent reading
 * the docs would have been told something untrue.
 */
class DocsCheck extends Command
{
    protected $signature = 'readlog:docs-check
        {--write : Regenerate the generated files under docs/machine/ instead of only checking them}';

    protected $description = 'Verify that the documentation still matches the repository, and regenerate docs/machine/';

    /** Directories whose contents may be named in prose as a repository path. */
    private const SOURCE_PREFIXES = [
        'app/', 'tests/', 'docs/', 'database/', 'resources/', 'config/',
        'routes/', 'public/', 'docker/', 'scripts/', 'ops/', '.github/',
    ];

    /**
     * Routes the framework registers that this app does not document.
     * `up` is Laravel's health route and is documented; these two are not.
     */
    private const UNDOCUMENTED_ROUTES = ['storage.local', 'storage.local.upload'];

    /**
     * Keys in the stock Laravel `services.php` for integrations this app does not
     * use. Documenting them in .env.example would bury the handful that matter.
     * Delete a name from here the day the app actually starts using it.
     */
    private const STOCK_SERVICE_KEYS = [
        'POSTMARK_API_KEY', 'POSTMARK_MESSAGE_STREAM_ID', 'RESEND_API_KEY',
        'AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'AWS_DEFAULT_REGION',
        'SLACK_BOT_USER_OAUTH_TOKEN', 'SLACK_BOT_USER_DEFAULT_CHANNEL',
    ];

    /** @var list<string> */
    private array $failures = [];

    /** @var list<string> */
    private array $notes = [];

    public function handle(): int
    {
        if ($this->option('write')) {
            $this->write();

            return self::SUCCESS;
        }

        $this->checkMarkdownLinks();
        $this->checkNoEmDashes();
        $this->checkDocumentedPathsExist();
        $this->checkRoutes();
        $this->checkCommands();
        $this->checkRepoMap();
        $this->checkEntryPoints();
        $this->checkInvariants();
        $this->checkGenerated('glossary.json', $this->currentGlossary(...), 'docs/GLOSSARY.md');
        $this->checkGenerated('decisions.json', $this->currentDecisions(...), 'DECISIONS.md');
        $this->checkEnvironmentSurface();
        $this->checkTestCounts();
        $this->checkCountedClaims();
        $this->checkLinkAnchors();

        foreach ($this->notes as $note) {
            $this->components->info($note);
        }

        if ($this->failures === []) {
            $this->components->info('Documentation matches the repository.');

            return self::SUCCESS;
        }

        foreach ($this->failures as $failure) {
            $this->components->error($failure);
        }

        $this->newLine();
        $this->components->warn(sprintf(
            '%d documentation %s. Fix the prose, or run --write if only docs/machine/ is stale.',
            count($this->failures),
            count($this->failures) === 1 ? 'claim is wrong' : 'claims are wrong',
        ));

        return self::FAILURE;
    }

    /**
     * Every relative link in every tracked markdown file resolves to a real file.
     * This is the check that catches a document being moved or renamed.
     */
    private function checkMarkdownLinks(): void
    {
        foreach ($this->markdownFiles() as $file) {
            $contents = (string) file_get_contents($file);
            $directory = dirname($file);

            preg_match_all('/\]\(([^)\s#]+)(?:#[^)\s]*)?\)/', $contents, $matches);

            foreach ($matches[1] as $target) {
                if (Str::startsWith($target, ['http://', 'https://', 'mailto:'])) {
                    continue;
                }

                $resolved = realpath($directory.'/'.$target);

                if ($resolved === false) {
                    $this->failures[] = sprintf(
                        '%s links to "%s", which does not exist.',
                        $this->relative($file),
                        $target,
                    );
                }
            }
        }
    }

    /**
     * House style: no em dashes in any user-facing prose. Zero appear in any
     * document in this repository and the count is trivially checkable, so it is
     * checked rather than remembered.
     */
    private function checkNoEmDashes(): void
    {
        foreach ($this->markdownFiles() as $file) {
            $count = substr_count((string) file_get_contents($file), "\u{2014}");

            if ($count > 0) {
                $this->failures[] = sprintf(
                    '%s contains %d em dash%s. This repository uses none.',
                    $this->relative($file),
                    $count,
                    $count === 1 ? '' : 'es',
                );
            }
        }
    }

    /**
     * Any repository path named in backticks in the documentation exists.
     * Restricted to the source prefixes, so prose about "app/" concepts or about
     * another project's files does not produce noise.
     *
     * TODO.md is exempt, and has to be: it names files that are supposed not to
     * exist yet. Its links and its prose style are still checked.
     */
    private function checkDocumentedPathsExist(): void
    {
        foreach ($this->markdownFiles() as $file) {
            if (basename($file) === 'TODO.md') {
                continue;
            }

            $contents = (string) file_get_contents($file);

            preg_match_all('/`([A-Za-z0-9_.\-\/]+\.(?:php|json|yaml|yml|sh|neon|xml|css|js|md|blade\.php))`/', $contents, $matches);

            foreach (array_unique($matches[1]) as $path) {
                if (! Str::startsWith($path, self::SOURCE_PREFIXES)) {
                    continue;
                }

                if (! File::exists(base_path($path))) {
                    $this->failures[] = sprintf(
                        '%s names `%s`, which does not exist.',
                        $this->relative($file),
                        $path,
                    );
                }
            }
        }
    }

    /**
     * The generated route list matches the live route table, in both directions:
     * a route added without regenerating fails, and a documented route that has
     * been removed fails too.
     */
    private function checkRoutes(): void
    {
        $recorded = $this->readJson('routes.json');

        if ($recorded === null) {
            $this->failures[] = 'docs/machine/routes.json is missing. Run readlog:docs-check --write.';

            return;
        }

        $actual = $this->currentRoutes();

        if ($recorded !== $actual) {
            $this->failures[] = 'docs/machine/routes.json does not match the route table. Run readlog:docs-check --write.';
        }

        // The route names ARCHITECTURE.md claims must all be real.
        $architecture = base_path('ARCHITECTURE.md');

        if (! File::exists($architecture)) {
            return;
        }

        $documented = (string) file_get_contents($architecture);
        $names = array_column($actual, 'name');

        foreach ($names as $name) {
            if ($name === '') {
                continue;
            }

            if (! str_contains($documented, $name)) {
                $this->failures[] = sprintf('ARCHITECTURE.md does not document the route "%s".', $name);
            }
        }
    }

    /** Every readlog artisan command and every composer script is recorded. */
    private function checkCommands(): void
    {
        $recorded = $this->readJson('commands.json');

        if ($recorded === null) {
            $this->failures[] = 'docs/machine/commands.json is missing. Run readlog:docs-check --write.';

            return;
        }

        if ($recorded !== $this->currentCommands()) {
            $this->failures[] = 'docs/machine/commands.json does not match the registered commands. Run readlog:docs-check --write.';
        }
    }

    /**
     * A generated file still matches what its prose source would produce now.
     *
     * @param  callable(): array<string, mixed>  $generator
     */
    private function checkGenerated(string $name, callable $generator, string $from): void
    {
        $recorded = $this->readJson($name);

        if ($recorded === null) {
            $this->failures[] = sprintf('docs/machine/%s is missing. Run readlog:docs-check --write.', $name);

            return;
        }

        if ($recorded !== $generator()) {
            $this->failures[] = sprintf(
                'docs/machine/%s is stale against %s. Run readlog:docs-check --write.',
                $name,
                $from,
            );
        }
    }

    /**
     * Every file the repo map calls an entry point exists.
     *
     * markdownFiles() filters its list down to what is present, so until this
     * existed, deleting AGENTS.md made the em dash and link checks quietly cover
     * one fewer file and nothing failed. The contract's own existence should be
     * the one thing this command is loudest about.
     */
    private function checkEntryPoints(): void
    {
        $map = $this->readJson('repo-map.json');

        /** @var array<string, mixed> $entryPoints */
        $entryPoints = is_array($map['entryPoints'] ?? null) ? $map['entryPoints'] : [];

        if ($entryPoints === []) {
            $this->failures[] = 'docs/machine/repo-map.json names no entry points.';

            return;
        }

        foreach ($entryPoints as $role => $path) {
            if (is_string($path) && ! File::exists(base_path($path))) {
                $this->failures[] = sprintf('The "%s" entry point, %s, does not exist.', $role, $path);
            }
        }
    }

    /** Every directory the repo map describes still exists. */
    private function checkRepoMap(): void
    {
        $map = $this->readJson('repo-map.json');

        if ($map === null) {
            $this->failures[] = 'docs/machine/repo-map.json is missing. Run readlog:docs-check --write.';

            return;
        }

        /** @var array<int, array{path?: string}> $directories */
        $directories = is_array($map['directories'] ?? null) ? $map['directories'] : [];

        $described = [];

        foreach ($directories as $entry) {
            $path = $entry['path'] ?? null;

            if (! is_string($path)) {
                continue;
            }

            $described[] = $path;

            if (! File::exists(base_path($path))) {
                $this->failures[] = sprintf('docs/machine/repo-map.json describes "%s", which does not exist.', $path);
            }
        }

        // And the other direction, which is the one that actually rots: a new
        // directory under app/ that nothing describes. Checked only one level
        // deep, because that is the level at which this repository makes its
        // "what belongs here" decisions. A grader found app/Exceptions and
        // app/Providers missing before this existed.
        foreach (File::directories(base_path('app')) as $directory) {
            $relative = 'app/'.basename($directory);

            if (in_array($relative, $described, true)) {
                continue;
            }

            // A directory that only holds further directories is described by its
            // children, as app/Services is by app/Services/Ai.
            $holdsPhp = collect(File::files($directory))
                ->contains(fn ($file) => $file->getExtension() === 'php');

            if ($holdsPhp) {
                $this->failures[] = sprintf(
                    '%s holds PHP and docs/machine/repo-map.json does not describe it.',
                    $relative,
                );
            }
        }
    }

    /**
     * Every test file named as guarding an invariant exists. This cannot prove the
     * test still asserts what the invariant claims, and does not pretend to; it
     * catches the common rot, which is a test file being renamed or deleted.
     */
    private function checkInvariants(): void
    {
        $recorded = $this->readJson('invariants.json');

        if ($recorded === null) {
            $this->failures[] = 'docs/machine/invariants.json is missing. Write it by hand; it is not generated.';

            return;
        }

        /** @var array<int, array{id?: string, guardedBy?: string}> $invariants */
        $invariants = is_array($recorded['invariants'] ?? null) ? $recorded['invariants'] : [];

        if ($invariants === []) {
            $this->failures[] = 'docs/machine/invariants.json lists no invariants.';

            return;
        }

        foreach ($invariants as $invariant) {
            $guard = $invariant['guardedBy'] ?? null;
            $id = $invariant['id'] ?? '?';

            if (is_string($guard) && ! File::exists(base_path($guard))) {
                $this->failures[] = sprintf('Invariant %s names "%s" as its guard, which does not exist.', $id, $guard);
            }
        }

        // The prose file and the JSON must list the same invariant ids.
        $prose = base_path('docs/INVARIANTS.md');

        if (! File::exists($prose)) {
            $this->failures[] = 'docs/INVARIANTS.md is missing.';

            return;
        }

        $contents = (string) file_get_contents($prose);

        foreach ($invariants as $invariant) {
            $id = $invariant['id'] ?? null;

            if (is_string($id) && ! preg_match('/\|\s*'.preg_quote($id, '/').'\s*\|/', $contents)) {
                $this->failures[] = sprintf('Invariant %s is in the JSON but not in docs/INVARIANTS.md.', $id);
            }
        }
    }

    /**
     * Every environment variable this application's own configuration reads is
     * documented in .env.example. Framework configuration is not checked: Laravel
     * ships far more knobs than any app documents, and listing them would bury the
     * handful that matter here.
     */
    private function checkEnvironmentSurface(): void
    {
        $example = base_path('.env.example');

        if (! File::exists($example)) {
            $this->failures[] = '.env.example is missing.';

            return;
        }

        $documented = (string) file_get_contents($example);

        foreach (['config/services.php', 'config/trustedproxy.php'] as $config) {
            $path = base_path($config);

            if (! File::exists($path)) {
                continue;
            }

            preg_match_all("/env\(\s*'([A-Z0-9_]+)'/", (string) file_get_contents($path), $matches);

            foreach (array_unique($matches[1]) as $variable) {
                if (in_array($variable, self::STOCK_SERVICE_KEYS, true)) {
                    continue;
                }

                if (! preg_match('/^#?\s*'.preg_quote($variable, '/').'=/m', $documented)) {
                    $this->failures[] = sprintf(
                        '%s reads %s, which .env.example does not document.',
                        $config,
                        $variable,
                    );
                }
            }
        }
    }

    /**
     * The statically countable facts about the suite: how many test files there
     * are, and how many test blocks they contain. The number of test *cases* is
     * larger, because Pest datasets expand, and is checked by CI after a real run
     * rather than guessed at here.
     */
    private function checkTestCounts(): void
    {
        $recorded = $this->readJson('test-counts.json');

        if ($recorded === null) {
            $this->failures[] = 'docs/machine/test-counts.json is missing. Run readlog:docs-check --write.';

            return;
        }

        $actual = $this->currentTestCounts();

        foreach (['files', 'blocks'] as $key) {
            if (($recorded[$key] ?? null) !== $actual[$key]) {
                $this->failures[] = sprintf(
                    'docs/machine/test-counts.json says %s=%s; the tests directory has %d. Run readlog:docs-check --write.',
                    $key,
                    var_export($recorded[$key] ?? null, true),
                    $actual[$key],
                );
            }
        }

        $this->notes[] = sprintf(
            'Suite totals (%d cases, %d assertions) are checked by CI after a real run, not here.',
            is_int($recorded['cases'] ?? null) ? $recorded['cases'] : 0,
            is_int($recorded['assertions'] ?? null) ? $recorded['assertions'] : 0,
        );
    }

    /** Regenerates the machine-readable files. */
    private function write(): void
    {
        $directory = base_path('docs/machine');
        File::ensureDirectoryExists($directory);

        $this->putJson('routes.json', $this->currentRoutes());
        $this->putJson('commands.json', $this->currentCommands());
        $this->putJson('glossary.json', $this->currentGlossary());
        $this->putJson('decisions.json', $this->currentDecisions());

        $counts = $this->readJson('test-counts.json') ?? [];
        $this->putJson('test-counts.json', array_merge(
            ['cases' => 0, 'assertions' => 0, 'skipped' => 0],
            $counts,
            $this->currentTestCounts(),
        ));

        $this->components->info('Regenerated docs/machine/: routes, commands, glossary, decisions, test-counts.');
        $this->components->warn('repo-map.json and invariants.json are written by hand. Check them yourself.');
    }

    /** @return list<array{method: string, uri: string, name: string, action: string, middleware: list<string>}> */
    private function currentRoutes(): array
    {
        $routes = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $name = (string) $route->getName();

            if (in_array($name, self::UNDOCUMENTED_ROUTES, true)) {
                continue;
            }

            $methods = array_values(array_diff($route->methods(), ['HEAD']));

            $routes[] = [
                'method' => implode('|', $methods),
                'uri' => '/'.ltrim($route->uri(), '/'),
                'name' => $name,
                'action' => $route->getActionName(),
                'middleware' => array_values($route->gatherMiddleware()),
            ];
        }

        usort($routes, fn (array $a, array $b) => [$a['uri'], $a['method']] <=> [$b['uri'], $b['method']]);

        return $routes;
    }

    /** @return array{artisan: array<string, string>, composer: array<string, list<string>>} */
    private function currentCommands(): array
    {
        $artisan = [];

        foreach ($this->getApplication()?->all() ?? [] as $name => $command) {
            if (Str::startsWith($name, 'readlog:')) {
                $artisan[$name] = $command->getDescription();
            }
        }

        ksort($artisan);

        /** @var array<string, mixed> $composerJson */
        $composerJson = (array) json_decode((string) file_get_contents(base_path('composer.json')), true);
        /** @var array<string, mixed> $scripts */
        $scripts = is_array($composerJson['scripts'] ?? null) ? $composerJson['scripts'] : [];

        $composer = [];

        foreach ($scripts as $name => $steps) {
            // Composer's own lifecycle hooks are not commands anyone runs.
            if (Str::startsWith($name, ['post-', 'pre-'])) {
                continue;
            }

            $composer[$name] = array_map(strval(...), (array) $steps);
        }

        ksort($composer);

        return ['artisan' => $artisan, 'composer' => $composer];
    }

    /**
     * The glossary, parsed out of its own prose. Generated rather than maintained
     * twice, because two hand-written copies of the same definitions is exactly the
     * drift this command exists to catch.
     *
     * @return array<string, mixed>
     */
    private function currentGlossary(): array
    {
        $source = base_path('docs/GLOSSARY.md');

        if (! File::exists($source)) {
            return ['source' => 'docs/GLOSSARY.md', 'count' => 0, 'terms' => []];
        }

        $contents = (string) file_get_contents($source);
        $terms = [];
        $section = null;

        foreach (preg_split('/\n(?=\*\*|## )/', $contents) ?: [] as $block) {
            if (preg_match('/^## (.+)/', $block, $heading) === 1) {
                $section = trim($heading[1]);

                continue;
            }

            if (preg_match('/^\*\*(.+?)\*\*\n(.+?)(?=\n\n|\z)/s', $block, $match) === 1) {
                $terms[] = [
                    'term' => trim($match[1]),
                    'section' => $section,
                    'definition' => (string) preg_replace('/\s+/', ' ', trim($match[2])),
                ];
            }
        }

        return [
            '$comment' => 'Generated from docs/GLOSSARY.md by readlog:docs-check --write.',
            'source' => 'docs/GLOSSARY.md',
            'count' => count($terms),
            'terms' => $terms,
        ];
    }

    /**
     * The decision log and its topic index, as data. 120 entries is past the point
     * where a reader can scan the file, and a tool should not have to parse tables.
     *
     * @return array<string, mixed>
     */
    private function currentDecisions(): array
    {
        $source = base_path('DECISIONS.md');

        if (! File::exists($source)) {
            return ['source' => 'DECISIONS.md', 'count' => 0, 'decisions' => []];
        }

        $contents = (string) file_get_contents($source);

        preg_match_all('/^\| (\d+) \| (.+?) \| (.+?) \|\s*$/m', $contents, $rows, PREG_SET_ORDER);

        $decisions = array_map(fn (array $row) => [
            'id' => (int) $row[1],
            'decision' => trim($row[2]),
            'reasoning' => trim($row[3]),
        ], $rows);

        preg_match_all('/^\| ([A-Z][^|]+?) \| ([\d,\s to]+) \|\s*$/m', $contents, $topicRows, PREG_SET_ORDER);

        $topics = array_map(fn (array $row) => [
            'topic' => trim($row[1]),
            'decisions' => trim($row[2]),
        ], $topicRows);

        return [
            '$comment' => 'Generated from DECISIONS.md by readlog:docs-check --write. Append-only; never renumber.',
            'source' => 'DECISIONS.md',
            'count' => count($decisions),
            'topicIndex' => $topics,
            'decisions' => $decisions,
        ];
    }

    /** @return array{files: int, blocks: int} */
    private function currentTestCounts(): array
    {
        $files = 0;
        $blocks = 0;

        foreach (File::allFiles(base_path('tests')) as $file) {
            if ($file->getExtension() !== 'php' || ! Str::endsWith($file->getFilename(), 'Test.php')) {
                continue;
            }

            $files++;
            $blocks += preg_match_all('/^\s*(?:it|test)\(/m', (string) file_get_contents($file->getPathname()));
        }

        return ['files' => $files, 'blocks' => $blocks];
    }

    /**
     * Numbers the prose states about things this command can count.
     *
     * Added because two documents claimed the decision log had 111 and 115
     * entries when it had 120, while this command printed "Documentation matches
     * the repository." A count is the easiest kind of claim to check and the
     * easiest to leave behind, which is exactly the combination worth automating.
     *
     * Only phrasings that name what is being counted are matched, so ordinary
     * numbers in prose are left alone.
     */
    private function checkCountedClaims(): void
    {
        $decisions = 0;
        $decisionsFile = base_path('DECISIONS.md');

        if (File::exists($decisionsFile)) {
            $decisions = preg_match_all('/^\| \d+ \| /m', (string) file_get_contents($decisionsFile));
        }

        $recipes = 0;
        $recipesFile = base_path('docs/RECIPES.md');

        if (File::exists($recipesFile)) {
            $recipes = preg_match_all('/^## /m', (string) file_get_contents($recipesFile));
        }

        $invariants = 0;
        $invariantsFile = base_path('docs/INVARIANTS.md');

        if (File::exists($invariantsFile)) {
            $invariants = preg_match_all('/^\| [A-Z]\d+ \| /m', (string) file_get_contents($invariantsFile));
        }

        // Prose here writes small counts as words ("nine recipes") and larger ones
        // as digits ("124 numbered entries"). Both have to be checkable, or this
        // silently covers only half the claims it appears to.
        $words = [
            'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5,
            'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10,
            'eleven' => 11, 'twelve' => 12, 'thirteen' => 13, 'fourteen' => 14,
            'fifteen' => 15, 'sixteen' => 16, 'seventeen' => 17, 'eighteen' => 18,
            'nineteen' => 19, 'twenty' => 20,
        ];

        $n = '(\d+|'.implode('|', array_keys($words)).')';

        // Each pattern must name what it counts. "20 entries" on its own is the
        // public feed's cap and "14 entries" is the seeded library, neither of
        // which is a decision.
        /** @var list<array{0: string, 1: int, 2: string}> $claims */
        $claims = [
            ['/'.$n.'\s+numbered\s+entries/i', $decisions, 'decision entries'],
            ['/'.$n.'\s+decision\s+entries/i', $decisions, 'decision entries'],
            ['/DECISIONS\.md\)?[^\r\n]{0,40}?'.$n.'\s+entries/i', $decisions, 'decision entries'],
            ['/'.$n.'\s+entries\s+(?:is\s+)?past the size/i', $decisions, 'decision entries'],
            ['/'.$n.'\s+invariants/i', $invariants, 'invariants'],
            ['/'.$n.'\s+things that must stay true/i', $invariants, 'invariants'],
            ['/'.$n.'\s+step-by-step walkthroughs/i', $recipes, 'recipes'],
            ['/RECIPES\.md\)?[^\r\n]{0,20}?has '.$n.'\b/i', $recipes, 'recipes'],
        ];

        foreach ($this->markdownFiles() as $file) {
            $contents = (string) file_get_contents($file);

            foreach ($claims as [$pattern, $actual, $label]) {
                if ($actual === 0) {
                    continue;
                }

                preg_match_all($pattern, $contents, $matches, PREG_SET_ORDER);

                foreach ($matches as $match) {
                    $claimed = $words[strtolower($match[1])] ?? (int) $match[1];

                    if ($claimed !== $actual) {
                        $this->failures[] = sprintf(
                            '%s says "%s"; there are %d %s.',
                            $this->relative($file),
                            trim($match[0]),
                            $actual,
                            $label,
                        );
                    }
                }
            }
        }
    }

    /**
     * A link with a #fragment points at a heading that exists in the target.
     *
     * checkMarkdownLinks strips the fragment before resolving the path, so until
     * this existed a link to a renamed heading resolved happily and landed the
     * reader at the top of the file. Anchors are derived the way GitHub derives
     * them: lower-cased, punctuation dropped, spaces to hyphens.
     */
    private function checkLinkAnchors(): void
    {
        foreach ($this->markdownFiles() as $file) {
            $contents = (string) file_get_contents($file);
            $directory = dirname($file);

            preg_match_all('/\]\(([^)\s#]*)#([^)\s]+)\)/', $contents, $matches, PREG_SET_ORDER);

            foreach ($matches as [$whole, $target, $fragment]) {
                if (Str::startsWith($target, ['http://', 'https://', 'mailto:'])) {
                    continue;
                }

                $path = $target === '' ? $file : realpath($directory.'/'.$target);

                if ($path === false || ! File::exists($path)) {
                    // checkMarkdownLinks already reports a missing target.
                    continue;
                }

                if (! in_array(strtolower($fragment), $this->anchorsIn((string) $path), true)) {
                    $this->failures[] = sprintf(
                        '%s links to "%s", and that heading does not exist.',
                        $this->relative($file),
                        $whole === '' ? $fragment : trim($whole, '[]()'),
                    );
                }
            }
        }
    }

    /**
     * The heading anchors a markdown file offers, in GitHub's slug form.
     *
     * @return list<string>
     */
    private function anchorsIn(string $file): array
    {
        preg_match_all('/^#{1,6}\s+(.+?)\s*$/m', (string) file_get_contents($file), $headings);

        return array_map(function (string $heading): string {
            $slug = strtolower(trim($heading));
            $slug = (string) preg_replace('/`|\*|_|\[|\]|\(|\)/', '', $slug);
            $slug = (string) preg_replace('/[^a-z0-9\s-]/', '', $slug);

            return trim((string) preg_replace('/\s+/', '-', $slug), '-');
        }, $headings[1]);
    }

    /** @return list<string> */
    private function markdownFiles(): array
    {
        $files = array_map(
            fn (string $name) => base_path($name),
            ['README.md', 'AGENTS.md', 'CLAUDE.md', 'ARCHITECTURE.md', 'CONTRIBUTING.md',
                'DECISIONS.md', 'DEMO.md', 'MIGRATION.md', 'STATUS.md', 'TODO.md'],
        );

        $docs = base_path('docs');

        if (File::isDirectory($docs)) {
            foreach (File::allFiles($docs) as $file) {
                if ($file->getExtension() === 'md') {
                    $files[] = $file->getPathname();
                }
            }
        }

        return array_values(array_filter($files, File::exists(...)));
    }

    /**
     * A decoded docs/machine/ file, or null when it is absent or unreadable.
     * The shape varies: routes.json decodes to a list, the rest to maps.
     *
     * @return array<array-key, mixed>|null
     */
    private function readJson(string $name): ?array
    {
        $path = base_path('docs/machine/'.$name);

        if (! File::exists($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    private function putJson(string $name, mixed $data): void
    {
        File::put(
            base_path('docs/machine/'.$name),
            // A literal newline, not PHP_EOL: this file is committed,
            // .gitattributes normalises the repository to LF, and a generator
            // that emits CRLF on Windows and LF on Linux would produce a diff
            // for nobody's change.
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
        );
    }

    private function relative(string $path): string
    {
        return str_replace('\\', '/', Str::after($path, base_path().DIRECTORY_SEPARATOR));
    }
}
