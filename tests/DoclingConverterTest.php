<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Documents — DoclingConverter
 *
 * Docling is a 6+ GB Python virtualenv, not something this repo's own
 * `composer install` brings in — see DoclingConverter's own docblock. This
 * suite skips loudly, not silently, when it is not configured, the same
 * pattern MariaDbVectorStoreTest/PgVectorStoreTest use for an optional real
 * backend: IX_DOCLING_BINARY names the `docling` executable (a venv path,
 * usually — see DoclingConverter's docblock on why this is a constructor
 * argument rather than assumed to be on $PATH); unset, this driver's own
 * default ('docling' on $PATH) is tried instead.
 *
 * The real-conversion test explicitly disables OCR — verified directly (see
 * DoclingConverter's docblock) that Docling's default OCR pass turns even a
 * single trivial page into a multi-second-to-multi-minute call. A test
 * suite that should run in well under a second stays that fast by not
 * exercising the expensive default.
 *
 * Run: IX_DOCLING_BINARY=/path/to/venv/bin/docling \
 *      php src/Libs/Italix/Documents/tests/DoclingConverterTest.php
 */

declare(strict_types=1);

(static function (): void {
    foreach ([
        __DIR__ . '/../vendor/autoload.php',
        __DIR__ . '/../../../../../vendor/autoload.php',
        __DIR__ . '/../../../../vendor/autoload.php',
        __DIR__ . '/../../../autoload.php',
    ] as $autoload) {
        if (is_file($autoload)) {
            require_once $autoload;

            return;
        }
    }

    fwrite(STDERR, "Could not find an autoloader. Run composer install.\n");
    exit(2);
})();

use Italix\Converters\ConversionException;
use Italix\Converters\ConversionOptions;
use Italix\Converters\ConverterSet;
use Italix\Documents\DoclingConverter;
use Italix\Documents\HtmlToPdfConverter;

use function Italix\Testing\{suite, section, test, summary};

suite('Italix Documents — DoclingConverter');

$driver = new DoclingConverter(getenv('IX_DOCLING_BINARY') ?: 'docling');

if (!$driver->is_available()) {
    echo "  SKIPPED — Docling is absent (set IX_DOCLING_BINARY to a real venv's docling executable).\n";
    exit(summary());
}

// -----------------------------------------------------------------------------
section('the driver describes itself honestly');

test('pairs() covers exactly the formats actually verified here — not Docling\'s whole claimed list', $driver->pairs() === [['pdf', 'md'], ['docx', 'md'], ['pptx', 'md']]);
test('is_available() is true — the configured binary is on PATH', $driver->is_available());
test('describe() names the real mechanism', str_contains($driver->describe(), 'Docling'));

// -----------------------------------------------------------------------------
section('convert() produces real Markdown from a real PDF, OCR disabled for speed');

// Built with the same dompdf driver PdfPageToImageConverterTest uses to get
// a real PDF rather than a hand-crafted fixture — not testing dompdf here,
// just need genuine PDF bytes with a table in them.
$real_pdf = (new HtmlToPdfConverter())->convert(
    '<h1>Deposit terms</h1>'
        . '<p>The tenant deposit is held until handover of the property.</p>'
        . '<table border="1"><tr><th>Month</th><th>Amount</th></tr>'
        . '<tr><td>January</td><td>850</td></tr></table>',
    'html',
    'pdf',
    ConversionOptions::none()
)->bytes();

$result = $driver->convert(
    $real_pdf,
    'pdf',
    'md',
    ConversionOptions::none()->with(DoclingConverter::OPTION_OCR, false)
);

test('the extension is md', $result->extension_code() === 'md');
test('the mime type says so too', $result->mime() === 'text/markdown; charset=utf-8');
test('the heading survived the round trip', str_contains($result->bytes(), 'Deposit terms'));
test('the paragraph text survived the round trip', str_contains($result->bytes(), 'tenant deposit'));
test('the table survived as real Markdown table syntax, not flattened prose', str_contains($result->bytes(), '|') && str_contains($result->bytes(), 'January'));

// -----------------------------------------------------------------------------
section('OPTION_IMAGE_MODE — embedded images are the real cost on a screenshot-heavy document, worse than OCR');

// A real-sized picture (GD, not a 10x10 dot dompdf might not even lay out as
// a Picture item) — needed to actually exercise Docling's image handling,
// not just prove the flag doesn't crash. Verified directly against a real
// 23-page Fabrick guide first: default (embedded) mode alone turned 45
// screenshots into an 11.3MB Markdown file, 99.97% base64 image bytes
// against under 4KB of real prose — this is the fast, small-scale version
// of that same finding.
// A flat, featureless rectangle is not enough — verified directly, Docling's
// layout model discards it as noise rather than a Picture item. Drawn text
// gives it real visual content, the same as an actual screenshot would have.
$image = imagecreatetruecolor(300, 200);
imagefill($image, 0, 0, imagecolorallocate($image, 30, 100, 200));
imagestring($image, 5, 20, 90, 'SCREENSHOT', imagecolorallocate($image, 255, 255, 255));
ob_start();
imagepng($image);
$image_png_b64 = base64_encode(ob_get_clean());

$pdf_with_image = (new HtmlToPdfConverter())->convert(
    '<h1>Deposit terms</h1><p>The tenant deposit is held.</p>'
        . "<img src=\"data:image/png;base64,{$image_png_b64}\" width=\"300\" height=\"200\">",
    'html',
    'pdf',
    ConversionOptions::none()
)->bytes();

$embedded = $driver->convert(
    $pdf_with_image,
    'pdf',
    'md',
    ConversionOptions::none()->with(DoclingConverter::OPTION_OCR, false)
);
test('the default (no OPTION_IMAGE_MODE) embeds the image as base64 — Docling\'s own default, left alone', str_contains($embedded->bytes(), 'data:image'));

$placeholder = $driver->convert(
    $pdf_with_image,
    'pdf',
    'md',
    ConversionOptions::none()
        ->with(DoclingConverter::OPTION_OCR, false)
        ->with(DoclingConverter::OPTION_IMAGE_MODE, 'placeholder')
);
test('OPTION_IMAGE_MODE=placeholder marks the image\'s position instead of embedding it', str_contains($placeholder->bytes(), '<!-- image -->'));
test('...and carries no base64 payload at all — this is the lever that fixes the 11.3MB-from-45-screenshots problem', !str_contains($placeholder->bytes(), 'data:image'));
test('...while the real text content is unaffected either way', str_contains($placeholder->bytes(), 'tenant deposit'));

// -----------------------------------------------------------------------------
section('genuinely invalid input fails loudly, with Docling\'s own output attached');

$threw = false;
try {
    $driver->convert('this is not a pdf at all', 'pdf', 'md', ConversionOptions::none()->with(DoclingConverter::OPTION_OCR, false));
} catch (ConversionException $e) {
    $threw = true;
    test('the exception message says what failed', str_contains($e->getMessage(), 'Docling'));
    test('...and carries Docling\'s own output, not a generic message', $e->driver_stderr() !== null && $e->driver_stderr() !== '');
}
test('genuinely invalid input throws ConversionException rather than returning empty or garbage bytes', $threw);

// -----------------------------------------------------------------------------
section('registered in a ConverterSet, the missing half of the pipeline: pdf -> md now has a driver');

$set   = new ConverterSet([$driver]);
$route = $set->route('pdf', 'md');

test('pdf -> md routes directly, one hop', count($route) === 1);
test('...naming this exact instance', $route[0][2] === $driver);

exit(summary());
