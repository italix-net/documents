<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Documents — MarkdownChunker: Markdown into retrievable passages
 *
 * Pure: no key, no network, no database. Which is the point — chunking is the
 * one part of a retrieval pipeline that can be judged for free, so it is the
 * part that should be judged hardest.
 *
 * Run: php src/Libs/Italix/Documents/tests/MarkdownChunkerTest.php
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

use Italix\Documents\MarkdownChunk;
use Italix\Documents\MarkdownChunker;

use function Italix\Testing\{suite, section, test, summary};

suite('Italix Documents — MarkdownChunker');

$chunker = new MarkdownChunker();

// -----------------------------------------------------------------------------
section('Sections, and what comes before the first one');

// The prologue is a real paragraph, not one line. Written short the first time,
// which made every piece of this document a runt: they all merged into a single
// chunk, and the assertions below failed for a reason that was about the
// fixture rather than the chunker. A 130-character README *should* be one
// chunk — that is the coalescing rule working.
$doc = <<<MD
# Italix Widgets

A library for widgets, and for the registries that hold them. This paragraph
sits above every heading, which is where these documents put the sentence that
says what the library is actually for — the single most quotable passage in the
file, and the one a splitter that only looks at headings throws away.

## Installation

composer require italix/widgets

## Drivers

Register one with `DriverRegistry::register()`. This paragraph is long enough to
survive the runt-coalescing pass, which folds anything under two hundred and
twenty characters into whatever came before it, so it stands on its own as a
chunk and can be asserted on directly without the assertion being about the
merging rule instead.
MD;

$chunks = $chunker->chunk('Widgets/README.md', $doc);

test('the prologue is a chunk of its own', $chunks[0]->heading_trail === []);
test('...carrying the sentence that says what the library is for',
    strpos($chunks[0]->body, 'A library for widgets') !== false);
test('the title is taken from the h1', $chunks[0]->title === 'Italix Widgets');
test('every chunk carries the source', $chunks[0]->source_c === 'Widgets/README.md');

$drivers = array_values(array_filter($chunks, static fn (MarkdownChunk $c): bool => $c->heading_trail === ['Drivers']));

test('a `##` section becomes a chunk under its heading', count($drivers) === 1);
test('...with the heading text, not the hashes', $drivers[0]->heading_trail[0] === 'Drivers');

// -----------------------------------------------------------------------------
section('The heading trail is embedded, not just recorded');

// A chunk reading "Register one with DriverRegistry::register()" is meaningless
// alone and embeds close to nothing useful. Retrieval lives or dies here.
$text = $drivers[0]->embeddable_text();

test('the document title is in front of the body', strpos($text, 'Italix Widgets') === 0);
test('...and so is the heading', strpos($text, '› Drivers') !== false);
test('...before the body itself', strpos($text, 'DriverRegistry') > strpos($text, 'Drivers'));

// -----------------------------------------------------------------------------
section('A `##` inside a code fence is not a heading');

$fenced = <<<MD
# Fences

## Real Heading

Here is how a markdown document is structured, shown rather than described:

```markdown
## This Is Not A Heading

It is an example of one, inside a fence, and splitting here would cut the code
sample in half — which is worse than not splitting at all, because half a code
sample still looks authoritative and does not run.
```

That was the example.
MD;

$fenced_chunks = $chunker->chunk('Fences/README.md', $fenced);
$headings = array_map(static fn (MarkdownChunk $c): string => implode('/', $c->heading_trail), $fenced_chunks);

test('the fenced heading did not become a section', !in_array('This Is Not A Heading', $headings, true));
test('the real one did', in_array('Real Heading', $headings, true));

$body = implode("\n", array_map(static fn (MarkdownChunk $c): string => $c->body, $fenced_chunks));

test('the code sample survived whole',
    strpos($body, '## This Is Not A Heading') !== false && strpos($body, 'That was the example.') !== false);

// -----------------------------------------------------------------------------
section('Long sections are split; short ones are not');

$long_section = "# Long\n\n## Big\n\n" . implode("\n\n", array_map(
    static fn (int $i): string => "Paragraph {$i}. " . str_repeat('Filler sentence for length. ', 20),
    range(1, 30)
));

$long_chunks = $chunker->chunk('Long/README.md', $long_section);

test('an over-long section is split into several', count($long_chunks) > 1);
test('...all under the hard maximum', array_reduce(
    $long_chunks,
    static fn (bool $ok, MarkdownChunk $c): bool => $ok && mb_strlen($c->body) <= 3200,
    true
));
test('...each numbered within its heading', $long_chunks[0]->parts_n === count($long_chunks));
test('...starting at one', $long_chunks[0]->part_i === 1);
test('...and the part number reaches the reader', strpos($long_chunks[0]->embeddable_text(), 'part 1 of') !== false);

