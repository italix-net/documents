<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Documents — a tree that keeps everything, and admits when it diverged
 *
 * Two properties carry this library, and both pass a naive test that proves
 * nothing:
 *
 *   1. **Nothing is ever lost.** Last-writer-wins is only safe because the loser
 *      is still there. A store that overwrote in place would also pass "save,
 *      read it back".
 *   2. **A divergence is visible.** Two people writing from the same base is not
 *      an error, but silence about it is.
 *
 * Everything here attacks those, plus the boundary where a human-chosen name
 * becomes a real file on a real filesystem.
 *
 * Run: php src/Libs/Italix/Documents/tests/DocumentsTest.php
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

use Italix\Documents\Change;
use Italix\Documents\DocumentsException;
use Italix\Documents\Library;
use Italix\Documents\Path;
use Italix\Documents\Projection;
use Italix\Documents\ProjectionIndex;
use Italix\Documents\RendererSet;
use Italix\Storage\HashedPath;
use Italix\Storage\LocalStore;

use function Italix\Testing\{suite, section, test, summary};

suite('Italix Documents');

if (!extension_loaded('pdo_sqlite')) {
    echo "  SKIPPED — pdo_sqlite is absent.\n";
    exit(summary());
}

$base = sys_get_temp_dir() . '/ix-documents-test-' . bin2hex(random_bytes(6));

$throws = static function (callable $fn): array {
    try {
        $fn();

        return [false, ''];
    } catch (DocumentsException $e) {
        return [true, $e->getMessage()];
    }
};

$fresh = static function (string $tag) use ($base): array {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $store = new LocalStore(
        $base . '/' . $tag . '/store',
        Library::suggested_path_strategy(),
        Library::suggested_catalog()
    );
    $lib   = new Library($pdo, $store);
    $lib->install();

    return [$lib, $lib->create_tree('manual', 'Handbook')];
};

