<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Documents — pack_folder(): a folder packed cold, the latest versions included
 *
 * One archive for a folder's documents, a file they share stored once, every
 * version read back, nothing loose left; an archive deleted only when nothing
 * refers to it; a bundle saved again after packing keeps its assets.
 *
 * Run: php src/Libs/Italix/Documents/tests/FolderPackTest.php
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

use Italix\Documents\DocumentsException;
use Italix\Documents\Library;
use Italix\Documents\RevisionArchive;
use Italix\Storage\LocalStore;

use function Italix\Testing\{suite, section, test, summary};

suite('Italix Documents — pack_folder()');

if (!extension_loaded('pdo_sqlite') || !extension_loaded('gd') || !RevisionArchive::is_available()) {
    echo "  SKIPPED — needs pdo_sqlite, ext-gd, and the tar and zstd commands.\n";
    exit(summary());
}

function folder_png(int $red, int $green, int $blue): string
{
    $image = imagecreatetruecolor(8, 8);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, $red, $green, $blue));
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

/** The archive's members, as tar lists them. */
function folder_members(LocalStore $store, string $archive_c): array
{
    $listing = (string) shell_exec('zstd -dc --long=27 ' . escapeshellarg((string) $store->absolute_path($archive_c)) . ' | tar -tf -');

    return array_values(array_filter(explode("\n", $listing)));
}

function folder_throws(callable $fn): bool
{
    try {
        $fn();
    } catch (DocumentsException $e) {
        return true;
    }

    return false;
}

$base = sys_get_temp_dir() . '/ix-documents-folder-pack-' . bin2hex(random_bytes(6));

