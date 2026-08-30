<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Documents - ProjectionIndex
 *
 * @package Italix\Documents
 */

declare(strict_types=1);

namespace Italix\Documents;

/**
 * What was checked out, and from which version.
 *
 * This is git's index and exists for the same two reasons: without it `import`
 * cannot tell a changed file from an unchanged one without hashing everything,
 * and — more important — it cannot know **what the editor was working from**,
 * which is the input to the divergence test.
 *
 * Stored as JSON at `.ix-documents/index` under the projection root. Plain text
 * on purpose: when something goes wrong with a working copy, being able to read
 * the state file with `less` is worth more than the bytes it saves.
 */
final class ProjectionIndex
{
    public const DIR_NAME  = '.ix-documents';
    public const FILE_NAME = 'index';

    private int $tree_id;

    /** @var array<string, array{version_id: int, sha256_c: string, bytes_n: int, mtime_t: int}> */
    private array $entries = [];

    private string $checked_out_dt;

    /**
     * When this index was last written.
     *
     * Not decoration: it is what makes the mtime shortcut in `Projection::status()`
     * safe. See `saved_t()`.
     */
    private int $saved_t = 0;

    public function __construct(int $tree_id, string $checked_out_dt = '')
    {
        $this->tree_id        = $tree_id;
        $this->checked_out_dt = $checked_out_dt;
    }

    public static function path_for(string $root): string
    {
        return rtrim($root, '/') . '/' . self::DIR_NAME . '/' . self::FILE_NAME;
    }

    public static function load(string $root): ?self
    {
        $path = self::path_for($root);

        if (!is_file($path)) {
            return null;
        }

        $raw = json_decode((string) file_get_contents($path), true);

        if (!is_array($raw) || !isset($raw['tree_id'])) {
            throw new DocumentsException(
                "The index at {$path} is unreadable. Delete the projection and check out again."
            );
        }

        $index = new self((int) $raw['tree_id'], (string) ($raw['checked_out_dt'] ?? ''));
        $index->saved_t = (int) ($raw['saved_t'] ?? 0);

        foreach ((array) ($raw['entries'] ?? []) as $path_c => $entry) {
            $index->entries[(string) $path_c] = [
                'version_id' => (int) $entry['version_id'],
                'sha256_c'   => (string) $entry['sha256_c'],
                'bytes_n'    => (int) $entry['bytes_n'],
                'mtime_t'    => (int) $entry['mtime_t'],
            ];
        }

        return $index;
    }

    public function save(string $root): void
    {
        $dir = rtrim($root, '/') . '/' . self::DIR_NAME;

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new DocumentsException("Cannot create the index directory: {$dir}");
        }

        $this->saved_t = time();

        $json = json_encode([
            'tree_id'        => $this->tree_id,
            'checked_out_dt' => $this->checked_out_dt,
            'saved_t'        => $this->saved_t,
            'entries'        => $this->entries,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // Written through a temporary file and renamed: an index truncated by a
        // crash would make the next import think every file was new.
        $final = self::path_for($root);
        $temp  = $final . '.tmp';

        if (@file_put_contents($temp, (string) $json) === false || !@rename($temp, $final)) {
            @unlink($temp);

            throw new DocumentsException("Cannot write the index: {$final}");
        }
    }

    public function tree_id(): int
    {
        return $this->tree_id;
    }

    /**
     * The second in which this index was written.
     *
     * `Projection::status()` skips hashing a file whose size and mtime match the
     * index. That shortcut is **wrong** for any file whose mtime falls in the
     * same second the index was written, because a change made after the index
     * was saved but within that second leaves both size and mtime looking
     * untouched.
     *
     * Found by measurement, not by reasoning: an edit that replaced 32 bytes
     * with 32 different bytes in the same second was reported as unchanged, and
     * a divergent write went unflagged as a result.
     *
     * git calls these entries "racily clean" and handles it the same way — any
     * entry at or after this timestamp must be hashed.
     */
    public function saved_t(): int
    {
        return $this->saved_t;
    }

    public function record(string $path_c, int $version_id, string $sha256_c, int $bytes_n, int $mtime_t): void
    {
        $this->entries[$path_c] = [
            'version_id' => $version_id,
            'sha256_c'   => $sha256_c,
            'bytes_n'    => $bytes_n,
            'mtime_t'    => $mtime_t,
        ];
    }

    public function forget(string $path_c): void
    {
        unset($this->entries[$path_c]);
    }

    public function has(string $path_c): bool
    {
        return isset($this->entries[$path_c]);
    }

    /**
     * @return array{version_id: int, sha256_c: string, bytes_n: int, mtime_t: int}|null
     */
    public function entry(string $path_c): ?array
    {
        return $this->entries[$path_c] ?? null;
    }

    /**
     * @return string[]
     */
    public function paths(): array
    {
        return array_keys($this->entries);
    }

    public function count(): int
    {
        return count($this->entries);
    }
}
