<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Documents - DoclingConverter
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
 * PDF/DOCX/PPTX to Markdown, via Docling (https://github.com/docling-project/docling)
 * — shelled out to, like PdfPageToImageConverter shells out to Ghostscript in
 * italix/media. Not in-process: Docling is a Python CLI backed by a real ML
 * stack (layout detection, table-structure recognition, OCR), not something
 * this driver could reimplement or call over a lightweight extension.
 *
 * This is the one gap the rest of this library's converters don't cover:
 * PDF/DOCX/PPTX exist here only as conversion *targets* today
 * (HtmlToPdfConverter), never as sources feeding INTO Markdown. That's the
 * missing half of turning an arbitrary uploaded document into something
 * Rendered::text() can chunk and embed.
 *
 * **`is_available()` is weaker than every other driver's.** For Ghostscript
 * or dompdf, "the binary/class exists" really does mean "ready right now".
 * For Docling it does not: a fresh install reports available immediately,
 * but its first real conversion blocks for tens of seconds pulling several
 * hundred MB of layout/table/OCR model weights from huggingface.co and
 * modelscope.cn — verified directly (a clean `pip install docling` + first
 * `docling convert` on a synthetic PDF: 42.8s, ~500MB from HF, ~30MB from
 * modelscope.cn). This class does not try to detect "installed but models
 * not yet cached" — that would mean reaching into huggingface_hub's cache
 * layout, which is not this driver's concern — so a deploy that wants
 * predictable first-request latency needs to pre-warm that cache itself
 * (run one real conversion during provisioning), not rely on
 * `is_available()` to say so.
 *
 * **OCR is on by default even for born-digital PDFs with a real text
 * layer**, and it dominates wall-clock time — verified directly, not
 * assumed, against two real Fabrick API-docs PDFs (not synthetic fixtures):
 * a 5-page PDF with a handful of embedded screenshots took 400.35s
 * end-to-end (CPU-only, `--num-threads 4`, this box has no GPU); the same
 * driver call with OCR disabled on a comparable one-paragraph, no-image
 * synthetic PDF took well under two seconds of the total. Docling has no
 * way to know a page's text layer is already trustworthy without OCR'ing
 * it, so it always does — every bitmap region on every page, per page.
 * `ConversionOptions::none()` leaves that default alone (never silently
 * loosen a driver's default — see HtmlToPdfConverter's docblock); a caller
 * with born-digital sources and no need for image text can pass
 * `ConversionOptions::none()->with(DoclingConverter::OPTION_OCR, false)` to
 * skip it — this is the one lever that turns "minutes" into "seconds" for
 * the common case of a PDF that already has a real text layer.
 *
 * Quality on that same 5-page real document, checked by reading the actual
 * output, not assumed: headings, paragraph structure and one genuine table
 * all survived correctly. Two lines came out with visibly dropped
 * characters (an artifact of that specific PDF's font encoding, not
 * reproduced on the synthetic test fixture) — a reminder that Docling's
 * output quality depends on the input PDF's own text encoding same as any
 * text-extraction tool, and is worth spot-checking per real-world source,
 * not assumed uniform.
 *
 * **Embedded images are the bigger cost on a screenshot-heavy document —
 * far more than OCR.** A real 23-page Fabrick onboarding guide (45 UI
 * screenshots, walking a user through a form flow) converted to an
 * **11.3MB** Markdown file — of which 11,281,501 of 11,285,187 bytes
 * (99.97%) were base64 image data; the real prose was under 4KB. That is
 * `image-export-mode`'s default (`embedded`) doing exactly what it says,
 * and it is the wrong default for feeding a chunker/embedder: 45 logo- and
 * screenshot-sized images will dwarf every real paragraph's token count for
 * no semantic value. `OPTION_IMAGE_MODE` controls this the same way
 * `OPTION_OCR` controls OCR; `placeholder` mode (just marks where an image
 * was, no bytes) is the sane default for a RAG pipeline, but this class
 * does not silently switch to it — `ConversionOptions::none()` still means
 * Docling's own default, same reasoning as OCR.
 *
 * `referenced` mode (write each image as its own file, alongside the .md)
 * is accepted here but **not fully usable through this driver**: `convert()`
 * returns a single `ConvertedDocument` — bytes only, no side-channel for a
 * directory of images — and the `finally` block that cleans up
 * `$output_dir` deletes exactly those files before `convert()` returns, so
 * the Markdown would come back with image links pointing at nothing. Left
 * as a documented gap rather than silently accepted: a caller that actually
 * needs `referenced` mode needs this driver extended to return the image
 * files too, not just told the option "worked".
 *
 * The binary path is a constructor argument rather than assumed to be on
 * `$PATH`, unlike `gs`: Docling's own dependency footprint (torch,
 * torchvision, transformers — 6+ GB) makes a dedicated virtualenv the
 * realistic deployment shape, not a system package, so the path to that
 * venv's `docling` executable needs to be told to this class, not guessed.
 */
