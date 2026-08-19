<?php

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

    $this->artisan('readlog:snapshot', ['--out' => snapshotDir()])
        ->doesntExpectOutputToContain('405')
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
