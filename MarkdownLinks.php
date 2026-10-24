<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Documents - MarkdownLinks
 *
 * @package Italix\Documents
 */

declare(strict_types=1);

namespace Italix\Documents;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\HtmlBlock;
use League\CommonMark\Extension\CommonMark\Node\Block\IndentedCode;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\Parser\MarkdownParser;

/**
 * The link and image destinations of a Markdown source, and a rewrite of
 * some of them in the text itself — what Library::relink() needs.
 *
 * destinations() asks the parser, so a URL inside a code block or a code
 * span is never one. rewrite() cannot: CommonMark says which URL a link
 * has and which lines its block spans, not where the URL sits on the line.
 * So it replaces text — only in a destination's place (`](dest)`,
 * `](<dest>)`, a `[label]: dest` definition), never on a code block's
 * lines, never inside a code span — and its caller checks the result
 * against the parser before keeping it. The parser is the authority; this
 * only proposes.
 */
final class MarkdownLinks
{
    private static ?MarkdownParser $parser = null;

    private function __construct()
    {
    }

    /**
     * Every link and image destination, in document order, as the parser
     * reads them (reference links resolved to their definition's URL).
     *
     * @return list<string>
     */
    public static function destinations(string $source): array
    {
        $walker = self::parser()->parse($source)->walker();
        $out    = [];

        while ($event = $walker->next()) {
            $node = $event->getNode();

            if ($event->isEntering() && ($node instanceof Link || $node instanceof Image) && $node->getUrl() !== '') {
                $out[] = $node->getUrl();
            }
        }

        return $out;
    }

    /**
     * <source> with each destination named in <map> (as written, or as the
     * parser reports it) replaced by its value, where it stands as a
     * destination. Code blocks and code spans are left as they are.
     *
     * @param array<string, string> $map old destination => new destination
     */
    public static function rewrite(string $source, array $map): string
    {
        if ($map === []) {
            return $source;
        }

        $variants = [];
        foreach ($map as $from => $to) {
            $variants[$from]               = $to;
            $variants[rawurldecode($from)] = $to;
        }
        uksort($variants, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        $alternatives = implode('|', array_map(static fn (string $d): string => preg_quote($d, '~'), array_keys($variants)));

        $skip  = self::code_block_lines($source);
        $lines = explode("\n", $source);

        foreach ($lines as $i => $line) {
            if (isset($skip[$i + 1])) {
                continue;
            }

            // A reference definition: `[label]: dest` at the start of the line.
            $line = (string) preg_replace_callback(
                '~^( {0,3}\[[^\]]+\]:[ \t]*)(<?)(' . $alternatives . ')(>?)(?=[ \t]|$)~',
                static fn (array $m): string => $m[1] . $m[2] . $variants[$m[3]] . $m[4],
                $line
            );

            // Inline destinations, outside code spans.
            $lines[$i] = self::outside_code_spans($line, static fn (string $text): string => (string) preg_replace_callback(
                '~(\]\([ \t]*)(<?)(' . $alternatives . ')(>?)(?=[ \t)])~',
                static fn (array $m): string => $m[1] . $m[2] . $variants[$m[3]] . $m[4],
                $text
            ));
        }

        return implode("\n", $lines);
    }

    /**
     * The 1-based numbers of the lines a code block or a raw HTML block occupies.
     *
     * @return array<int, true>
     */
    private static function code_block_lines(string $source): array
    {
        $walker = self::parser()->parse($source)->walker();
        $lines  = [];

        while ($event = $walker->next()) {
            $node = $event->getNode();

            if ($event->isEntering() && ($node instanceof FencedCode || $node instanceof IndentedCode || $node instanceof HtmlBlock)) {
                for ($n = (int) $node->getStartLine(); $n <= (int) $node->getEndLine(); $n++) {
                    $lines[$n] = true;
                }
            }
        }

        return $lines;
    }

    /**
     * <edit> applied to the parts of <line> outside code spans — a run of
     * backticks up to the next run of the same length, on this line. A span
     * over two lines is not seen; the caller's check against the parser is
     * what catches a rewrite inside one.
     *
     * @param callable(string): string $edit
     */
    private static function outside_code_spans(string $line, callable $edit): string
    {
        $out = '';
        $at  = 0;

        while (preg_match('/`+/', $line, $open, PREG_OFFSET_CAPTURE, $at) === 1) {
            $start = (int) $open[0][1];
            $ticks = $open[0][0];
            $close = preg_match('/(?<!`)' . $ticks . '(?!`)/', $line, $m, PREG_OFFSET_CAPTURE, $start + strlen($ticks)) === 1 ? (int) $m[0][1] : null;

            if ($close === null) {
                break;   // unmatched: literal backticks, the rest is text
            }

            $out .= $edit(substr($line, $at, $start - $at)) . substr($line, $start, $close + strlen($ticks) - $start);
            $at   = $close + strlen($ticks);
        }

        return $out . $edit(substr($line, $at));
    }

    private static function parser(): MarkdownParser
    {
        if (self::$parser === null) {
            if (!class_exists(MarkdownParser::class)) {
                throw new DocumentsException('league/commonmark is required to read links. Run: composer require league/commonmark');
            }

            $environment = new Environment(['html_input' => 'escape', 'allow_unsafe_links' => false, 'max_nesting_level' => 64]);
            $environment->addExtension(new CommonMarkCoreExtension());
            $environment->addExtension(new TableExtension());
            self::$parser = new MarkdownParser($environment);
        }

        return self::$parser;
    }
}
