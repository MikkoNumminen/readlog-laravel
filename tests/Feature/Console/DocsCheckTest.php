<?php

use App\Console\Commands\DocsCheck;

/*
| readlog:docs-check. These run against the real repository rather than a fixture,
| which is the point: the command's job is to be true about this checkout, and a
| test over a mock directory would pass while the docs rotted.
|
| That makes them the one place in the suite where a failure means "go fix a
| document", not "go fix the code".
*/

it('passes against the repository as it stands', function () {
    $this->artisan('readlog:docs-check')
        ->expectsOutputToContain('Documentation matches the repository.')
        ->assertExitCode(0);
});

it('finds every generated file it needs', function () {
    foreach (['routes.json', 'commands.json', 'invariants.json', 'repo-map.json', 'test-counts.json'] as $file) {
        expect(base_path('docs/machine/'.$file))->toBeReadableFile();
    }
});

it('names a real test file for every invariant', function () {
    $invariants = json_decode((string) file_get_contents(base_path('docs/machine/invariants.json')), true);

    expect($invariants['invariants'])->not->toBeEmpty();

    foreach ($invariants['invariants'] as $invariant) {
        expect(base_path($invariant['guardedBy']))
            ->toBeReadableFile("Invariant {$invariant['id']} names a guard that does not exist");
    }
});

it('keeps the prose and the JSON listing the same invariants', function () {
    $json = json_decode((string) file_get_contents(base_path('docs/machine/invariants.json')), true);
    $prose = (string) file_get_contents(base_path('docs/INVARIANTS.md'));

    foreach ($json['invariants'] as $invariant) {
        expect($prose)->toMatch('/\|\s*'.preg_quote($invariant['id'], '/').'\s*\|/');
    }
});

it('records the route table that the app actually serves', function () {
    $recorded = json_decode((string) file_get_contents(base_path('docs/machine/routes.json')), true);
    $names = array_filter(array_column($recorded, 'name'));

    // Every documented route resolves. The reverse direction (every real route is
    // documented) is what the command itself checks; this is the cheap half.
    foreach ($names as $name) {
        expect(route($name, ['entry' => 1], false))->toBeString();
    }
});

it('documents every route in ARCHITECTURE.md', function () {
    $architecture = (string) file_get_contents(base_path('ARCHITECTURE.md'));
    $recorded = json_decode((string) file_get_contents(base_path('docs/machine/routes.json')), true);

    foreach (array_filter(array_column($recorded, 'name')) as $name) {
        expect($architecture)->toContain($name);
    }
});

it('uses no em dashes in any document', function () {
    // House style, and the single strongest tell that prose was generated rather
    // than written. Zero appear anywhere in this repository; this keeps it so.
    $documents = array_merge(
        glob(base_path('*.md')) ?: [],
        glob(base_path('docs/*.md')) ?: [],
    );

    expect($documents)->not->toBeEmpty();

    foreach ($documents as $document) {
        expect(substr_count((string) file_get_contents($document), "\u{2014}"))
            ->toBe(0, basename($document).' contains an em dash');
    }
});

it('documents every environment variable its own configuration reads', function () {
    $documented = (string) file_get_contents(base_path('.env.example'));

    // The command's own list, not a copy of it. A copy drifts the day a name is
    // removed from the const, and then the test and the command disagree while
    // both look green.
    $stock = DocsCheck::STOCK_SERVICE_KEYS;

    preg_match_all("/env\(\s*'([A-Z0-9_]+)'/", (string) file_get_contents(config_path('services.php')), $matches);

    foreach (array_diff(array_unique($matches[1]), $stock) as $variable) {
        expect($documented)->toMatch('/^#?\s*'.preg_quote($variable, '/').'=/m');
    }
});
