<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Documents - MarkdownChunker
 *
 * @package Italix\Documents
 */

declare(strict_types=1);

namespace Italix\Documents;

use InvalidArgumentException;

/**
 * Markdown into retrievable passages.
 *
 * **In a retrieval pipeline this decides whether the answers are any good.**
 * Not the model, not the number of dimensions, not the value of k — the
 * chunker. A passage split in the wrong place answers a question with half an
 * answer, and no later tuning recovers the half that was cut off.
 *
 * Three rules, in order:
 *
 * 1. **Split on `##`, because that is where a well-written document changes
 *    subject.** The author's own structure is a better boundary than any fixed
 *    window.
 * 2. **Never split inside a fenced code block.** Half a code sample is worse
 *    than none: it looks authoritative and does not run. Fences are tracked
 *    while scanning, and a heading inside one is not a heading.
 * 3. **Sub-split only what is too long** — on `###` first, then on paragraph
 *    boundaries, then on sentence boundaries. One section of a long reference
 *    document can outweigh everything else in a corpus put together.
 *
 * Short pieces are folded into a neighbour rather than shipped: a forty-
 * character passage will match something, once, and answer nothing.
 *
 * What comes out carries its heading trail, and
 * `MarkdownChunk::embeddable_text()` puts it back in front of the body — see
 * there for why. Pure string work: no parser, no store, no model.
 */
final class MarkdownChunker
{
    /**
     * The default sizes, in characters, suit an embedding model with a generous
     * context. A model whose tokenizer truncates early — MiniLM stops at 128
     * tokens — needs smaller ones passed to the constructor; the sentence-level
     * split below is what makes small sizes work on long paragraphs.
     */

    /** Aim for this; a section under it is never split. */
    public const TARGET_N = 2000;

    /** Never emit a chunk longer than this if a legal split point exists. */
    public const MAX_N = 3200;

    /** Below this, a piece is folded into its neighbour. */
    public const MIN_N = 220;

    public function __construct(
        private readonly int $target_n = self::TARGET_N,
        private readonly int $max_n = self::MAX_N,
        private readonly int $min_n = self::MIN_N
    ) {
        if ($min_n < 1 || $min_n > $target_n || $target_n > $max_n) {
            throw new InvalidArgumentException(
                "MarkdownChunker sizes must satisfy 1 <= min ({$min_n}) <= target ({$target_n}) <= max ({$max_n})."
            );
        }
    }

    /**
     * @param array<string, mixed> $metadata attached to every chunk from this file
     * @return list<MarkdownChunk>
     */
    public function chunk(string $source_c, string $markdown, array $metadata = []): array
    {
        $lines = preg_split('/\R/', $markdown) ?: [];
        $title = $this->title_of($lines, $source_c);

        // The `# Title` line goes, because the title is already carried
        // separately and put back in front of every chunk by
        // `MarkdownChunk::embeddable_text()`. Left in, a file whose prologue is
        // only its title produces a seven-character first chunk that cannot
        // merge backwards — there is nothing before it — and ships as
        // retrievable noise.
        $lines = $this->without_title_line($lines);

        $chunks   = [];
        $sections = $this->split_on_headings($lines, 2);

        foreach ($sections as $section) {
            $body = trim($section['body']);

            if ($body === '') {
                continue;
            }

            $trail = $section['heading_c'] === null ? [] : [$section['heading_c']];

            foreach ($this->fit($body, $trail) as $piece) {
                $chunks[] = $piece;
            }
        }

        return $this->finish($this->coalesce_runts($chunks), $source_c, $title, $metadata);
    }

    /**
     * @param list<string> $lines
     * @return list<string>
     */
    private function without_title_line(array $lines): array
    {
        foreach ($lines as $i => $line) {
            if (strncmp($line, '# ', 2) === 0) {
                unset($lines[$i]);

                return array_values($lines);
            }
        }

        return $lines;
    }

