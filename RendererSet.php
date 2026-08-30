<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Documents - RendererSet
 *
 * @package Italix\Documents
 */

declare(strict_types=1);

namespace Italix\Documents;

/**
 * The renderers a library will use, first match wins.
 *
 * `PlainRenderer` sits at the end and handles everything, so `for_extension()`
 * never returns null and no caller has to decide what to do about an unknown
 * format at the point of use.
 */
final class RendererSet
{
    /** @var Renderer[] */
    private array $renderers;

    /**
     * @param Renderer[] $renderers
     */
    public function __construct(array $renderers = [])
    {
        foreach ($renderers as $renderer) {
            if (!$renderer instanceof Renderer) {
                throw new DocumentsException('A RendererSet takes Renderer instances.');
            }
        }

        $renderers[] = new PlainRenderer();

        $this->renderers = $renderers;
    }

    /**
     * Markdown when `league/commonmark` is installed, plain text otherwise.
     *
     * Degrading rather than throwing, because a tree of PDFs and images is a
     * perfectly good tree and should not require a Markdown parser to exist.
     */
    public static function defaults(): self
    {
        if (class_exists(\League\CommonMark\MarkdownConverter::class)) {
            return new self([new MarkdownRenderer()]);
        }

        return new self();
    }

    public function for_extension(string $extension_c): Renderer
    {
        foreach ($this->renderers as $renderer) {
            if ($renderer->handles($extension_c)) {
                return $renderer;
            }
        }

        // Unreachable: PlainRenderer handles everything. Kept so a future
        // refactor that removes it fails loudly instead of returning null.
        throw new DocumentsException("No renderer handles \".{$extension_c}\".");
    }

    public function render(string $source, string $path_c): Rendered
    {
        return $this->for_extension(Path::extension_of($path_c))->render($source, $path_c);
    }

    /**
     * @return string[]
     */
    public function describe(): array
    {
        return array_map(static function (Renderer $r): string {
            return $r->describe();
        }, $this->renderers);
    }
}
