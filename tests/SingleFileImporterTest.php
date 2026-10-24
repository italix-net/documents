<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Documents — SingleFileImporter: a saved web page, the text alive and the original cold
 *
 * Recognised by content, placed by its manifest, the images re-encoded when
 * smaller and every reference rewritten to match, the text's header and its
 * source, importing again, two URLs with one slug, and the original checked
 * out with every reference resolving. The pages are built here, as SingleFile
 * writes them: a ZIP whose offsets count from the start of the whole file.
 *
 * Run: php src/Libs/Italix/Documents/tests/SingleFileImporterTest.php
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
use Italix\Documents\DocumentsException;
use Italix\Documents\HtmlToMarkdownConverter;
use Italix\Documents\Library;
use Italix\Documents\RevisionArchive;
use Italix\Documents\SingleFileImporter;
use Italix\Storage\LocalStore;

use function Italix\Testing\{suite, section, test, summary};

suite('Italix Documents — SingleFileImporter');

if (!extension_loaded('pdo_sqlite') || !extension_loaded('dom') || !extension_loaded('gd') || !function_exists('imagewebp') || !class_exists(ZipArchive::class)) {
    echo "  SKIPPED — needs pdo_sqlite, ext-dom, ext-gd with WebP, and ext-zip.\n";
    exit(summary());
}

/** To WebP with GD — italix/media's GdImageConverter's job, done here so this test needs no other library. */
final class WebpStandIn implements Converter
{
    public int $calls_n = 0;

    /** @param callable(string, string): ?string|null $override bytes to return instead, or null to encode */
    public function __construct(private $override = null)
    {
    }

    public function pairs(): array { return [['png', 'webp'], ['jpg', 'webp'], ['jpeg', 'webp']]; }
    public function is_available(): bool { return true; }
    public function describe(): string { return 'png/jpg -> webp (test stand-in)'; }

    public function convert(string $source, string $from_extension_c, string $to_extension_c, ConversionOptions $options): ConvertedDocument
    {
        $this->calls_n++;
        if ($this->override !== null && ($bytes = ($this->override)($source, $from_extension_c)) !== null) {
            return new ConvertedDocument($bytes, 'webp', 'image/webp');
        }
        $image = imagecreatefromstring($source);
        if ($image === false) {
            throw new ConversionException('not an image');
        }
        imagepalettetotruecolor($image);
        ob_start();
        imagewebp($image, null, $options->quality(90));

        return new ConvertedDocument((string) ob_get_clean(), 'webp', 'image/webp');
    }
}

