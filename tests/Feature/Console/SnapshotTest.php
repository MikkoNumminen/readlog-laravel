<?php

use App\Console\Commands\Snapshot;
use App\Models\Book;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/*
| readlog:snapshot. The crawl runs the real app in-process against a temporary
| seeded SQLite database; only the cover-image downloads are faked.
*/

function snapshotDir(): string
{
    return sys_get_temp_dir().'/readlog-snapshot-test-'.getmypid();
}

function fakeCovers(): void
{
    Http::fake([
        'covers.openlibrary.org/*' => Http::response('not really a jpeg', 200, ['Content-Type' => 'image/jpeg']),
    ]);
}

afterEach(function () {
    File::deleteDirectory(snapshotDir());
});

it('writes a browsable tree from a fresh seeded database', function () {
    fakeCovers();

    $this->artisan('readlog:snapshot', ['--out' => snapshotDir()])->assertExitCode(0);

    $out = snapshotDir();

    expect(File::exists("$out/index.html"))->toBeTrue()
        ->and(File::exists("$out/library/index.html"))->toBeTrue()
        ->and(File::exists("$out/library/list/index.html"))->toBeTrue()
        ->and(File::exists("$out/log/index.html"))->toBeTrue()
        ->and(File::exists("$out/account/index.html"))->toBeTrue()
        ->and(File::exists("$out/css/site.css"))->toBeTrue()
        ->and(File::glob("$out/book/*/index.html"))->toHaveCount(12)      // one page per catalogue book
        ->and(File::glob("$out/library/*/edit/index.html"))->toHaveCount(10) // the acting reader's entries
        ->and(File::glob("$out/assets/covers/*.jpg"))->toHaveCount(12);
});

it('rewrites every link to the base path and leaves nothing pointing at localhost', function () {
    fakeCovers();

    $this->artisan('readlog:snapshot', ['--out' => snapshotDir(), '--base' => '/demo'])->assertExitCode(0);

    $index = File::get(snapshotDir().'/index.html');

    expect($index)->toContain('href="/demo/library"')
        ->and($index)->toContain('href="/demo/book/dune-frank-herbert"')
        ->and($index)->toContain('href="/demo/css/site.css"')
        ->and($index)->toContain('src="/demo/assets/covers/')
        ->and($index)->not->toContain('http://localhost')
        ->and($index)->not->toContain('covers.openlibrary.org');

    // The whole tree, not only the front page.
    foreach (File::allFiles(snapshotDir()) as $file) {
        if ($file->getExtension() === 'html') {
            expect(File::get($file->getPathname()))->not->toContain('http://localhost');
        }
    }
});

it('maps the default grid view and ?view=grid to the same page', function () {
    fakeCovers();

    $this->artisan('readlog:snapshot', ['--out' => snapshotDir()])->assertExitCode(0);

    $list = File::get(snapshotDir().'/library/list/index.html');

    expect($list)->toContain('href="/readlog-laravel/library">Grid')
        ->and(File::exists(snapshotDir().'/library-2/index.html'))->toBeFalse();
});

it('rewrites form actions but never fetches them as pages', function () {
    fakeCovers();

    // "answered 405", not a bare "405": snapshotDir() carries getmypid() and the
    // command prints that path back, so a bare substring made the suite red for
    // any pest process whose pid happened to contain 405. Rare, real, and the
    // second flake found in this file; see decision 121 for the first.
    $this->artisan('readlog:snapshot', ['--out' => snapshotDir()])
        ->doesntExpectOutputToContain('answered 405')
        ->assertExitCode(0);

    $edit = File::get(File::glob(snapshotDir().'/library/*/edit/index.html')[0]);

    expect($edit)->toContain('action="/readlog-laravel/library/')
        ->and(File::exists(snapshotDir().'/library/1/index.html'))->toBeFalse();
});

it('injects the snapshot notice and drops the script tag by default', function () {
    fakeCovers();

    $this->artisan('readlog:snapshot', ['--out' => snapshotDir()])->assertExitCode(0);

    $index = File::get(snapshotDir().'/index.html');

    expect($index)->toContain('static snapshot of ReadLog')
        ->and($index)->toContain('the AI &quot;ask your library&quot; box are inactive')
        ->and($index)->toContain('github.com/MikkoNumminen/readlog-laravel')
        ->and($index)->not->toContain('<script');
});

it('can keep the script and skip the notice when asked', function () {
    fakeCovers();

    $this->artisan('readlog:snapshot', [
        '--out' => snapshotDir(), '--no-banner' => true, '--keep-scripts' => true,
    ])->assertExitCode(0);

    $index = File::get(snapshotDir().'/index.html');

    expect($index)->not->toContain('static snapshot of ReadLog')
        ->and($index)->toContain('<script src="/readlog-laravel/js/site.js"></script>')
        ->and(File::exists(snapshotDir().'/js/site.js'))->toBeTrue();
});

it('leaves a cover pointing at the provider when the download fails, and says so', function () {
    Http::fake([
        'covers.openlibrary.org/*' => Http::response('gone', 404),
    ]);

    $this->artisan('readlog:snapshot', ['--out' => snapshotDir()])
        ->expectsOutputToContain('failed, left external')
        ->assertExitCode(0);

    expect(File::glob(snapshotDir().'/assets/covers/*'))->toHaveCount(0)
        ->and(File::get(snapshotDir().'/index.html'))->toContain('covers.openlibrary.org');
});

