<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Documents — the README's Cookbook, run
 *
 * Every example of the README's "Cookbook" section, in the same order and
 * with the same calls, on SQLite in memory and a store in a temporary
 * directory — and what each comment there says came back, asserted. When
 * a README example changes, this file changes with it.
 *
 * Run: php src/Libs/Italix/Documents/tests/CookbookTest.php
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

use Italix\Converters\ConversionOptions;
use Italix\Converters\ConversionException;
use Italix\Documents\DocumentsException;
use Italix\Documents\HtmlToPdfConverter;
use Italix\Documents\Library;
use Italix\Documents\MarkdownToHtmlConverter;
use Italix\Documents\Path;
use Italix\Documents\PdfMerger;
use Italix\Documents\PdfPageSplitter;
use Italix\Documents\Projection;
use Italix\Storage\LocalStore;

use function Italix\Testing\{suite, section, test, summary};

suite('Italix Documents — the README Cookbook, run');

if (!extension_loaded('pdo_sqlite')) {
    echo "  SKIPPED — pdo_sqlite is absent.\n";
    exit(summary());
}

$base = sys_get_temp_dir() . '/ix-documents-cookbook-' . bin2hex(random_bytes(6));

/** How many pages a PDF has, by pdfinfo — never by trusting non-empty bytes. */
function cookbook_pages(string $pdf, string $dir): int
{
    $path = $dir . '/count-' . bin2hex(random_bytes(4)) . '.pdf';
    file_put_contents($path, $pdf);
    $info = (string) shell_exec('pdfinfo ' . escapeshellarg($path) . ' 2>/dev/null');
    unlink($path);

    return preg_match('/^Pages:\s+(\d+)/m', $info, $m) === 1 ? (int) $m[1] : 0;
}

