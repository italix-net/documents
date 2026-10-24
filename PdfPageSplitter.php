<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Documents - PdfPageSplitter
 *
 * @package Italix\Documents
 */

declare(strict_types=1);

namespace Italix\Documents;

use Italix\Converters\ConversionException;
use Italix\Converters\ConversionOptions;
use Italix\Converters\ConvertedDocument;
use Italix\Converters\Extractor;

/**
 * A PDF, split into one single-page PDF per page — via `pdfseparate`
 * (poppler-utils), shelled out to directly. Same shelling-out shape as
 * `PdfPageToImageConverter`'s Ghostscript call in `italix/media`.
 *
 * `$boundaries = null` means "every page" — `pdfseparate`'s own default
 * behavior, one output file per page, in order. An explicit `$boundaries`
 * list is a list of *page ranges* `[first, last]` (1-based, inclusive),
 * not single page numbers, so "pages 3 through 5 as one combined piece"
 * and "each page separately" are both expressible with the same shape —
 * a single-page range is just `[3, 3]`. A range is achieved by splitting
 * to individual pages first (pdfseparate has no native range-to-one-file
 * mode) and re-merging same-range pages — see the implementation note on
 * `extract()` for why that, rather than teaching this class two different
 * subprocess strategies for what is conceptually one operation.
 */
final class PdfPageSplitter implements Extractor
{
    /** Kill a pdfseparate invocation that has not finished within this many seconds. */
    private const TIMEOUT_S = 60;

    public function __construct(
        private readonly string $binary_c = 'pdfseparate'
    ) {
    }

    public function extensions(): array
    {
        return ['pdf'];
    }

    public function is_available(): bool
    {
        $found = shell_exec('command -v ' . escapeshellarg($this->binary_c) . ' 2>/dev/null');

        return is_string($found) && trim($found) !== '';
    }

    public function extract(string $source, string $extension_c, ?array $boundaries, ConversionOptions $options): array
    {
        $work_dir = sys_get_temp_dir() . '/ix-documents-pdfsplit-' . bin2hex(random_bytes(8));

        try {
            mkdir($work_dir, 0700, true);

            $input_path = $work_dir . '/source.pdf';
            file_put_contents($input_path, $source);

            $pages_n = self::page_count($input_path);

            $command = sprintf(
                'timeout %d %s %s %s 2>&1',
                self::TIMEOUT_S,
                escapeshellarg($this->binary_c),
                escapeshellarg($input_path),
                escapeshellarg($work_dir . '/page-%d.pdf')
            );

            exec($command, $output_lines, $exit_code);

            if ($exit_code !== 0) {
                throw new ConversionException(
                    "pdfseparate could not split this PDF (exit {$exit_code}).",
                    implode("\n", $output_lines)
                );
            }

            $single_pages = [];

            for ($page_i = 1; $page_i <= $pages_n; $page_i++) {
                $page_path = $work_dir . "/page-{$page_i}.pdf";

                if (!is_file($page_path)) {
                    throw new ConversionException("pdfseparate did not produce page {$page_i} of {$pages_n}.");
                }

                $single_pages[$page_i] = file_get_contents($page_path);
            }

            if ($boundaries === null) {
                return array_map(
                    static fn (string $bytes): ConvertedDocument => new ConvertedDocument($bytes, 'pdf', 'application/pdf'),
                    array_values($single_pages)
                );
            }

            return array_map(
                fn (array $range): ConvertedDocument => $this->merge_range($single_pages, $range, $work_dir),
                $boundaries
            );
        } finally {
            exec('rm -rf ' . escapeshellarg($work_dir));
        }
    }

    /**
     * @param array<int, string> $single_pages page number => single-page PDF bytes
     * @param array{0: int, 1: int} $range [first, last], 1-based, inclusive
     */
    private function merge_range(array $single_pages, array $range, string $work_dir): ConvertedDocument
    {
        [$first_i, $last_i] = $range;

        if ($first_i === $last_i) {
            if (!isset($single_pages[$first_i])) {
                throw new ConversionException("Requested page {$first_i} does not exist in this document.");
            }

            return new ConvertedDocument($single_pages[$first_i], 'pdf', 'application/pdf');
        }

        // A multi-page boundary needs pdfunite, not pdfseparate — PdfMerger
        // already wraps it, but this class stays self-contained rather than
        // depending on a sibling class for an internal implementation
        // detail; both shell out to the same real binary either way.
        $unite_binary_c = 'pdfunite';
        $found          = shell_exec('command -v ' . escapeshellarg($unite_binary_c) . ' 2>/dev/null');

        if (!is_string($found) || trim($found) === '') {
            throw new ConversionException('A multi-page boundary needs pdfunite (poppler-utils), which is not on PATH.');
        }

        $part_paths = [];

        for ($page_i = $first_i; $page_i <= $last_i; $page_i++) {
            if (!isset($single_pages[$page_i])) {
                throw new ConversionException("Requested range [{$first_i}, {$last_i}] includes page {$page_i}, which does not exist.");
            }

            $part_paths[] = $work_dir . "/page-{$page_i}.pdf";
        }

        $output_path = $work_dir . '/range-' . $first_i . '-' . $last_i . '.pdf';

        // pdfunite's own argument order is INPUTS... then OUTPUT last —
        // verified directly after getting this backwards on the first
        // attempt (pdfunite tried to read the output path as an input and
        // failed with a "damaged document" error, not an argument-order
        // complaint, which made the real cause non-obvious from the error
        // message alone).
        $command = sprintf(
            'timeout %d %s %s %s 2>&1',
            self::TIMEOUT_S,
            escapeshellarg($unite_binary_c),
            implode(' ', array_map('escapeshellarg', $part_paths)),
            escapeshellarg($output_path)
        );

        exec($command, $output_lines, $exit_code);

        if ($exit_code !== 0 || !is_file($output_path)) {
            throw new ConversionException(
                "pdfunite could not combine pages {$first_i}-{$last_i} (exit {$exit_code}).",
                implode("\n", $output_lines)
            );
        }

        return new ConvertedDocument((string) file_get_contents($output_path), 'pdf', 'application/pdf');
    }

    private static function page_count(string $pdf_path): int
    {
        $output = shell_exec('pdfinfo ' . escapeshellarg($pdf_path) . ' 2>/dev/null');

        if (is_string($output) && preg_match('/^Pages:\s+(\d+)/m', $output, $m) === 1) {
            return (int) $m[1];
        }

        throw new ConversionException('Could not determine the page count of this PDF (pdfinfo unavailable or output unrecognized).');
    }

    public function describe(): string
    {
        return 'pdf -> N single-page pdfs (pdfseparate; page ranges via pdfunite)';
    }
}
