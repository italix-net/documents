<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Documents — DoclingConverter's options reach the command line
 *
 * No Docling here: a stand-in executable, written by this test, answers
 * the same command line and writes the arguments it was given as the
 * "Markdown" — so what DoclingConverter reads from ConversionOptions is
 * checked where it ends up, on every host, in milliseconds. What Docling
 * itself makes of a real PDF is DoclingConverterTest's job, and needs the
 * real thing (IX_DOCLING_BINARY).
 *
 * Run: php src/Libs/Italix/Documents/tests/DoclingOptionsTest.php
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
use Italix\Documents\DoclingConverter;

use function Italix\Testing\{suite, section, test, summary};

suite('Italix Documents — DoclingConverter options, through a stand-in executable');

// The stand-in: `docling convert [flags] --to md --output DIR INPUT` -> DIR/<INPUT's name>.md holding its own arguments.
$stand_in = sys_get_temp_dir() . '/ix-docling-stand-in-' . bin2hex(random_bytes(6));
file_put_contents($stand_in, <<<'SH'
#!/bin/sh
out=""
prev=""
for arg in "$@"; do
    [ "$prev" = "--output" ] && out="$arg"
    prev="$arg"
    input="$arg"
done
name=$(basename "$input")
printf '%s\n' "$*" > "$out/${name%.*}.md"
SH);
chmod($stand_in, 0700);

$driver = new DoclingConverter($stand_in);

/** The command line DoclingConverter ran, as the stand-in recorded it. */
function docling_arguments(DoclingConverter $driver, ConversionOptions $options): string
{
    return $driver->convert('%PDF-1.4 stand-in', 'pdf', 'md', $options)->bytes();
}

try {
    // -------------------------------------------------------------------------
    section('the defaults: OCR on, Docling\'s own image mode');

    $plain = docling_arguments($driver, ConversionOptions::none());
    test('the stand-in answered: convert ... --to md --output', str_starts_with($plain, 'convert ') && str_contains($plain, '--to md --output'));
    test('no option: no --no-ocr, no --image-export-mode', !str_contains($plain, '--no-ocr') && !str_contains($plain, '--image-export-mode'));

    // -------------------------------------------------------------------------
    section('unscoped options');

    $unscoped = docling_arguments($driver, ConversionOptions::none()
        ->with(DoclingConverter::OPTION_OCR, false)
        ->with(DoclingConverter::OPTION_IMAGE_MODE, 'placeholder'));
    test('OPTION_OCR false: --no-ocr', str_contains($unscoped, '--no-ocr'));
    test("OPTION_IMAGE_MODE 'placeholder': --image-export-mode placeholder", str_contains($unscoped, '--image-export-mode placeholder'));

    // -------------------------------------------------------------------------
    section('driver-scoped: set for this driver it is read, set for another it is not');

    $scoped = docling_arguments($driver, ConversionOptions::none()
        ->with(DoclingConverter::OPTION_OCR, false, DoclingConverter::class)
        ->with(DoclingConverter::OPTION_IMAGE_MODE, 'placeholder', DoclingConverter::class));
    test('scoped to DoclingConverter: --no-ocr', str_contains($scoped, '--no-ocr'));
    test('...and --image-export-mode placeholder', str_contains($scoped, '--image-export-mode placeholder'));

    $elsewhere = docling_arguments($driver, ConversionOptions::none()
        ->with(DoclingConverter::OPTION_OCR, false, 'Some\\OtherDriver')
        ->with(DoclingConverter::OPTION_IMAGE_MODE, 'placeholder', 'Some\\OtherDriver'));
    test('scoped to another driver: neither reaches the command line', !str_contains($elsewhere, '--no-ocr') && !str_contains($elsewhere, '--image-export-mode'));

    $over = docling_arguments($driver, ConversionOptions::none()
        ->with(DoclingConverter::OPTION_IMAGE_MODE, 'embedded')
        ->with(DoclingConverter::OPTION_IMAGE_MODE, 'placeholder', DoclingConverter::class));
    test('a scoped value wins over the unscoped one, for this driver', str_contains($over, '--image-export-mode placeholder'));
} finally {
    @unlink($stand_in);
}

exit(summary());
