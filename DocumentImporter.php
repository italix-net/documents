<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Documents - DocumentImporter
 *
 * @package Italix\Documents
 */

declare(strict_types=1);

namespace Italix\Documents;

use Italix\Converters\ConversionOptions;
use Italix\Converters\Converter;

/**
 * A PDF or an office file taken from the web, as SingleFileImporter takes a
 * page:
 *
 * - **the original** — `originals/<host>/<yyyy-mm>/<name>.<ext>`, a bundle
 *   whose `manifest.json` records its URL and time, so it packs with its
 *   site's pages (`Library::pack_folder()`) and two URLs never share it;
 * - **the text**, when the converter given turns the type into Markdown or
 *   plain text — `texts/<host>/<yyyy-mm>/<name>.<ext>.md`, the extension
 *   kept so a page and a document of one name never meet: a header (name,
 *   URL, time, the original's path), then the text, saved with
 *   `source_version_id` = the original's version.
 *
 * Without a converter for the type, or when it fails, the original alone;
 * the report says why there is no text.
 */
final class DocumentImporter
{
    public function __construct(private Library $library, private ?Converter $to_text = null)
    {
    }

    /**
     * @param  array{author_c?: string, note_c?: string, texts_folder_c?: string, originals_folder_c?: string} $options
     * @return array{original: Version, text: ?Version, original_path_c: string, text_path_c: ?string, text_error: string}
     * @throws DocumentsException no usable host, a time that is not one, a name this tree cannot hold
     */
    public function import(int $tree_id, string $url_c, string $saved_dt, string $bytes, string $extension_c, array $options = []): array
    {
        $extension_c = strtolower($extension_c);
        $folders     = [
            Path::clean((string) ($options['originals_folder_c'] ?? 'originals')),
            Path::clean((string) ($options['texts_folder_c'] ?? 'texts')),
        ];
        $place  = WebPlace::host_of($url_c) . '/' . WebPlace::month_of($saved_dt);
        $slug   = WebPlace::slug_of($url_c);
        $base_c = str_ends_with(strtolower($slug), '.' . $extension_c) ? substr($slug, 0, -strlen($extension_c) - 1) : $slug;
        $base_c = WebPlace::free_name($this->library, $tree_id, $folders[0] . '/' . $place, $base_c !== '' ? $base_c : 'document', '.' . $extension_c, $url_c);
        $name_c = $base_c . '.' . $extension_c;

        $original_path_c = $folders[0] . '/' . $place . '/' . $name_c;
        $note_c          = (string) ($options['note_c'] ?? 'Web: ' . $url_c);
        $author_c        = (string) ($options['author_c'] ?? '');

        $original = $this->library->save($tree_id, $original_path_c, $bytes, [
            'assets'   => ['manifest.json' => WebPlace::manifest($url_c, $name_c, $saved_dt, null)],
            'author_c' => $author_c,
            'note_c'   => $note_c,
        ]);

        [$body, $text_error] = $this->text($bytes, $extension_c);
        if ($body === null) {
            return ['original' => $original, 'text' => null, 'original_path_c' => $original_path_c, 'text_path_c' => null, 'text_error' => $text_error];
        }

        $text_path_c = $folders[1] . '/' . $place . '/' . $name_c . '.md';
        $header      = '# ' . HtmlToMarkdownConverter::escape($name_c) . "\n\n"
            . 'Saved from <' . $url_c . '> on ' . $saved_dt . '. Original: `' . $original_path_c . "`.\n\n";
        $text = $this->library->save($tree_id, $text_path_c, $header . $body, [
            'author_c'          => $author_c,
            'note_c'            => $note_c,
            'source_version_id' => $original->id(),
        ]);

        return ['original' => $original, 'text' => $text, 'original_path_c' => $original_path_c, 'text_path_c' => $text_path_c, 'text_error' => ''];
    }

    /**
     * The document's text as Markdown — the converter's Markdown, or its
     * plain text escaped where Markdown would read it — or null and why not.
     *
     * @return array{0: ?string, 1: string}
     */
    private function text(string $bytes, string $extension_c): array
    {
        if ($this->to_text === null || !$this->to_text->is_available()) {
            return [null, 'no converter'];
        }

        $to_c = in_array([$extension_c, 'md'], $this->to_text->pairs(), true) ? 'md'
            : (in_array([$extension_c, 'txt'], $this->to_text->pairs(), true) ? 'txt' : null);
        if ($to_c === null) {
            return [null, "no converter for .{$extension_c}"];
        }

        try {
            $converted = $this->to_text->convert($bytes, $extension_c, $to_c, ConversionOptions::none())->bytes();
        } catch (\Throwable $e) {
            return [null, $e->getMessage()];
        }

        $body = $to_c === 'md' ? $converted : HtmlToMarkdownConverter::escape($converted);

        return trim($body) === '' ? [null, 'no text in the document'] : [rtrim($body) . "\n", ''];
    }
}
