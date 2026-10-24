<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Documents - PdfToTextConverter
 *
 * @package Italix\Documents
 */

declare(strict_types=1);

namespace Italix\Documents;

use Italix\Converters\ConversionException;
use Italix\Converters\ConversionOptions;
use Italix\Converters\ConvertedDocument;
use Italix\Converters\Converter;

/**
 * A PDF's text layer as plain text, with poppler's `pdftotext` — the same
 * package PdfPageSplitter and PdfMerger use. No layout, no OCR: what a PDF
 * says, for reading and searching. Docling gives structure too, when it is
 * installed; this needs nothing but poppler.
 */
final class PdfToTextConverter implements Converter
{
    /** Kill a pdftotext invocation that has not finished within this many seconds. */
    private const TIMEOUT_S = 120;

    public function __construct(
        private readonly string $binary_c = 'pdftotext'
    ) {
    }

    public function pairs(): array
    {
        return [['pdf', 'txt']];
    }

    public function is_available(): bool
    {
        $found = shell_exec('command -v ' . escapeshellarg($this->binary_c) . ' 2>/dev/null');

        return is_string($found) && trim($found) !== '';
    }

    public function convert(
        string $source,
        string $from_extension_c,
        string $to_extension_c,
        ConversionOptions $options
    ): ConvertedDocument {
        $work_dir = sys_get_temp_dir() . '/ix-documents-pdftotext-' . bin2hex(random_bytes(8));
        mkdir($work_dir, 0700, true);

        try {
            file_put_contents($work_dir . '/source.pdf', $source);
            exec(sprintf(
                'timeout %d %s -enc UTF-8 -eol unix %s %s 2>&1',
                self::TIMEOUT_S,
                escapeshellarg($this->binary_c),
                escapeshellarg($work_dir . '/source.pdf'),
                escapeshellarg($work_dir . '/text.txt')
            ), $output, $exit_code);

            if ($exit_code !== 0 || !is_file($work_dir . '/text.txt')) {
                throw new ConversionException("pdftotext failed (exit {$exit_code}): " . implode("\n", $output));
            }

            return new ConvertedDocument((string) file_get_contents($work_dir . '/text.txt'), 'txt', 'text/plain; charset=utf-8');
        } finally {
            @unlink($work_dir . '/source.pdf');
            @unlink($work_dir . '/text.txt');
            @rmdir($work_dir);
        }
    }

    public function describe(): string
    {
        return 'pdf -> txt (poppler pdftotext)';
    }
}