$short = $chunker->chunk('Short/README.md', "# Short\n\n## One\n\n" . str_repeat('Short but sufficient. ', 20));

test('a section that fits is left alone', count($short) === 1);
test('...and says so rather than claiming to be part 1 of 1',
    strpos($short[0]->embeddable_text(), 'part') === false);

// -----------------------------------------------------------------------------
section('A long section is never split inside a fence');

// The code block contains blank lines. That detail is the whole test: a blank
// line is the only place the paragraph splitter may cut, so a fixture whose
// fence has none cannot tell a guarded splitter from an unguarded one. Found by
// mutation — removing the fence check left the first version of this passing.
$long_fence = "# Fence\n\n## Big\n\n"
    . str_repeat("Prose paragraph, padding the section past the point where a split becomes legal.\n\n", 30)
    . "```php\n"
    . str_repeat("// a line of the sample\n\n// and another, with a blank line between them\n\n", 30)
    . "```\n\n"
    . str_repeat("Trailing prose paragraph, so the fence is not simply the end of the section.\n\n", 20);

$fence_chunks = $chunker->chunk('Fence/README.md', $long_fence);

$opens_n = 0;

foreach ($fence_chunks as $chunk) {
    $opens_n += substr_count($chunk->body, '```') % 2;
}

test('the section really was long enough to be split', count($fence_chunks) > 1);
test('the fence really does contain splittable blank lines',
    substr_count($long_fence, "\n\n") > 60);
test('no chunk ends with a fence left open', $opens_n === 0,
    'a chunk with an odd number of ``` markers has half a code sample in it');

// -----------------------------------------------------------------------------
section('The title line is not repeated inside the body');

// It is already carried as `title` and put back in front of every chunk by
// embeddable_text(). Left in the prologue it is duplicated in the embedded
// text, and in a file whose prologue is *only* a title it produces a
// seven-character chunk that nothing can merge backwards into.
$titled = $chunker->chunk('Titled/README.md', $doc);

test('no chunk body contains the h1 line', array_reduce(
    $titled,
    static fn (bool $ok, MarkdownChunk $c): bool => $ok && strpos($c->body, '# Italix Widgets') === false,
    true
));
test('...though the title still reaches the embedding', strpos($titled[0]->embeddable_text(), 'Italix Widgets') === 0);

$title_only = $chunker->chunk('TitleOnly/README.md', "# Just A Title\n\n## Real Section\n\n" . str_repeat('Real content here. ', 30));

test('a file whose prologue is only its title produces no runt chunk', count($title_only) === 1);
test('...and that chunk is the real section', $title_only[0]->heading_trail === ['Real Section']);

// -----------------------------------------------------------------------------
section('Runts are folded, not shipped');

$runty = "# Runts\n\n## Body\n\n" . str_repeat('Real content that stands on its own. ', 20) . "\n\n## License\n\nMPL-2.0";

$runt_chunks = $chunker->chunk('Runts/README.md', $runty);

test('a ten-character section does not become its own chunk', count($runt_chunks) === 1);
test('...but its text is still retrievable', strpos($runt_chunks[0]->body, 'MPL-2.0') !== false);
test('...under a heading that says what it is', strpos($runt_chunks[0]->body, '## License') !== false);

// -----------------------------------------------------------------------------
section('Metadata is flat and scalar');

// `Italix\Rag\Chunk` documents its metadata as array<string, scalar> and means
// it. A heading trail stored as a list survives the JSON round trip and would
// quietly break the first store that mapped metadata onto columns.
$meta = $drivers[0]->to_metadata();

// `gettype()` rather than `is_array()`: the declared return type already says
// scalar-or-null, so a static analyser calls `is_array()` dead code — and a
// docblock is not enforcement. This checks the values that actually exist.
test('no metadata value is an array', array_reduce(
    array_values($meta),
    static fn (bool $ok, $v): bool => $ok && gettype($v) !== 'array',
    true
));
test('...so it survives a JSON round trip flat', array_reduce(
    array_values((array) json_decode((string) json_encode($meta), true)),
    static fn (bool $ok, $v): bool => $ok && gettype($v) !== 'array',
    true
));
test('the heading crosses as a readable path', $meta['heading'] === 'Drivers');
test('...joined when it is nested', (new MarkdownChunk('i', 's', 't', ['A', 'B'], 'b', 1, 1))->heading_path() === 'A › B');
test('the part numbers cross', $meta['part_i'] === 1 && $meta['parts_n'] === 1);

// -----------------------------------------------------------------------------
section('Identity is stable across re-indexing');

$again = $chunker->chunk('Widgets/README.md', $doc);

test('the same document produces the same codes', array_map(static fn (MarkdownChunk $c): string => $c->code, $again)
    === array_map(static fn (MarkdownChunk $c): string => $c->code, $chunks));

