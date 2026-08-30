<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Documents - PlainRenderer
 *
 * @package Italix\Documents
 */

declare(strict_types=1);

namespace Italix\Documents;

/**
 * The fallback: anything with no renderer of its own becomes an escaped `<pre>`.
 *
 * Escaped, not raw, and that is the point. A tree holds whatever an author puts
 * in it, and the day someone commits a `.txt` containing `<script>` this is what
 * stands between that and stored XSS. Refusing to render unknown formats would
 * be safe too, but it would make a documentation tree lose files silently, which
 * is worse.
 */
final class PlainRenderer implements Renderer
{
    public function handles(string $extension_c): bool
    {
        return true;
    }

    public function render(string $source, string $path_c): Rendered
    {
        $safe = htmlspecialchars($source, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return new Rendered('<pre>' . $safe . '</pre>', $source, []);
    }

    public function describe(): string
    {
        return 'anything else -> escaped <pre>';
    }
}
