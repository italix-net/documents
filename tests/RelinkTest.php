<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Documents — relink(): the sources follow a moved document
 *
 * What is rewritten (inline links, fragments, a reference definition, an
 * image, an angle-bracket destination with a space), what is not (code,
 * live, external, removed, out-of-tree destinations), how it is saved (a
 * new version, author, note, parent, never divergent), a dry run, a second
 * run with nothing to do, and a rewrite the parser refuses — skipped, not
 * saved.
 *
 * Run: php src/Libs/Italix/Documents/tests/RelinkTest.php
 */

declare(strict_types=1);

(static function (): void {
    foreach ([
        __DIR__ . '/../vendor/autoload.php',
        __DIR__ . '/../../../../../vendor/autoload.php',
        __DIR__ . '/../../../../vendor/autoload.php',
        __DIR__ . '/../../../autoload.php',
    ] as $autoload) {
        if (is_file($autoload)) {
            require_once $autoload;

            return;
        }
    }

    fwrite(STDERR, "Could not find an autoloader. Run composer install.\n");
    exit(2);
})();

use Italix\Documents\Library;
use Italix\Documents\Projection;
use Italix\Storage\FileStore;
use Italix\Storage\LocalStore;
use Italix\Storage\StoredFile;

use function Italix\Testing\{suite, section, test, summary};

suite('Italix Documents — relink()');

if (!extension_loaded('pdo_sqlite')) {
    echo "  SKIPPED — pdo_sqlite is absent.\n";
    exit(summary());
}

$base = sys_get_temp_dir() . '/ix-documents-relink-' . bin2hex(random_bytes(6));

/**
 * A store that runs <on_read> once, just before it serves <path_c> — somebody else saving while
 * relink() is between reading a document and writing it back.
 */
final class InterleavingStore implements FileStore
{
    /** @var (callable(): void)|null */
    public $on_read = null;
    public string $path_c = '';

    public function __construct(private readonly FileStore $inner)
    {
    }

    public function read(string $path_c): ?string
    {
        if ($this->on_read !== null && $path_c === $this->path_c) {
            $hook          = $this->on_read;
            $this->on_read = null;
            $hook();
        }

        return $this->inner->read($path_c);
    }

    public function put(string $folder, string $source_path, string $original_name_c = ''): StoredFile { return $this->inner->put($folder, $source_path, $original_name_c); }
    public function put_contents(string $folder, string $contents, string $original_name_c = ''): StoredFile { return $this->inner->put_contents($folder, $contents, $original_name_c); }
    public function put_stream(string $folder, $handle, string $original_name_c = ''): StoredFile { return $this->inner->put_stream($folder, $handle, $original_name_c); }
    public function has(string $path_c): bool { return $this->inner->has($path_c); }
    public function read_stream(string $path_c) { return $this->inner->read_stream($path_c); }
    public function absolute_path(string $path_c): ?string { return $this->inner->absolute_path($path_c); }
    public function temporary_url(string $path_c, int $ttl_n = 300): ?string { return $this->inner->temporary_url($path_c, $ttl_n); }
    public function delete(string $path_c): bool { return $this->inner->delete($path_c); }
    public function size_n(string $path_c): int { return $this->inner->size_n($path_c); }
}