// An edited paragraph must keep its identity, or every re-index doubles the
// store instead of updating it.
$edited = str_replace('Register one with', 'Register a driver with', $doc);
$edited_chunks = $chunker->chunk('Widgets/README.md', $edited);

test('an edited body keeps its code, so a re-index updates rather than duplicates',
    $edited_chunks[count($edited_chunks) - 1]->code === $chunks[count($chunks) - 1]->code);
test('...while the text really did change',
    $edited_chunks[count($edited_chunks) - 1]->body !== $chunks[count($chunks) - 1]->body);

$other_file = $chunker->chunk('Other/README.md', $doc);

test('the same text in a different file gets different codes',
    $other_file[0]->code !== $chunks[0]->code);

// -----------------------------------------------------------------------------
section('A paragraph too long for small sizes is split on sentences');

// Sizes for a model that truncates early. One unbroken paragraph — no blank
// line, no `###` — is the case the paragraph splitter cannot touch.
$small = new MarkdownChunker(300, 500, 80);

$sentences = array_map(
    static fn (int $i): string => "Sentence number {$i} says one complete thing about the subject.",
    range(1, 40)
);
$one_paragraph = "# Small\n\n## Para\n\n" . implode(' ', $sentences);

$sentence_chunks = $small->chunk('Small/README.md', $one_paragraph);

test('one long paragraph becomes several chunks', count($sentence_chunks) > 1, (string) count($sentence_chunks));
test('...each within the maximum', array_reduce(
    $sentence_chunks,
    static fn (bool $ok, MarkdownChunk $c): bool => $ok && mb_strlen($c->body) <= 500,
    true
));
test('...each ending at a full stop, never mid-sentence', array_reduce(
    $sentence_chunks,
    static fn (bool $ok, MarkdownChunk $c): bool => $ok && str_ends_with($c->body, '.'),
    true
));
test('...and nothing is lost', implode(' ', array_map(
    static fn (MarkdownChunk $c): string => $c->body,
    $sentence_chunks
)) === implode(' ', $sentences));

test('the default sizes leave that paragraph whole', count($chunker->chunk('Small/README.md', $one_paragraph)) === 1);

// -----------------------------------------------------------------------------
section('Sizes that cannot work are refused');

foreach ([
    'min above target'  => [100, 200, 150],
    'target above max'  => [300, 200, 50],
    'min of zero'       => [100, 200, 0],
] as $label => [$target_n, $max_n, $min_n]) {
    $thrown = false;

    try {
        new MarkdownChunker($target_n, $max_n, $min_n);
    } catch (InvalidArgumentException) {
        $thrown = true;
    }

    test("{$label} is refused", $thrown);
}

test('equal sizes are allowed', (new MarkdownChunker(200, 200, 200)) instanceof MarkdownChunker);

// -----------------------------------------------------------------------------
section('A real corpus: the READMEs next to this library, when they are there');

// Asserted as ranges, not exact figures: documentation changes weekly, and a
// test that failed whenever somebody edited a README would be deleted within a
// month. What is worth pinning is that nothing tiny and nothing enormous gets
// through. Installed alone, the library has no siblings and the section skips.
$readmes = glob(dirname(__DIR__, 2) . '/*/README.md') ?: [];

if (count($readmes) < 10) {
    test('fewer than ten sibling READMEs — real-corpus checks skipped', true);
} else {
    $all = [];

    foreach ($readmes as $path) {
        $source_c = basename(dirname($path)) . '/README.md';
        $all      = array_merge($all, $chunker->chunk($source_c, (string) file_get_contents($path), ['library' => basename(dirname($path))]));
    }

    $lengths = array_map(static fn (MarkdownChunk $c): int => mb_strlen($c->embeddable_text()), $all);
    $codes   = array_map(static fn (MarkdownChunk $c): string => $c->code, $all);

    test('every README produced at least one chunk', count(array_unique(array_map(
        static fn (MarkdownChunk $c): string => $c->source_c,
        $all
    ))) === count($readmes));
    test('nothing is too small to be worth retrieving', min($lengths) >= MarkdownChunker::MIN_N, 'smallest: ' . min($lengths));
    // The hard maximum binds the body; the embedded text adds the title and trail.
    test('no body is over the hard maximum', max(array_map(
        static fn (MarkdownChunk $c): int => mb_strlen($c->body),
        $all
    )) <= MarkdownChunker::MAX_N);
    test('every code is unique — a collision would silently overwrite a passage', count(array_unique($codes)) === count($codes));
    test('no chunk ends with a fence left open', array_reduce(
        $all,
        static fn (bool $ok, MarkdownChunk $c): bool => $ok && substr_count($c->body, '```') % 2 === 0,
        true
    ));
}

exit(summary());
