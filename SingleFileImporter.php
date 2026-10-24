<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Documents - SingleFileImporter
 *
 * @package Italix\Documents
 */

declare(strict_types=1);

namespace Italix\Documents;

use Italix\Converters\ConversionException;
use Italix\Converters\ConversionOptions;
use Italix\Converters\Converter;
use ZipArchive;

/**
 * A web page saved by SingleFile as a self-extracting ZIP (`.u.zip.html`)
 * into two documents of a tree:
 *
 * - **the text** — `texts/<host>/<yyyy-mm>/<slug>.md`: a header (title, URL,
 *   capture time, the original's path) and the page's main content as
 *   Markdown (HtmlToMarkdownConverter), saved with `source_version_id` = the
 *   original's version;
 * - **the original** — `originals/<host>/<yyyy-mm>/<slug>.html`, a bundle:
 *   the page's `index.html`, every other file of the ZIP as an asset, PNG and
 *   JPEG re-encoded as WebP when that is smaller, the references rewritten to
 *   match. Faithful to the eye, not to the byte.
 *
 * Recognised by content, never by name. The host and month come from
 * the page's `manifest.json`. Two different URLs never share a
 * document: the second goes to `<slug>-2`. Importing the same file
 * again writes nothing. Packing the originals cold is the caller's
 * call, `Library::pack_folder()`, per month.
 */
final class SingleFileImporter
{
    public const DEFAULT_QUALITY = 90;

    /** Re-encoded as WebP when smaller. GIF may be animated; WebP, AVIF and SVG are already what they are. */
    private const REENCODED = ['png', 'jpg', 'jpeg'];

    private Library $library;
    private HtmlToMarkdownConverter $markdown;
    private ?Converter $images;

    /**
     * @param Converter|null $images to WebP — italix/media's GdImageConverter; without it the images are kept
     */
    public function __construct(Library $library, HtmlToMarkdownConverter $markdown, ?Converter $images = null)
    {
        $this->library  = $library;
        $this->markdown = $markdown;
        $this->images   = $images;
    }

    /**
     * Whether <bytes> is a SingleFile self-extracting page: the marker near the
     * start, a ZIP that opens, a manifest naming an index the ZIP holds.
     */
    public static function is_singlefile(string $bytes): bool
    {
        try {
            self::read_page($bytes);

            return true;
        } catch (DocumentsException $e) {
            return false;
        }
    }

    /**
     * A SingleFile page: read_page(), then import_page().
     *
     * @param  array{author_c?: string, note_c?: string, quality?: int, texts_folder_c?: string, originals_folder_c?: string} $options
     * @return array{text: Version, original: Version, text_path_c: string, original_path_c: string, url_c: string, reencoded_n: int, kept_n: int, image_bytes_before_n: int, image_bytes_after_n: int}
     * @throws DocumentsException not a SingleFile page, or a page this tree cannot hold
     */
    public function import(int $tree_id, string $bytes, array $options = []): array
    {
        return $this->import_page($tree_id, self::read_page($bytes), $options);
    }

    /**
     * A saved page, whatever saved it: SingleFile, or `italix/crawler`.
     * A page whose files hold no `manifest.json` gets one — URL, title, time
     * and index — so a page is placed the same way whatever made it.
     *
     * @param  array{author_c?: string, note_c?: string, quality?: int, texts_folder_c?: string, originals_folder_c?: string} $options
     * @return array{text: Version, original: Version, text_path_c: string, original_path_c: string, url_c: string, reencoded_n: int, kept_n: int, image_bytes_before_n: int, image_bytes_after_n: int}
     * @throws DocumentsException a page this tree cannot hold
     */
    public function import_page(int $tree_id, SavedPage $page, array $options = []): array
    {
        $url_c   = $page->url();
        $folders = [
            Path::clean((string) ($options['originals_folder_c'] ?? 'originals')),
            Path::clean((string) ($options['texts_folder_c'] ?? 'texts')),
        ];
        $place   = WebPlace::host_of($url_c) . '/' . WebPlace::month_of($page->saved_dt());
        $slug    = WebPlace::free_name($this->library, $tree_id, $folders[0] . '/' . $place, WebPlace::slug_of($url_c), '.html', $url_c);

        $original_path_c = $folders[0] . '/' . $place . '/' . $slug . '.html';
        $text_path_c     = $folders[1] . '/' . $place . '/' . $slug . '.md';

        $index   = $page->html();
        $entries = $page->files();
        $entries['manifest.json'] ??= WebPlace::manifest($url_c, $page->title(), $page->saved_dt(), 'index.html');

        $report  = ['reencoded_n' => 0, 'kept_n' => 0, 'image_bytes_before_n' => 0, 'image_bytes_after_n' => 0];
        $renamed = $this->reencode($entries, (int) ($options['quality'] ?? self::DEFAULT_QUALITY), $report);

        // The page's references into its folder, and to the new names; the CSS's to the new names only.
        $prefix = $slug . '.html.assets/';
        $map    = [];
        foreach (array_keys($page->files()) as $name_c) {
            $map[$name_c] = $prefix . ($renamed[$name_c] ?? $name_c);
        }
        foreach ($entries as $name_c => $content) {
            if (str_ends_with(strtolower($name_c), '.css')) {
                $entries[$name_c] = self::rewrite($content, $renamed);
            }
        }

        $author_c = (string) ($options['author_c'] ?? '');
        $original = $this->library->save($tree_id, $original_path_c, self::rewrite($index, $map), [
            'assets'   => $entries,
            'author_c' => $author_c,
            'note_c'   => (string) ($options['note_c'] ?? 'SingleFile: ' . $url_c),
        ]);

        $header = '# ' . HtmlToMarkdownConverter::escape(trim($page->title()) !== '' ? trim($page->title()) : $slug) . "\n\n"
            . 'Saved from <' . $url_c . '> on ' . $page->saved_dt() . '. Original: `' . $original_path_c . "`.\n\n";
        $text = $this->library->save($tree_id, $text_path_c, $header . $this->markdown->markdown($index), [
            'author_c'          => $author_c,
            'note_c'            => (string) ($options['note_c'] ?? 'SingleFile: ' . $url_c),
            'source_version_id' => $original->id(),
        ]);

        return ['text' => $text, 'original' => $original, 'text_path_c' => $text_path_c, 'original_path_c' => $original_path_c, 'url_c' => $url_c] + $report;
    }

