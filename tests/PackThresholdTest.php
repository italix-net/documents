<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Documents — packing large documents sooner
 *
 * A
 * document whose latest version, file and assets, is more than 400 KB is
 * packed from its third version; any other from its sixth, as before. The
 * boundary, a bundle made large by its assets, the rule turned off, custom
 * values, and what the third version costs once packed.
 *
 * Run: php src/Libs/Italix/Documents/tests/PackThresholdTest.php
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
use Italix\Documents\RevisionArchive;
use Italix\Storage\LocalStore;

use function Italix\Testing\{suite, section, test, summary};

suite('Italix Documents — packing large documents sooner');

if (!extension_loaded('pdo_sqlite') || !RevisionArchive::is_available()) {
    echo "  SKIPPED — needs pdo_sqlite, and the tar and zstd commands.\n";
    exit(summary());
}

/** Text of exactly <bytes_n> bytes: a page of hard-to-compress prose, <revision> at its end. */
function threshold_text(string $body, int $bytes_n, string $revision): string
{
    $head = "# Page\n\n" . $body;
    $tail = "\nRevision {$revision}.\n";

    return substr($head, 0, $bytes_n - strlen($tail)) . $tail;
}

$base = sys_get_temp_dir() . '/ix-documents-pack-threshold-' . bin2hex(random_bytes(6));

try {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $store   = new LocalStore($base . '/store', Library::suggested_path_strategy(), Library::suggested_catalog());
    $library = new Library($pdo, $store);
    $library->install();
    $tree_id = $library->create_tree('manual', 'Handbook');

    $body = base64_encode(random_bytes(600 * 1024));   // ~800 KB of text zstd can hardly shrink on its own

    // Three versions each: two old ones.
    foreach (['a large page' => ['big.md', 500 * 1024], 'a small page' => ['small.md', 10 * 1024], 'exactly 400 KB' => ['edge.md', 400 * 1024], 'one byte more' => ['edge-plus.md', 400 * 1024 + 1]] as [$path_c, $bytes_n]) {
        for ($v = 1; $v <= 3; $v++) {
            $library->save($tree_id, $path_c, threshold_text($body, $bytes_n, (string) $v));
        }
    }
    for ($v = 1; $v <= 3; $v++) {
        $library->save($tree_id, 'bundle.md', "# Data, revision {$v}\n", ['assets' => ['data.txt' => substr($body, 0, 450 * 1024) . $v]]);
    }
    test('the pages are the sizes they claim', strlen((string) $library->read($tree_id, 'edge.md')) === 400 * 1024 && strlen((string) $library->read($tree_id, 'edge-plus.md')) === 400 * 1024 + 1);

    // -------------------------------------------------------------------------
    section('turned off, or set differently');

    $off = $library->pack($tree_id, ['large_bytes_n' => null, 'dry_run' => true]);
    test("'large_bytes_n' => null: the count rule alone, nothing packed from two old versions", $off['packed'] === [] && $off['below_threshold_n'] === 5);

    $higher = $library->pack($tree_id, ['large_bytes_n' => 1024 * 1024, 'dry_run' => true]);
    test('a line at 1 MB: none of them is large', $higher['packed'] === []);

    $stricter = $library->pack($tree_id, ['large_min_old_versions_n' => 3, 'dry_run' => true]);
    test('large documents from three old versions: none has three yet', $stricter['packed'] === []);

    $lower = $library->pack($tree_id, ['large_bytes_n' => 5 * 1024, 'dry_run' => true]);
    test('a line at 5 KB: every one is large, the small page too', isset($lower['packed']['small.md']) && count($lower['packed']) === 5);

    // -------------------------------------------------------------------------
    section('the default: more than 400 KB, from the third version');

    $before_n = $library->history($library->node($tree_id, 'big.md')->id())[1]->bytes_n();
    $report   = $library->pack($tree_id);
    test('packed: the large page, the page one byte over the line, and the bundle made large by its assets', array_keys($report['packed']) === ['big.md', 'bundle.md', 'edge-plus.md']);
    test('...each with its two old versions', array_column($report['packed'], 'versions_n') === [2, 2, 2]);
    test('left loose: the small page, and the page of exactly 400 KB', $report['below_threshold_n'] === 2);

    $big = $report['packed']['big.md'];
    test('the archive of the large page costs less than one of its versions: the second is almost free', $big['archive_bytes_n'] < $before_n);
    printf("    (big.md: two old versions of %d KB, %d KB loose, archive %d KB)\n", intdiv($before_n, 1024), intdiv($big['loose_bytes_n'], 1024), intdiv($big['archive_bytes_n'], 1024));

    $history = $library->history($library->node($tree_id, 'big.md')->id());
    test('...its old versions archived, read back as saved; the latest loose', !$history[0]->is_archived() && $history[2]->is_archived() && $library->read_version($history[2]->id()) === threshold_text($body, 500 * 1024, '1'));
} finally {
    RevisionArchive::remove_tree($base);
}

test('the temporary directories were removed', !is_dir($base));

exit(summary());
