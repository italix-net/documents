<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Documents - Node
 *
 * @package Italix\Documents
 */

declare(strict_types=1);

namespace Italix\Documents;

/**
 * A folder or a document in a tree. Immutable; a change produces a new row and
 * a new value object.
 */
final class Node
{
    public const KIND_FOLDER   = 'folder';
    public const KIND_DOCUMENT = 'document';

    private int $id;
    private int $tree_id;
    private ?int $parent_id;
    private string $kind_c;
    private string $name_c;
    private string $path_c;
    private ?int $latest_version_id;
    private ?string $lang_c;
    private ?int $translation_of_node_id;
    private bool $is_published;

    public function __construct(
        int $id,
        int $tree_id,
        ?int $parent_id,
        string $kind_c,
        string $name_c,
        string $path_c,
        ?int $latest_version_id = null,
        ?string $lang_c = null,
        ?int $translation_of_node_id = null,
        bool $is_published = true
    ) {
        $this->id                     = $id;
        $this->tree_id                = $tree_id;
        $this->parent_id              = $parent_id;
        $this->kind_c                 = $kind_c;
        $this->name_c                 = $name_c;
        $this->path_c                 = $path_c;
        $this->latest_version_id      = $latest_version_id;
        $this->lang_c                 = $lang_c;
        $this->translation_of_node_id = $translation_of_node_id;
        $this->is_published           = $is_published;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function from_row(array $row): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['tree_id'],
            $row['parent_id'] === null ? null : (int) $row['parent_id'],
            (string) $row['kind_c'],
            (string) $row['name_c'],
            (string) $row['path_c'],
            $row['latest_version_id'] === null ? null : (int) $row['latest_version_id'],
            $row['lang_c'] === null || $row['lang_c'] === '' ? null : (string) $row['lang_c'],
            $row['translation_of_node_id'] === null ? null : (int) $row['translation_of_node_id'],
            (bool) $row['is_published']
        );
    }

    public function id(): int
    {
        return $this->id;
    }

    public function tree_id(): int
    {
        return $this->tree_id;
    }

    public function parent_id(): ?int
    {
        return $this->parent_id;
    }

    public function kind_code(): string
    {
        return $this->kind_c;
    }

    public function is_folder(): bool
    {
        return $this->kind_c === self::KIND_FOLDER;
    }

    public function name(): string
    {
        return $this->name_c;
    }

    public function path(): string
    {
        return $this->path_c;
    }

    public function latest_version_id(): ?int
    {
        return $this->latest_version_id;
    }

    /** BCP-47, or null for language-neutral content. */
    public function lang_code(): ?string
    {
        return $this->lang_c;
    }

    public function translation_of_node_id(): ?int
    {
        return $this->translation_of_node_id;
    }

    public function is_published(): bool
    {
        return $this->is_published;
    }

    public function extension_code(): string
    {
        return Path::extension_of($this->path_c);
    }
}