try {

// -----------------------------------------------------------------------------
section('a human chooses the name, so the name is checked');

foreach ([
    '../../etc/passwd'   => 'traversal',
    'a/../b'             => 'traversal in the middle',
    'a//b'               => 'an empty segment',
    "a\0b"               => 'a NUL byte',
    "line\nbreak.md"     => 'a newline',
    '.hidden.md'         => 'a hidden name',
    '-rf.md'             => 'a name that reads as a flag',
    'en/ guide/x.md'     => 'leading whitespace inside a segment',
    'en/guide /x.md'     => 'trailing whitespace inside a segment',
    ''                   => 'nothing at all',
    '   '                => 'whitespace and nothing else',
] as $path_c => $why) {
    [$threw] = $throws(static function () use ($path_c): void { Path::clean($path_c); });
    test("{$why} is refused", $threw, var_export($path_c, true));
}

foreach ([
    'en/guide/install.md',
    'it/guida/città.md',
    'Manuale Utente/capitolo 1.md',
    'a_b-c.d.md',
] as $path_c) {
    test('a reasonable name is allowed: ' . $path_c, Path::is_clean($path_c));
}

test('cleaning is idempotent', Path::clean('/en/guide/install.md/') === 'en/guide/install.md');
test('whitespace around the whole path is normalised away, not refused',
    Path::clean('  en/guide/install.md  ') === 'en/guide/install.md',
    'a path pasted from a terminal should just work');
test('backslashes become slashes', Path::clean('en\\guide\\install.md') === 'en/guide/install.md');
test('the extension is lowercased', Path::extension_of('a/B.MD') === 'md');
test('a dotfile has no extension', Path::extension_of('a/.md') === '');
test('ancestors come out nearest first',
    Path::ancestors_of('en/guide/deep/x.md') === ['en/guide/deep', 'en/guide', 'en']);

// -----------------------------------------------------------------------------
section('nothing is ever lost');

[$lib, $tree_id] = $fresh('lossless');

$v1 = $lib->save($tree_id, 'en/guide/install.md', "# Install\n\nOne.\n", ['author_c' => 'a@example.org']);
$v2 = $lib->save($tree_id, 'en/guide/install.md', "# Install\n\nTwo.\n", ['parent_version_id' => $v1->id()]);
$v3 = $lib->save($tree_id, 'en/guide/install.md', "# Install\n\nThree.\n", ['parent_version_id' => $v2->id()]);

$node = $lib->node($tree_id, 'en/guide/install.md');

test('the newest version is what reads back',
    strpos((string) $lib->read($tree_id, 'en/guide/install.md'), 'Three') !== false);
test('every version is still there', count($lib->history($node->id())) === 3);
test('…and every one is still readable',
    strpos((string) $lib->read_version($v1->id()), 'One') !== false
    && strpos((string) $lib->read_version($v2->id()), 'Two') !== false);
test('history is newest first', $lib->history($node->id())[0]->id() === $v3->id());
test('the author is kept', $v1->author() === 'a@example.org');

$again = $lib->save($tree_id, 'en/guide/install.md', "# Install\n\nThree.\n");
test('identical bytes are not a new revision', $again->id() === $v3->id(),
    'otherwise every no-op import would inflate the history');

// -----------------------------------------------------------------------------
section('divergence is accepted and admitted');

test('a first version is not divergent', !$v1->is_divergent());
test('a fast-forward is not divergent', !$v2->is_divergent());

$stale = $lib->save($tree_id, 'en/guide/install.md', "# Install\n\nFrom an old base.\n",
    ['parent_version_id' => $v1->id()]);

test('a write from a stale base IS divergent', $stale->is_divergent());
test('…but it was accepted, not refused', $lib->version($stale->id()) !== null);
test('…and it is now latest', $lib->node($tree_id, 'en/guide/install.md')->latest_version_id() === $stale->id());
test('…and the version it overtook survives untouched',
    strpos((string) $lib->read_version($v3->id()), 'Three') !== false);

$blind = $lib->save($tree_id, 'en/guide/install.md', "# Install\n\nNo claim made.\n");
test('claiming no base is not divergence, it is silence', !$blind->is_divergent(),
    'flagging every unchecked write would make the flag useless');

// -----------------------------------------------------------------------------
section('names, moves and removal');

[$lib, $tree_id] = $fresh('names');

$lib->save($tree_id, 'en/guide/install.md', "# Install\n");
test('folders were created on the way', $lib->node($tree_id, 'en/guide') !== null);
test('…as folders', $lib->node($tree_id, 'en/guide')->is_folder());
test('documents() excludes folders', count($lib->documents($tree_id)) === 1);

test('language is taken from a top-level folder that looks like one',
    $lib->node($tree_id, 'en/guide/install.md')->lang_code() === 'en');

$lib->save($tree_id, 'handbook/intro.md', "# Intro\n");
test('…and not invented for one that does not',
    $lib->node($tree_id, 'handbook/intro.md')->lang_code() === null,
    var_export($lib->node($tree_id, 'handbook/intro.md')->lang_code(), true));

$lib->move($tree_id, 'en/guide/install.md', 'en/guide/setup.md');
test('a move changes the path', $lib->node($tree_id, 'en/guide/setup.md') !== null);
test('…the old path is gone', $lib->node($tree_id, 'en/guide/install.md') === null);
test('…but still resolves as an alias', $lib->node_for_alias($tree_id, 'en/guide/install.md') !== null);
test('…to the same node',
    $lib->node_for_alias($tree_id, 'en/guide/install.md')->id() === $lib->node($tree_id, 'en/guide/setup.md')->id());
test('…and the history came with it', count($lib->history($lib->node($tree_id, 'en/guide/setup.md')->id())) === 1);

[$threw] = $throws(static function () use ($lib, $tree_id): void {
    $lib->move($tree_id, 'en/guide/setup.md', 'handbook/intro.md');
});
test('moving onto an existing document is refused', $threw);

$lib->remove($tree_id, 'en/guide/setup.md');
test('a removed document is gone from the tree', $lib->node($tree_id, 'en/guide/setup.md') === null);
test('…but its versions are not', count($lib->history(
    $lib->node_for_alias($tree_id, 'en/guide/install.md')->id()
)) === 1, 'a delete must not shred the record of what was there');

// -----------------------------------------------------------------------------
section('rendering refuses to be a vector');

$renderers = RendererSet::defaults();
$markdown  = <<<'MD'
# Title

<script>alert(1)</script>

An [ordinary link](guide/install.md) and a [dangerous one](javascript:alert(1)).

```
https://inside-a-code-fence.example/not-a-link
```

| a | b |
|---|---|
| 1 | 2 |
MD;

$rendered = $renderers->render($markdown, 'en/intro.md');

test('a heading renders', strpos($rendered->html(), '<h1>') !== false);
test('a table renders', strpos($rendered->html(), '<table>') !== false);
test('raw HTML is escaped, not passed through', strpos($rendered->html(), '<script>') === false,
    'a <script> in a .md is stored XSS the moment it is rendered');
test('…and survives as visible text', strpos($rendered->html(), '&lt;script&gt;') !== false);
test('a javascript: URL does not become an href',
    strpos($rendered->html(), 'href="javascript:') === false);
test('an ordinary link is kept', strpos($rendered->html(), 'href="guide/install.md"') !== false);

test('links come from the parse, so a code fence is not a link',
    !in_array('https://inside-a-code-fence.example/not-a-link', $rendered->links(), true),
    json_encode($rendered->links()));
test('…and the real links are found', in_array('guide/install.md', $rendered->links(), true));

test('extracted text drops fenced code', strpos($rendered->text(), 'code-fence') === false);
test('…and drops markup', strpos($rendered->text(), '<script') === false, $rendered->text());
test('…and keeps prose', strpos($rendered->text(), 'ordinary link') !== false);

$plain = $renderers->render('<b>not markdown</b>', 'notes/raw.txt');
test('an unknown format still renders, escaped', strpos($plain->html(), '&lt;b&gt;') !== false);
test('…rather than being refused, which would lose the file', strpos($plain->html(), '<pre>') === 0);

// -----------------------------------------------------------------------------
section('the catalogue a documentation tree actually needs');

$catalog = Library::suggested_catalog();

test('an empty file is accepted', $catalog->allows('application/x-empty'),
    'a placeholder page with nothing in it yet is ordinary');
test('JSON is accepted', $catalog->allows('application/json'));
test('a page that quotes HTML is accepted', $catalog->allows('text/html'),
    'a short page demonstrating markup sniffs as text/html, and a docs library must hold it');
test('a page that quotes XML is accepted', $catalog->allows('application/xml'));
test('SVG is accepted: stored, never served as it is', $catalog->allows('image/svg+xml'),
    'published through its renderer, escaped; as an asset never copied into a site');

[$lib, $tree_id] = $fresh('catalog');

// The guarantee that makes widening the catalogue safe: whatever is stored,
// nothing is ever published as live markup.
$lib->save($tree_id, 'en/markup.md', "<div class=\"box\">x</div>\n");
$lib->save($tree_id, 'en/real.html', "<b>a genuine HTML document</b>\n");

$catalog_site = $base . '/catalog/site';
(new Projection($lib, $base . '/catalog/work'))->publish($tree_id, $catalog_site);

test('a page quoting markup stores and publishes', is_file($catalog_site . '/en/markup.html'));
test('…with the markup escaped, not live',
    strpos((string) file_get_contents($catalog_site . '/en/markup.html'), '<div class="box">') === false,
    'this is the property that lets the catalogue be wider here than in an upload endpoint');
test('a genuine .html document is published escaped too',
    strpos((string) file_get_contents($catalog_site . '/en/real.html'), '<b>a genuine') === false,
    'no shipped renderer passes raw markup through');
test('…and its text is still visible',
    strpos((string) file_get_contents($catalog_site . '/en/real.html'), '&lt;b&gt;') !== false);

$empty = $lib->save($tree_id, 'en/placeholder.md', '');
test('an empty document really does store', $empty->bytes_n() === 0);
test('…and reads back as empty, not as null', $lib->read($tree_id, 'en/placeholder.md') === '');

// -----------------------------------------------------------------------------
section('a document\'s revisions land together');

// Not cosmetic: phase 3 packs one document's history into a solid tar.zst, and
// that is a subtree operation only if the history is a subtree.
[$lib, $tree_id] = $fresh('locality');

$a1 = $lib->save($tree_id, 'en/page.md', "# Page\n\nOne.\n");
$a2 = $lib->save($tree_id, 'en/page.md', "# Page\n\nTwo.\n", ['parent_version_id' => $a1->id()]);
$a3 = $lib->save($tree_id, 'en/page.md', "# Page\n\nThree.\n", ['parent_version_id' => $a2->id()]);
$b1 = $lib->save($tree_id, 'en/other.md', "# Other\n");

$folders = array_unique(array_map(static function ($v): string {
    return dirname($v->storage_path());
}, [$a1, $a2, $a3]));

test('three revisions share one folder', count($folders) === 1, json_encode(array_values($folders)));
test('…and a different document does not', dirname($b1->storage_path()) !== reset($folders));
test('the folder names the tree first, so a tree is one subtree',
    strpos((string) reset($folders), 'tree/' . $tree_id . '/') === 0, (string) reset($folders));
test('…then a bucket, so the parent never grows without limit',
    preg_match('~^tree/\d+/[0-9a-f]{2}/\d+$~', (string) reset($folders)) === 1, (string) reset($folders));

$spread = [];
for ($i = 1; $i <= 3000; $i++) {
    $spread[sprintf('%02x', $i % 256)] = true;
}
test('the bucket spreads sequential ids evenly', count($spread) === 256, (string) count($spread));

// The mistake this guards against is silent: a store with the default depth
// works perfectly and only shows itself when somebody tries to pack a history.
$scattered = new Library(
    (static function () { $p = new PDO('sqlite::memory:'); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); return $p; })(),
    new LocalStore($base . '/scattered/store', new HashedPath(2), Library::suggested_catalog())
);
$scattered->install();
$scattered_tree = $scattered->create_tree('x', 'X');
$s1 = $scattered->save($scattered_tree, 'p.md', "one\n");
$s2 = $scattered->save($scattered_tree, 'p.md', "two\n", ['parent_version_id' => $s1->id()]);

