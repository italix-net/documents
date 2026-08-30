<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Documents — HtmlToPdfConverter, and the full md -> html -> pdf chain
 *
 * The second half of this file is the actual milestone: two drivers from
 * this library, registered into one ConverterSet, routing a real multi-hop
 * conversion neither driver could do alone. Everything up to here has only
 * ever exercised one hop at a time.
 *
 * convert()'s ConversionException wrapping is not exercised here: dompdf
 * turned out to be forgiving of the malformed input this suite tried
 * (broken inline CSS renders as if the declaration were absent) and
 * pathologically slow rather than exception-throwing on deeply nested
 * markup, so there is no fast, reliable way to force it to fail. Recorded
 * honestly rather than asserted against a case that does not actually
 * exercise the catch branch.
 *
 * Run: php src/Libs/Italix/Documents/tests/HtmlToPdfConverterTest.php
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

use Italix\Converters\ConversionOptions;
use Italix\Converters\ConverterSet;
use Italix\Converters\UnsupportedOptionException;
use Italix\Documents\HtmlToPdfConverter;
use Italix\Documents\MarkdownToHtmlConverter;

use function Italix\Testing\{suite, section, test, summary};

suite('Italix Documents — HtmlToPdfConverter');

if (!class_exists(\Dompdf\Dompdf::class)) {
    echo "  SKIPPED — dompdf/dompdf is absent.\n";
    exit(summary());
}

$driver = new HtmlToPdfConverter();

// -----------------------------------------------------------------------------
section('the driver describes itself honestly');

test('pairs() covers both html and htm, both targeting pdf', $driver->pairs() === [
    ['html', 'pdf'],
    ['htm', 'pdf'],
]);
test('is_available() is true — dompdf/dompdf is installed in this app', $driver->is_available());
test('describe() names both ends of the conversion', str_contains($driver->describe(), 'html') && str_contains($driver->describe(), 'pdf'));

// -----------------------------------------------------------------------------
section('convert() produces a real PDF, not a stub');

$result = $driver->convert('<h1>Hello</h1><p>A paragraph.</p>', 'html', 'pdf', ConversionOptions::none());

test('the extension is pdf', $result->extension_code() === 'pdf');
test('the mime type says so too', $result->mime() === 'application/pdf');
test('the bytes are an actual PDF, not a placeholder', str_starts_with($result->bytes(), '%PDF-'));
test('...with real content, not an empty shell', strlen($result->bytes()) > 500);

// -----------------------------------------------------------------------------
section('dompdf is RGB only — an explicit CMYK request is refused, not silently downgraded to RGB');

$rgb_explicit = $driver->convert(
    '<p>fine</p>',
    'html',
    'pdf',
    ConversionOptions::none()->with_colorspace(ConversionOptions::COLORSPACE_RGB)
);
test('explicitly requesting rgb (what this driver already produces) still succeeds', str_starts_with($rgb_explicit->bytes(), '%PDF-'));

$cmyk_threw = false;
try {
    $driver->convert('<p>irrelevant</p>', 'html', 'pdf', ConversionOptions::none()->with_colorspace(ConversionOptions::COLORSPACE_CMYK));
} catch (UnsupportedOptionException $e) {
    $cmyk_threw = true;
    test('the exception names the colorspace key', $e->option_key_code() === ConversionOptions::COLORSPACE);
    test('...and the value that was rejected', $e->option_value() === ConversionOptions::COLORSPACE_CMYK);
}
test('requesting cmyk throws UnsupportedOptionException rather than returning an RGB PDF anyway', $cmyk_threw);

// -----------------------------------------------------------------------------
section('registered alone in a ConverterSet');

$pdf_only = new ConverterSet([$driver]);
$route    = $pdf_only->route('html', 'pdf');

test('html -> pdf routes directly, one hop', count($route) === 1);
test('...naming this exact instance', $route[0][2] === $driver);

// -----------------------------------------------------------------------------
section('the actual milestone: md -> html -> pdf through two drivers that have never heard of each other');

$md_to_html = new MarkdownToHtmlConverter();
$html_to_pdf = $driver;
$pipeline    = new ConverterSet([$md_to_html, $html_to_pdf]);

$route = $pipeline->route('md', 'pdf');
test('no direct md -> pdf driver exists, but a two-hop route is found', count($route) === 2);
test('...specifically: first hop is md -> html', [$route[0][0], $route[0][1]] === ['md', 'html']);
test('...and second hop is html -> pdf', [$route[1][0], $route[1][1]] === ['html', 'pdf']);

$final = $pipeline->convert("# A real document\n\nWith a **bold** claim and a paragraph.", 'md', 'pdf');

test('the end-to-end conversion is a real PDF', str_starts_with($final->bytes(), '%PDF-'));
test('the reported format is the target, not an intermediate hop', $final->extension_code() === 'pdf');
test('the intermediate HTML never leaks out — only the final PDF bytes do', !str_contains($final->bytes(), '<h1>'));

exit(summary());
