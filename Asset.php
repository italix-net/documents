<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Documents - Asset
 *
 * @package Italix\Documents
 */

declare(strict_types=1);

namespace Italix\Documents;

/**
 * One file of a bundle version's `.assets/` folder.
 *
 * Belongs to a version, not to a path: moving the document moves the folder.
 * Archived when its version is, as `assets/<basename of storage_path_c>` in
 * that version's archive.
 */
final class Asset
{
    private int $version_id;
    private string $name_c;
    private string $storage_path_c;
    private string $sha256_c;
    private string $mime_c;
    private int $bytes_n;

    public function __construct(int $version_id, string $name_c, string $storage_path_c, string $sha256_c, string $mime_c, int $bytes_n)
    {
        $this->version_id     = $version_id;
        $this->name_c         = $name_c;
        $this->storage_path_c = $storage_path_c;
        $this->sha256_c       = $sha256_c;
        $this->mime_c         = $mime_c;
        $this->bytes_n        = $bytes_n;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function from_row(array $row): self
    {
        return new self(
            (int) $row['version_id'],
            (string) $row['name_c'],
            (string) $row['storage_path_c'],
            (string) $row['sha256_c'],
            (string) $row['mime_c'],
            (int) $row['bytes_n']
        );
    }

    public function version_id(): int
    {
        return $this->version_id;
    }

    /** The path inside the folder: `pump.png`, `img/pump.png`. */
    public function name(): string
    {
        return $this->name_c;
    }

    public function storage_path(): string
    {
        return $this->storage_path_c;
    }

    public function sha256(): string
    {
        return $this->sha256_c;
    }

    public function mime(): string
    {
        return $this->mime_c;
    }

    public function bytes_n(): int
    {
        return $this->bytes_n;
    }
}