test('a sharding store scatters what the layout gathered',
    dirname($s1->storage_path()) !== dirname($s2->storage_path()),
    'which is why suggested_path_strategy() exists');
test('suggested_path_strategy is depth 0',
    Library::suggested_path_strategy()->describe() === (new HashedPath(0))->describe());

// -----------------------------------------------------------------------------
section('ready for an archive that does not exist yet');

// Phase 3 packs a document's history into a solid tar.zst. The column that
// records where a packed version went has to exist *now*: install() cannot
// introduce it later without a migration, and this library ships no migration
// runner. Same reasoning as lang_code and source_version_id.
[$lib, $tree_id] = $fresh('archive');
$loose = $lib->save($tree_id, 'en/page.md', "# Page\n");

test('a fresh version is loose', $loose->archive_path() === null);
test('…and says so', !$loose->is_archived());
test('storage_path_c is meaningful while loose', $loose->storage_path() !== '');

// install() must upgrade, not merely create: a schema that grows would
// otherwise leave every existing installation behind.
$upgrade_pdo = new PDO('sqlite::memory:');
$upgrade_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$upgrade_pdo->exec('CREATE TABLE ix_doc_version (id INTEGER PRIMARY KEY AUTOINCREMENT, node_id INTEGER)');

$upgraded = new Library($upgrade_pdo, new LocalStore(
    $base . '/archive/upgrade-store',
    Library::suggested_path_strategy(),
    Library::suggested_catalog()
));
$upgraded->install();

