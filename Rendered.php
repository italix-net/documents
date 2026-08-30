<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Documents - Rendered
 *
 * @package Italix\Documents
 */

declare(strict_types=1);

namespace Italix\Documents;

/**
 * The output of a renderer: HTML, plus the plain text and the links found.
 *
 * All three come out of one parse because that parse already walked the whole
 * document. Extracting links by re-reading the HTML afterwards would be a second
 * pass over the same data and a worse one — the parser knows a link inside a
 * code fence is not a link, and a regular expression does not.
 */
final class Rendered
{
    private string $html;
    private string $text;

    /** @var string[] raw link targets, in document order, deduplicated */
    private array $links;

    /**
     * @param string[] $links
     */
    public function __construct(string $html, string $text = '', array $links = [])
    {
        $this->html  = $html;
        $this->text  = $text;
        $this->links = array_values(array_unique($links));
    }

    public function html(): string
    {
        return $this->html;
    }

    /** For the search index. */
    public function text(): string
    {
        return $this->text;
    }

    /**
     * @return string[]
     */
    public function links(): array
    {
        return $this->links;
    }
}
