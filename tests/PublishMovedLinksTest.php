<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Documents — a published link follows a moved document
 *
 * move() leaves the former path behind as an alias. Before 0.8.0, publish()
 * turned a link to that former path into `former.html` — a page that no
 * longer exists. Now it points where the document is, relative to the page
 * the link is on; a live document at the old path wins over the alias, and a
 * link whose document was removed is left as it was.
 *
 * Run: php src/Libs/Italix/Documents/tests/PublishMovedLinksTest.php
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
use Italix\Storage\LocalStore;

use function Italix\Testing\{suite, section, test, summary};

suite('Italix Documents — a published link follows a moved document');

if (!extension_loaded('pdo_sqlite')) {
    echo "  SKIPPED — pdo_sqlite is absent.\n";
    exit(summary());
}

$base = sys_get_temp_dir() . '/ix-documents-moved-links-' . bin2hex(random_bytes(6));

/** The href values of a published page, in order. */
function moved_links_hrefs(string $page_path): array
{
    preg_match_all('~href="([^"]+)"~', (string) file_get_contents($page_path), $m);

    return $m[1];
}

try {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $library = new Library($pdo, new LocalStore($base . '/store', Library::suggested_path_strategy(), Library::suggested_catalog()));
    $library->install();
    $tree_id    = $library->create_tree('manual', 'Handbook');
    $projection = new Projection($library, $base . '/work');
    $site       = $base . '/site';

    $library->save($tree_id, 'en/guide/install.md', "# Install\n");
    $library->save($tree_id, 'en/old-name.md', "# Old\n");
    $library->save($tree_id, 'en/gone.md', "# Gone\n");
    $library->save($tree_id, 'en/index.md', implode("\n", [
        '# Handbook',
        '',
        '[install](guide/install.md)',
        '[steps](guide/install.md#steps)',
        '[old](old-name.md)',
        '[gone](gone.md)',
        '[web](https://example.org/guide/install.md)',
        '[outside](../../outside.md)',
        '',
    ]));
    $library->save($tree_id, 'it/pagina.md', "# Pagina\n\n[install](../en/guide/install.md)\n");

    // -------------------------------------------------------------------------
    section('before any move: links become .html, as always');

    $projection->publish($tree_id, $site);
    test('the index links as written, with .html', moved_links_hrefs($site . '/en/index.html') === [
        'guide/install.html', 'guide/install.html#steps', 'old-name.html', 'gone.html',
        'https://example.org/guide/install.md', '../../outside.html',
    ]);

    // -------------------------------------------------------------------------
    section('after moves: a link to a former path follows the document');

    $library->move($tree_id, 'en/guide/install.md', 'en/guide/setup.md');
    $library->move($tree_id, 'en/old-name.md', 'en/Manuale Utente.md');
    $library->move($tree_id, 'en/gone.md', 'en/gone-too.md');
    $library->remove($tree_id, 'en/gone-too.md');
    $projection->publish($tree_id, $site);

    $hrefs = moved_links_hrefs($site . '/en/index.html');
    test('guide/install.md -> guide/setup.html', $hrefs[0] === 'guide/setup.html');
    test('...its fragment kept: guide/setup.html#steps', $hrefs[1] === 'guide/setup.html#steps');
    test('a new name with a space is URL-encoded: Manuale%20Utente.html', $hrefs[2] === 'Manuale%20Utente.html');
    test('a document moved and then removed: the link is left as it was', $hrefs[3] === 'gone.html');
    test('an absolute URL is never touched; a link climbing out of the tree is not followed, only given .html as before', [$hrefs[4], $hrefs[5]] === ['https://example.org/guide/install.md', '../../outside.html']);
    test('from a page in another folder: ../en/guide/setup.html', moved_links_hrefs($site . '/it/pagina.html') === ['../en/guide/setup.html']);

    // -------------------------------------------------------------------------
    section('moved again, into another folder');

    $library->move($tree_id, 'en/guide/setup.md', 'docs/setup.md');
    $projection->publish($tree_id, $site);
    test('the first former path still leads to it: ../docs/setup.html', moved_links_hrefs($site . '/en/index.html')[0] === '../docs/setup.html');
    test('...and from it/: ../docs/setup.html', moved_links_hrefs($site . '/it/pagina.html') === ['../docs/setup.html']);
    test('the published page is really there', is_file($site . '/docs/setup.html'));

    // -------------------------------------------------------------------------
    section('a live document at the former path wins over the alias');

    $library->save($tree_id, 'en/guide/install.md', "# A new install page\n");
    $projection->publish($tree_id, $site);
    test('guide/install.md is a document again: the link stays guide/install.html', moved_links_hrefs($site . '/en/index.html')[0] === 'guide/install.html');
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
