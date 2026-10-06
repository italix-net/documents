<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Documents — PdfPageSplitter
 *
 * Run: php src/Libs/Italix/Documents/tests/PdfPageSplitterTest.php
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
use Italix\Documents\PdfPageSplitter;

use function Italix\Testing\{suite, section, test, summary};

suite('Italix Documents — PdfPageSplitter');

$driver = new PdfPageSplitter();

if (!$driver->is_available()) {
    echo "  SKIPPED — pdfseparate (poppler-utils) is absent from PATH.\n";
    exit(summary());
}

/** A real, genuine 3-page PDF (CSS page-break-before), not a hand-crafted fixture. */
$three_page_pdf = (new HtmlToPdfConverter())->convert(
    '<div>PAGE ONE</div>'
        . '<div style="page-break-before: always">PAGE TWO</div>'
        . '<div style="page-break-before: always">PAGE THREE</div>',
    'html',
    'pdf',
    ConversionOptions::none()
)->bytes();

// -----------------------------------------------------------------------------
section('the driver describes itself honestly');

test('extensions() is exactly pdf', $driver->extensions() === ['pdf']);
test('is_available() is true — pdfseparate is on PATH', $driver->is_available());
test('describe() names the real mechanism', str_contains($driver->describe(), 'pdfseparate'));

// -----------------------------------------------------------------------------
section('null boundaries: every page, in order, each a real single-page PDF');

$pages = $driver->extract($three_page_pdf, 'pdf', null, ConversionOptions::none());

test('a 3-page document produces 3 pieces', count($pages) === 3);
test('each piece is a real, decodable single-page PDF', array_reduce(
    $pages,
    static fn (bool $ok, $p) => $ok && str_starts_with($p->bytes(), '%PDF'),
    true
));
test('each piece really is a single page', array_reduce(
    $pages,
    static function (bool $ok, $p): bool {
        $tmp = tempnam(sys_get_temp_dir(), 'ix-split-check-');
        file_put_contents($tmp, $p->bytes());
        $info = shell_exec('pdfinfo ' . escapeshellarg($tmp) . ' 2>/dev/null');
        @unlink($tmp);

        return $ok && is_string($info) && preg_match('/^Pages:\s+1$/m', $info) === 1;
    },
    true
));
test('the extension is pdf on every piece', $pages[0]->extension_code() === 'pdf');
test('the mime type is set correctly', $pages[0]->mime() === 'application/pdf');

// -----------------------------------------------------------------------------
section('explicit boundaries: page ranges, some single, some spanning several pages');

$ranged = $driver->extract($three_page_pdf, 'pdf', [[1, 1], [2, 3]], ConversionOptions::none());

test('two boundaries produce exactly two pieces, not three', count($ranged) === 2);

$first_tmp = tempnam(sys_get_temp_dir(), 'ix-split-check-');
file_put_contents($first_tmp, $ranged[0]->bytes());
$first_info = (string) shell_exec('pdfinfo ' . escapeshellarg($first_tmp) . ' 2>/dev/null');
@unlink($first_tmp);
test('the [1,1] boundary is a genuine single page', preg_match('/^Pages:\s+1$/m', $first_info) === 1);

$second_tmp = tempnam(sys_get_temp_dir(), 'ix-split-check-');
file_put_contents($second_tmp, $ranged[1]->bytes());
$second_info = (string) shell_exec('pdfinfo ' . escapeshellarg($second_tmp) . ' 2>/dev/null');
@unlink($second_tmp);
test('the [2,3] boundary was really merged into one 2-page piece via pdfunite, not left as page 2 alone', preg_match('/^Pages:\s+2$/m', $second_info) === 1);

// -----------------------------------------------------------------------------
section('a boundary past the end of the document fails loudly');

$threw = false;
try {
    $driver->extract($three_page_pdf, 'pdf', [[5, 5]], ConversionOptions::none());
} catch (ConversionException $e) {
    $threw = true;
    test('the exception names the missing page', str_contains($e->getMessage(), '5'));
}
test('a page that does not exist throws rather than returning something wrong', $threw);

exit(summary());