$columns = [];
foreach ($upgrade_pdo->query('PRAGMA table_info(ix_doc_version)') as $row) {
    $columns[] = $row['name'];
}

test('install() adds a missing column to an existing table',
    in_array('archive_path_c', $columns, true), json_encode($columns));
$upgraded->install();
test('…and doing it twice is still fine', true, 'idempotent has to mean "brings the schema up to date"');

// -----------------------------------------------------------------------------
section('the projection is a real directory');

[$lib, $tree_id] = $fresh('projection');
$lib->save($tree_id, 'en/index.md', "# Handbook\n\nSee [install](guide/install.md).\n");
$lib->save($tree_id, 'en/guide/install.md', "# Install\n\nSteps.\n");

$work       = $base . '/projection/work';
$projection = new Projection($lib, $work);

test('checkout writes every document', $projection->checkout($tree_id) === 2);
test('…at its real path', is_file($work . '/en/guide/install.md'));
test('…with the real contents',
    strpos((string) file_get_contents($work . '/en/guide/install.md'), '# Install') === 0);
test('…and an index beside it', is_file(ProjectionIndex::path_for($work)));
test('the index is readable text', json_decode((string) file_get_contents(ProjectionIndex::path_for($work)), true) !== null,
    'when a working copy goes wrong, being able to read the state file matters');

