<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Documents - RevisionArchive
 *
 * @package Italix\Documents
 */

declare(strict_types=1);

namespace Italix\Documents;

use ZipArchive;

/**
 * The files-and-processes half of the revision archive — no database, no
 * store: Library::pack() and read_version() hold those.
 *
 * - explode() turns a container (ODF, OOXML, EPUB) into its entries and a
 *   recipe, **and proves the recipe rebuilds it** — every entry byte-equal,
 *   in order, stored where it was stored — or answers null, and the version
 *   is archived whole. Content-equal, not byte-equal.
 * - rebuild() is that recipe applied.
 * - write() and extract() are `tar` and the `zstd` command, with a
 *   long window both ways. The caller hands write() its members already
 *   in the order that makes equal content adjacent.
 *
 * @internal its shape follows what Library needs
 */
final class RevisionArchive
{
    public const RECIPE_FORMAT = 1;

    private const LEVEL_N   = 9;
    private const WINDOW_N  = 27;     // --long=27: a 128 MB window; the archive cannot be read without it
    private const TIMEOUT_S = 600;

    private function __construct()
    {
    }

    /** Whether `tar` and `zstd` are on PATH. */
    public static function is_available(): bool
    {
        foreach (['tar', 'zstd'] as $binary) {
            $found = shell_exec('command -v ' . escapeshellarg($binary) . ' 2>/dev/null');
            if (!is_string($found) || trim($found) === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * A container's entries and the recipe that rebuilds it — or null: not a
     * container, not readable, an entry name unsafe to put on a disk, an
     * encrypted entry, or a rebuild that does not come back entry for entry.
     *
     * @return array{recipe: array{format: int, entries: list<array{name: string, dir: bool, stored: bool, sha256: string}>}, entries: array<string, string>}|null
     */
    public static function explode(string $bytes, string $work_dir): ?array
    {
        if (strncmp($bytes, "PK\x03\x04", 4) !== 0) {
            return null;
        }

        $path = $work_dir . '/explode-' . bin2hex(random_bytes(6)) . '.zip';
        file_put_contents($path, $bytes);

        try {
            $zip = new ZipArchive();
            if ($zip->open($path, ZipArchive::RDONLY) !== true) {
                return null;
            }

            try {
                if (!self::is_container($zip)) {
                    return null;
                }

                $recipe  = ['format' => self::RECIPE_FORMAT, 'entries' => []];
                $entries = [];

                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $stat = $zip->statIndex($i);
                    $name = $stat === false ? '' : (string) $stat['name'];

                    if ($stat === false || !self::is_safe_name($name) || isset($entries[$name]) || $stat['encryption_method'] !== 0) {
                        return null;
                    }

                    $is_dir  = str_ends_with($name, '/');
                    $content = $is_dir ? '' : $zip->getFromIndex($i);
                    if ($content === false) {
                        return null;
                    }

                    $recipe['entries'][] = [
                        'name'   => $name,
                        'dir'    => $is_dir,
                        'stored' => (int) $stat['comp_method'] === ZipArchive::CM_STORE,
                        'sha256' => hash('sha256', $content),
                    ];
                    $entries[$name] = $content;
                }
            } finally {
                $zip->close();
            }
        } finally {
            @unlink($path);
        }

        // The proof: what the recipe rebuilds must read back as what was saved.
        $rebuilt = self::rebuild($recipe, static fn (string $name): string => $entries[$name], $work_dir);

        return self::matches($rebuilt, $recipe, $work_dir) ? ['recipe' => $recipe, 'entries' => $entries] : null;
    }

    /**
     * The container a recipe describes, its entries read through <entry> —
     * in the recipe's order, each stored or deflated as it was, each checked
     * against the recipe's sha256.
     *
     * @param array{format: int, entries: list<array{name: string, dir: bool, stored: bool, sha256: string}>} $recipe
     * @param callable(string): string $entry the bytes of an entry, by name
     * @throws DocumentsException an entry that does not match its recipe, or a ZIP that cannot be written
     */
    public static function rebuild(array $recipe, callable $entry, string $work_dir): string
    {
        $path = $work_dir . '/rebuild-' . bin2hex(random_bytes(6)) . '.zip';
        $zip  = new ZipArchive();

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new DocumentsException('Cannot write a container to rebuild.');
        }

        try {
            foreach ($recipe['entries'] as $item) {
                if ($item['dir']) {
                    $zip->addEmptyDir(rtrim($item['name'], '/'));

                    continue;
                }

                $content = $entry($item['name']);
                if (hash('sha256', $content) !== $item['sha256']) {
                    throw new DocumentsException("Entry \"{$item['name']}\" does not match its recipe.");
                }

                $zip->addFromString($item['name'], $content);
                $zip->setCompressionName($item['name'], $item['stored'] ? ZipArchive::CM_STORE : ZipArchive::CM_DEFLATE);
            }

            if (!$zip->close()) {
                throw new DocumentsException('Cannot finish a rebuilt container.');
            }

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }
    }

    /**
     * Writes <members> — [path inside the archive, bytes], in the order given —
     * as a `tar` compressed by `zstd`, and returns the archive's bytes.
     *
     * @param list<array{0: string, 1: string}> $members
     * @throws DocumentsException tar or zstd failing
     */
    public static function write(array $members, string $work_dir): string
    {
        $root = $work_dir . '/write-' . bin2hex(random_bytes(6));
        $list = $root . '.list';
        $tar  = $root . '.tar';
        $zst  = $root . '.tar.zst';

        try {
            foreach ($members as [$member, $bytes]) {
                $file = $root . '/' . $member;
                if (!is_dir(dirname($file)) && !mkdir(dirname($file), 0700, true) && !is_dir(dirname($file))) {
                    throw new DocumentsException("Cannot prepare \"{$member}\" for the archive.");
                }
                file_put_contents($file, $bytes);
            }
            file_put_contents($list, implode("\n", array_column($members, 0)) . "\n");

            self::run(sprintf(
                'tar -cf %s -C %s --no-recursion --verbatim-files-from -T %s --owner=0 --group=0 --numeric-owner --mtime=@0',
                escapeshellarg($tar),
                escapeshellarg($root),
                escapeshellarg($list)
            ), 'tar could not write the archive');
            self::run(sprintf(
                'zstd -%d --long=%d -q -f %s -o %s',
                self::LEVEL_N,
                self::WINDOW_N,
                escapeshellarg($tar),
                escapeshellarg($zst)
            ), 'zstd could not compress the archive');

            return (string) file_get_contents($zst);
        } finally {
            @unlink($list);
            @unlink($tar);
            @unlink($zst);
            self::remove_tree($root);
        }
    }

    /**
     * Extracts <paths> (all of it when empty) from an archive's bytes into <dir>.
     *
     * @param list<string> $paths
     * @throws DocumentsException zstd or tar failing, or a path the archive does not hold
     */
    public static function extract(string $archive, array $paths, string $dir): void
    {
        $zst = $dir . '.tar.zst';
        $tar = $dir . '.tar';

        try {
            file_put_contents($zst, $archive);
            if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
                throw new DocumentsException('Cannot prepare a directory to extract into.');
            }

            self::run(sprintf(
                'zstd -d --long=%d -q -f %s -o %s',
                self::WINDOW_N,
                escapeshellarg($zst),
                escapeshellarg($tar)
            ), 'zstd could not decompress the archive');
            self::run(sprintf(
                'tar -xf %s -C %s%s',
                escapeshellarg($tar),
                escapeshellarg($dir),
                $paths === [] ? '' : ' -- ' . implode(' ', array_map('escapeshellarg', $paths))
            ), 'tar could not extract from the archive');
        } finally {
            @unlink($zst);
            @unlink($tar);
        }
    }

