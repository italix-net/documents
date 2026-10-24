<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Documents — bundles: a document and its `.assets/` folder
 *
 * Saving a
 * bundle and what "the same version" means, the pump case, reserved folder
 * names, a move followed by relink() and publish(), the working copy
 * (checkout, status, import, orphan folders, divergence), checkout_version()
 * for every kind, and packing a bundle's history.
 *
 * Run: php src/Libs/Italix/Documents/tests/BundleTest.php
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
use Italix\Documents\Projection;
use Italix\Documents\RevisionArchive;
use Italix\Storage\LocalStore;
use Italix\Storage\StorageException;

use function Italix\Testing\{suite, section, test, summary};

suite('Italix Documents — bundles (kind C)');

if (!extension_loaded('pdo_sqlite') || !extension_loaded('gd') || !class_exists(ZipArchive::class) || !RevisionArchive::is_available()) {
    echo "  SKIPPED — needs pdo_sqlite, ext-gd, ext-zip, and the tar and zstd commands.\n";
    exit(summary());
}

/** A small PNG of one colour: a different colour is a different image. */
function bundle_png(int $red, int $green, int $blue): string
{
    $image = imagecreatetruecolor(8, 8);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, $red, $green, $blue));
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

/** Whether <fn> throws <class>. */
function bundle_throws(callable $fn, string $class = DocumentsException::class): bool
{
    try {
        $fn();
    } catch (Throwable $e) {
        return $e instanceof $class;
    }

    return false;
}

/** The changes status() reports, unchanged ones left out, as describe() prints them. */
function bundle_status(Projection $projection, int $tree_id): array
{
    $lines = [];
    foreach ($projection->status($tree_id) as $change) {
        if ($change->kind_code() !== Change::UNCHANGED) {
            $lines[] = $change->describe();
        }
    }

    return $lines;
}

/** Files under a directory, relative, sorted. */
function bundle_files(string $dir): array
{
    $out = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
        $out[] = substr((string) $file, strlen($dir) + 1);
    }
    sort($out);

    return $out;
}

$base = sys_get_temp_dir() . '/ix-documents-bundles-' . bin2hex(random_bytes(6));

