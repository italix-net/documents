<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Documents - HtmlToPdfConverter
 *
 * @package Italix\Documents
 */

declare(strict_types=1);

namespace Italix\Documents;

use Dompdf\Dompdf;
use Italix\Converters\ConversionException;
use Italix\Converters\ConversionOptions;
use Italix\Converters\ConvertedDocument;
use Italix\Converters\Converter;
use Italix\Converters\UnsupportedOptionException;

/**
 * HTML to PDF, via dompdf — pure PHP, no external binary or subprocess.
 *
 * dompdf's own defaults are used unmodified on purpose: remote fetching of
 * images and stylesheets is disabled by default (`isRemoteEnabled = false`),
 * and so is embedded PHP execution from the markup being rendered
 * (`isPhpEnabled = false`). Both are exactly the kind of footgun
 * MarkdownRenderer's docblock already argues against for raw HTML in
 * Markdown — a driver silently loosening either one to be more permissive
 * would undo a safety decision from a config file nobody reviews.
 *
 * Paper size is the one default overridden: dompdf defaults to US Letter,
 * which is the wrong silent default for a project whose whole domain is
 * Italian. A4 portrait is set explicitly rather than inherited.
 *
 * dompdf's own failures (malformed markup it cannot recover from, a font it
 * cannot load) surface as its own exception types; convert() normalizes
 * them to ConversionException so a caller routing through ConverterSet gets
 * one exception type regardless of which driver actually ran.
 *
 * dompdf's PDF output is RGB only — there is no CMYK mode to switch to, the
 * way there might be for a driver built on a color-management-aware
 * library. A colorspace explicitly requested via ConversionOptions and not
 * rgb throws UnsupportedOptionException rather than silently producing RGB
 * output anyway: a PDF a print vendor expects in CMYK and receives in RGB
 * is a correctness bug, not a cosmetic one, and this is where that request
 * should fail — loudly, at conversion time — not downstream when a proof
 * comes back the wrong color.
 */
final class HtmlToPdfConverter implements Converter
{
    public function pairs(): array
    {
        return [['html', 'pdf'], ['htm', 'pdf']];
    }

    public function is_available(): bool
    {
        return class_exists(Dompdf::class);
    }

    public function convert(
        string $source,
        string $from_extension_c,
        string $to_extension_c,
        ConversionOptions $options
    ): ConvertedDocument {
        $colorspace_c = $options->colorspace_code(ConversionOptions::COLORSPACE_RGB, static::class);

        if ($colorspace_c !== ConversionOptions::COLORSPACE_RGB) {
            throw new UnsupportedOptionException(ConversionOptions::COLORSPACE, $colorspace_c, $this->describe());
        }

        try {
            $dompdf = new Dompdf();
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->loadHtml($source);
            $dompdf->render();

            return new ConvertedDocument((string) $dompdf->output(), 'pdf', 'application/pdf');
        } catch (\Throwable $e) {
            throw new ConversionException("dompdf could not render this document: {$e->getMessage()}");
        }
    }

    public function describe(): string
    {
        return 'html/htm -> pdf (dompdf, A4 portrait, RGB only, remote content and embedded PHP disabled)';
    }
}