$changes = array_filter($projection->status($tree_id), static function (Change $c): bool {
    return $c->is_actionable();
});
test('a fresh checkout has nothing to report', $changes === []);

file_put_contents($work . '/en/guide/install.md', "# Install\n\nRevised steps.\n");
file_put_contents($work . '/en/new-page.md', "# New\n");

$kinds = [];
foreach ($projection->status($tree_id) as $change) {
    $kinds[$change->path()] = $change->kind_code();
}

test('an edited file is modified', ($kinds['en/guide/install.md'] ?? '') === Change::MODIFIED);
test('a new file is added', ($kinds['en/new-page.md'] ?? '') === Change::ADDED);
test('an untouched file is unchanged', ($kinds['en/index.md'] ?? '') === Change::UNCHANGED);

$report = $projection->import($tree_id, 'editor@example.org', 'a round of edits');
test('import writes both back', count($report['imported']) === 2, json_encode($report['imported']));
test('…and nothing was divergent', $report['divergent'] === []);
test('…the tree now has the new document', $lib->node($tree_id, 'en/new-page.md') !== null);
test('…and the edit landed',
    strpos((string) $lib->read($tree_id, 'en/guide/install.md'), 'Revised') !== false);

$second = $projection->import($tree_id);
test('importing again does nothing', $second['imported'] === [],
    'an idempotent import is what makes it safe to run from a hook');

// -----------------------------------------------------------------------------
section('the racy second: a same-size edit in the same second');

// This is the case that a size+mtime shortcut gets wrong, and the reason the
// index records when it was written. Measured, not imagined: 32 bytes replaced
// by 32 different bytes went unnoticed and a divergent write went unflagged.
[$lib, $tree_id] = $fresh('racy');
$lib->save($tree_id, 'a.md', "# A\n\nRevised.\n");

$racy_work  = $base . '/racy/work';
$projection = new Projection($lib, $racy_work);
$projection->checkout($tree_id);

$before = (string) file_get_contents($racy_work . '/a.md');
$after  = "# A\n\nAmended.\n";

file_put_contents($racy_work . '/a.md', $after);

test('the two contents are the same length', strlen($before) === strlen($after),
    strlen($before) . ' vs ' . strlen($after));
test('…and the change is still detected', array_reduce(
    $projection->status($tree_id),
    static function (bool $carry, Change $c): bool {
        return $carry || ($c->path() === 'a.md' && $c->kind_code() === Change::MODIFIED);
    },
    false
), 'the mtime shortcut must not be trusted inside the index\'s own second');

// -----------------------------------------------------------------------------
section('two writers, and the projection admits it');

[$lib, $tree_id] = $fresh('two-writers');
$lib->save($tree_id, 'a.md', "# A\n\nOriginal.\n");

$work_a     = $base . '/two-writers/work';
$projection = new Projection($lib, $work_a);
$projection->checkout($tree_id);

// Somebody else writes through the API while this working copy is out.
$lib->save($tree_id, 'a.md', "# A\n\nWritten by somebody else.\n");
file_put_contents($work_a . '/a.md', "# A\n\nWritten by the editor, unaware.\n");