    /**
     * Fold sections too short to stand on their own into the one before them.
     *
     * Measured over a corpus of 34 library READMEs before this existed: 34 of
     * 384 chunks were under 220 characters, most of them `## License` —
     * thirty-four mutually indistinguishable vectors saying "Mozilla Public
     * License 2.0". A question about licensing got five of them at random,
     * crowding out everything else; any other question never touched them.
     * Small, plausible and useless is the worst thing a chunk can be.
     *
     * Dropping them is worse: "how do I install it" has a two-line answer
     * that deserves to exist. Merged, the
     * text stays retrievable through its neighbour and stops competing with
     * itself. The heading goes back into the body as a `##` line so the merged
     * passage still reads as what it is.
     *
     * @param list<array{trail: list<string>, body: string}> $pieces
     * @return list<array{trail: list<string>, body: string}>
     */
    private function coalesce_runts(array $pieces): array
    {
        $out = [];

        // A runt in first position has nothing behind it, so it merges
        // forwards instead — and keeps the *next* piece's heading, because
        // that is where the bulk of the merged text comes from.
        while (count($pieces) > 1 && $this->length_of($pieces[0]['body']) < $this->min_n) {
            $pieces[1]['body'] = trim($pieces[0]['body'] . "\n\n" . $pieces[1]['body']);
            array_shift($pieces);
            $pieces = array_values($pieces);
        }

        foreach ($pieces as $piece) {
            $n = count($out);

            $mergeable_flag = $n > 0
                && $this->length_of($piece['body']) < $this->min_n
                && $this->length_of($out[$n - 1]['body']) + $this->length_of($piece['body']) <= $this->max_n;

            if (!$mergeable_flag) {
                $out[] = $piece;

                continue;
            }

            $heading_c = $piece['trail'] === [] ? null : (string) end($piece['trail']);
            $prefix_c  = $heading_c === null ? '' : "## {$heading_c}\n\n";

            $out[$n - 1]['body'] .= "\n\n" . $prefix_c . $piece['body'];
        }

        return $out;
    }

    // -----------------------------------------------------------------------

    /**
     * Sections of `$markdown` at heading level `$level_n`.
     *
     * The prologue — whatever sits above the first heading — is its own section
     * with a null heading. In a README that is the one-line description of what
     * the thing is for: the single most quotable passage in the file, and the
     * one a naive splitter drops.
     *
     * @param list<string> $lines
     * @return list<array{heading_c: ?string, body: string}>
     */
    private function split_on_headings(array $lines, int $level_n): array
    {
        $marker    = str_repeat('#', $level_n) . ' ';
        $sections  = [];
        $heading_c = null;
        $buffer    = [];
        $fence_c   = null;

        foreach ($lines as $line) {
            $fence_c = $this->fence_after($line, $fence_c);

            // A `## ` inside a code block is a shell comment, a markdown
            // example, or a heading being demonstrated — never a heading here.
            if ($fence_c === null && strncmp($line, $marker, strlen($marker)) === 0) {
                $sections[] = ['heading_c' => $heading_c, 'body' => implode("\n", $buffer)];
                $heading_c  = trim(substr($line, strlen($marker)));
                $buffer     = [];

                continue;
            }

            $buffer[] = $line;
        }

        $sections[] = ['heading_c' => $heading_c, 'body' => implode("\n", $buffer)];

        return array_values(array_filter(
            $sections,
            static fn (array $s): bool => trim($s['body']) !== '' || $s['heading_c'] !== null
        ));
    }

    /**
     * One section into pieces that fit, keeping the heading trail.
     *
     * @param list<string> $trail
     * @return list<array{trail: list<string>, body: string}>
     */
    private function fit(string $body, array $trail): array
    {
        if ($this->length_of($body) <= $this->max_n) {
            return [['trail' => $trail, 'body' => $body]];
        }

        $out = [];

        foreach ($this->split_on_headings(preg_split('/\R/', $body) ?: [], 3) as $sub) {
            $sub_body = trim($sub['body']);

            if ($sub_body === '') {
                continue;
            }

            $sub_trail = $sub['heading_c'] === null ? $trail : [...$trail, $sub['heading_c']];

            foreach ($this->split_on_paragraphs($sub_body) as $piece) {
                foreach ($this->split_on_sentences($piece) as $sentence_piece) {
                    $out[] = ['trail' => $sub_trail, 'body' => $sentence_piece];
                }
            }
        }

        // A section with no `###` and no blank lines — one enormous table, say.
        // Emitting it whole beats cutting it at an arbitrary character.
        return $out === [] ? [['trail' => $trail, 'body' => $body]] : $out;
    }

    /**
     * Greedy paragraph packing, up to the target, never inside a fence.
     *
     * @return list<string>
     */
    private function split_on_paragraphs(string $body): array
    {
        if ($this->length_of($body) <= $this->max_n) {
            return [$body];
        }

        $pieces  = [];
        $current = [];
        $fence_c = null;
        $lines   = preg_split('/\R/', $body) ?: [];

        foreach ($lines as $line) {
            $was_open = $fence_c !== null;
            $fence_c  = $this->fence_after($line, $fence_c);

            // A paragraph break outside a fence is the only place to cut.
            $breakable_flag = !$was_open && $fence_c === null && trim($line) === '';

            if ($breakable_flag && $this->length_of(implode("\n", $current)) >= $this->target_n) {
                $pieces[]  = trim(implode("\n", $current));
                $current   = [];

                continue;
            }

            $current[] = $line;
        }

        if (trim(implode("\n", $current)) !== '') {
            $pieces[] = trim(implode("\n", $current));
        }

        // Fold a runt back into its predecessor rather than emitting it.
        $n = count($pieces);

        if ($n > 1 && $this->length_of($pieces[$n - 1]) < $this->min_n) {
            $pieces[$n - 2] .= "\n\n" . $pieces[$n - 1];
            array_pop($pieces);
        }

        return array_values($pieces);
    }

