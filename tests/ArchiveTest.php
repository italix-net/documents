<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Documents — the revision archive, kinds A (containers) and B (single files)
 *
 * The user's case measured (four versions of an ODT carrying 1 MB of images:
 * ~2 MB packed, not ~4), every archived version read back — a container
 * content-equal with `mimetype` first and stored, a single file byte-equal —
 * entries ordered by name then version, a container that cannot be proven
 * archived whole, the threshold, a repack, a revert, a dry run, and an
 * archive that fails its check leaving everything as it was.
 *
 * Run: php src/Libs/Italix/Documents/tests/ArchiveTest.php
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
use Italix\Storage\FileStore;
use Italix\Storage\LocalStore;
use Italix\Storage\StoredFile;

use function Italix\Testing\{suite, section, test, summary};

suite('Italix Documents — the revision archive (kinds A and B)');

if (!extension_loaded('pdo_sqlite') || !class_exists(ZipArchive::class) || !RevisionArchive::is_available()) {
    echo "  SKIPPED — needs pdo_sqlite, ext-zip, and the tar and zstd commands.\n";
    exit(summary());
}

/**
 * A store that can hand back, for archives, a **valid** archive with other contents: every file inside
 * one byte longer. zstd's own checksum passes — only pack()'s entry-by-entry check can tell.
 */
final class TamperingStore implements FileStore
{
    public bool $tamper = false;

    public function __construct(private readonly FileStore $inner)
    {
    }

    public function read(string $path_c): ?string
    {
        $bytes = $this->inner->read($path_c);

        if ($bytes === null || !$this->tamper || !str_ends_with($path_c, '.zst')) {
            return $bytes;
        }

        $dir = sys_get_temp_dir() . '/ix-tamper-' . bin2hex(random_bytes(6));
        mkdir($dir . '/x', 0700, true);
        file_put_contents($dir . '/a.zst', $bytes);
        shell_exec('zstd -dc --long=27 ' . escapeshellarg($dir . '/a.zst') . ' | tar -xf - -C ' . escapeshellarg($dir . '/x'));
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir . '/x', FilesystemIterator::SKIP_DOTS)) as $file) {
            file_put_contents((string) $file, 'X', FILE_APPEND);
        }
        shell_exec('tar -cf - -C ' . escapeshellarg($dir . '/x') . ' . | zstd -q --long=27 -o ' . escapeshellarg($dir . '/b.zst'));
        $tampered = (string) file_get_contents($dir . '/b.zst');
        RevisionArchive::remove_tree($dir);

        return $tampered;
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

/**
 * A ZIP of <entries> in order, the names in <stored> stored uncompressed.
 *
 * @param array<string, string> $entries
 * @param list<string>          $stored
 */
function archive_zip(array $entries, array $stored = []): string
{
    $path = tempnam(sys_get_temp_dir(), 'ixzip');
    $zip  = new ZipArchive();
    $zip->open($path, ZipArchive::OVERWRITE);
    foreach ($entries as $name => $content) {
        str_ends_with($name, '/') ? $zip->addEmptyDir(rtrim($name, '/')) : $zip->addFromString($name, $content);
        if (in_array($name, $stored, true)) {
            $zip->setCompressionName($name, ZipArchive::CM_STORE);
        }
    }
    $zip->close();
    $bytes = (string) file_get_contents($path);
    unlink($path);

    return $bytes;
}

/** An ODT: mimetype first and stored, a 1 MB picture stored (already compressed), the text changing. */
function archive_odt(string $picture, string $text): string
{
    return archive_zip([
        'mimetype'              => 'application/vnd.oasis.opendocument.text',
        'content.xml'           => '<office:document-content><text:p>' . $text . '</text:p></office:document-content>',
        'Pictures/'             => '',
        'Pictures/photo.jpg'    => $picture,
        'META-INF/manifest.xml' => '<manifest:manifest/>',
    ], ['mimetype', 'Pictures/photo.jpg']);
}

/** @return array<string, array{0: string, 1: bool}> name => [bytes, stored], in order */
function archive_entries(string $zip_bytes): array
{
    $path = tempnam(sys_get_temp_dir(), 'ixzip');
    file_put_contents($path, $zip_bytes);
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::RDONLY);
    $out = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        $out[$stat['name']] = [str_ends_with($stat['name'], '/') ? '' : (string) $zip->getFromIndex($i), (int) $stat['comp_method'] === ZipArchive::CM_STORE];
    }
    $zip->close();
    unlink($path);

    return $out;
}

/** Bytes under a directory, recursively. */
function archive_du(string $dir): int
{
    $n = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
        $n += $file->getSize();
    }

    return $n;
}