it('refuses to wipe a directory it did not write', function () {
    // --out=. resolves to the project root. Without this guard the command
    // would delete the checkout to make room for the snapshot.
    File::ensureDirectoryExists(snapshotDir());
    File::put(snapshotDir().'/precious.txt', 'not yours');
    fakeCovers();

    expect(fn () => $this->artisan('readlog:snapshot', ['--out' => snapshotDir()]))
        ->toThrow(RuntimeException::class, 'Refusing to write into');

    expect(File::exists(snapshotDir().'/precious.txt'))->toBeTrue();
});

it('happily overwrites its own previous output', function () {
    fakeCovers();

    $this->artisan('readlog:snapshot', ['--out' => snapshotDir()])->assertExitCode(0);
    File::put(snapshotDir().'/stale.html', 'from an older run');
    $this->artisan('readlog:snapshot', ['--out' => snapshotDir()])->assertExitCode(0);

    // The second run must produce a full tree, not only remove the stale file:
    // the console container reuses the command instance, and a first version
    // kept its page map between runs and wrote nothing the second time.
    expect(File::exists(snapshotDir().'/stale.html'))->toBeFalse()
        ->and(File::exists(snapshotDir().'/.readlog-snapshot'))->toBeTrue()
        ->and(File::exists(snapshotDir().'/index.html'))->toBeTrue()
        ->and(File::glob(snapshotDir().'/book/*/index.html'))->toHaveCount(12);
});

it('gives covers that share a path but not a URL distinct file names', function () {
    // Google Books thumbnails are all /books/content?id=...; a name taken from
    // the path alone would make every one of them the same file. The seeded
    // snapshot database only carries Open Library covers, so the naming is
    // exercised directly.
    Http::fake(['books.google.com/*' => Http::response('gb', 200)]);
    File::ensureDirectoryExists(snapshotDir());

    $command = new Snapshot;
    $reflect = new ReflectionClass($command);
    $reflect->getProperty('out')->setValue($command, snapshotDir());
    $fetch = $reflect->getMethod('fetchCover');

    $fetch->invoke($command, 'https://books.google.com/books/content?id=AAA&printsec=frontcover');
    $fetch->invoke($command, 'https://books.google.com/books/content?id=BBB&printsec=frontcover');

    $covers = array_values($reflect->getProperty('covers')->getValue($command));

    expect($covers)->toHaveCount(2)
        ->and($covers[0])->not->toBe($covers[1])
        ->and(File::glob(snapshotDir().'/assets/covers/*'))->toHaveCount(2);
});

it('leaves no temporary database behind', function () {
    fakeCovers();

    $this->artisan('readlog:snapshot', ['--out' => snapshotDir()])->assertExitCode(0);

    // This process's own file, by the prefix the command builds from getmypid()
    // (decision 121). Asserting on the old fixed path storage/app/snapshot.sqlite
    // would pass even with the cleanup removed; asserting the whole directory is
    // empty would fail on a file a killed run left behind, or on another suite's
    // snapshot still in flight beside this one.
    expect(File::glob(storage_path('app/snapshot-'.getmypid().'-*.sqlite')))->toBeEmpty();
});

it('sweeps a throwaway database an earlier run left behind, but not a live one', function () {
    // The per-process name means a killed run leaks a file nothing would reclaim.
    // Anything older than an hour belongs to no running crawl.
    fakeCovers();
    // Named for this process, like the real thing: fixed names collide when two
    // suites run at once, and one run's sweep then deletes the other's fixture
    // between the glob and the stat.
    $tag = getmypid().'-'.bin2hex(random_bytes(4));
    $stale = storage_path('app/snapshot-stale'.$tag.'.sqlite');
    $fresh = storage_path('app/snapshot-fresh'.$tag.'.sqlite');
    File::put($stale, 'x');
    File::put($fresh, 'x');
    touch($stale, time() - 7200);

    $this->artisan('readlog:snapshot', ['--out' => snapshotDir()])->assertExitCode(0);

    expect(File::exists($stale))->toBeFalse()
        ->and(File::exists($fresh))->toBeTrue();

    File::delete($fresh);
});

it('produces byte-identical output on two runs', function () {
    // A committed snapshot is refreshed by regenerate-and-copy; a diff on that
    // copy should show real changes only, never per-render randomness.
    fakeCovers();

    $this->artisan('readlog:snapshot', ['--out' => snapshotDir()])->assertExitCode(0);
    $first = collect(File::allFiles(snapshotDir()))->mapWithKeys(fn ($f) => [$f->getRelativePathname() => md5_file($f->getPathname())])->all();

    $this->artisan('readlog:snapshot', ['--out' => snapshotDir()])->assertExitCode(0);
    $second = collect(File::allFiles(snapshotDir()))->mapWithKeys(fn ($f) => [$f->getRelativePathname() => md5_file($f->getPathname())])->all();

    expect($second)->toBe($first)
        ->and(File::get(snapshotDir().'/account/index.html'))->not->toContain('name="_token"');
});