/** A noisy PNG: large as a PNG, far smaller as a WebP. */
function sf_png(int $seed): string
{
    mt_srand($seed);
    $image = imagecreatetruecolor(120, 80);
    for ($x = 0; $x < 120; $x += 2) {
        for ($y = 0; $y < 80; $y += 2) {
            imagefilledrectangle($image, $x, $y, $x + 1, $y + 1, mt_rand(0, 0xFFFFFF));
        }
    }
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

function sf_jpeg(): string
{
    $image = imagecreatetruecolor(16, 16);
    ob_start();
    imagejpeg($image, null, 50);

    return (string) ob_get_clean();
}

/**
 * A page as SingleFile saves it: an HTML prefix, then a ZIP whose offsets are shifted by that prefix's
 * length (they count from the start of the whole file), then the closing comment and extra data.
 *
 * @param array<string, string> $entries
 */
function sf_page(array $entries, array $manifest): string
{
    $path = tempnam(sys_get_temp_dir(), 'ixsf');
    $zip  = new ZipArchive();
    $zip->open($path, ZipArchive::OVERWRITE);
    $zip->addFromString('manifest.json', (string) json_encode($manifest + ['indexFilename' => 'index.html']));
    foreach ($entries as $name => $bytes) {
        $zip->addFromString($name, $bytes);
    }
    $zip->close();
    $bytes = (string) file_get_contents($path);
    unlink($path);

    $prefix = "<!DOCTYPE html> <html data-sfz><meta charset=windows-1252><script>/* loader */</script><!--";
    $by     = strlen($prefix);
    $eocd   = (int) strrpos($bytes, "PK\x05\x06");
    $n      = unpack('v', substr($bytes, $eocd + 10, 2))[1];
    $cd     = unpack('V', substr($bytes, $eocd + 16, 4))[1];
    for ($i = 0, $at = $cd; $i < $n; $i++) {
        $lengths = unpack('vname/vextra/vcomment', substr($bytes, $at + 28, 6));
        $bytes   = substr_replace($bytes, pack('V', unpack('V', substr($bytes, $at + 42, 4))[1] + $by), $at + 42, 4);
        $at     += 46 + $lengths['name'] + $lengths['extra'] + $lengths['comment'];
    }
    $bytes = substr_replace($bytes, pack('V', $cd + $by), $eocd + 16, 4);

    return $prefix . $bytes . '--><sfz-extra-data>AAAA</sfz-extra-data>';
}

function sf_throws(callable $fn, string $needle = ''): bool
{
    try {
        $fn();
    } catch (DocumentsException $e) {
        return $needle === '' || str_contains($e->getMessage(), $needle);
    }

    return false;
}

$index = <<<'HTML'
<!DOCTYPE html><html><head><meta charset="utf-8"><title>Pumps</title>
<link rel="stylesheet" href="stylesheet_0.css"></head>
<body><nav>Home · Blog · Sign in</nav>
<article><h1>Choosing a pump</h1><p>Top highlight</p>
<p>A pump moves water. See <a href="https://example.org/more">more</a>.</p>
<figure><img src="images/1.png" alt="The pump"><figcaption>The pump</figcaption></figure>
<img src="images/10.png" alt="Ten"><img src=images/2.jpg alt="Small">
<p style="background:url(images/1.png)">Styled.</p>
</article><footer>Copyright</footer></body></html>
HTML;
$css     = "@font-face{font-family:x;src:url(fonts/0.woff2)}\n.hero{background:url('images/10.png')}\n";
$entries = [
    'index.html'       => $index,
    'stylesheet_0.css' => $css,
    'fonts/0.woff2'    => "wOF2\x00\x01\x00\x00" . str_repeat("\x00", 200),
    'images/1.png'     => sf_png(1),
    'images/10.png'    => sf_png(10),
    'images/2.jpg'     => sf_jpeg(),
];
$manifest = ['originalUrl' => 'https://Mirror.Example.COM/https://blog.example.org/choosing-a-pump-1a2b/?utm_source=x', 'title' => 'Choosing a pump - Blog', 'archiveTime' => '2026-07-31T23:30:00.000-02:00'];

$base = sys_get_temp_dir() . '/ix-documents-singlefile-' . bin2hex(random_bytes(6));

try {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $store   = new LocalStore($base . '/store', Library::suggested_path_strategy(), Library::suggested_catalog());
    $library = new Library($pdo, $store);
    $library->install();
    $tree_id = $library->create_tree('web', 'Web');

    // A JPEG this small grows as a WebP: the stand-in makes it grow for certain.
    $images   = new WebpStandIn(static fn (string $source, string $from): ?string => $from === 'jpg' ? str_repeat('x', strlen($source) + 1) : null);
    $importer = new SingleFileImporter($library, new HtmlToMarkdownConverter(), $images);
    $page     = sf_page($entries, $manifest);

    // -------------------------------------------------------------------------
    section('recognised by content');

    test('a SingleFile page is one', SingleFileImporter::is_singlefile($page));
    test('a plain HTML page is not', !SingleFileImporter::is_singlefile('<!DOCTYPE html><html><body>Hi</body></html>'));
    $plain_zip_path = tempnam(sys_get_temp_dir(), 'ixz');
    $z = new ZipArchive();
    $z->open($plain_zip_path, ZipArchive::OVERWRITE);
    $z->addFromString('index.html', '<p>x</p>');
    $z->addFromString('manifest.json', (string) json_encode($manifest + ['indexFilename' => 'index.html']));
    $z->close();
    test('a plain ZIP is not, even with a manifest and an index: no marker', !SingleFileImporter::is_singlefile((string) file_get_contents($plain_zip_path)));
    unlink($plain_zip_path);
    test('the marker without a ZIP is not', sf_throws(fn () => $importer->import($tree_id, '<html data-sfz><body>no zip</body></html>'), 'no ZIP'));
    test('a manifest without a URL is refused, saying so', sf_throws(fn () => $importer->import($tree_id, sf_page(['index.html' => '<p>x</p>'], ['archiveTime' => '2026-01-01T00:00:00Z'])), 'manifest.json'));
    test('a file the tree cannot name is refused, saying which', sf_throws(fn () => $importer->import($tree_id, sf_page(['index.html' => '<p>x</p>', '.hidden' => 'x'], $manifest)), '".hidden"'));

    // -------------------------------------------------------------------------
    section('placed by its manifest');

    $report = $importer->import($tree_id, $page, ['author_c' => 'ada']);
    test('the host lowercased — the mirror\'s own —, the month in UTC (23:30 at -02:00 on 31 July is August)', $report['original_path_c'] === 'originals/mirror.example.com/2026-08/choosing-a-pump-1a2b.html');
    test('the text beside it, under texts/', $report['text_path_c'] === 'texts/mirror.example.com/2026-08/choosing-a-pump-1a2b.md');
    test('the text made from the original: source_version_id', $report['text']->source_version_id() === $report['original']->id() && $report['text']->author() === 'ada');

    // -------------------------------------------------------------------------
    section('the original: faithful to the eye');

    $original = $report['original'];
    $html     = (string) $library->read_version($original->id());
    $assets   = $library->assets($original->id());
    test('two PNGs re-encoded, the JPEG that would grow kept', [$report['reencoded_n'], $report['kept_n']] === [2, 1] && $report['image_bytes_after_n'] < $report['image_bytes_before_n']);
    test('the assets: every file but the page, the PNGs under their new names', array_keys($assets) === ['fonts/0.woff2', 'images/1.webp', 'images/10.webp', 'images/2.jpg', 'manifest.json', 'stylesheet_0.css']);
    test('the page points into its folder, at the new names', str_contains($html, 'src="choosing-a-pump-1a2b.html.assets/images/1.webp"') && str_contains($html, 'src="choosing-a-pump-1a2b.html.assets/images/10.webp"'));
    test('...unquoted, and inside a style attribute too', str_contains($html, 'src=choosing-a-pump-1a2b.html.assets/images/2.jpg') && str_contains($html, 'url(choosing-a-pump-1a2b.html.assets/images/1.webp)'));
    test('...images/1.png never matched inside images/10.png', !str_contains($html, '1.webp0'));
    $new_css = (string) $library->read_asset($original->id(), 'stylesheet_0.css');
    test('the CSS: the renamed image, and the font as it was — side by side in the folder', str_contains($new_css, "url('images/10.webp')") && str_contains($new_css, 'url(fonts/0.woff2)'));
    test('the loader is not kept', !str_contains($html, 'data-sfz') && !str_contains($html, 'loader'));

    $near = $importer->import($tree_id, sf_page([
        'index.html'   => '<article><img src="https://cdn.example.org/images/1.png" alt="cdn"><a href="images/1.png.html">older</a><img src="images/1.png" alt="own"></article>',
        'images/1.png' => sf_png(1),
    ], ['originalUrl' => 'https://a.example/near'] + $manifest));
    $near_html = (string) $library->read_version($near['original']->id());
    test('only whole names are rewritten: a URL ending in one, and a longer name starting with one, left as they were',
        str_contains($near_html, 'src="https://cdn.example.org/images/1.png"') && str_contains($near_html, 'href="images/1.png.html"') && str_contains($near_html, 'src="near.html.assets/images/1.webp"'));

    // -------------------------------------------------------------------------
    section('the text');

    $text = (string) $library->read_version($report['text']->id());
    test('the header: title, URL, time, and the original\'s path', str_starts_with($text, "# Choosing a pump - Blog\n\nSaved from <https://Mirror.Example.COM/https://blog.example.org/choosing-a-pump-1a2b/?utm_source=x> on 2026-07-31T23:30:00.000-02:00. Original: `originals/mirror.example.com/2026-08/choosing-a-pump-1a2b.html`.\n\n"));
    test('the article, not the navigation, the footer or the interface line', str_contains($text, "# Choosing a pump\n\nA pump moves water. See [more](https://example.org/more).") && !str_contains($text, 'Sign in') && !str_contains($text, 'Copyright') && !str_contains($text, 'Top highlight'));

    // -------------------------------------------------------------------------
    section('importing again, and a second URL with the same slug');

    $again = $importer->import($tree_id, $page);
    test('the same file again: the same two versions', $again['original']->id() === $original->id() && $again['text']->id() === $report['text']->id());

    $other = $importer->import($tree_id, sf_page(['index.html' => '<article><p>Another site\'s pump.</p></article>'], ['originalUrl' => 'https://mirror.example.com/https://other.example.net/choosing-a-pump-1a2b', 'title' => 'Other', 'archiveTime' => '2026-08-02T10:00:00Z']));
    test('another URL reducing to the same slug in the same month: -2, never a version of the first', $other['original_path_c'] === 'originals/mirror.example.com/2026-08/choosing-a-pump-1a2b-2.html' && count($library->history($original->node_id())) === 1);

    $recapture = $importer->import($tree_id, sf_page(['index.html' => str_replace('moves water', 'moves water, fast', $index)] + $entries, ['archiveTime' => '2026-08-20T10:00:00Z'] + $manifest));
    test('a new capture of the same URL in the same month: a new version of both', $recapture['original_path_c'] === $report['original_path_c'] && $recapture['original']->id() !== $original->id() && $recapture['text']->source_version_id() === $recapture['original']->id());

    // -------------------------------------------------------------------------
    section('re-encoding: kept whenever it does not pay or fails');

    $failing = new SingleFileImporter($library, new HtmlToMarkdownConverter(), new WebpStandIn(static function (): ?string {
        throw new ConversionException('GD failed');
    }));
    $r = $failing->import($tree_id, sf_page($entries, ['originalUrl' => 'https://a.example/failing'] + $manifest));
    test('a converter that fails: every image kept, the import done', [$r['reencoded_n'], $r['kept_n']] === [0, 3] && isset($library->assets($r['original']->id())['images/1.png']));

    $empty = new SingleFileImporter($library, new HtmlToMarkdownConverter(), new WebpStandIn(static fn (): ?string => ''));
    $r     = $empty->import($tree_id, sf_page($entries, ['originalUrl' => 'https://a.example/empty'] + $manifest));
    test('a converter that returns nothing: kept — an empty file is never smaller', $r['reencoded_n'] === 0 && $library->read_asset($r['original']->id(), 'images/1.png') === $entries['images/1.png']);

    $bare = new SingleFileImporter($library, new HtmlToMarkdownConverter());
    $r    = $bare->import($tree_id, sf_page($entries, ['originalUrl' => 'https://a.example/bare'] + $manifest));
    test('no converter: the images as they were, and the page\'s references to them', $r['reencoded_n'] === 0 && str_contains((string) $library->read_version($r['original']->id()), 'src="bare.html.assets/images/1.png"'));

    // -------------------------------------------------------------------------
    section('the original, taken out and cold');

    $library->pack_folder($tree_id, 'originals/mirror.example.com/2026-08');
    $out   = $base . '/checkout';
    $files = $library->checkout_version($original->id(), $out);
    $page_html = (string) file_get_contents($out . '/' . $files[0]);
    preg_match_all('~(?:src|href)=["\']?([^"\' >]+)|url\(["\']?([^)"\']+)~', $page_html, $m);
    $local  = array_values(array_filter(array_merge($m[1], $m[2]), static fn (string $r): bool => $r !== '' && preg_match('~^(https?:|data:|#)~', $r) !== 1));
    $broken = array_filter($local, static fn (string $r): bool => !is_file($out . '/' . $r));
    test('packed cold, checked out: every reference in the page names a file that exists', $library->version($original->id())->is_archived() && count($local) === 5 && $broken === []);
    $css_out = (string) file_get_contents($out . '/choosing-a-pump-1a2b.html.assets/stylesheet_0.css');
    test('...and in its CSS', is_file($out . '/choosing-a-pump-1a2b.html.assets/images/10.webp') && is_file($out . '/choosing-a-pump-1a2b.html.assets/fonts/0.woff2') && str_contains($css_out, 'images/10.webp'));
} finally {
    RevisionArchive::remove_tree($base);
}

test('the temporary directories were removed', !is_dir($base));

exit(summary());
