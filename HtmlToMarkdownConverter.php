<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Documents - HtmlToMarkdownConverter
 *
 * @package Italix\Documents
 */

declare(strict_types=1);

namespace Italix\Documents;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Italix\Converters\ConversionOptions;
use Italix\Converters\ConvertedDocument;
use Italix\Converters\Converter;

/**
 * A web page's **main content** as Markdown, as an italix/converters driver.
 *
 * - **Where the content is**: the `<article>` with the most text, else
 *   `<main>`, else `<body>`. Scripts, styles, `noscript`, `template`, inline
 *   SVG, buttons and form controls are removed first.
 * - **What it writes**: headings, paragraphs, lists (nested), block quotes,
 *   code blocks and spans, emphasis, rules, simple tables. An absolute link
 *   stays a link, any other becomes its text; an image with an alt becomes
 *   `[image: alt]`; a caption an italic line.
 * - **Interface lines**: a paragraph or heading whose whole text is one of
 *   the drop lines (DEFAULT_DROP_LINES, or the constructor's) is left out.
 *
 * The input is read as UTF-8. Text is escaped only where it would change
 * the Markdown's inline structure (`\ ` * _ [ ] <`).
 */
final class HtmlToMarkdownConverter implements Converter
{
    /** Interface text found inside the articles of the samples the design was measured on. */
    public const DEFAULT_DROP_LINES = [
        'Top highlight',
        'Press enter or click to view image in full size',
        'Member-only story',
        'Listen',
        'Share',
    ];

    private const REMOVED = ['script', 'style', 'noscript', 'template', 'svg', 'button', 'input', 'select', 'textarea', 'iframe'];

    private const BLOCKS = [
        'address', 'article', 'aside', 'blockquote', 'dd', 'details', 'dialog', 'div', 'dl', 'dt', 'fieldset',
        'figcaption', 'figure', 'footer', 'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hgroup', 'hr',
        'li', 'main', 'nav', 'ol', 'p', 'pre', 'section', 'summary', 'table', 'ul',
    ];

    private const BREAK = "\u{E000}";   // a <br> until whitespace has been collapsed

    /** @var array<string, true> */
    private array $drop_lines;

    /**
     * @param list<string>|null $drop_lines paragraphs to leave out, by their whole text; null for DEFAULT_DROP_LINES
     */
    public function __construct(?array $drop_lines = null)
    {
        $this->drop_lines = array_fill_keys($drop_lines ?? self::DEFAULT_DROP_LINES, true);
    }

    public function pairs(): array
    {
        return [['html', 'md'], ['htm', 'md']];
    }

    public function is_available(): bool
    {
        return extension_loaded('dom');
    }

    public function convert(
        string $source,
        string $from_extension_c,
        string $to_extension_c,
        ConversionOptions $options
    ): ConvertedDocument {
        return new ConvertedDocument($this->markdown($source), 'md', 'text/markdown; charset=utf-8');
    }

    public function describe(): string
    {
        return 'html/htm -> md, the main content (Italix\Documents, DOM)';
    }

    /** The Markdown of <html>'s main content, ending with a newline — or an empty string when there is no text. */
    public function markdown(string $html): string
    {
        $doc = new DOMDocument();
        @$doc->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($doc);

        foreach (iterator_to_array($xpath->query('//' . implode('|//', self::REMOVED)) ?: []) as $node) {
            $node->parentNode?->removeChild($node);
        }

        $blocks = $this->blocks($this->main_content($xpath) ?? $doc);

        return $blocks === [] ? '' : implode("\n\n", $blocks) . "\n";
    }

    /** Markdown's inline punctuation escaped, and nothing else. */
    public static function escape(string $text): string
    {
        return (string) preg_replace('~([\\\\`*_\[\]<])~', '\\\\$1', $text);
    }

    private function main_content(DOMXPath $xpath): ?DOMNode
    {
        $best   = null;
        $best_n = -1;
        foreach ($xpath->query('//article') ?: [] as $article) {
            $text_n = mb_strlen(self::collapse($article->textContent));
            if ($text_n > $best_n) {
                [$best, $best_n] = [$article, $text_n];
            }
        }

        if ($best !== null) {
            return $best;
        }

        foreach (['//main', '//body'] as $query) {
            $found = $xpath->query($query);
            if ($found !== false && $found->length > 0) {
                return $found->item(0);
            }
        }

        return null;
    }

