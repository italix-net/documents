<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Documents — PdfMerger
 *
 * The second half of the round trip PdfPageSplitterTest.php proves the
 * first half of: split a real document into pages, merge a subset back,
 * and confirm the result is a genuine multi-page PDF, not just non-empty
 * bytes.
 *
 * Run: php src/Libs/Italix/Documents/tests/PdfMergerTest.php
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
use Italix\Documents\HtmlToPdfConverter;
use Italix\Documents\PdfMerger;
use Italix\Documents\PdfPageSplitter;

use function Italix\Testing\{suite, section, test, summary};

suite('Italix Documents — PdfMerger');

$driver = new PdfMerger();

if (!$driver->is_available()) {
    echo "  SKIPPED — pdfunite (poppler-utils) is absent from PATH.\n";
    exit(summary());
}

$two_page_pdf = (new HtmlToPdfConverter())->convert(
    '<div>PAGE ONE</div><div style="page-break-before: always">PAGE TWO</div>',
    'html',
    'pdf',
    ConversionOptions::none()
)->bytes();

// -----------------------------------------------------------------------------
section('the driver describes itself honestly');

test('pairs() is exactly pdf -> pdf', $driver->pairs() === [['pdf', 'pdf']]);
test('is_available() is true — pdfunite is on PATH', $driver->is_available());
test('describe() names the real mechanism', str_contains($driver->describe(), 'pdfunite'));

// -----------------------------------------------------------------------------
section('merge() produces a real, correctly-paginated PDF — checked with pdfinfo, not just non-empty bytes');

$pages   = (new PdfPageSplitter())->extract($two_page_pdf, 'pdf', null, ConversionOptions::none());
$rejoined = $driver->merge([$pages[0]->bytes(), $pages[1]->bytes()], 'pdf', 'pdf', ConversionOptions::none());

test('the extension is pdf', $rejoined->extension_code() === 'pdf');
test('the mime type says so too', $rejoined->mime() === 'application/pdf');
test('the bytes really are a PDF', str_starts_with($rejoined->bytes(), '%PDF'));

$tmp = tempnam(sys_get_temp_dir(), 'ix-merge-check-');
file_put_contents($tmp, $rejoined->bytes());
$info = (string) shell_exec('pdfinfo ' . escapeshellarg($tmp) . ' 2>/dev/null');
@unlink($tmp);
test('rejoining two single-page PDFs produces a genuine 2-page document', preg_match('/^Pages:\s+2$/m', $info) === 1);

// -----------------------------------------------------------------------------
section('merging three real, independently-built PDFs — not just re-splitting one document');

$a = (new HtmlToPdfConverter())->convert('<div>A</div>', 'html', 'pdf', ConversionOptions::none())->bytes();
$b = (new HtmlToPdfConverter())->convert('<div>B</div>', 'html', 'pdf', ConversionOptions::none())->bytes();
$c = (new HtmlToPdfConverter())->convert('<div>C</div>', 'html', 'pdf', ConversionOptions::none())->bytes();

$merged_three = $driver->merge([$a, $b, $c], 'pdf', 'pdf', ConversionOptions::none());
$tmp3 = tempnam(sys_get_temp_dir(), 'ix-merge-check-');
file_put_contents($tmp3, $merged_three->bytes());
$info3 = (string) shell_exec('pdfinfo ' . escapeshellarg($tmp3) . ' 2>/dev/null');
@unlink($tmp3);
test('three independent one-page PDFs merge into a genuine 3-page document', preg_match('/^Pages:\s+3$/m', $info3) === 1);

// -----------------------------------------------------------------------------
section('fewer than two sources is refused rather than silently returning the single input untouched');

$threw = false;
try {
    $driver->merge([$a], 'pdf', 'pdf', ConversionOptions::none());
} catch (ConversionException $e) {
    $threw = true;
    test('the message explains why', str_contains($e->getMessage(), 'at least two'));
}
test('a single source throws', $threw);

exit(summary());
