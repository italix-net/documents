<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Documents — moving and removing a folder takes everything inside it
 *
 * Before 0.15.1, move() on a folder updated the folder's own row only: its
 * documents kept their old paths, so the tree listed them under a folder that
 * no longer existed and the new folder was empty. remove() on a folder marked
 * its direct children deleted but not their children, which stayed live under
 * a deleted parent. Both now work on the whole subtree, and every moved
 * document leaves its former path behind as an alias, as a single move does.
 *
 * Run: php src/Libs/Italix/Documents/tests/FolderMoveTest.php
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
use Italix\Documents\Node;
use Italix\Documents\Projection;
use Italix\Storage\LocalStore;

use function Italix\Testing\{suite, section, test, summary};

suite('Italix Documents — moving and removing a folder');

if (!extension_loaded('pdo_sqlite')) {
    echo "  SKIPPED — pdo_sqlite is absent.\n";
    exit(summary());
}

$base = sys_get_temp_dir() . '/ix-documents-folder-move-' . bin2hex(random_bytes(6));

/** @return list<string> the live document paths of the tree */
function folder_move_paths(Library $library, int $tree_id): array
{
    return array_map(static fn (Node $n): string => $n->path(), $library->documents($tree_id));
}

/** True when the call throws DocumentsException. */
function folder_move_throws(callable $fn): bool
{
    try {
        $fn();
    } catch (DocumentsException) {
        return true;
    }

    return false;
}

try {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $library = new Library($pdo, new LocalStore($base . '/store', Library::suggested_path_strategy(), Library::suggested_catalog()));
    $library->install();
    $tree_id = $library->create_tree('site', 'Site');

    $library->save($tree_id, 'books/old/meta.yaml', "title: Old\n");
    $library->save($tree_id, 'books/old/it.md', "# Vecchio\n");
    $library->save($tree_id, 'books/old/notes/draft.md', "# Draft\n");
    $library->save($tree_id, 'books/older/it.md', "# A sibling whose name starts the same\n");
    $library->save($tree_id, 'books/index.md', "[old](old/it.md) [draft](old/notes/draft.md)\n");

    $meta_id  = $library->node($tree_id, 'books/old/meta.yaml')->id();
    $draft_id = $library->node($tree_id, 'books/old/notes/draft.md')->id();

    // -------------------------------------------------------------------------
    section('move() on a folder');

    $moved = $library->move($tree_id, 'books/old', 'books/new');
    test('returns the folder at its new path', $moved->is_folder() && $moved->path() === 'books/new');
    test('every document inside moved, at any depth', folder_move_paths($library, $tree_id) === [
        'books/index.md', 'books/new/it.md', 'books/new/meta.yaml', 'books/new/notes/draft.md', 'books/older/it.md',
    ]);
    test('...keeping its node id', $library->node($tree_id, 'books/new/meta.yaml')?->id() === $meta_id
        && $library->node($tree_id, 'books/new/notes/draft.md')?->id() === $draft_id);
    test('...and its name', $library->node($tree_id, 'books/new/notes/draft.md')?->name() === 'draft.md');
    test('the sub-folder moved too, under the moved folder', $library->node($tree_id, 'books/new/notes')?->parent_id() === $moved->id());
    test('nothing is left at the old paths', $library->node($tree_id, 'books/old') === null
        && $library->node($tree_id, 'books/old/it.md') === null && $library->node($tree_id, 'books/old/notes') === null);
    test('a sibling whose name only starts the same is untouched', $library->node($tree_id, 'books/older/it.md') !== null);
    test('the contents read back at the new path', $library->read($tree_id, 'books/new/it.md') === "# Vecchio\n");

    // -------------------------------------------------------------------------
    section('the former paths are aliases');

    test('of the folder', $library->node_for_alias($tree_id, 'books/old')?->id() === $moved->id());
    test('of a document inside it', $library->node_for_alias($tree_id, 'books/old/meta.yaml')?->id() === $meta_id);
    test('of a document two levels down', $library->node_for_alias($tree_id, 'books/old/notes/draft.md')?->id() === $draft_id);
    test('moved_href() follows a link into the moved folder', $library->moved_href($tree_id, 'books/index.md', 'old/notes/draft.md#top') === 'new/notes/draft.md#top');

    $result = $library->relink($tree_id, ['author_c' => 'test']);
    test('relink() rewrites links into the moved folder', $library->read($tree_id, 'books/index.md') === "[old](new/it.md) [draft](new/notes/draft.md)\n"
        && array_keys($result['rewritten']) === ['books/index.md']);

    // -------------------------------------------------------------------------
    section('moved again, to another parent');

    $library->move($tree_id, 'books/new', 'archive/2010/new');
    test('everything followed', $library->node($tree_id, 'archive/2010/new/notes/draft.md')?->id() === $draft_id);
    test('the first former path still leads to the document', $library->node_for_alias($tree_id, 'books/old/notes/draft.md')?->path() === 'archive/2010/new/notes/draft.md');

    // -------------------------------------------------------------------------
    section('refused');

    test('a folder into itself', folder_move_throws(fn () => $library->move($tree_id, 'archive/2010', 'archive/2010/x')));
    test('anything under a document', folder_move_throws(fn () => $library->move($tree_id, 'books/older/it.md', 'books/index.md/it.md')));
    test('a refused move changes nothing', $library->node($tree_id, 'archive/2010/new/it.md') !== null
        && $library->node($tree_id, 'books/older/it.md') !== null);

    // -------------------------------------------------------------------------
    section('remove() on a folder');

    $library->remove($tree_id, 'archive');
    test('removes documents at every depth', folder_move_paths($library, $tree_id) === ['books/index.md', 'books/older/it.md']);
    test('...and the sub-folders', $library->node($tree_id, 'archive/2010') === null && $library->node($tree_id, 'archive/2010/new/notes') === null);
    test('a removed document is still in its history', $library->node_by_id($draft_id)?->latest_version_id() !== null);

    // -------------------------------------------------------------------------
    section('a checkout after a move');

    $work       = $base . '/work';
    $projection = new Projection($library, $work);
    $library->save($tree_id, 'albums/one/it.md', "# One\n");
    $library->save($tree_id, 'albums/two/it.md', "# Two\n");
    $projection->checkout($tree_id);
    file_put_contents($work . '/albums/two/it.md', "# Two, edited on disk\n");

    $library->move($tree_id, 'albums', 'music');
    $projection->checkout($tree_id);
    $changes = [];
    foreach ($projection->status($tree_id) as $change) {
        $changes[$change->path()] = $change->kind_code();
    }

    test('the moved files are written at the new path', is_file($work . '/music/one/it.md') && is_file($work . '/music/two/it.md'));
    test('an old copy left as checked out is deleted, its empty folder too', !is_file($work . '/albums/one/it.md') && !is_dir($work . '/albums/one'));
    test('an old copy edited since is kept, and reported as added', is_file($work . '/albums/two/it.md') && ($changes['albums/two/it.md'] ?? null) === Change::ADDED);
    test('nothing else to import', array_diff_key($changes, ['albums/two/it.md' => true]) === array_fill_keys(['books/index.md', 'books/older/it.md', 'music/one/it.md', 'music/two/it.md'], Change::UNCHANGED));
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

exit(summary());
