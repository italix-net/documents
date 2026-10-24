<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Documents - SavedPage
 *
 * @package Italix\Documents
 */

declare(strict_types=1);

namespace Italix\Documents;

/**
 * A web page as saved, whatever saved it: its URL, title and time, its
 * HTML, and the files the HTML refers to by local name — SingleFile's
 * layout, which `italix/crawler` writes too. What SingleFileImporter imports.
 */
final class SavedPage
{
    /**
     * @param array<string, string> $files local name => bytes, the HTML itself not among them
     */
    public function __construct(
        private string $url_c,
        private string $title,
        private string $saved_dt,
        private string $html,
        private array $files
    ) {
    }

    public function url(): string
    {
        return $this->url_c;
    }

    public function title(): string
    {
        return $this->title;
    }

    /** When it was saved, ISO 8601 with its offset (`2026-07-09T09:52:49.275Z`). */
    public function saved_dt(): string
    {
        return $this->saved_dt;
    }

    public function html(): string
    {
        return $this->html;
    }

    /** @return array<string, string> */
    public function files(): array
    {
        return $this->files;
    }
}
