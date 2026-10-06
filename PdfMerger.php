<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Documents - PdfMerger
 *
 * @package Italix\Documents
 */

declare(strict_types=1);

namespace Italix\Documents;

use Italix\Converters\ConversionException;
use Italix\Converters\ConversionOptions;
use Italix\Converters\ConvertedDocument;
use Italix\Converters\Merger;

/**
 * N PDFs into one — via `pdfunite` (poppler-utils), the exact mirror of
 * `PdfPageSplitter`'s `pdfseparate`. Same shelling-out shape as every other
 * subprocess-backed driver in this library (`DoclingConverter`) and in
 * `italix/media` (`PdfPageToImageConverter`).
 *
 * Only `pdf -> pdf`: reaching a combined image or another format from
 * several PDFs is not this driver's job — chain a `Converter` afterward.
 */
final class PdfMerger implements Merger
{
    /** Kill a pdfunite invocation that has not finished within this many seconds. */
    private const TIMEOUT_S = 120;

    public function __construct(
        private readonly string $binary_c = 'pdfunite'
    ) {
    }

    public function pairs(): array
    {
        return [['pdf', 'pdf']];
    }

    public function is_available(): bool
    {
        $found = shell_exec('command -v ' . escapeshellarg($this->binary_c) . ' 2>/dev/null');

        return is_string($found) && trim($found) !== '';
    }

    public function merge(array $sources, string $from_extension_c, string $to_extension_c, ConversionOptions $options): ConvertedDocument
    {
        if (count($sources) < 2) {
            throw new ConversionException('merge() needs at least two sources — a single PDF needs no merging.');
        }

        $work_dir = sys_get_temp_dir() . '/ix-documents-pdfmerge-' . bin2hex(random_bytes(8));

        try {
            mkdir($work_dir, 0700, true);

            $part_paths = [];

            foreach ($sources as $i => $bytes) {
                $part_path = $work_dir . "/part-{$i}.pdf";
                file_put_contents($part_path, $bytes);
                $part_paths[] = $part_path;
            }

            $output_path = $work_dir . '/merged.pdf';

            // pdfunite's argument order is INPUTS... then OUTPUT last — see
            // PdfPageSplitter::merge_range()'s docblock for how this was
            // caught: reversed, pdfunite treats the output path as an
            // input and fails with a "damaged document" error, not an
            // argument-order complaint.
            $command = sprintf(
                'timeout %d %s %s %s 2>&1',
                self::TIMEOUT_S,
                escapeshellarg($this->binary_c),
                implode(' ', array_map('escapeshellarg', $part_paths)),
                escapeshellarg($output_path)
            );

            exec($command, $output_lines, $exit_code);

            if ($exit_code !== 0 || !is_file($output_path) || filesize($output_path) === 0) {
                throw new ConversionException(
                    'pdfunite could not merge these PDFs (exit ' . $exit_code . ').',
                    implode("\n", $output_lines)
                );
            }

            $bytes = file_get_contents($output_path);
        } finally {
            exec('rm -rf ' . escapeshellarg($work_dir));
        }

        if ($bytes === false || $bytes === '') {
            throw new ConversionException('pdfunite produced no readable output.');
        }

        return new ConvertedDocument($bytes, 'pdf', 'application/pdf');
    }

    public function describe(): string
    {
        return 'N pdfs -> 1 pdf (pdfunite, subprocess)';
    }
}