try {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $store   = new InterleavingStore(new LocalStore($base . '/store', Library::suggested_path_strategy(), Library::suggested_catalog()));
    $library = new Library($pdo, $store);
    $library->install();
    $tree_id = $library->create_tree('manual', 'Handbook');

    $library->save($tree_id, 'en/guide/install.md', "# Install\n");
    $library->save($tree_id, 'en/old-name.md', "# Old\n");
    $library->save($tree_id, 'en/logo.png', "not really a png, a document all the same\n");
    $library->save($tree_id, 'en/gone.md', "# Gone\n");
    $library->save($tree_id, 'en/live.md', "# Live\n");

    $index = implode("\n", [
        '# Handbook',
        '',
        'See [install](guide/install.md), the [steps](guide/install.md#steps) and [the manual](<old-name.md>).',
        '',
        '![logo](logo.png)',
        '',
        'Also [by reference][setup], [live](live.md), [gone](gone.md), [web](https://example.org/guide/install.md) and [out](../../outside.md).',
        '',
        'In code: `[install](guide/install.md)` stays.',
        '',
        '```',
        '[install](guide/install.md)',
        '```',
        '',
        '[setup]: guide/install.md "the setup page"',
        '',
    ]);
    $index_v1 = $library->save($tree_id, 'en/index.md', $index, ['author_c' => 'ada@example.org']);
    $library->save($tree_id, 'it/pagina.md', "# Pagina\n\n[install](../en/guide/install.md)\n");
    $library->save($tree_id, 'en/untouched.md', "# Nothing moved here\n\n[live](live.md)\n");

    $library->move($tree_id, 'en/guide/install.md', 'docs/setup.md');
    $library->move($tree_id, 'en/old-name.md', 'en/Manuale Utente.md');
    $library->move($tree_id, 'en/logo.png', 'img/logo.png');
    $library->move($tree_id, 'en/gone.md', 'en/gone-too.md');
    $library->remove($tree_id, 'en/gone-too.md');

    // -------------------------------------------------------------------------
    section('a dry run reports, and writes nothing');

    $dry = $library->relink($tree_id, ['author_c' => 'bob@example.org', 'dry_run' => true]);
    test('two documents would be rewritten: en/index.md and it/pagina.md', array_keys($dry['rewritten']) === ['en/index.md', 'it/pagina.md']);
    test('...none skipped; the four other Markdown documents unchanged (the PNG is not read)', $dry['skipped'] === [] && $dry['unchanged_n'] === 4);
    test('...and no version was written', $library->node($tree_id, 'en/index.md')->latest_version_id() === $index_v1->id());

    // -------------------------------------------------------------------------
    section('relink(): what is rewritten');

    $report = $library->relink($tree_id, ['author_c' => 'bob@example.org']);
    test('the report says the same as the dry run did', $report === $dry);
    test('en/index.md, every planned change', $report['rewritten']['en/index.md'] === [
        ['guide/install.md', '../docs/setup.md'],
        ['guide/install.md#steps', '../docs/setup.md#steps'],
        ['old-name.md', 'Manuale%20Utente.md'],
        ['logo.png', '../img/logo.png'],
    ]);

    $after = (string) $library->read($tree_id, 'en/index.md');
    test('an inline link: [install](../docs/setup.md)', str_contains($after, '[install](../docs/setup.md)'));
    test('...its fragment kept: [steps](../docs/setup.md#steps)', str_contains($after, '[steps](../docs/setup.md#steps)'));
    test('an angle-bracket destination, now with the new name encoded: [the manual](<Manuale%20Utente.md>)', str_contains($after, '[the manual](<Manuale%20Utente.md>)'));
    test('an image: ![logo](../img/logo.png)', str_contains($after, '![logo](../img/logo.png)'));
    test('a reference definition, its title kept: [setup]: ../docs/setup.md "the setup page"', str_contains($after, '[setup]: ../docs/setup.md "the setup page"'));
    test('from another folder: [install](../docs/setup.md)', str_contains((string) $library->read($tree_id, 'it/pagina.md'), '[install](../docs/setup.md)'));

    // -------------------------------------------------------------------------
    section('relink(): what is not');

    test('a code span is code: `[install](guide/install.md)` stays', str_contains($after, 'In code: `[install](guide/install.md)` stays.'));
    test('a code block is code: its line stays', substr_count($after, "```\n[install](guide/install.md)\n```") === 1);
    test('a live document, a removed one, a URL and a link out of the tree: as written', str_contains($after, '[live](live.md), [gone](gone.md), [web](https://example.org/guide/install.md) and [out](../../outside.md)'));
    test('a document with nothing moved: no new version', count($library->history($library->node($tree_id, 'en/untouched.md')->id())) === 1);
    test('the aliases stay: the old path still finds the document', $library->node_for_alias($tree_id, 'en/guide/install.md')?->path() === 'docs/setup.md');

    // -------------------------------------------------------------------------
    section('relink(): how it is saved');

    $latest = $library->version($library->node($tree_id, 'en/index.md')->latest_version_id());
    test('a new version, by the one who ran it', $latest->id() !== $index_v1->id() && $latest->author() === 'bob@example.org');
    test('its note says what changed', str_starts_with($latest->note(), 'relink: guide/install.md -> ../docs/setup.md, '));
    test('its parent is the version it rewrote: not divergent', $latest->parent_version_id() === $index_v1->id() && !$latest->is_divergent());
    test('the version before is still there', $library->read_version($index_v1->id()) === $index);

    // -------------------------------------------------------------------------
    section('a second run finds nothing');

    $again = $library->relink($tree_id, ['author_c' => 'bob@example.org']);
    test('nothing rewritten, nothing skipped', $again['rewritten'] === [] && $again['skipped'] === []);

    // -------------------------------------------------------------------------
    section('a rewrite the parser refuses is skipped, never saved');

    $library->save($tree_id, 'en/tricky.md', "# Tricky\n\nA code span over two lines: `start\n[x](live-soon.md) end` — code, all of it.\n\nAnd a real link: [x](live-soon.md).\n");
    $library->save($tree_id, 'en/live-soon.md', "# Soon\n");
    $library->move($tree_id, 'en/live-soon.md', 'en/moved-soon.md');
    $tricky_before = $library->node($tree_id, 'en/tricky.md')->latest_version_id();
    $refused       = $library->relink($tree_id, ['author_c' => 'bob@example.org']);
    test('the destinations alone would pass (the parser never saw a link in the code span) — the HTML check refuses it', ($refused['skipped']['en/tricky.md'] ?? '') === 'the rewrite would change more than link destinations');
    test('...and not saved', $library->node($tree_id, 'en/tricky.md')->latest_version_id() === $tricky_before);

    // -------------------------------------------------------------------------
    section('somebody saved while relink() was working: its version is flagged, not silently on top');

    $library->save($tree_id, 'en/busy.md', "# Busy\n\n[install](guide/setup-old.md)\n");
    $library->save($tree_id, 'en/guide/setup-old.md', "# Setup old\n");
    $library->move($tree_id, 'en/guide/setup-old.md', 'en/guide/setup-new.md');
    $busy_node      = $library->node($tree_id, 'en/busy.md');
    $store->path_c  = $library->version($busy_node->latest_version_id())->storage_path();
    $store->on_read = static function () use ($library, $tree_id, $busy_node): void {
        $library->save($tree_id, 'en/busy.md', "# Busy, edited meanwhile\n\n[install](guide/setup-old.md)\n", [
            'author_c'          => 'carol@example.org',
            'parent_version_id' => $busy_node->latest_version_id(),
        ]);
    };
    $library->relink($tree_id, ['author_c' => 'bob@example.org']);
    $relinked = $library->version($library->node($tree_id, 'en/busy.md')->latest_version_id());
    test("relink()'s version names the version it read as its parent — and so is flagged divergent", $relinked->author() === 'bob@example.org' && $relinked->parent_version_id() === $busy_node->latest_version_id() && $relinked->is_divergent());
    test("...Carol's edit is still there, one version back", count($library->history($busy_node->id())) === 3);

    // -------------------------------------------------------------------------
    section('publishing after a relink');

    $projection = new Projection($library, $base . '/work');
    $projection->publish($tree_id, $base . '/site');
    $page = (string) file_get_contents($base . '/site/en/index.html');
    test('the page links the moved documents where they are', str_contains($page, 'href="../docs/setup.html"') && str_contains($page, 'href="Manuale%20Utente.html"') && str_contains($page, 'src="../img/logo.png"'));
} finally {
    if (is_dir($base)) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir((string) $f) : @unlink((string) $f);
        }
        @rmdir($base);
    }
}

test('the temporary directories were removed', !is_dir($base));

exit(summary());
