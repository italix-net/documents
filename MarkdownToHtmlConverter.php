<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Documents - MarkdownToHtmlConverter
 *
 * @package Italix\Documents
 */

declare(strict_types=1);

namespace Italix\Documents;

use Italix\Converters\ConversionOptions;
use Italix\Converters\ConvertedDocument;
use Italix\Converters\Converter;
use League\CommonMark\MarkdownConverter;

/**
 * Markdown to HTML, as an italix/converters driver.
 *
 * A thin adapter over MarkdownRenderer, not a second implementation: the
 * same escaping and unsafe-link rules that protect a published Documents
 * tree (see MarkdownRenderer's docblock) apply to anything routed here
 * through a ConverterSet, because it is the same renderer doing the work.
 *
 * is_available() checks league/commonmark's presence directly rather than
 * constructing a MarkdownRenderer to find out. MarkdownRenderer's
 * constructor throws when the dependency is missing — the right behavior
 * for a document tree that genuinely needs Markdown to work — but that is
 * exactly the throw-on-absence behavior a Converter must not have: it is
 * ConverterSet's job to decide what to do with an unavailable driver, not
 * the driver's, and that only works if is_available() can be asked first,
 * cheaply, without constructing anything.
 */
final class MarkdownToHtmlConverter implements Converter
{
    private const EXTENSIONS = ['md', 'markdown', 'mdown', 'mkd'];

    public function pairs(): array
    {
        return array_map(static fn (string $ext): array => [$ext, 'html'], self::EXTENSIONS);
    }

    public function is_available(): bool
    {
        return class_exists(MarkdownConverter::class);
    }

    public function convert(
        string $source,
        string $from_extension_c,
        string $to_extension_c,
        ConversionOptions $options
    ): ConvertedDocument {
        // $options is never read: Markdown -> HTML has no quality, page or
        // colorspace concept to configure. A hop later in the same route
        // (an encoding step, say) may still read it — that is between that
        // driver and the caller, not this one's concern.

        // Constructed here, not in the constructor of this class: this
        // method only ever runs after is_available() has already been
        // checked by whoever is routing, so the dependency is known to be
        // present by the time MarkdownRenderer's own constructor runs.
        $rendered = (new MarkdownRenderer())->render($source, "document.{$from_extension_c}");

        return new ConvertedDocument($rendered->html(), 'html', 'text/html; charset=utf-8');
    }

    public function describe(): string
    {
        return implode('/', self::EXTENSIONS) . ' -> html (Italix\Documents\MarkdownRenderer)';
    }
}
