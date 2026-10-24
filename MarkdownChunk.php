<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Documents - MarkdownChunk
 *
 * @package Italix\Documents
 */

declare(strict_types=1);

namespace Italix\Documents;

/**
 * One passage of a Markdown document, before anything has embedded it.
 *
 * Separate from `Italix\Rag\Chunk` on purpose: that one carries a vector and
 * cannot exist without a call to an embedding model. This one is what chunking
 * produces, so chunking can be judged — in a dry run, in tests, over a whole
 * corpus — without spending anything. Retrieval quality is decided here far
 * more than by any later tuning, and a chunker that can only be inspected after
 * being paid for does not get inspected.
 */
final class MarkdownChunk
{
    /**
     * @param list<string>         $heading_trail  e.g. ['Drivers', 'Registering one']
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        /** Stable identity for this passage — a re-index updates the row it names instead of adding one. */
        public readonly string $code,
        public readonly string $source_c,
        public readonly string $title,
        public readonly array $heading_trail,
        public readonly string $body,
        public readonly int $part_i,
        public readonly int $parts_n,
        public readonly array $metadata = []
    ) {
    }

    /**
     * What should be embedded — the body with its context restored.
     *
     * A passage that reads "call it with an array of names" is meaningless on
     * its own and embeds close to nothing useful. The document title and the
     * heading trail go in front so the vector carries where the passage came
     * from, not just what it said: a handful of tokens per chunk, and the
     * difference between retrieving the right section and a plausible sentence.
     */
    public function embeddable_text(): string
    {
        $trail = $this->heading_trail === [] ? '' : ' › ' . $this->heading_path();
        $part  = $this->parts_n > 1 ? sprintf(' (part %d of %d)', $this->part_i, $this->parts_n) : '';

        return "{$this->title}{$trail}{$part}\n\n{$this->body}";
    }

    /**
     * The caller's metadata plus where the passage sits, flat.
     *
     * Every value is a scalar or null — `Italix\Rag\Chunk` documents its
     * metadata as `array<string, scalar>` and means it. The heading trail
     * therefore crosses as the joined string it is displayed as anyway: a
     * nested array survives a JSON round trip and quietly breaks the first
     * store that maps metadata onto columns.
     *
     * @return array<string, mixed>
     */
    public function to_metadata(): array
    {
        return array_merge($this->metadata, [
            'title'   => $this->title,
            'heading' => $this->heading_path(),
            'part_i'  => $this->part_i,
            'parts_n' => $this->parts_n,
        ]);
    }

    /** The heading trail as one readable path — `Drivers › Registering one`. */
    public function heading_path(): string
    {
        return implode(' › ', $this->heading_trail);
    }
}