$status = [];
foreach ($projection->status($tree_id) as $change) {
    $status[$change->path()] = $change;
}
test('status warns before the import', $status['a.md']->would_diverge());

$report = $projection->import($tree_id, 'editor@example.org');
test('the import is accepted', $report['imported'] === ['a.md']);
test('…and reported as divergent', $report['divergent'] === ['a.md']);
test('…the editor won, because last writer wins',
    strpos((string) $lib->read($tree_id, 'a.md'), 'unaware') !== false);
test('…and the other writer lost nothing', array_reduce(
    $lib->history($lib->node($tree_id, 'a.md')->id()),
    static function (bool $carry, $v) use ($lib): bool {
        return $carry || strpos((string) $lib->read_version($v->id()), 'somebody else') !== false;
    },
    false
), 'that is the entire justification for last-writer-wins');

// -----------------------------------------------------------------------------
section('an absent file is a question, not an instruction');

unlink($work_a . '/a.md');
$report = $projection->import($tree_id);

test('a deleted file is reported as missing', $report['missing'] === ['a.md']);
test('…and the document is still in the tree', $lib->node($tree_id, 'a.md') !== null,
    'a missing file may be a partial sync, and guessing destroys work');

// -----------------------------------------------------------------------------
section('the projection refuses to be confused');

[$threw] = $throws(static function () use ($lib): void {
    (new Projection($lib, sys_get_temp_dir() . '/ix-doc-no-checkout-' . bin2hex(random_bytes(4))))->status(1);
});
test('status without a checkout says so', $threw);

[$threw, $message] = $throws(static function () use ($projection): void {
    $projection->status(9999);
});
test('a projection of another tree is refused', $threw);
test('…naming which tree it holds', strpos($message, 'tree') !== false, $message);

// -----------------------------------------------------------------------------
section('publish produces a static site');

[$lib, $tree_id] = $fresh('publish');
$lib->save($tree_id, 'en/index.md', "# Handbook\n\nSee [install](guide/install.md) and [the web](https://example.org).\n");
$lib->save($tree_id, 'en/guide/install.md', "# Install\n\n<script>alert(1)</script>\n");

$site       = $base . '/publish/site';
$projection = new Projection($lib, $base . '/publish/work');

test('publish writes a page per document', $projection->publish($tree_id, $site) === 2);
test('…as HTML at a mirrored path', is_file($site . '/en/guide/install.html'));

$index_html = (string) file_get_contents($site . '/en/index.html');

test('a page is a whole document', strpos($index_html, '<!doctype html>') === 0);
test('…with the language declared', strpos($index_html, 'lang="en"') !== false);
test('internal .md links are rewritten to .html',
    strpos($index_html, 'href="guide/install.html"') !== false, $index_html);
test('…and external links are left alone',
    strpos($index_html, 'href="https://example.org"') !== false);
test('the escaping survives into the published page',
    strpos((string) file_get_contents($site . '/en/guide/install.html'), '<script>') === false);

// A renamed or removed document must not stay published. Found on a real tree:
// a page renamed weeks earlier was still being served, because publish only
// ever wrote and never removed.
test('a manifest records what was published', is_file($site . '/' . Projection::PUBLISHED_MANIFEST));

$lib->move($tree_id, 'en/guide/install.md', 'en/guide/setup.md');
$projection->publish($tree_id, $site);

test('the renamed page is published under its new name', is_file($site . '/en/guide/setup.html'));
test('…and the old page is gone', !is_file($site . '/en/guide/install.html'),
    'a withdrawn page that stays readable is not untidiness, it is a leak');

$lib->remove($tree_id, 'en/guide/setup.md');
$projection->publish($tree_id, $site);

test('a removed document is unpublished too', !is_file($site . '/en/guide/setup.html'));
test('…and the pages that remain are untouched', is_file($site . '/en/index.html'));

// A file the library never wrote must survive: the manifest is what makes this
// safe, rather than deleting every .html that was not just written.
file_put_contents($site . '/en/not-ours.html', 'written by somebody else');
$projection->publish($tree_id, $site);
test('a file the library never published is left alone', is_file($site . '/en/not-ours.html'));

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