    /**
     * A SingleFile self-extracting page as a SavedPage — its manifest's
     * URL, title and time, its index, and its ZIP's other files by name (its
     * `manifest.json` among them).
     *
     * @throws DocumentsException anything that is not a SingleFile page this importer can read
     */
    public static function read_page(string $bytes): SavedPage
    {
        if (!str_contains(substr($bytes, 0, 4096), 'data-sfz')) {
            throw new DocumentsException('Not a SingleFile self-extracting page: no data-sfz marker.');
        }

        $path = tempnam(sys_get_temp_dir(), 'ix-sfz');
        file_put_contents($path, $bytes);

        try {
            $zip = new ZipArchive();
            if ($zip->open($path, ZipArchive::RDONLY) !== true) {
                throw new DocumentsException('Not a SingleFile self-extracting page: no ZIP inside.');
            }

            $entries = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if (str_ends_with($name, '/')) {
                    continue;
                }
                if (!Path::is_clean($name) || Path::assets_owner_of($name . '/') !== null) {
                    throw new DocumentsException("The page holds a file this tree cannot name: \"{$name}\".");
                }
                $entries[$name] = (string) $zip->getFromIndex($i);
            }
            $zip->close();
        } finally {
            @unlink($path);
        }

        $manifest = json_decode($entries['manifest.json'] ?? '', true);
        $index_c  = is_array($manifest) ? (string) ($manifest['indexFilename'] ?? '') : '';

        if (!is_array($manifest) || $index_c === '' || !isset($entries[$index_c])
            || !is_string($manifest['originalUrl'] ?? null) || !is_string($manifest['archiveTime'] ?? null)) {
            throw new DocumentsException('Not a SingleFile self-extracting page: its manifest.json is missing, or names no index, URL or time.');
        }

        WebPlace::host_of($manifest['originalUrl']);
        WebPlace::month_of($manifest['archiveTime']);

        $html = $entries[$index_c];
        unset($entries[$index_c]);

        return new SavedPage($manifest['originalUrl'], (string) ($manifest['title'] ?? ''), $manifest['archiveTime'], $html, $entries);
    }

    /**
     * PNG and JPEG re-encoded as WebP in place, when smaller and the new name is free.
     *
     * @param  array<string, string> $entries
     * @param  array{reencoded_n: int, kept_n: int, image_bytes_before_n: int, image_bytes_after_n: int} $report
     * @return array<string, string> old name => new name
     */
    private function reencode(array &$entries, int $quality, array &$report): array
    {
        $renamed = [];
        $options = ConversionOptions::none()->with_quality($quality);

        foreach (array_keys($entries) as $name_c) {
            $extension_c = strtolower(pathinfo($name_c, PATHINFO_EXTENSION));
            if (!in_array($extension_c, self::REENCODED, true)) {
                continue;
            }

            $before = $entries[$name_c];
            $report['image_bytes_before_n'] += strlen($before);
            $new_c  = substr($name_c, 0, -strlen($extension_c)) . 'webp';
            $webp   = null;

            if ($this->images !== null && $this->images->is_available() && !isset($entries[$new_c])) {
                try {
                    $webp = $this->images->convert($before, $extension_c, 'webp', $options)->bytes();
                } catch (ConversionException $e) {
                    $webp = null;
                }
            }

            // Kept: no converter, a failure, nothing back, or no smaller.
            if ($webp === null || $webp === '' || strlen($webp) >= strlen($before)) {
                $report['kept_n']++;
                $report['image_bytes_after_n'] += strlen($before);

                continue;
            }

            unset($entries[$name_c]);
            $entries[$new_c]   = $webp;
            $renamed[$name_c]  = $new_c;
            $report['reencoded_n']++;
            $report['image_bytes_after_n'] += strlen($webp);
        }

        return $renamed;
    }

    /**
     * Every reference in <text> to a name of <map> replaced by its target: the name whole, at a reference's
     * boundaries — after a quote, `(`, `=`, `,` or a space, before a quote, `)`, `,`, `?`, `#`, `>` or a
     * space — so `images/1.png` never matches inside `images/10.png`.
     *
     * @param array<string, string> $map
     */
    private static function rewrite(string $text, array $map): string
    {
        if ($map === []) {
            return $text;
        }

        $names = array_keys($map);
        usort($names, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        $pattern = '~(?<=["\'(\s,=])(' . implode('|', array_map(static fn (string $n): string => preg_quote($n, '~'), $names)) . ')(?=["\')\s,?#>])~';

        return (string) preg_replace_callback($pattern, static fn (array $m): string => $map[$m[1]], $text);
    }
}