try {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $store   = new LocalStore($base . '/store', Library::suggested_path_strategy(), Library::suggested_catalog());
    $library = new Library($pdo, $store);
    $library->install();
    $library->install();
    $tree_id = $library->create_tree('plant', 'Plant');

    $red    = bundle_png(255, 0, 0);
    $green  = bundle_png(0, 255, 0);
    $blue   = bundle_png(0, 0, 255);
    $yellow = bundle_png(255, 255, 0);
    $text   = "# Pump room\n\n![pump](notes.md.assets/pump.png)\n\n[valve](notes.md.assets/img/valve.png)\n";

    // -------------------------------------------------------------------------
    section('saving a bundle');

    $v1 = $library->save($tree_id, 'plant/notes.md', $text, ['assets' => ['pump.png' => $red, 'img/valve.png' => $blue], 'note_c' => 'February']);
    test('a version with two assets, by name, sorted', array_keys($library->assets($v1->id())) === ['img/valve.png', 'pump.png'] && $v1->has_assets());
    test('each asset reads back byte for byte', $library->read_asset($v1->id(), 'pump.png') === $red && $library->read_asset($v1->id(), 'img/valve.png') === $blue);
    test('read_version() is still the main file', $library->read_version($v1->id()) === $text);
    test('an asset the version does not have: null', $library->read_asset($v1->id(), 'nope.png') === null);
    test('the asset rows keep its type', $library->assets($v1->id())['pump.png']->mime() === 'image/png');

    $same = $library->save($tree_id, 'plant/notes.md', $text, ['assets' => ['img/valve.png' => $blue, 'pump.png' => $red]]);
    test('saved again, the same file and the same assets in another order: the same version', $same->id() === $v1->id());

    $v2 = $library->save($tree_id, 'plant/notes.md', $text, ['assets' => ['pump.png' => $green, 'img/valve.png' => $blue], 'note_c' => 'March']);
    test('the same text with another pump: a new version', $v2->id() !== $v1->id() && $v2->sha256() === $v1->sha256());
    test('...whose unchanged valve is the same blob', $library->assets($v2->id())['img/valve.png']->storage_path() === $library->assets($v1->id())['img/valve.png']->storage_path());
    test("the pump case: February's version still shows February's pump", $library->read_asset($v1->id(), 'pump.png') === $red && $library->read_asset($v2->id(), 'pump.png') === $green);

    $v3 = $library->save($tree_id, 'plant/notes.md', $text . "\nChecked.\n");
    $carried = array_map(static fn ($a): string => $a->storage_path(), $library->assets($v3->id()));
    test('saved without `assets`: the latest assets carried forward, the same blobs', $carried === array_map(static fn ($a): string => $a->storage_path(), $library->assets($v2->id())));
    test('...and the same assets digest', $v3->assets_sha256() === $v2->assets_sha256());

    $v4 = $library->save($tree_id, 'plant/notes.md', $text . "\nChecked.\n", ['assets' => []]);
    test('`assets` => []: a new version with none', $v4->id() !== $v3->id() && !$v4->has_assets() && $library->assets($v4->id()) === []);

    $count = count($library->history($v4->node_id()));
    test('an asset name the tree refuses: refused', bundle_throws(fn () => $library->save($tree_id, 'plant/notes.md', 'x', ['assets' => ['../escape.png' => $red]])));
    test('an asset named twice: refused', bundle_throws(fn () => $library->save($tree_id, 'plant/notes.md', 'x', ['assets' => ['a.png' => $red, 'a.png/' => $blue]])));
    test('an asset that is also another asset\'s folder: refused', bundle_throws(fn () => $library->save($tree_id, 'plant/notes.md', 'x', ['assets' => ['img' => $red, 'img/a.png' => $blue]])));
    test('an asset the catalogue refuses (an executable): refused by the store', bundle_throws(fn () => $library->save($tree_id, 'plant/notes.md', 'x', ['assets' => ['run.bin' => "\x7fELF\x02\x01\x01\x00" . str_repeat("\x00", 56)]]), StorageException::class));
    test('...and none of them wrote a version', count($library->history($v4->node_id())) === $count);
    $pdo->beginTransaction();
    $inside = $library->save($tree_id, 'plant/draft.md', "# Draft\n", ['assets' => ['a.png' => $red]]);
    $pdo->rollBack();
    test("save() inside the caller's transaction: part of it — rolled back with it", $library->version($inside->id()) === null && $library->node($tree_id, 'plant/draft.md') === null);
    test('an unsafe name refused before the document is created', bundle_throws(fn () => $library->save($tree_id, 'plant/fresh.md', 'x', ['assets' => ['../x.png' => $red]])) && $library->node($tree_id, 'plant/fresh.md') === null);

    // -------------------------------------------------------------------------
    section('`*.assets` is reserved');

    test('save() refuses a document inside a bundle folder', bundle_throws(fn () => $library->save($tree_id, 'plant/notes.md.assets/extra.md', 'x')));
    test('...and inside any folder named *.assets, bundle or not', bundle_throws(fn () => $library->save($tree_id, 'shared.assets/logo.md', 'x')));
    test('...and a document named *.assets', bundle_throws(fn () => $library->save($tree_id, 'plant/notes.md.assets', 'x')));
    test('...saying why', (static function () use ($library, $tree_id): bool {
        try {
            $library->save($tree_id, 'shared.assets/logo.md', 'x');
        } catch (DocumentsException $e) {
            return str_contains($e->getMessage(), 'reserved');
        }

        return false;
    })());
    test('move() refuses such a target', bundle_throws(fn () => $library->move($tree_id, 'plant/notes.md', 'plant/other.md.assets/notes.md')));
    test('nothing was created by the refusals', $library->node($tree_id, 'shared.assets/logo.md') === null && $library->node($tree_id, 'plant/notes.md.assets/extra.md') === null);

    // -------------------------------------------------------------------------
    section('a moved bundle: relink() and publish()');

    $v5 = $library->save($tree_id, 'plant/notes.md', $text, ['assets' => ['pump.png' => $green, 'img/valve.png' => $blue]]);
    $library->move($tree_id, 'plant/notes.md', 'plant/log.md');

    test('moved_href(): a file of the old folder points into the new one', $library->moved_href($tree_id, 'plant/log.md', 'notes.md.assets/pump.png') === 'log.md.assets/pump.png');
    test('...from another folder too', $library->moved_href($tree_id, 'index.md', 'plant/notes.md.assets/img/valve.png#x') === 'plant/log.md.assets/img/valve.png#x');
    test('...not for a file the bundle does not have', $library->moved_href($tree_id, 'plant/log.md', 'notes.md.assets/nope.png') === null);
    test('...not for a folder whose document is live', $library->moved_href($tree_id, 'plant/log.md', 'log.md.assets/pump.png') === null);

    $library->save($tree_id, 'yard/a.md', "# A\n", ['assets' => ['pump.png' => $red]]);
    $library->move($tree_id, 'yard/a.md', 'yard/b.md');
    $library->save($tree_id, 'yard/a.md', "# A again, no pump\n");
    test('...not for a folder whose document is live again, though its name is also an alias', $library->moved_href($tree_id, 'yard/a.md', 'a.md.assets/pump.png') === null);

    $relinked = $library->relink($tree_id, ['author_c' => 'ada']);
    test('relink(): the moved bundle\'s own image and link rewritten', ($relinked['rewritten']['plant/log.md'] ?? []) === [['notes.md.assets/pump.png', 'log.md.assets/pump.png'], ['notes.md.assets/img/valve.png', 'log.md.assets/img/valve.png']]);
    $log_node = $library->node($tree_id, 'plant/log.md');
    $v6       = $library->version((int) $log_node->latest_version_id());
    test('...a new version that kept its assets', $v6->id() !== $v5->id() && $v6->assets_sha256() === $v5->assets_sha256());
    test('...a second run finds nothing', $library->relink($tree_id)['rewritten'] === []);

    $page = $library->save($tree_id, 'plant/page.md', "# Page\n\n![pic](page.md.assets/pic.png)\n", ['assets' => [
        'pic.png'    => $red,
        'frame.html' => "<!doctype html>\n<html><body><script>alert(1)</script></body></html>\n",
    ]]);
    test('an HTML asset is accepted by the store (the catalogue takes text/html)', $library->assets($page->id())['frame.html']->mime() === 'text/html');

    $site = $base . '/site';
    $projection_for_site = new Projection($library, $base . '/unused');
    $projection_for_site->publish($tree_id, $site);
    test('publish(): the images beside the page, byte for byte', @file_get_contents($site . '/plant/log.md.assets/pump.png') === $green && @file_get_contents($site . '/plant/log.md.assets/img/valve.png') === $blue);
    test('...never an HTML asset: a stored blob served raw would be live markup', !file_exists($site . '/plant/page.md.assets/frame.html') && is_file($site . '/plant/page.md.assets/pic.png'));
    test('...and the page links where the images are', str_contains((string) file_get_contents($site . '/plant/log.html'), 'src="log.md.assets/pump.png"'));

    $library->remove($tree_id, 'plant/page.md');
    $projection_for_site->publish($tree_id, $site);
    test('a removed bundle: its page and its images pruned on the next publish', !file_exists($site . '/plant/page.html') && !file_exists($site . '/plant/page.md.assets/pic.png'));
    test('...the other bundle\'s images still there', is_file($site . '/plant/log.md.assets/pump.png'));

    // -------------------------------------------------------------------------
    section('an SVG drawing and its parts');

    $svg     = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><image xlink:href="drawing.svg.assets/pump.png"/><script>alert(1)</script></svg>';
    $valve   = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(2)</script><circle r="4"/></svg>';
    $drawing = $library->save($tree_id, 'plant/drawing.svg', $svg, ['assets' => ['pump.png' => $red, 'valve.svg' => $valve]]);
    test('an SVG drawing with an SVG part and a PNG part: stored as a bundle', $drawing->mime() === 'image/svg+xml' && $library->assets($drawing->id())['valve.svg']->mime() === 'image/svg+xml');
    $projection_for_site->publish($tree_id, $site);
    test('published: the drawing as an escaped page, no live script', is_file($site . '/plant/drawing.html') && !str_contains((string) file_get_contents($site . '/plant/drawing.html'), '<script'));
    test('...its PNG part copied, its SVG part never', is_file($site . '/plant/drawing.svg.assets/pump.png') && !file_exists($site . '/plant/drawing.svg.assets/valve.svg'));

    // -------------------------------------------------------------------------
    section('the working copy');

    $work       = $base . '/work';
    $projection = new Projection($library, $work);
    $projection->checkout($tree_id);
    test('checkout(): an SVG drawing and its folder too', @file_get_contents($work . '/plant/drawing.svg') === $svg && @file_get_contents($work . '/plant/drawing.svg.assets/valve.svg') === $valve);
    test('checkout(): the file and its folder', is_file($work . '/plant/log.md') && @file_get_contents($work . '/plant/log.md.assets/pump.png') === $green && is_file($work . '/plant/log.md.assets/img/valve.png'));
    test('status() right after: nothing to report', bundle_status($projection, $tree_id) === []);

    file_put_contents($work . '/plant/log.md.assets/pump.png', $red);
    test('one asset changed: one MODIFIED, for the bundle', bundle_status($projection, $tree_id) === ['M  plant/log.md']);

    file_put_contents($work . '/plant/log.md.assets/pump.png', $green);
    test('changed back, same size, same second: unchanged again (hashed, not trusted)', bundle_status($projection, $tree_id) === []);

    unlink($work . '/plant/log.md.assets/img/valve.png');
    test('an asset deleted: one MODIFIED', bundle_status($projection, $tree_id) === ['M  plant/log.md']);
    file_put_contents($work . '/plant/log.md.assets/new.png', $yellow);
    test('...and one added: still one MODIFIED', bundle_status($projection, $tree_id) === ['M  plant/log.md']);

    $report = $projection->import($tree_id, 'ada', 'new part');
    test('import(): the bundle imported', $report['imported'] === ['plant/log.md'] && $report['divergent'] === []);
    $v7 = $library->version((int) $library->node($tree_id, 'plant/log.md')->latest_version_id());
    test('...as a version with exactly the files in the folder: the deleted valve is not in it', array_keys($library->assets($v7->id())) === ['new.png', 'pump.png']);
    test('...the previous version still holds it', $library->read_asset($v6->id(), 'img/valve.png') === $blue);
    test('status() after import: nothing to report', bundle_status($projection, $tree_id) === []);

    mkdir($work . '/plant/fresh.md.assets');
    file_put_contents($work . '/plant/fresh.md', "# Fresh\n");
    file_put_contents($work . '/plant/fresh.md.assets/a.png', $yellow);
    test('a new file beside a new folder: one ADDED, the pair', bundle_status($projection, $tree_id) === ['A  plant/fresh.md']);

    mkdir($work . '/plant/ghost.md.assets');
    file_put_contents($work . '/plant/ghost.md.assets/x.png', $red);
    file_put_contents($work . '/stray.assets', 'a file with a reserved name');
    test('a folder with no file beside it: ORPHAN_ASSETS; a file named *.assets: ignored', bundle_status($projection, $tree_id) === ['A  plant/fresh.md', '~  plant/ghost.md.assets']);

    $report = $projection->import($tree_id, 'ada');
    test('import(): the new bundle imported with its asset', $report['imported'] === ['plant/fresh.md'] && array_keys($library->assets((int) $library->node($tree_id, 'plant/fresh.md')->latest_version_id())) === ['a.png']);
    test('...the orphan reported, not imported, not deleted', $report['orphan_assets'] === ['plant/ghost.md.assets'] && $library->node($tree_id, 'plant/ghost.md') === null && is_file($work . '/plant/ghost.md.assets/x.png'));

    $library->save($tree_id, 'plant/log.md', "# Somebody else's edit\n");
    file_put_contents($work . '/plant/log.md.assets/pump.png', $blue);
    test('an asset edited on a stale base: MODIFIED, would diverge', in_array('MD plant/log.md', bundle_status($projection, $tree_id), true));
    $report = $projection->import($tree_id, 'ada');
    test('...imported as divergent', $report['divergent'] === ['plant/log.md']);

    unlink($work . '/plant/fresh.md');
    test('a bundle whose file went missing: MISSING, and its folder an orphan', array_values(array_filter(bundle_status($projection, $tree_id), static fn (string $l): bool => str_contains($l, 'fresh'))) === ['!  plant/fresh.md', '~  plant/fresh.md.assets']);
    unlink($work . '/plant/fresh.md.assets/a.png');
    rmdir($work . '/plant/fresh.md.assets');

    // -------------------------------------------------------------------------
    section('checkout_version(): any version, into a directory');

    $out = $base . '/v1';
    test('a bundle version: the file under the document\'s name now, and its folder', $library->checkout_version($v1->id(), $out) === ['log.md', 'log.md.assets/img/valve.png', 'log.md.assets/pump.png']);
    test("...February's pump", @file_get_contents($out . '/log.md.assets/pump.png') === $red && @file_get_contents($out . '/log.md') === $text);
    test('a directory that is not empty: refused', bundle_throws(fn () => $library->checkout_version($v2->id(), $out)));
    test('a version without assets: the file alone', $library->checkout_version($v4->id(), $base . '/v4') === ['log.md'] && bundle_files($base . '/v4') === ['log.md']);
    test('no such version: refused', bundle_throws(fn () => $library->checkout_version(999999, $base . '/none')));

    // -------------------------------------------------------------------------
    section('packing a bundle');

    $log     = $library->node($tree_id, 'plant/log.md');
    $old     = array_values(array_filter($library->history($log->id()), static fn ($v): bool => $v->id() !== $log->latest_version_id()));
    $before  = [];
    $sha256s = [];
    foreach ($old as $version) {
        foreach ($library->assets($version->id()) as $name_c => $asset) {
            $before[$version->id() . ':' . $name_c] = $library->read_asset($version->id(), $name_c);
            $sha256s[$asset->sha256()]              = true;
        }
    }
    $latest        = $library->version((int) $log->latest_version_id());
    $latest_assets = $library->assets($latest->id());
    $red_blob      = $library->assets($v1->id())['pump.png']->storage_path();
    test('before packing: the red pump is a loose blob only old versions use', $store->has($red_blob) && !in_array($red_blob, array_map(static fn ($a): string => $a->storage_path(), $latest_assets), true));

    $report = $library->pack($tree_id, ['min_old_versions_n' => 5]);
    $packed = $report['packed']['plant/log.md'] ?? null;
    test('packed: every old version of the bundle', $packed !== null && $packed['versions_n'] === count($old));

    $after = [];
    foreach ($old as $version) {
        foreach (array_keys($library->assets($version->id())) as $name_c) {
            $after[$version->id() . ':' . $name_c] = $library->read_asset($version->id(), $name_c);
        }
    }
    test('every old version\'s every asset reads back byte for byte from the archive', $after === $before && $library->version($v1->id())->is_archived());

    $listing = (string) shell_exec('zstd -dc --long=27 ' . escapeshellarg((string) $store->absolute_path((string) $library->version($v1->id())->archive_path())) . ' | tar -tf -');
    $members = array_values(array_filter(explode("\n", $listing), static fn (string $f): bool => str_starts_with($f, 'assets/')));
    test('each image stored once in the archive, however many versions carry it', count($members) === count($sha256s));

    test('the loose red pump is gone: only archived versions used it', !$store->has($red_blob));
    $kept = true;
    foreach ($latest_assets as $asset) {
        $kept = $kept && $store->has($asset->storage_path());
    }
    test('no blob the latest version uses was deleted — its blue pump is also the old valve\'s blob', $kept && $library->read_asset($latest->id(), 'pump.png') === $blue);

    $v1_out = $base . '/v1-archived';
    $library->checkout_version($v1->id(), $v1_out);
    test("checkout_version() of an archived bundle version: February's pump, and the text", @file_get_contents($v1_out . '/log.md.assets/pump.png') === $red && @file_get_contents($v1_out . '/log.md') === $text);

    // An archive that holds other bytes under the asset's member: refused, never returned.
    $asset_member = 'assets/' . basename($library->assets($v2->id())['pump.png']->storage_path());
    $impostor     = RevisionArchive::write([[$asset_member, 'not the pump']], $base);
    file_put_contents((string) $store->absolute_path((string) $library->version($v2->id())->archive_path()), $impostor);
    test('an archived asset that does not come back as saved: refused', bundle_throws(fn () => $library->read_asset($v2->id(), 'pump.png')));

    // -------------------------------------------------------------------------
    section('checkout_version() of a container (kind A), archived');

    $odt = static function (string $text): string {
        $path = tempnam(sys_get_temp_dir(), 'ixodt');
        $zip  = new ZipArchive();
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('mimetype', 'application/vnd.oasis.opendocument.text');
        $zip->setCompressionName('mimetype', ZipArchive::CM_STORE);
        $zip->addFromString('content.xml', '<office:document-content><text:p>' . $text . '</text:p></office:document-content>');
        $zip->addFromString('META-INF/manifest.xml', '<manifest:manifest/>');
        $zip->close();
        $bytes = (string) file_get_contents($path);
        unlink($path);

        return $bytes;
    };
    $first = $library->save($tree_id, 'offers/offer.odt', $odt('one'));
    $library->save($tree_id, 'offers/offer.odt', $odt('two'));
    $library->save($tree_id, 'offers/offer.odt', $odt('three'));
    $library->pack($tree_id, ['min_old_versions_n' => 2]);
    $written = $library->checkout_version($first->id(), $base . '/odt');
    $zip     = new ZipArchive();
    $opened  = $zip->open($base . '/odt/offer.odt', ZipArchive::RDONLY) === true;
    test('an archived ODT version, rebuilt into the directory, opens with its text', $library->version($first->id())->is_archived() && $written === ['offer.odt'] && $opened && str_contains((string) $zip->getFromName('content.xml'), 'one'));
    if ($opened) {
        $zip->close();
    }
} finally {
    RevisionArchive::remove_tree($base);
}

test('the temporary directories were removed', !is_dir($base));

exit(summary());
