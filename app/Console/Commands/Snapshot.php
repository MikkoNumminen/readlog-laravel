<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Writes a static, browsable snapshot of the app to a directory.
 *
 *     php artisan readlog:snapshot                          # build/snapshot, links under /readlog-laravel
 *     php artisan readlog:snapshot --out=/tmp/snap --base=/demo
 *
 * The brief was "boot the app, crawl it, write HTML plus assets", and the usual
 * tool for that is wget --mirror. This does the same job in-process instead:
 * every page is rendered by handing a Request to the HTTP kernel, exactly as a
 * browser hit would be, and the crawl follows the links each page emits. No
 * server to start, no wget to install, and the same run on Windows, macOS, Linux
 * and CI. Nothing outside the app is needed except the network, for cover images.
 *
 * What comes out is a plain directory tree meant to be dropped under a path on a
 * static host and served from a subdirectory:
 *
 *   index.html                    the feed
 *   library/index.html            the library, grid
 *   library/list/index.html       the library, list
 *   library/3/edit/index.html     one reading entry
 *   book/dune-frank-herbert/index.html
 *   log/index.html  account/index.html
 *   css/site.css  assets/covers/*.jpg
 *
 * Every link is rewritten to <base>/<clean path> so the tree works from a
 * subdirectory. Directory-per-page with index.html is chosen over page.html
 * because both Vercel (trailingSlash off, cleanUrls on) and Astro's preview
 * server serve `<base>/library` from `library/index.html`; `.html` links would
 * redirect on one and 404 on the other. Cover images are downloaded into the
 * tree, because a host with img-src 'self' would block the originals.
 *
 * The data behind the snapshot is a fresh, temporary SQLite database seeded
 * with the demo library, never the developer's own, so the output is
 * reproducible from any checkout. Search, forms and the provider lookup are
 * naturally inert in static HTML; a banner on every page says so.
 *
 * .NET counterpart: none. The .NET app is hosted, so it never needed a static
 * copy of itself to serve while the machine is off.
 */
class Snapshot extends Command
{
    protected $signature = 'readlog:snapshot
        {--out=build/snapshot : Output directory, relative to the project root unless absolute}
        {--base=/readlog-laravel : URL prefix the snapshot will be served under, with leading slash and no trailing slash}
        {--no-banner : Do not inject the "this is a static snapshot" notice}
        {--keep-scripts : Keep the <script> tag; by default it is dropped so nothing auto-submits}';

    protected $description = 'Write a static, browsable snapshot of the seeded app to a directory';

    private const ORIGIN = 'http://localhost';

    private const BANNER = '<div class="rl-notice" role="note">This is a static snapshot of ReadLog (Laravel). '
        .'Search, forms, the multi-source book lookup and the AI &quot;ask your library&quot; box are inactive '
        .'here. The full app runs locally with one Docker command; the source is at '
        .'<a href="https://github.com/MikkoNumminen/readlog-laravel">github.com/MikkoNumminen/readlog-laravel</a>.</div>';

    private string $out;

    private string $base;

    /** @var array<string, string> url (path?query) => file path relative to out */
    private array $pages = [];

    /** @var list<string> */
    private array $queue = [];

    /** @var array<string, string> remote cover url => relative path under out */
    private array $covers = [];

    /** @var array<string, string> public asset path => relative path under out */
    private array $assets = [];

    private int $coverFailures = 0;

    /**
     * The throwaway database's path, unique to this process.
     *
     * It used to be a fixed storage/app/snapshot.sqlite, which made the checkout
     * shared mutable state: two suite runs on one working copy, or `pest
     * --parallel`, or an IDE watcher running alongside, and one run truncates the
     * other's file while the other's `finally` deletes it. The symptoms are
     * "database disk image is malformed" and "no such table: migrations" in
     * SnapshotTest, on a change that touched nothing near it. A gate that goes red
     * for reasons unrelated to the diff teaches an agent to ignore red.
     */
    private string $databaseFile;