    /**
     * The blocks of <parent>'s children: each block element its own, runs of inline content between them
     * a paragraph.
     *
     * @return list<string>
     */
    private function blocks(DOMNode $parent): array
    {
        $blocks = [];
        $inline = '';
        $plain  = '';

        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement && in_array(strtolower($child->tagName), self::BLOCKS, true)) {
                $this->flush($blocks, $inline, $plain);
                array_push($blocks, ...$this->block($child));

                continue;
            }
            $inline .= $this->inline($child);
            $plain  .= $child->textContent;
        }
        $this->flush($blocks, $inline, $plain);

        return $blocks;
    }

    /** @param list<string> $blocks */
    private function flush(array &$blocks, string &$inline, string &$plain): void
    {
        $text = self::finish($inline);

        if ($text !== '' && !$this->is_dropped($plain)) {
            $blocks[] = $text;
        }
        $inline = '';
        $plain  = '';
    }

    /** @return list<string> */
    private function block(DOMElement $element): array
    {
        $tag = strtolower($element->tagName);

        switch ($tag) {
            case 'h1': case 'h2': case 'h3': case 'h4': case 'h5': case 'h6':
                $text = self::finish($this->inline_children($element));

                return $text === '' || $this->is_dropped($element->textContent) ? [] : [str_repeat('#', (int) $tag[1]) . ' ' . str_replace("\\\n", ' ', $text)];

            case 'p':
            case 'summary':
                return $this->blocks($element);

            case 'dt':
                $text = self::finish($this->inline_children($element));

                return $text === '' ? [] : ['**' . $text . '**'];

            case 'hr':
                return ['---'];

            case 'pre':
                $code  = rtrim(str_replace("\r\n", "\n", $element->textContent), "\n");
                $fence = str_repeat('`', max(3, self::longest_run($code, '`') + 1));

                return $code === '' ? [] : [$fence . "\n" . $code . "\n" . $fence];

            case 'blockquote':
                $inner = $this->blocks($element);

                return $inner === [] ? [] : [(string) preg_replace('/^/m', '> ', implode("\n\n", $inner))];

            case 'ul':
            case 'ol':
                return $this->list($element, $tag === 'ol');

            case 'table':
                return $this->table($element);

            case 'figcaption':
                $text = self::finish($this->inline_children($element));

                return $text === '' ? [] : ['*' . $text . '*'];

            default:
                return $this->blocks($element);
        }
    }

    /** @return list<string> */
    private function list(DOMElement $list, bool $is_ordered): array
    {
        $items = [];
        $i     = (int) ($list->getAttribute('start') ?: 1);

        foreach ($list->childNodes as $child) {
            if (!$child instanceof DOMElement || strtolower($child->tagName) !== 'li') {
                continue;
            }
            $marker = $is_ordered ? ($i++) . '.' : '-';
            $inner  = $this->blocks($child);
            $body   = implode("\n", $inner === [] ? [''] : $inner);
            $items[] = $marker . ' ' . preg_replace('/\n/', "\n" . str_repeat(' ', strlen($marker) + 1), $body);
        }

        return $items === [] ? [] : [implode("\n", $items)];
    }

    /** @return list<string> */
    private function table(DOMElement $table): array
    {
        $rows = [];
        foreach ($table->getElementsByTagName('tr') as $tr) {
            $cells = [];
            foreach ($tr->childNodes as $cell) {
                if ($cell instanceof DOMElement && in_array(strtolower($cell->tagName), ['td', 'th'], true)) {
                    $cells[] = str_replace('|', '\|', str_replace("\\\n", ' ', self::finish($this->inline_children($cell))));
                }
            }
            if ($cells !== []) {
                $rows[] = $cells;
            }
        }

        if ($rows === []) {
            return [];
        }

        $columns_n = max(array_map('count', $rows));
        $lines     = [];
        foreach ($rows as $i => $cells) {
            $lines[] = '| ' . implode(' | ', array_pad($cells, $columns_n, '')) . ' |';
            if ($i === 0) {
                $lines[] = '|' . str_repeat(' --- |', $columns_n);
            }
        }

        return [implode("\n", $lines)];
    }

    private function inline_children(DOMNode $node): string
    {
        $out = '';
        foreach ($node->childNodes as $child) {
            $out .= $this->inline($child);
        }

        return $out;
    }

    private function inline(DOMNode $node): string
    {
        if ($node->nodeType === XML_TEXT_NODE || $node->nodeType === XML_CDATA_SECTION_NODE) {
            return self::escape((string) $node->nodeValue);
        }

        if (!$node instanceof DOMElement) {
            return '';
        }

        switch (strtolower($node->tagName)) {
            case 'br':
                return self::BREAK;

            case 'strong':
            case 'b':
                return self::wrap($this->inline_children($node), '**');

            case 'em':
            case 'i':
                return self::wrap($this->inline_children($node), '*');

            case 'code':
                $code = self::collapse($node->textContent);
                $ticks = str_repeat('`', self::longest_run($code, '`') + 1);

                return trim($code) === '' ? '' : $ticks . (str_starts_with($code, '`') ? ' ' : '') . $code . (str_ends_with($code, '`') ? ' ' : '') . $ticks;

            case 'a':
                $inner = $this->inline_children($node);
                $href  = trim($node->getAttribute('href'));

                if (trim($inner) === '' || preg_match('~^https?://~i', $href) !== 1) {
                    return $inner;
                }

                return '[' . trim($inner) . '](' . str_replace([' ', '(', ')'], ['%20', '%28', '%29'], $href) . ')';

            case 'img':
                $alt = self::collapse($node->getAttribute('alt'));

                return trim($alt) === '' ? '' : ' [image: ' . self::escape(trim($alt)) . '] ';

            default:
                return $this->inline_children($node);
        }
    }

    private function is_dropped(string $plain): bool
    {
        return isset($this->drop_lines[trim(self::collapse($plain))]);
    }

    /** <mark>…<mark> around <inner>, its outer spaces kept outside the marks. */
    private static function wrap(string $inner, string $mark): string
    {
        $trimmed = trim(self::collapse($inner));

        if ($trimmed === '') {
            return $inner === '' ? '' : ' ';
        }

        return (preg_match('/^\s/', $inner) === 1 ? ' ' : '') . $mark . $trimmed . $mark . (preg_match('/\s$/', $inner) === 1 ? ' ' : '');
    }

    /** Whitespace collapsed, trimmed, a <br> made a hard line break. */
    private static function finish(string $inline): string
    {
        $text = trim(self::collapse($inline));
        $text = (string) preg_replace('/ ?' . self::BREAK . ' ?/u', self::BREAK, $text);
        $text = trim($text, self::BREAK . ' ');

        return str_replace(self::BREAK, "\\\n", $text);
    }

    private static function collapse(string $text): string
    {
        return (string) preg_replace('/[ \t\r\n\f]+/', ' ', $text);
    }

    private static function longest_run(string $text, string $char): int
    {
        preg_match_all('/' . preg_quote($char, '/') . '+/', $text, $m);

        return $m[0] === [] ? 0 : max(array_map('strlen', $m[0]));
    }
}
