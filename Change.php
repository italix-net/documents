<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Documents - Change
 *
 * @package Italix\Documents
 */

declare(strict_types=1);

namespace Italix\Documents;

/**
 * One difference between the projection on disk and the tree in the database.
 *
 * `MISSING` deserves its own kind rather than being treated as a delete. A file
 * absent from the working tree may be a document somebody removed, or a
 * directory that is still syncing, or a checkout that ran out of disk. Guessing
 * wrong destroys work, so `import` reports it and `documents:rm` is the only
 * thing that deletes.
 */
final class Change
{
    public const ADDED     = 'added';
    public const MODIFIED  = 'modified';
    public const UNCHANGED = 'unchanged';
    public const MISSING   = 'missing';

    private string $kind_c;
    private string $path_c;
    private bool $would_diverge;
    private ?int $base_version_id;

    public function __construct(
        string $kind_c,
        string $path_c,
        bool $would_diverge = false,
        ?int $base_version_id = null
    ) {
        $this->kind_c          = $kind_c;
        $this->path_c          = $path_c;
        $this->would_diverge   = $would_diverge;
        $this->base_version_id = $base_version_id;
    }

    public function kind_code(): string
    {
        return $this->kind_c;
    }

    public function path(): string
    {
        return $this->path_c;
    }

    /**
     * True when the file on disk was checked out from a version that is no
     * longer `latest` — somebody else has written since.
     *
     * Importing it is still allowed and still loses nothing. This only says that
     * the result will not have seen their work.
     */
    public function would_diverge(): bool
    {
        return $this->would_diverge;
    }

    /** The version the working copy came from, per the index. */
    public function base_version_id(): ?int
    {
        return $this->base_version_id;
    }

    public function is_actionable(): bool
    {
        return $this->kind_c !== self::UNCHANGED;
    }

    public function describe(): string
    {
        $mark = [
            self::ADDED     => 'A',
            self::MODIFIED  => 'M',
            self::UNCHANGED => ' ',
            self::MISSING   => '!',
        ][$this->kind_c] ?? '?';

        return $mark . ($this->would_diverge ? 'D' : ' ') . ' ' . $this->path_c;
    }
}