try {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $store   = new LocalStore($base . '/store', Library::suggested_path_strategy(), Library::suggested_catalog());
    $library = new Library($pdo, $store);
    $library->install();
    $tree_id = $library->create_tree('web', 'Web');

    $font = "A shared stylesheet the three pages carry.\n" . str_repeat("p { margin: 0; }\n", 50);
    $a1 = $library->save($tree_id, 'web/site/2026-07/a.html', "<p>A, first</p>\n", ['assets' => ['site.txt' => $font, 'a.png' => folder_png(255, 0, 0)]]);
    $a2 = $library->save($tree_id, 'web/site/2026-07/a.html', "<p>A, second</p>\n");
    $b  = $library->save($tree_id, 'web/site/2026-07/b.html', "<p>B</p>\n", ['assets' => ['site.txt' => $font, 'b.png' => folder_png(0, 255, 0)]]);
    $c  = $library->save($tree_id, 'web/site/2026-07/c.html', "<p>C</p>\n", ['assets' => ['site.txt' => $font]]);
    $library->save($tree_id, 'web/other/elsewhere.html', "<p>Elsewhere</p>\n");
    $all = [$a1, $a2, $b, $c];

    $loose = [];
    foreach ($all as $v) {
        $loose[] = $v->storage_path();
        foreach ($library->assets($v->id()) as $asset) {
            $loose[] = $asset->storage_path();
        }
    }
    $before = [];
    foreach ($all as $v) {
        $before[$v->id()] = [$library->read_version($v->id()), array_map(static fn ($a) => $library->read_asset($v->id(), $a->name()), $library->assets($v->id()))];
    }

    // -------------------------------------------------------------------------
    section('a folder packed cold');

    test('no such folder: refused', folder_throws(fn () => $library->pack_folder($tree_id, 'web/nothing')));
    test('a document is not a folder: refused', folder_throws(fn () => $library->pack_folder($tree_id, 'web/site/2026-07/a.html')));

    $dry = $library->pack_folder($tree_id, 'web/site/2026-07', ['dry_run' => true]);
    test('a dry run reports, and marks nothing', $dry['documents_n'] === 3 && $dry['versions_n'] === 4 && !$library->version($a1->id())->is_archived());

    $report = $library->pack_folder($tree_id, 'web/site/2026-07');
    test('three documents, four versions — the latest ones included', [$report['documents_n'], $report['versions_n'], $report['is_unchanged']] === [3, 4, false]);
    test('...the same numbers the dry run gave', array_diff_key($report, ['is_unchanged' => 1]) === array_diff_key($dry, ['is_unchanged' => 1]));

    $archive_c = (string) $library->version($a1->id())->archive_path();
    $marked    = array_map(static fn ($v) => $library->version($v->id())->archive_path(), $all);
    test('every version of the folder, latest included, inside one archive', count(array_unique($marked)) === 1 && $archive_c !== '');
    test("the folder's own archive, not a document's", str_contains($archive_c, '/folders/'));

    $after = [];
    foreach ($all as $v) {
        $after[$v->id()] = [$library->read_version($v->id()), array_map(static fn ($a) => $library->read_asset($v->id(), $a->name()), $library->assets($v->id()))];
    }
    test('every version and every asset reads back byte for byte', $after === $before);

    $members = folder_members($store, $archive_c);
    test('the file the three pages share is one member', count(array_filter($members, static fn (string $m): bool => str_starts_with($m, 'assets/') && str_ends_with($m, '.txt'))) === 1);
    test('no loose blob left behind', array_filter($loose, static fn (string $p): bool => $store->has($p)) === []);
    test('a document outside the folder: untouched, loose', !$library->version((int) $library->node($tree_id, 'web/other/elsewhere.html')->latest_version_id())->is_archived());

    $again = $library->pack_folder($tree_id, 'web/site/2026-07');
    test('packed again: unchanged, nothing written', $again['is_unchanged'] === true && $store->has($archive_c));

    // -------------------------------------------------------------------------
    section('saving on a cold bundle');

    $a3 = $library->save($tree_id, 'web/site/2026-07/a.html', "<p>A, third</p>\n");
    test('a new version, loose, its assets carried from the archive', !$a3->is_archived() && array_keys($library->assets($a3->id())) === ['a.png', 'site.txt']);
    test("...stored again: loose and real, not rows pointing at a blob the pack deleted",
        $store->has($library->assets($a3->id())['a.png']->storage_path()) && $library->read_asset($a3->id(), 'a.png') === folder_png(255, 0, 0));

    // -------------------------------------------------------------------------
    section('an archive is deleted only when nothing refers to it');

    $library->save($tree_id, 'web/site/2026-07/a.html', "<p>A, fourth</p>\n");
    $packed = $library->pack($tree_id, ['min_old_versions_n' => 1, 'large_bytes_n' => null]);
    test("pack() takes a's old versions out of the folder's archive into its own", isset($packed['packed']['web/site/2026-07/a.html']) && $library->version($a1->id())->archive_path() !== $archive_c);
    test("...and leaves the folder's archive alone: b and c still live in it", $store->has($archive_c) && $library->read_version($b->id()) === "<p>B</p>\n" && $library->read_asset($c->id(), 'site.txt') === $font);

    $own_c = (string) $library->version($a1->id())->archive_path();
    $library->pack_folder($tree_id, 'web/site/2026-07');
    $new_c = (string) $library->version($a1->id())->archive_path();
    test('packed as a folder again: everything in a new archive', $new_c !== $archive_c && $new_c === $library->version($c->id())->archive_path());
    test("...a's own archive and the old folder archive, referred to by nothing, deleted", !$store->has($own_c) && !$store->has($archive_c));

    // -------------------------------------------------------------------------
    section('a folder inside a folder packed cold');

    $library->pack_folder($tree_id, 'web');
    $parent_c = (string) $library->version($b->id())->archive_path();
    test("the parent folder 'web': everything in it, 'other' included, in its archive", $parent_c === $library->version((int) $library->node($tree_id, 'web/other/elsewhere.html')->latest_version_id())->archive_path());
    $sub = $library->pack_folder($tree_id, 'web/site/2026-07');
    test("then 'web/site/2026-07': not unchanged — its versions sit in the parent's archive, not its own", $sub['is_unchanged'] === false && $library->version($b->id())->archive_path() !== $parent_c);
    test("...the parent's archive kept: 'other' still lives in it", $store->has($parent_c) && $library->read((int) $tree_id, 'web/other/elsewhere.html') === "<p>Elsewhere</p>\n");
} finally {
    RevisionArchive::remove_tree($base);
}

test('the temporary directories were removed', !is_dir($base));

exit(summary());