$base = sys_get_temp_dir() . '/ix-documents-archive-' . bin2hex(random_bytes(6));

try {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $store   = new TamperingStore(new LocalStore($base . '/store', Library::suggested_path_strategy(), Library::suggested_catalog()));
    $library = new Library($pdo, $store);
    $library->install();
    $tree_id = $library->create_tree('manual', 'Handbook');

    // -------------------------------------------------------------------------
    section("the user's case: four versions of an ODT carrying 1 MB of images");

    $picture = random_bytes(1024 * 1024);   // already compressed: nothing left for zstd but deduplication
    $odt     = [];
    for ($v = 1; $v <= 4; $v++) {
        $odt[$v] = archive_odt($picture, "Revision {$v} of the offer.");
        $library->save($tree_id, 'offers/offer.odt', $odt[$v]);
    }
    $before = archive_du($base . '/store');
    test('four versions side by side: ~4 MB', $before > 4 * 1024 * 1024 && $before < 4.2 * 1024 * 1024);

    $below = $library->pack($tree_id, ['large_bytes_n' => null]);
    test('the count rule alone: three old versions are below the default threshold of 5, nothing packed', $below['packed'] === [] && $below['below_threshold_n'] === 1);

    $dry = $library->pack($tree_id, ['min_old_versions_n' => 3, 'dry_run' => true]);
    test('a dry run reports the archive, and stores nothing', isset($dry['packed']['offers/offer.odt']) && archive_du($base . '/store') === $before);

    $report = $library->pack($tree_id, ['min_old_versions_n' => 3]);
    $packed = $report['packed']['offers/offer.odt'] ?? [];
    test('packed: three old versions, all three unpacked (kind A), none whole', [$packed['versions_n'] ?? 0, $packed['unpacked_n'] ?? 0, $packed['whole_n'] ?? -1] === [3, 3, 0]);
    test('...the same numbers the dry run gave', $packed === $dry['packed']['offers/offer.odt']);
    test('the archive: ~1 MB — the picture once for three versions', $packed['archive_bytes_n'] < 1.1 * 1024 * 1024);
    $after = archive_du($base . '/store');
    test('the store now: the latest ODT and the archive, ~2 MB instead of ~4', $after < 2.2 * 1024 * 1024);
    printf("    (store: %.2f MB before, %.2f MB after; archive %.2f MB)\n", $before / 1048576, $after / 1048576, $packed['archive_bytes_n'] / 1048576);

    // -------------------------------------------------------------------------
    section('an archived container reads back content-equal');

    $node    = $library->node($tree_id, 'offers/offer.odt');
    $history = array_reverse($library->history($node->id()));   // oldest first
    test('the three old versions are archived, the latest is not', array_map(static fn ($v) => $v->is_archived(), $history) === [true, true, true, false]);

    $ok = true;
    foreach ([1, 2, 3] as $v) {
        $read = (string) $library->read_version($history[$v - 1]->id());
        $ok   = $ok && archive_entries($read) === archive_entries($odt[$v]);
    }
    test('versions 1-3: every entry byte-equal, in order, stored where it was stored', $ok);
    test('...mimetype first and stored, as ODF requires', array_key_first(archive_entries((string) $library->read_version($history[0]->id()))) === 'mimetype' && archive_entries((string) $library->read_version($history[0]->id()))['mimetype'][1] === true);
    test('the latest is untouched, byte-equal', $library->read($tree_id, 'offers/offer.odt') === $odt[4]);

    $listing = (string) shell_exec('zstd -dc --long=27 ' . escapeshellarg((string) $store->absolute_path((string) $history[0]->archive_path())) . ' | tar -t');
    $lines   = array_values(array_filter(explode("\n", $listing)));
    $photos  = array_keys(array_filter($lines, static fn (string $l): bool => str_ends_with($l, '/entries/Pictures/photo.jpg')));
    test('the three copies of the picture are adjacent in the archive', count($photos) === 3 && $photos[2] - $photos[0] === 2);

    // -------------------------------------------------------------------------
    section('kind B: a single file comes back byte-equal');

    $markdown = [];
    for ($v = 1; $v <= 6; $v++) {
        $markdown[$v] = "# Guide\n\n" . str_repeat("A paragraph that stays the same.\n", 50) . "Revision {$v}.\n";
        $library->save($tree_id, 'en/guide.md', $markdown[$v]);
    }
    $md_report = $library->pack($tree_id, ['large_bytes_n' => null]);
    test('five old versions: packed at the default threshold, whole (kind B)', [($md_report['packed']['en/guide.md'] ?? [])['whole_n'] ?? 0, ($md_report['packed']['en/guide.md'] ?? [])['unpacked_n'] ?? -1] === [5, 0]);
    test('...and the ODT — three old versions, under the default five — left alone, counted below the threshold', !isset($md_report['packed']['offers/offer.odt']) && $md_report['below_threshold_n'] === 1);
    $md_history = array_reverse($library->history($library->node($tree_id, 'en/guide.md')->id()));
    $same = true;
    foreach ([1, 2, 3, 4, 5] as $v) {
        $same = $same && $library->read_version($md_history[$v - 1]->id()) === $markdown[$v];
    }
    test('versions 1-5 read back byte-equal', $same);

    // -------------------------------------------------------------------------
    section('what cannot be proven goes in whole');

    $plain_zip = archive_zip(['notes.txt' => 'not a document format']);
    $unsafe    = archive_zip(['mimetype' => 'application/vnd.oasis.opendocument.text', '../outside.xml' => '<x/>'], ['mimetype']);
    $library->save($tree_id, 'misc/bundle.zip', $plain_zip);
    $library->save($tree_id, 'misc/bundle.zip', archive_zip(['notes.txt' => 'changed']));
    $library->save($tree_id, 'misc/odd.odt', $unsafe);
    $library->save($tree_id, 'misc/odd.odt', archive_odt('x', 'fine'));
    $odd = $library->pack($tree_id, ['min_old_versions_n' => 1]);
    test('a plain ZIP is not a container: whole', ($odd['packed']['misc/bundle.zip']['whole_n'] ?? 0) === 1);
    test('an entry named ../outside.xml: whole, never unpacked onto a disk', ($odd['packed']['misc/odd.odt']['whole_n'] ?? 0) === 1);
    $odd_old = array_reverse($library->history($library->node($tree_id, 'misc/odd.odt')->id()))[0];
    test('...and it reads back byte-equal', $library->read_version($odd_old->id()) === $unsafe);

    // -------------------------------------------------------------------------
    section('a repack, and a revert');

    $library->save($tree_id, 'offers/offer.odt', archive_odt($picture, 'Revision 5.'));
    $library->save($tree_id, 'offers/offer.odt', $odt[2]);   // back to what version 2 said
    $old_archive = $history[0]->archive_path();
    $repack      = $library->pack($tree_id, ['min_old_versions_n' => 3]);
    test('five old versions now, repacked into one new archive', ($repack['packed']['offers/offer.odt']['versions_n'] ?? 0) === 5);
    test('...the previous archive deleted', !$store->has((string) $old_archive));
    $again = array_reverse($library->history($node->id()));
    test('every old version still reads back content-equal', archive_entries((string) $library->read_version($again[0]->id())) === archive_entries($odt[1]) && archive_entries((string) $library->read_version($again[4]->id())) === archive_entries(archive_odt($picture, 'Revision 5.')));
    test('the latest — byte-equal to version 2 — kept its blob: a revert is never deleted', $library->read($tree_id, 'offers/offer.odt') === $odt[2]);
    test('a second pack finds nothing to do', $library->pack($tree_id, ['min_old_versions_n' => 1])['packed'] === []);

    // -------------------------------------------------------------------------
    section('an archive that fails its check: nothing marked, nothing deleted');

    $library->save($tree_id, 'en/revert.md', "# First\n");
    $library->save($tree_id, 'en/revert.md', "# Second\n");
    $library->save($tree_id, 'en/revert.md', "# First\n");   // back to version 1: the same blob
    $library->pack($tree_id, ['min_old_versions_n' => 2]);
    test('a revert packed while the first version is still loose: the latest — the same blob — still reads', $library->read($tree_id, 'en/revert.md') === "# First\n");


    for ($v = 1; $v <= 3; $v++) {
        $library->save($tree_id, 'en/fragile.md', "# Fragile {$v}\n");
    }
    $fragile        = $library->node($tree_id, 'en/fragile.md');
    $store->tamper  = true;
    $failed         = $library->pack($tree_id, ['min_old_versions_n' => 2]);
    $store->tamper  = false;
    test('a valid archive holding other contents: skipped, the entry-by-entry check says why', str_starts_with($failed['skipped']['en/fragile.md'] ?? '', 'The archive does not hold'));
    $fragile_old = array_reverse($library->history($fragile->id()));
    test('its versions are not marked archived', !$fragile_old[0]->is_archived() && !$fragile_old[1]->is_archived());
    test('...and still read back from their loose blobs', $library->read_version($fragile_old[0]->id()) === "# Fragile 1\n");
} finally {
    RevisionArchive::remove_tree($base);
}

test('the temporary directories were removed', !is_dir($base));

exit(summary());