    public function handle(Kernel $kernel): int
    {
        $this->out = $this->resolveOut((string) $this->option('out'));
        $this->base = '/'.trim((string) $this->option('base'), '/');

        // The console container hands back the same command instance for every
        // Artisan::call in one process. Without this reset a second run would find
        // its own first run's page map, queue nothing, write nothing, and print
        // the first run's counts as if it had. Found by running it twice.
        $this->pages = [];
        $this->queue = [];
        $this->covers = [];
        $this->assets = [];
        $this->coverFailures = 0;

        // Set before the try, so the finally always has a path to delete even if
        // prepareDatabase() throws before it gets there.
        $this->databaseFile = storage_path('app/snapshot-'.getmypid().'-'.bin2hex(random_bytes(4)).'.sqlite');

        // Whatever database, cache and session the process was using are put back
        // when the crawl is done. This runs in-process, so leaving the default
        // connection pointed at the throwaway database would surprise anything that
        // runs after it in the same process, the test suite included.
        $previous = [
            'connection' => DB::getDefaultConnection(),
            'database.default' => Config::get('database.default'),
            'cache.default' => Config::get('cache.default'),
            'session.driver' => Config::get('session.driver'),
        ];

        try {
            $this->prepareDatabase();
            $this->prepareOutput();

            $this->enqueue('/');

            while (($url = array_shift($this->queue)) !== null) {
                $this->crawl($kernel, $url);
            }

            $this->copyAssets();
        } finally {
            DB::purge('snapshot');
            File::delete($this->databaseFile);
            DB::setDefaultConnection($previous['connection']);
            Config::set('database.default', $previous['database.default']);
            Config::set('cache.default', $previous['cache.default']);
            Config::set('session.driver', $previous['session.driver']);
        }

        $this->newLine();
        $this->table(['What', 'Count'], [
            ['Pages', count($this->pages)],
            ['Cover images', count($this->covers).($this->coverFailures > 0 ? " ({$this->coverFailures} failed, left external)" : '')],
            ['Assets', count($this->assets)],
        ]);
        $this->components->info("Snapshot written to {$this->out}, links under {$this->base}");

        return self::SUCCESS;
    }

    /**
     * A throwaway SQLite database with the demo library, so the snapshot never
     * depends on, or reveals, whatever database the developer happens to have.
     * Cache and session are moved to array stores for the same reason.
     */
    private function prepareDatabase(): void
    {
        // Unique per process, assigned in handle(). See the property's docblock.
        $file = $this->databaseFile;
        File::ensureDirectoryExists(dirname($file));
        File::put($file, '');

        Config::set('database.connections.snapshot', array_merge(
            Config::get('database.connections.sqlite'),
            ['database' => $file],
        ));
        Config::set('database.default', 'snapshot');
        Config::set('cache.default', 'array');
        Config::set('session.driver', 'array');
        DB::purge('snapshot');
        DB::setDefaultConnection('snapshot');

        // Output goes to a null buffer rather than --quiet: --quiet is a console
        // flag, not a command option, and Artisan::call rejects it outside the test
        // harness.
        $silent = new NullOutput;
        Artisan::call('migrate:fresh', ['--database' => 'snapshot', '--force' => true], $silent);
        Artisan::call('db:seed', ['--class' => 'DemoLibrarySeeder', '--database' => 'snapshot', '--force' => true], $silent);
    }

    /** Written into every snapshot; its presence is what makes a directory safe to wipe. */
    private const MARKER = '.readlog-snapshot';