    /**
     * The fallback `split_on_paragraphs()` never needed until a caller asked
     * for chunks this small: a single paragraph — one unbroken run of text
     * with no blank line inside it — that is still bigger than `$max_n`.
     *
     * At the default sizes this almost never fires; real prose rarely runs
     * 3200 characters between blank lines. It fires constantly at sizes for a
     * model that truncates early, where an encyclopaedia paragraph is several
     * times over the budget — found by tracing why a pipeline missed a fact
     * that was indexed, but three sentences into a paragraph the model's
     * 128-token limit had already truncated past.
     *
     * Splits at sentence boundaries and packs greedily to `$target_n`, the
     * same rule `split_on_paragraphs()` uses one level up — a cut mid
     * sentence is worse than one at the nearest full stop, for the same
     * reason a cut mid paragraph would be.
     *
     * @return list<string>
     */
    private function split_on_sentences(string $piece): array
    {
        if ($this->length_of($piece) <= $this->max_n) {
            return [$piece];
        }

        // A plain ". " would also break "Sig. Rossi" and "1520 a.C." — not
        // worth solving in general here. An abbreviation misread as a
        // sentence end just merges harmlessly into its neighbour below.
        $sentences = preg_split('/(?<=[.!?])\s+(?=[A-ZÀ-Þ"\x{00AB}])/u', $piece) ?: [$piece];

        $pieces  = [];
        $current = '';

        foreach ($sentences as $sentence) {
            if ($current !== '' && $this->length_of($current . ' ' . $sentence) > $this->target_n) {
                $pieces[] = trim($current);
                $current  = $sentence;

                continue;
            }

            $current = $current === '' ? $sentence : $current . ' ' . $sentence;
        }

        if (trim($current) !== '') {
            $pieces[] = trim($current);
        }

        // Fold a runt back into its predecessor, same rule as
        // split_on_paragraphs() — a trailing half-sentence should not stand
        // alone as its own vector.
        $n = count($pieces);

        if ($n > 1 && $this->length_of($pieces[$n - 1]) < $this->min_n) {
            $pieces[$n - 2] .= ' ' . $pieces[$n - 1];
            array_pop($pieces);
        }

        return $pieces === [] ? [$piece] : array_values($pieces);
    }

    /**
     * The fence state after this line.
     *
     * Returns the fence marker that is currently open, or null outside one.
     * Both ``` and ~~~ count, and a closing fence must use the same character
     * — otherwise a ~~~ inside a ``` block would close it.
     */
    private function fence_after(string $line, ?string $open_c): ?string
    {
        if (preg_match('/^\s{0,3}(`{3,}|~{3,})/', $line, $found) !== 1) {
            return $open_c;
        }

        $marker_c = $found[1][0];

        if ($open_c === null) {
            return $marker_c;
        }

        return $marker_c === $open_c ? null : $open_c;
    }

    /** @param list<string> $lines */
    private function title_of(array $lines, string $source_c): string
    {
        foreach ($lines as $line) {
            if (strncmp($line, '# ', 2) === 0) {
                return trim(substr($line, 2));
            }
        }

        return $source_c;
    }

    /**
     * @param list<array{trail: list<string>, body: string}> $pieces
     * @param array<string, mixed> $metadata
     * @return list<MarkdownChunk>
     */
    private function finish(array $pieces, string $source_c, string $title, array $metadata): array
    {
        // Parts are numbered within their heading, not within the file: "part 2
        // of 3 of the Drivers section" is a thing a reader can place, "part 17
        // of 60 of the README" is not.
        $counts = [];

        foreach ($pieces as $piece) {
            $key_c = implode(' › ', $piece['trail']);
            $counts[$key_c] = ($counts[$key_c] ?? 0) + 1;
        }

        $seen   = [];
        $chunks = [];

        foreach ($pieces as $piece) {
            $key_c        = implode(' › ', $piece['trail']);
            $seen[$key_c] = ($seen[$key_c] ?? 0) + 1;

            $chunks[] = new MarkdownChunk(
                // The store's `code`: deterministic, so re-indexing updates a
                // row instead of adding one. Derived from where the passage
                // sits rather than from what it says, so an edited paragraph
                // keeps its identity.
                substr(sha1($source_c . '|' . $key_c . '|' . $seen[$key_c]), 0, 32),
                $source_c,
                $title,
                $piece['trail'],
                $piece['body'],
                $seen[$key_c],
                $counts[$key_c],
                $metadata
            );
        }

        return $chunks;
    }

    private function length_of(string $text): int
    {
        return mb_strlen($text);
    }
}
