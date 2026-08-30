<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Documents - Renderer
 *
 * @package Italix\Documents
 */

declare(strict_types=1);

namespace Italix\Documents;

/**
 * Turns a source document into HTML.
 *
 * Selected by **extension**, not by sniffed MIME type. The two answer different
 * questions: sniffing decides whether bytes may be stored at all, which
 * `italix/storage` has already settled before anything reaches here; the
 * extension declares how the author intends the file to be read. A `.md` file is
 * `text/plain` to `finfo` and Markdown to a reader, and both are correct.
 *
 * Adding a format is an implementation, never a change to this library — the
 * same shape as `PathStrategy` and `MimeCatalog` in `italix/storage`.
 */
interface Renderer
{
    /**
     * @param string $extension_c lowercase, no dot
     */
    public function handles(string $extension_c): bool;

    public function render(string $source, string $path_c): Rendered;

    /** One line, for a CLI probe. */
    public function describe(): string;
}
