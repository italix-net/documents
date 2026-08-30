<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Documents - MarkdownRenderer
 *
 * @package Italix\Documents
 */

declare(strict_types=1);

namespace Italix\Documents;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\MarkdownConverter;
use League\CommonMark\Node\Block\Document;
use League\CommonMark\Parser\MarkdownParser;

/**
 * Markdown to HTML, via `league/commonmark`.
 *
 * ## Two settings that are not configurable, on purpose
 *
 *     'html_input'         => 'escape'
 *     'allow_unsafe_links' => false
 *
 * Markdown permits raw HTML. A `<script>` inside a `.md` is stored XSS the
 * moment it is rendered, and a documentation tree is precisely a place where
 * many people write files that many other people read. This library sits next to
 * `italix/encode`, which refuses to trust output, and `italix/storage`, whose
 * default catalogue refuses `text/html` on upload for the same reason. Offering
 * a switch to turn it off here would undo both of them from a config file.
 *
 * An application that genuinely needs raw HTML in Markdown can write its own
 * `Renderer` — which is a deliberate act, visible in code review, rather than a
 * boolean somebody flipped.
 *
 * ## Links come out of the parse
 *
 * The converter runs the parse once and the link walk reads the resulting
 * document tree, so a URL inside a code fence is not mistaken for a link. A
 * regular expression over the HTML would get that wrong, and would get it wrong
 * silently.
 */
final class MarkdownRenderer implements Renderer
{
    private const EXTENSIONS = ['md', 'markdown', 'mdown', 'mkd'];

    private MarkdownConverter $converter;
    private MarkdownParser $parser;

    public function __construct()
    {
        if (!class_exists(MarkdownConverter::class)) {
            // Named, and told what to do about it: a renderer that quietly
            // produced escaped <pre> instead would look like it worked.
            throw new DocumentsException(
                'league/commonmark is required by MarkdownRenderer. Run: composer require league/commonmark'
            );
        }

        $environment = new Environment([
            // Raw HTML is escaped, never passed through. See the class docblock.
            'html_input'         => 'escape',
            'allow_unsafe_links' => false,
            'max_nesting_level'  => 64,
        ]);

        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new TableExtension());

        $this->converter = new MarkdownConverter($environment);
        $this->parser    = new MarkdownParser($environment);
    }

    public function handles(string $extension_c): bool
    {
        return in_array($extension_c, self::EXTENSIONS, true);
    }

    public function render(string $source, string $path_c): Rendered
    {
        $html = (string) $this->converter->convert($source);

        return new Rendered($html, self::to_text($source), $this->links_in($source));
    }

    public function describe(): string
    {
        return implode('/', self::EXTENSIONS) . ' -> HTML (raw HTML escaped, unsafe links refused)';
    }

    /**
     * Every link target in document order.
     *
     * @return string[]
     */
    private function links_in(string $source): array
    {
        $document = $this->parser->parse($source);
        $links    = [];

        if (!$document instanceof Document) {
            return $links;
        }

        $walker = $document->walker();

        while ($event = $walker->next()) {
            $node = $event->getNode();

            if ($event->isEntering() && $node instanceof Link) {
                $url = $node->getUrl();

                if ($url !== '') {
                    $links[] = $url;
                }
            }
        }

        return $links;
    }

    /**
     * A plain-text rendering, for the search index.
     *
     * Deliberately crude: strip the syntax that carries no words. Anything
     * cleverer belongs in the indexing step, where it can be tuned against real
     * queries rather than guessed at here.
     */
    private static function to_text(string $source): string
    {
        // Fenced code blocks: the words inside are rarely what anybody searches
        // for, and they dominate a technical document's token count.
        $text = (string) preg_replace('/^```.*?^```/ms', ' ', $source);
        $text = (string) preg_replace('/`[^`]*`/', ' ', $text);
        // Images before links, so the alt text of an image does not survive as
        // if it were link text.
        $text = (string) preg_replace('/!\[([^\]]*)\]\([^)]*\)/', '$1', $text);
        $text = (string) preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $text);
        $text = (string) preg_replace('/^\s{0,3}#{1,6}\s+/m', '', $text);
        // Raw HTML survived the parse as literal text (html_input=escape); it is
        // markup, not prose, and it would pollute the index with tag names.
        $text = (string) preg_replace('/<[^>]*>/', ' ', $text);
        $text = (string) preg_replace('/[*_~>]+/', ' ', $text);
        $text = (string) preg_replace('/\s+/u', ' ', $text);

        return trim($text);
    }
}