    public static function remove_tree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            $item->isDir() ? @rmdir((string) $item) : @unlink((string) $item);
        }
        @rmdir($dir);
    }

    /**
     * An ODF or EPUB `mimetype` entry naming such a type, or an OOXML
     * `[Content_Types].xml` — never the extension alone.
     */
    private static function is_container(ZipArchive $zip): bool
    {
        if ($zip->locateName('[Content_Types].xml') !== false) {
            return true;
        }

        $mimetype = $zip->getFromName('mimetype');

        return is_string($mimetype)
            && (str_starts_with($mimetype, 'application/vnd.oasis.opendocument.') || $mimetype === 'application/epub+zip');
    }

    /** An entry name that is safe to become a file under the archive's own folder. */
    private static function is_safe_name(string $name): bool
    {
        if ($name === '' || $name[0] === '/' || str_contains($name, '\\') || preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
            return false;
        }

        foreach (explode('/', rtrim($name, '/')) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether <container> holds exactly the recipe's entries, in its order,
     * each with its bytes and its storage.
     *
     * @param array{format: int, entries: list<array{name: string, dir: bool, stored: bool, sha256: string}>} $recipe
     */
    private static function matches(string $container, array $recipe, string $work_dir): bool
    {
        $path = $work_dir . '/check-' . bin2hex(random_bytes(6)) . '.zip';
        file_put_contents($path, $container);
        $zip    = new ZipArchive();
        $opened = false;

        try {
            $opened = $zip->open($path, ZipArchive::RDONLY) === true;
            if (!$opened || $zip->numFiles !== count($recipe['entries'])) {
                return false;
            }

            foreach ($recipe['entries'] as $i => $item) {
                $stat    = $zip->statIndex($i);
                $content = $item['dir'] ? '' : $zip->getFromIndex($i);

                if ($stat === false || $stat['name'] !== $item['name'] || $content === false
                    || hash('sha256', $content) !== $item['sha256']
                    || (!$item['dir'] && ((int) $stat['comp_method'] === ZipArchive::CM_STORE) !== $item['stored'])
                ) {
                    return false;
                }
            }

            return true;
        } finally {
            if ($opened) {
                $zip->close();
            }
            @unlink($path);
        }
    }

    /** @throws DocumentsException on a non-zero exit, with the command's output */
    private static function run(string $command, string $what_c): void
    {
        exec('timeout ' . self::TIMEOUT_S . ' ' . $command . ' 2>&1', $output, $exit_code);

        if ($exit_code !== 0) {
            throw new DocumentsException("{$what_c} (exit {$exit_code}): " . implode("\n", $output));
        }
    }
}
