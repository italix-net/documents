<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Documents — DocumentImporter and PdfToTextConverter; SingleFileImporter::import_page()
 *
 * A PDF taken from
 * the web kept as an original that packs with its site's pages, its text
 * through pdftotext; what happens without a converter, with one that fails,
 * importing again, two URLs with one name; and a page saved by something
 * other than SingleFile, given a manifest. PDFs are made here, with dompdf.
 *
 * Run: php src/Libs/Italix/Documents/tests/DocumentImporterTest.php
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
use Italix\Converters\ConvertedDocument;
use Italix\Converters\Converter;
use Italix\Documents\DocumentImporter;
use Italix\Documents\HtmlToMarkdownConverter;
use Italix\Documents\HtmlToPdfConverter;
use Italix\Documents\Library;
use Italix\Documents\PdfToTextConverter;
use Italix\Documents\RevisionArchive;
use Italix\Documents\SavedPage;
use Italix\Documents\SingleFileImporter;
use Italix\Storage\LocalStore;

use function Italix\Testing\{suite, section, test, summary};

suite('Italix Documents — DocumentImporter, PdfToTextConverter, import_page()');

$pdftotext = new PdfToTextConverter();
$html2pdf  = new HtmlToPdfConverter();
if (!extension_loaded('pdo_sqlite') || !extension_loaded('dom') || !extension_loaded('gd') || !$pdftotext->is_available() || !$html2pdf->is_available() || !RevisionArchive::is_available()) {
    echo "  SKIPPED — needs pdo_sqlite, ext-dom, ext-gd, pdftotext (poppler-utils), dompdf, and the tar and zstd commands.\n";
    exit(summary());
}

final class FailingText implements Converter
{
    public function pairs(): array { return [['pdf', 'txt']]; }
    public function is_available(): bool { return true; }
    public function describe(): string { return 'always fails'; }
    public function convert(string $source, string $from_extension_c, string $to_extension_c, ConversionOptions $options): ConvertedDocument
    {
        throw new ConversionException('the converter broke');
    }
}

$pdf = static fn (string $text): string => $html2pdf->convert("<html><body><h1>{$text}</h1><p>Ricavi 2025: 1.234 euro_netti, *crescita*.</p></body></html>", 'html', 'pdf', ConversionOptions::none())->bytes();

// -----------------------------------------------------------------------------
section('PdfToTextConverter');

test('pdf -> txt, available where poppler is', $pdftotext->pairs() === [['pdf', 'txt']] && $pdftotext->is_available());
$text = $pdftotext->convert($pdf('Relazione annuale'), 'pdf', 'txt', ConversionOptions::none());
test('the PDF\'s text, as UTF-8 plain text', $text->extension_code() === 'txt' && str_contains($text->bytes(), 'Relazione annuale') && str_contains($text->bytes(), 'Ricavi 2025'));
$refused = false;
try {
    $pdftotext->convert('not a PDF at all', 'pdf', 'txt', ConversionOptions::none());
} catch (ConversionException $e) {
    $refused = str_contains($e->getMessage(), 'pdftotext failed');
}
test('bytes that are not a PDF: ConversionException, saying so', $refused);
test('a binary that is not there: not available', !(new PdfToTextConverter('no-such-pdftotext'))->is_available());

$base = sys_get_temp_dir() . '/ix-documents-docimport-' . bin2hex(random_bytes(6));