try {
    // -------------------------------------------------------------------------
    section('A library');

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $library = new Library($pdo, new LocalStore(
        $base . '/store',
        Library::suggested_path_strategy(),
        Library::suggested_catalog()
    ));
    $library->install();

    $tree_id = $library->create_tree('manual', 'Handbook');
    test('a tree, by its slug', $library->tree_id_for('manual') === $tree_id);

    // -------------------------------------------------------------------------
    section('Versions: nothing is overwritten');

    $v1 = $library->save($tree_id, 'en/guide/install.md', "# Install\n\nOne.\n", [
        'author_c' => 'ada@example.org',
        'note_c'   => 'first draft',
    ]);
    $v2 = $library->save($tree_id, 'en/guide/install.md', "# Install\n\nTwo.\n", [
        'author_c'          => 'ada@example.org',
        'parent_version_id' => $v1->id(),
    ]);
    $same = $library->save($tree_id, 'en/guide/install.md', "# Install\n\nTwo.\n");

    test('the same bytes again are not a new version: save() returns v2', $same->id() === $v2->id());
    $node = $library->node($tree_id, 'en/guide/install.md');
    test('history(), newest first: v2, v1', array_map(static fn ($v) => $v->id(), $library->history($node->id())) === [$v2->id(), $v1->id()]);
    test('read() is the latest; read_version() any of them', $library->read($tree_id, 'en/guide/install.md') === "# Install\n\nTwo.\n" && $library->read_version($v1->id()) === "# Install\n\nOne.\n");
    test('a version knows who, why, what: ada@example.org, "first draft", text/plain, 16 bytes', [$v1->author(), $v1->note(), $v1->mime(), $v1->bytes_n()] === ['ada@example.org', 'first draft', 'text/plain', 16]);

    // -------------------------------------------------------------------------
    section('Two people wrote at once');

    $bob = $library->save($tree_id, 'en/guide/install.md', "# Install\n\nBob's.\n", [
        'author_c'          => 'bob@example.org',
        'parent_version_id' => $v1->id(),
    ]);
    test("Bob wrote from v1 while v2 was latest: accepted, and flagged divergent", $bob->is_divergent() && $bob->parent_version_id() === $v1->id());
    test('...and v2 is still there', $library->read_version($v2->id()) === "# Install\n\nTwo.\n");

    // -------------------------------------------------------------------------
    section('Moving, and removing');

    $moved = $library->move($tree_id, 'en/guide/install.md', 'en/guide/setup.md');
    test('the new path', $moved->path() === 'en/guide/setup.md');
    test('the old one is an alias: it still finds the document', $library->node_for_alias($tree_id, 'en/guide/install.md')?->path() === 'en/guide/setup.md');
    test('...with its whole history', count($library->history($moved->id())) === 3);

    $library->save($tree_id, 'en/draft.md', "# Draft\n");
    $draft_version = $library->node($tree_id, 'en/draft.md')->latest_version_id();
    $library->remove($tree_id, 'en/draft.md');
    test('remove(): gone from the tree', $library->node($tree_id, 'en/draft.md') === null);
    test('...its versions still readable by id', $library->read_version($draft_version) === "# Draft\n");

    // -------------------------------------------------------------------------
    section('Documents that come from other documents');

    $pdf    = (new HtmlToPdfConverter())->convert('<h1>Datasheet</h1><p>Rated 6 bar.</p>', 'html', 'pdf', ConversionOptions::none())->bytes();
    $source = $library->save($tree_id, 'sources/datasheet.pdf', $pdf);
    $text   = $library->save($tree_id, 'derived/datasheet.md', "# Datasheet\n\nRated 6 bar.\n", [
        'source_version_id' => $source->id(),
        'note_c'            => 'converted from the PDF',
    ]);
    test('a PDF is a document like any other: application/pdf', $source->mime() === 'application/pdf');
    test('the Markdown made from it points at that exact version of it', $text->source_version_id() === $source->id());

    $library->save($tree_id, 'it/guida.md', "# Guida\n", ['lang_c' => 'it']);
    test("a document's language: lang_c 'it'", $library->node($tree_id, 'it/guida.md')->lang_code() === 'it');

    // -------------------------------------------------------------------------
    section('Rendering');

    $library->save($tree_id, 'en/index.md', "# Handbook\n\nSee [setup](guide/setup.md).\n");
    $page = $library->render($tree_id, 'en/index.md');
    test('html(): the page', $page->html() === "<h1>Handbook</h1>\n<p>See <a href=\"guide/setup.md\">setup</a>.</p>\n");
    test("text(): 'Handbook See setup.' — what a search index or a chunker wants", $page->text() === 'Handbook See setup.');
    test("links(): ['guide/setup.md'], read from the parse tree", $page->links() === ['guide/setup.md']);

    $library->save($tree_id, 'en/unsafe.md', "# Hi\n\n<script>alert(1)</script>\n");
    test('raw HTML in Markdown is escaped, always', $library->render($tree_id, 'en/unsafe.md')->html() === "<h1>Hi</h1>\n&lt;script&gt;alert(1)&lt;/script&gt;\n");

    // -------------------------------------------------------------------------
    section('A working copy: checkout, edit with anything, import');

    $work       = $base . '/work';
    $projection = new Projection($library, $work);
    test('checkout(): every document in the tree written to disk, 6 (the removed draft is not)', $projection->checkout($tree_id) === 6);

    file_put_contents($work . '/en/guide/setup.md', "# Setup\n\nEdited on disk.\n");
    file_put_contents($work . '/en/new-page.md', "# New\n");
    unlink($work . '/it/guida.md');

    $lines = [];
    foreach ($projection->status($tree_id) as $change) {
        if ($change->is_actionable()) {
            $lines[] = $change->describe();
        }
    }
    test('status(): M, A and !', $lines === ['M  en/guide/setup.md', 'A  en/new-page.md', '!  it/guida.md']);

    $report = $projection->import($tree_id, 'ada@example.org', 'a round of edits');
    test('import(): two imported, one missing, four unchanged', $report === ['imported' => ['en/guide/setup.md', 'en/new-page.md'], 'divergent' => [], 'missing' => ['it/guida.md'], 'orphan_assets' => [], 'unchanged_n' => 4]);
    test('import() again: nothing to write', $projection->import($tree_id, 'ada@example.org')['imported'] === []);
    test('the missing file was reported, not deleted: it/guida.md is still in the tree', $library->node($tree_id, 'it/guida.md') !== null);

    // -------------------------------------------------------------------------
    section('Publishing a static site');

    $site = $base . '/site';
    $library->save($tree_id, 'en/faq.md', "# FAQ\n\nSee [install](guide/install.md).\n");
    test('publish(): one page per document, 8 — from the store, so it/guida.md too', $projection->publish($tree_id, $site) === 8);
    test("links between documents point at the pages: guide/setup.html", str_contains((string) file_get_contents($site . '/en/index.html'), '<a href="guide/setup.html">setup</a>'));
    test('a page still linking guide/install.md, the name before the move, links guide/setup.html', str_contains((string) file_get_contents($site . '/en/faq.html'), '<a href="guide/setup.html">install</a>'));

    file_put_contents($site . '/en/not-ours.html', 'written by somebody else');
    $library->remove($tree_id, 'en/new-page.md');
    $projection->publish($tree_id, $site);
    test('a removed document is unpublished on the next run', !is_file($site . '/en/new-page.html') && is_file($site . '/en/index.html'));
    test('...and a file the library never wrote is left alone', is_file($site . '/en/not-ours.html'));

    // -------------------------------------------------------------------------
    section('Relinking the sources after a move');

    $dry = $library->relink($tree_id, ['author_c' => 'ada@example.org', 'dry_run' => true]);
    test("a dry run: en/faq.md would have guide/install.md -> guide/setup.md, nothing skipped", ($dry['rewritten']['en/faq.md'] ?? null) === [['guide/install.md', 'guide/setup.md']] && $dry['skipped'] === []);
    $library->relink($tree_id, ['author_c' => 'ada@example.org']);
    test('relink(): en/faq.md now says [install](guide/setup.md)', str_contains((string) $library->read($tree_id, 'en/faq.md'), '[install](guide/setup.md)'));
    test('...a new version, its note saying what changed', $library->version($library->node($tree_id, 'en/faq.md')->latest_version_id())->note() === 'relink: guide/install.md -> guide/setup.md');

    // -------------------------------------------------------------------------
    section('Converters');

    $html = (new MarkdownToHtmlConverter())->convert("# Title\n\n*text*\n", 'md', 'html', ConversionOptions::none());
    test('md -> html: text/html', $html->mime() === 'text/html; charset=utf-8' && $html->bytes() === "<h1>Title</h1>\n<p><em>text</em></p>\n");

    $three = (new HtmlToPdfConverter())->convert(
        '<p>1</p><p style="page-break-before: always">2</p><p style="page-break-before: always">3</p>',
        'html',
        'pdf',
        ConversionOptions::none()
    )->bytes();
    test('html -> pdf: three pages', cookbook_pages($three, $base) === 3);

    if (!(new PdfPageSplitter())->is_available() || !(new PdfMerger())->is_available()) {
        echo "  SKIPPED the split/merge examples — poppler-utils is absent.\n";
    } else {
        $pages = (new PdfPageSplitter())->extract($three, 'pdf', null, ConversionOptions::none());
        test('every page on its own: three one-page PDFs', count($pages) === 3 && cookbook_pages($pages[2]->bytes(), $base) === 1);

        $parts = (new PdfPageSplitter())->extract($three, 'pdf', [[1, 1], [2, 3]], ConversionOptions::none());
        test('ranges: [1, 1] and [2, 3] give one page and two', cookbook_pages($parts[0]->bytes(), $base) === 1 && cookbook_pages($parts[1]->bytes(), $base) === 2);

        $again = (new PdfMerger())->merge([$parts[1]->bytes(), $parts[0]->bytes()], 'pdf', 'pdf', ConversionOptions::none());
        test('merged back, in the order given: three pages', cookbook_pages($again->bytes(), $base) === 3);

        $said = '';
        try {
            (new PdfPageSplitter())->extract($three, 'pdf', [[2, 9]], ConversionOptions::none());
        } catch (ConversionException $e) {
            $said = $e->getMessage();
        }
        test('a range past the end: "Requested range [2, 9] includes page 4, which does not exist."', $said === 'Requested range [2, 9] includes page 4, which does not exist.');
    }

    // -------------------------------------------------------------------------
    section('When it cannot work, it says so');

    test("a name is checked: '../etc/passwd' and '.hidden.md' refused, 'it/guida/città.md' allowed", !Path::is_clean('../etc/passwd') && !Path::is_clean('.hidden.md') && Path::is_clean('it/guida/città.md'));

    $said = [];
    foreach ([
        static fn () => $library->save($tree_id, '../x.md', 'x'),
        static fn () => $library->save($tree_id, 'en/guide', 'x'),
        static fn () => $library->move($tree_id, 'en/index.md', 'en/guide/setup.md'),
    ] as $call) {
        try {
            $call();
            $said[] = '';
        } catch (DocumentsException $e) {
            $said[] = $e->getMessage();
        }
    }
    test('traversal, a folder, a taken name: each refused with its reason', $said === [
        '"../x.md" contains "..", which is traversal.',
        '"en/guide" is a folder.',
        '"en/guide/setup.md" already exists.',
    ]);
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