    /**
     * The output directory is emptied before writing, and that is only safe when
     * it is ours. Without a check, `--out=.` resolves to the project root and
     * deleteDirectory() would take the checkout with it. A directory is wiped
     * only if it is empty or carries the marker a previous run left behind.
     */
    private function prepareOutput(): void
    {
        if (File::isDirectory($this->out)) {
            $entries = array_diff(scandir($this->out) ?: [], ['.', '..']);

            if ($entries !== [] && ! File::exists($this->out.'/'.self::MARKER)) {
                throw new \RuntimeException(
                    "Refusing to write into {$this->out}: it is not empty and was not written by readlog:snapshot. "
                    .'Choose an empty directory or a previous snapshot directory.'
                );
            }

            File::deleteDirectory($this->out);
        }

        File::ensureDirectoryExists($this->out);
        File::put($this->out.'/'.self::MARKER, 'Written by php artisan readlog:snapshot. Safe to delete.
');
    }

    private function enqueue(string $url): void
    {
        $url = $this->canonical($url);

        if (isset($this->pages[$url]) || in_array($url, $this->queue, true)) {
            return;
        }

        $this->pages[$url] = $this->fileFor($url);
        $this->queue[] = $url;
    }

    private function crawl(Kernel $kernel, string $url): void
    {
        $request = Request::create(self::ORIGIN.$url, 'GET');
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        if ($response->getStatusCode() !== 200) {
            $this->components->warn("{$url} answered {$response->getStatusCode()}; skipped");
            unset($this->pages[$url]);

            return;
        }

        $html = (string) $response->getContent();

        // Discover before rewriting, so every link that will be rewritten has a
        // target in the map by the time the page is written.
        foreach ($this->links($html) as $link) {
            $this->discover($link);
        }

        $html = $this->rewrite($html);

        $target = $this->out.'/'.$this->pages[$url];
        File::ensureDirectoryExists(dirname($target));
        File::put($target, $html);
    }

    /**
     * Every href and src in the page, decoded from HTML. Form actions are left
     * out on purpose: they are rewritten like any other URL, but they name POST,
     * PUT and DELETE endpoints and must not be fetched as pages.
     *
     * @return list<string>
     */
    private function links(string $html): array
    {
        preg_match_all('/\b(?:href|src)="([^"]*)"/i', $html, $m);

        return array_values(array_unique(array_map(
            fn (string $v) => html_entity_decode($v, ENT_QUOTES | ENT_HTML5),
            $m[1],
        )));
    }

    private function discover(string $link): void
    {
        $local = $this->localPath($link);

        if ($local !== null) {
            if ($this->isPublicAsset($local)) {
                // The script tag is dropped from the pages unless asked otherwise, so
                // the file it points at is not copied either.
                if (! str_ends_with(strtok($local, '?') ?: '', '.js') || $this->option('keep-scripts')) {
                    $this->assets[$local] = ltrim(strtok($local, '?') ?: '', '/');
                }
            } elseif ($this->isCrawlable($local)) {
                $this->enqueue($local);
            }

            return;
        }

        if ($this->isCover($link)) {
            $this->fetchCover($link);
        }
    }

    /** "/library?view=list" for a same-origin link, null for anything else. */
    private function localPath(string $link): ?string
    {
        if (str_starts_with($link, self::ORIGIN.'/') || $link === self::ORIGIN) {
            $link = substr($link, strlen(self::ORIGIN)) ?: '/';
        } elseif (! str_starts_with($link, '/') || str_starts_with($link, '//')) {
            return null;
        }

        return $this->canonical($link);
    }

    /**
     * Two URLs that render the same page map to one file. The library's Grid
     * button links to ?view=grid, which is the default view, so it is the plain
     * library page; an empty q is no search at all.
     */
    private function canonical(string $url): string
    {
        $path = strtok($url, '?') ?: '/';
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        if ($path === '/library') {
            if (($query['view'] ?? null) === 'grid') {
                unset($query['view']);
            }
            if (($query['q'] ?? null) === '') {
                unset($query['q']);
            }
        }

        return $query === [] ? $path : $path.'?'.http_build_query($query);
    }

    private function isPublicAsset(string $path): bool
    {
        $file = public_path(ltrim(strtok($path, '?') ?: '', '/'));

        return is_file($file) && (str_ends_with($file, '.css') || str_ends_with($file, '.js'));
    }

    /**
     * GET pages only. Form endpoints are excluded by name because a static host
     * has nothing to POST to, and the snapshot should not link into a 405.
     */
    private function isCrawlable(string $path): bool
    {
        $bare = strtok($path, '?') ?: '/';

        return ! in_array($bare, ['/demo-user', '/up'], true)
            && ! str_starts_with($bare, '/_');
    }

    private function isCover(string $link): bool
    {
        return (bool) preg_match('~^https://(covers\.openlibrary\.org|books\.google\.com)/~', $link);
    }

    private function fetchCover(string $url): void
    {
        if (isset($this->covers[$url])) {
            return;
        }

        // Open Library covers have a unique file name in the path (11481354-M.jpg)
        // and keep it, so the tree stays readable. Google Books thumbnails all
        // share the path /books/content and differ only in the query, so a name
        // taken from the path would make every one of them the same file; any
        // name already claimed by a different URL gets a short hash appended.
        $name = Str::slug(pathinfo(parse_url($url, PHP_URL_PATH) ?: 'cover', PATHINFO_FILENAME)) ?: 'cover';
        $extension = pathinfo(parse_url($url, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION) ?: 'jpg';
        $relative = "assets/covers/{$name}.{$extension}";

        if (in_array($relative, $this->covers, true)) {
            $relative = "assets/covers/{$name}-".substr(sha1($url), 0, 8).".{$extension}";
        }

        try {
            $response = Http::timeout(15)->get($url);

            if (! $response->ok() || $response->body() === '') {
                throw new \RuntimeException("status {$response->status()}");
            }

            $target = "{$this->out}/{$relative}";
            File::ensureDirectoryExists(dirname($target));
            File::put($target, $response->body());
            $this->covers[$url] = $relative;
        } catch (\Throwable $e) {
            // Left pointing at the provider. Under a strict img-src the browser will
            // show nothing rather than the picture, which is the honest outcome of
            // a failed download and is reported in the summary.
            $this->coverFailures++;
            $this->components->warn("cover {$url} not downloaded (".Str::limit($e->getMessage(), 80).')');
        }
    }

    /**
     * Rewrites every same-origin URL and every downloaded cover, drops the
     * script tag unless asked not to, and injects the banner.
     */
    private function rewrite(string $html): string
    {
        $html = preg_replace_callback('/\b(href|src|action)="([^"]*)"/i', function (array $m) {
            $attr = $m[1];
            $raw = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5);

            $local = $this->localPath($raw);
            if ($local !== null) {
                return $attr.'="'.e($this->publicUrl($local)).'"';
            }

            if (isset($this->covers[$raw])) {
                return $attr.'="'.e($this->base.'/'.$this->covers[$raw]).'"';
            }

            return $m[0];
        }, $html) ?? $html;

        // CSRF tokens are per-render randomness in otherwise inert forms. Left in,
        // no two runs of this command produce the same bytes and every refresh of
        // a committed snapshot churns every page for nothing.
        $html = preg_replace('~\s*<input type="hidden" name="_token" value="[^"]*" autocomplete="off">~', '', $html) ?? $html;

        if (! $this->option('keep-scripts')) {
            $html = preg_replace('~\s*<script src="[^"]*"></script>~', '', $html) ?? $html;
        }

        if (! $this->option('no-banner')) {
            $html = preg_replace('~(<main[^>]*>)~', '$1'."\n".self::BANNER, $html, 1) ?? $html;
        }

        return $html;
    }

    /** The clean, subdirectory-prefixed URL a local path is served at in the snapshot. */
    private function publicUrl(string $local): string
    {
        if ($this->isPublicAsset($local)) {
            return $this->base.'/'.ltrim(strtok($local, '?') ?: '', '/');
        }

        $file = $this->pages[$local] ?? $this->fileFor($local);
        $dir = dirname($file);

        return $dir === '.' ? $this->base : $this->base.'/'.$dir;
    }

    /**
     * Where a page lives in the tree. Directory-per-page with index.html, named
     * from the path plus a readable slug of the query where the query is what
     * distinguishes the page.
     */
    private function fileFor(string $url): string
    {
        $path = trim(strtok($url, '?') ?: '', '/');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $suffix = match (true) {
            $path === 'book' => Str::slug(trim(($query['title'] ?? 'book').' '.($query['author'] ?? ''))),
            $path === 'library' && ($query['view'] ?? 'grid') === 'list' && empty($query['q']) => 'list',
            $path === 'library' && ! empty($query['q']) => 'search-'.Str::slug((string) $query['q']).((($query['view'] ?? 'grid') === 'list') ? '-list' : ''),
            $path === 'library' => '',
            $query === [] => '',
            default => Str::slug(http_build_query($query)),
        };

        $segments = array_filter([$path, $suffix], fn ($s) => $s !== '');
        $dir = implode('/', $segments);

        // Two different URLs must never claim the same file.
        $candidate = $dir;
        $n = 2;
        while (in_array(($candidate === '' ? '' : $candidate.'/').'index.html', $this->pages, true)) {
            $candidate = $dir.'-'.$n++;
        }

        return ($candidate === '' ? '' : $candidate.'/').'index.html';
    }

    private function copyAssets(): void
    {
        foreach ($this->assets as $public => $relative) {
            $from = public_path(ltrim(strtok($public, '?') ?: '', '/'));
            $to = "{$this->out}/{$relative}";
            File::ensureDirectoryExists(dirname($to));
            File::copy($from, $to);
        }
    }

    private function resolveOut(string $out): string
    {
        $isAbsolute = str_starts_with($out, '/') || preg_match('~^[A-Za-z]:[\\\\/]~', $out) === 1;

        return rtrim($isAbsolute ? $out : base_path($out), '/\\');
    }
}