try {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $store   = new LocalStore($base . '/store', Library::suggested_path_strategy(), Library::suggested_catalog());
    $library = new Library($pdo, $store);
    $library->install();
    $tree_id = $library->create_tree('web', 'Web');

    $importer = new DocumentImporter($library, $pdftotext);
    $report_pdf = $pdf('Relazione annuale');

    // -------------------------------------------------------------------------
    section('a PDF from the web');

    $r = $importer->import($tree_id, 'https://www.Ex.com/files/Relazione%20annuale.pdf?x=1', '2026-09-29T10:00:00Z', $report_pdf, 'PDF', ['author_c' => 'crawl']);
    test('the original under originals/<host>/<month>/, its name from the URL, the extension kept', $r['original_path_c'] === 'originals/www.ex.com/2026-09/Relazione-annuale.pdf');
    test('...byte for byte, with a manifest recording the URL and time', $library->read_version($r['original']->id()) === $report_pdf
        && (json_decode((string) $library->read_asset($r['original']->id(), 'manifest.json'), true)['originalUrl'] ?? '') === 'https://www.Ex.com/files/Relazione%20annuale.pdf?x=1');
    test('the text beside it, the extension kept in its name', $r['text_path_c'] === 'texts/www.ex.com/2026-09/Relazione-annuale.pdf.md' && $r['text_error'] === '');
    $text_md = (string) $library->read_version($r['text']->id());
    test('...a header — name, URL, time, the original — and the text, escaped where Markdown would read it',
        str_starts_with($text_md, "# Relazione-annuale.pdf\n\nSaved from <https://www.Ex.com/files/Relazione%20annuale.pdf?x=1> on 2026-09-29T10:00:00Z. Original: `originals/www.ex.com/2026-09/Relazione-annuale.pdf`.\n\n")
        && str_contains($text_md, 'Relazione annuale') && str_contains($text_md, 'euro\_netti') && str_contains($text_md, '\*crescita\*'));
    test('...made from the original: source_version_id, and the author', $r['text']->source_version_id() === $r['original']->id() && $r['text']->author() === 'crawl');

    $again = $importer->import($tree_id, 'https://www.Ex.com/files/Relazione%20annuale.pdf?x=1', '2026-09-29T10:00:00Z', $report_pdf, 'pdf');
    test('the same document again: the same two versions', $again['original']->id() === $r['original']->id() && $again['text']->id() === $r['text']->id());

    $other = $importer->import($tree_id, 'https://www.ex.com/other/Relazione-annuale.pdf', '2026-09-30T10:00:00Z', $pdf('Un\'altra relazione'), 'pdf');
    test('another URL with the same name in the same month: -2, never a version of the first', $other['original_path_c'] === 'originals/www.ex.com/2026-09/Relazione-annuale-2.pdf' && count($library->history($r['original']->node_id())) === 1);

    $bare = $importer->import($tree_id, 'https://www.ex.com/files/', '2026-09-29T10:00:00Z', $report_pdf, 'pdf');
    test('a URL with no file name: the last segment, as for a page', $bare['original_path_c'] === 'originals/www.ex.com/2026-09/files.pdf');

    // -------------------------------------------------------------------------
    section('no text: the original alone, and why');

    $none = (new DocumentImporter($library))->import($tree_id, 'https://www.ex.com/a.pdf', '2026-09-29T10:00:00Z', $report_pdf, 'pdf');
    test('no converter', $none['text'] === null && $none['text_path_c'] === null && $none['text_error'] === 'no converter' && $library->node($tree_id, 'texts/www.ex.com/2026-09/a.pdf.md') === null);
    $docx_path = tempnam(sys_get_temp_dir(), 'ixdocx');
    $zip = new ZipArchive();
    $zip->open($docx_path, ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
    $zip->addFromString('word/document.xml', '<w:document/>');
    $zip->close();
    $docx = $importer->import($tree_id, 'https://www.ex.com/b.docx', '2026-09-29T10:00:00Z', (string) file_get_contents($docx_path), 'docx');
    unlink($docx_path);
    test('a converter that does not take the type', $docx['text'] === null && $docx['text_error'] === 'no converter for .docx');
    $broken = (new DocumentImporter($library, new FailingText()))->import($tree_id, 'https://www.ex.com/c.pdf', '2026-09-29T10:00:00Z', $report_pdf, 'pdf');
    test('a converter that fails: its message, and the original kept', $broken['text'] === null && $broken['text_error'] === 'the converter broke' && $library->node($tree_id, 'originals/www.ex.com/2026-09/c.pdf') !== null);

    $refused = false;
    try {
        $importer->import($tree_id, 'https://www.ex.com/old.ppt', '2026-09-29T10:00:00Z', "\xD0\xCF\x11\xE0 an old PowerPoint", 'ppt');
    } catch (\Italix\Storage\StorageException $e) {
        $refused = true;
    }
    test('a type the store refuses: refused, and no empty document left behind', $refused && $library->node($tree_id, 'originals/www.ex.com/2026-09/old.ppt') === null);

    // -------------------------------------------------------------------------
    section('a page saved by something other than SingleFile');

    $pages = new SingleFileImporter($library, new HtmlToMarkdownConverter());
    $saved = new SavedPage('https://www.ex.com/files/relazione-annuale', 'Relazione', '2026-09-29T10:00:00Z', '<html><body><article><p>La pagina.</p><img src="images/a.png" alt="A"></article></body></html>', ['images/a.png' => (static function (): string {
        $im = imagecreatetruecolor(4, 4);
        ob_start();
        imagepng($im);

        return (string) ob_get_clean();
    })()]);
    $page  = $pages->import_page($tree_id, $saved);
    test('imported: the same places as a SingleFile page', $page['original_path_c'] === 'originals/www.ex.com/2026-09/relazione-annuale.html' && $page['text_path_c'] === 'texts/www.ex.com/2026-09/relazione-annuale.md');
    $manifest = json_decode((string) $library->read_asset($page['original']->id(), 'manifest.json'), true);
    test('...given the manifest it did not have: URL, title, time, index', $manifest === ['originalUrl' => 'https://www.ex.com/files/relazione-annuale', 'title' => 'Relazione', 'archiveTime' => '2026-09-29T10:00:00Z', 'indexFilename' => 'index.html']);
    test('...its references pointed into its folder', str_contains((string) $library->read_version($page['original']->id()), 'src="relazione-annuale.html.assets/images/a.png"'));
    test('a page and a document of one name do not meet: relazione-annuale.md and Relazione-annuale.pdf.md', $library->node($tree_id, 'texts/www.ex.com/2026-09/relazione-annuale.md') !== null && $library->node($tree_id, 'texts/www.ex.com/2026-09/Relazione-annuale.pdf.md') !== null);
    $twin = $pages->import_page($tree_id, new SavedPage('https://www.ex.com/elsewhere/relazione-annuale', 'Twin', '2026-09-29T11:00:00Z', '<p>x</p>', []));
    test('the generated manifest is what tells two URLs apart: -2', $twin['original_path_c'] === 'originals/www.ex.com/2026-09/relazione-annuale-2.html');
    test('read_page() of something that is not a SingleFile page: refused', !SingleFileImporter::is_singlefile('<html><body>x</body></html>'));

    // -------------------------------------------------------------------------
    section('the month folder packed cold: pages and documents together');

    $s = $library->pack_folder($tree_id, 'originals/www.ex.com/2026-09');
    test('every original of the month, documents and pages alike, in one archive', $s['documents_n'] >= 7 && $library->version($r['original']->id())->is_archived() && $library->version($page['original']->id())->is_archived());
    test('...and still read back', $library->read_version($r['original']->id()) === $report_pdf);
} finally {
    RevisionArchive::remove_tree($base);
}

test('the temporary directories were removed', !is_dir($base));

exit(summary());
