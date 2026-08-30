<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Documents - Version
 *
 * @package Italix\Documents
 */

declare(strict_types=1);

namespace Italix\Documents;

/**
 * One saved state of one document. Append-only: nothing here is ever updated.
 *
 * `parent_version_id` is the whole conflict story. It records what the writer
 * was working from, which is what turns last-writer-wins from "probably fine"
 * into "provably lossless and honest about divergence" — see `Library::save()`.
 */
final class Version
{
    private int $id;
    private int $node_id;
    private ?int $parent_version_id;
    private string $storage_path_c;
    private string $sha256_c;
    private string $mime_c;
    private int $bytes_n;
    private string $author_c;
    private string $note_c;
    private bool $is_divergent;
    private ?int $source_version_id;
    private ?string $archive_path_c;
    private string $insert_dt;

    public function __construct(
        int $id,
        int $node_id,
        ?int $parent_version_id,
        string $storage_path_c,
        string $sha256_c,
        string $mime_c,
        int $bytes_n,
        string $author_c = '',
        string $note_c = '',
        bool $is_divergent = false,
        ?int $source_version_id = null,
        string $insert_dt = '',
        ?string $archive_path_c = null
    ) {
        $this->id                = $id;
        $this->node_id           = $node_id;
        $this->parent_version_id = $parent_version_id;
        $this->storage_path_c    = $storage_path_c;
        $this->sha256_c          = $sha256_c;
        $this->mime_c            = $mime_c;
        $this->bytes_n           = $bytes_n;
        $this->author_c          = $author_c;
        $this->note_c            = $note_c;
        $this->is_divergent      = $is_divergent;
        $this->source_version_id = $source_version_id;
        $this->archive_path_c    = $archive_path_c;
        $this->insert_dt         = $insert_dt;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function from_row(array $row): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['node_id'],
            $row['parent_version_id'] === null ? null : (int) $row['parent_version_id'],
            (string) $row['storage_path_c'],
            (string) $row['sha256_c'],
            (string) $row['mime_c'],
            (int) $row['bytes_n'],
            (string) ($row['author_c'] ?? ''),
            (string) ($row['note_c'] ?? ''),
            (bool) ($row['is_divergent'] ?? false),
            ($row['source_version_id'] ?? null) === null ? null : (int) $row['source_version_id'],
            (string) ($row['insert_dt'] ?? ''),
            ($row['archive_path_c'] ?? null) === null ? null : (string) $row['archive_path_c']
        );
    }

    public function id(): int
    {
        return $this->id;
    }

    public function node_id(): int
    {
        return $this->node_id;
    }

    public function parent_version_id(): ?int
    {
        return $this->parent_version_id;
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

    public function author(): string
    {
        return $this->author_c;
    }

    public function note(): string
    {
        return $this->note_c;
    }

    /**
     * True when this was written from a parent that was no longer `latest`.
     *
     * The write was still accepted — nothing is ever discarded — but somebody
     * else had moved the document in the meantime and this version did not see
     * their work.
     */
    public function is_divergent(): bool
    {
        return $this->is_divergent;
    }

    public function source_version_id(): ?int
    {
        return $this->source_version_id;
    }

    public function insert_dt(): string
    {
        return $this->insert_dt;
    }

    /**
     * The archive this version's blob was packed into, or null while it is loose.
     *
     * Unused until phase 3, and the contract is deliberately narrow so that
     * packing stays reversible:
     *
     * - **null** — the blob is a file at `storage_path_c`
     * - **set**  — the blob is an entry inside this archive, named by the
     *   basename of `storage_path_c`
     *
     * `storage_path_c` therefore means the same thing in both states, and
     * unpacking is: extract, clear this column, delete the archive. Nothing else
     * in the row moves.
     */
    public function archive_path(): ?string
    {
        return $this->archive_path_c;
    }

    public function is_archived(): bool
    {
        return $this->archive_path_c !== null;
    }
}