final class DoclingConverter implements Converter
{
    /**
     * Generic ConversionOptions key (bool), not one of the class's own named
     * options — Docling's OCR toggle exists nowhere else in this library's
     * vocabulary, and does not need to: see ConversionOptions' own docblock
     * on why a driver-specific knob is a plain with()/get() key rather than
     * a change to that class.
     */
    public const OPTION_OCR = 'docling_ocr';

    /**
     * Generic ConversionOptions key (string|null): 'placeholder', 'embedded'
     * or 'referenced', passed straight through to Docling's own
     * --image-export-mode. Null (the default) means "don't pass the flag" —
     * Docling's own default (embedded) applies. See this class's own
     * docblock for why 'referenced' is accepted but not fully usable here,
     * and why 'placeholder' is the recommended value for a RAG pipeline.
     */
    public const OPTION_IMAGE_MODE = 'docling_image_mode';

    /**
     * Generous on purpose: 400s measured for a real 5-page, image-bearing
     * PDF with OCR on (see this class's own docblock) — a larger real
     * document plausibly exceeds even this. Tune per deployment rather than
     * trusting this number for documents unlike the one it was measured on;
     * it exists to fail a genuinely stuck subprocess, not to bound normal
     * latency for every document this driver will ever see.
     */
    private const TIMEOUT_S = 1800;

    public function __construct(
        private readonly string $binary_c = 'docling'
    ) {
    }

    public function pairs(): array
    {
        // Docling documents support for a much longer list of input formats
        // (xlsx, html, images, odt/odp/ods, ...) — only these three have
        // actually been run against real files in this environment. Widen
        // this list once each addition has been verified the same way,
        // rather than trusting Docling's own claimed format list wholesale.
        return [['pdf', 'md'], ['docx', 'md'], ['pptx', 'md']];
    }

    public function is_available(): bool
    {
        // `command -v`, not `which`: a POSIX shell builtin everywhere that
        // matters here, with no dependency on a separate `which` package
        // being installed. Absolute paths pass through command -v unchanged.
        $found = shell_exec('command -v ' . escapeshellarg($this->binary_c) . ' 2>/dev/null');

        return is_string($found) && trim($found) !== '';
    }

    public function convert(
        string $source,
        string $from_extension_c,
        string $to_extension_c,
        ConversionOptions $options
    ): ConvertedDocument {
        $ocr_flag        = $options->get(self::OPTION_OCR, true) ? '' : '--no-ocr ';
        $image_mode_c    = $options->get(self::OPTION_IMAGE_MODE);
        $image_mode_flag = $image_mode_c !== null ? '--image-export-mode ' . escapeshellarg($image_mode_c) . ' ' : '';

        $input_path  = sys_get_temp_dir() . '/ix-documents-docling-' . bin2hex(random_bytes(8)) . '.' . $from_extension_c;
        $output_dir  = sys_get_temp_dir() . '/ix-documents-docling-out-' . bin2hex(random_bytes(8));
        $output_path = $output_dir . '/' . pathinfo($input_path, PATHINFO_FILENAME) . '.md';

        try {
            file_put_contents($input_path, $source);
            mkdir($output_dir, 0700, true);

            $command = sprintf(
                'timeout %d %s convert %s%s--to md --output %s %s 2>&1',
                self::TIMEOUT_S,
                escapeshellarg($this->binary_c),
                $ocr_flag,
                $image_mode_flag,
                escapeshellarg($output_dir),
                escapeshellarg($input_path)
            );

            exec($command, $output_lines, $exit_code);

            if ($exit_code !== 0 || !is_file($output_path) || filesize($output_path) === 0) {
                throw new ConversionException(
                    "Docling could not convert this document (exit {$exit_code}).",
                    implode("\n", $output_lines)
                );
            }

            $bytes = file_get_contents($output_path);
        } finally {
            @unlink($input_path);
            // rm -rf, not a glob()-based per-child loop: glob('*') does not
            // match dotfiles, which cost a real leftover-directory bug
            // elsewhere in this codebase's own Documents-connector cleanup.
            exec('rm -rf ' . escapeshellarg($output_dir));
        }

        if ($bytes === false || $bytes === '') {
            throw new ConversionException('Docling produced no readable output.');
        }

        return new ConvertedDocument($bytes, 'md', 'text/markdown; charset=utf-8');
    }

    public function describe(): string
    {
        return 'pdf/docx/pptx -> md (Docling, subprocess, OCR and embedded images on by default — see '
            . self::OPTION_OCR . ' and ' . self::OPTION_IMAGE_MODE . ')';
    }
}
