<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Documents — HtmlToMarkdownConverter: a web page's main content as Markdown
 *
 * Which
 * element is the content, what is removed, each construct written, the
 * interface lines dropped, and the Markdown rendered back saying what the
 * article said.
 *
 * Run: php src/Libs/Italix/Documents/tests/HtmlToMarkdownConverterTest.php
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

use Italix\Converters\ConversionOptions;
use Italix\Documents\HtmlToMarkdownConverter;
use Italix\Documents\MarkdownRenderer;

use function Italix\Testing\{suite, section, test, summary};

suite('Italix Documents — HtmlToMarkdownConverter');

if (!extension_loaded('dom')) {
    echo "  SKIPPED — ext-dom is absent.\n";
    exit(summary());
}

$converter = new HtmlToMarkdownConverter();
$md        = static fn (string $body): string => $converter->markdown('<!doctype html><html><head><title>t</title></head><body>' . $body . '</body></html>');

// -----------------------------------------------------------------------------
section('a converter like any other');

test('html and htm to md', $converter->pairs() === [['html', 'md'], ['htm', 'md']] && $converter->is_available());
$converted = $converter->convert('<article><p>Hello</p></article>', 'html', 'md', ConversionOptions::none());
test('convert(): Markdown, as text/markdown', $converted->bytes() === "Hello\n" && $converted->extension_code() === 'md' && str_starts_with($converted->mime(), 'text/markdown'));
test('a page without text: an empty string', $md('<div>   </div>') === '');

// -----------------------------------------------------------------------------
section('where the content is');

$page = '<nav>Home Blog About</nav><article><p>A teaser.</p></article><article><h1>The post</h1><p>The body of the post, much longer than the teaser.</p></article><footer>Copyright</footer>';
test('the <article> with the most text, not the first', $md($page) === "# The post\n\nThe body of the post, much longer than the teaser.\n");
test('no <article>: the <main>', $md('<nav>Menu</nav><main><p>Main text</p></main><aside>Side</aside>') === "Main text\n");
test('neither: the whole <body>', $md('<div><p>One</p></div><p>Two</p>') === "One\n\nTwo\n");
test('scripts, styles, buttons, inline SVG and form controls removed',
    $md('<article><p>Text</p><script>alert(1)</script><style>p{}</style><button>Follow</button><svg><text>icon</text></svg><input value="x"><noscript>JS off</noscript></article>') === "Text\n");

// -----------------------------------------------------------------------------
section('what it writes');

test('headings, one to six', $md('<article><h1>A</h1><h3>B <em>c</em></h3><h6>D</h6></article>') === "# A\n\n### B *c*\n\n###### D\n");
test('emphasis, spaces kept outside the marks', $md('<p>a <b>bold</b> and <i> it </i>, <strong></strong>end</p>') === "a **bold** and *it* , end\n");
test('an absolute link stays a link, spaces and parentheses encoded; a relative one becomes its text',
    $md('<p><a href="https://x.org/a b(c)">abs</a> and <a href="/local">rel</a> and <a href="https://x.org"></a>.</p>') === "[abs](https://x.org/a%20b%28c%29) and rel and .\n");
test('a code span, fenced past the backticks it holds', $md('<p>use <code>a_b</code> or <code>x`y</code></p>') === "use `a_b` or ``x`y``\n");
test('a code block, its spaces kept, fenced past the backticks it holds',
    $md("<pre><code>a  b\n  ```c\n</code></pre>") === "````\na  b\n  ```c\n````\n");
test('lists: nested, ordered from its start', $md('<ul><li>one<ul><li>inner</li></ul></li><li>two</li></ul><ol start="3"><li>three</li><li>four</li></ol>') === "- one\n  - inner\n- two\n\n3. three\n4. four\n");
test('a block quote of two paragraphs', $md('<blockquote><p>q1</p><p>q2</p></blockquote>') === "> q1\n> \n> q2\n");
test('an image by its alt, none without; a caption in italics',
    $md('<figure><img src="a.png" alt="a pump"><img src="b.png"><figcaption>The pump</figcaption></figure>') === "[image: a pump]\n\n*The pump*\n");
test('a table: a header row, pipes escaped', $md('<table><tr><th>a</th><th>b</th></tr><tr><td>1|2</td></tr></table>') === "| a | b |\n| --- | --- |\n| 1\\|2 |  |\n");
test('a rule, and a <br> as a hard line break', $md('<p>line one<br>line two</p><hr>') === "line one\\\nline two\n\n---\n");
test("text escaped only where Markdown would read it: \\ ` * _ [ ] <", $md('<p>snake_case *x* [a] &lt;b&gt; #1 1. ok</p>') === "snake\\_case \\*x\\* \\[a\\] \\<b> #1 1. ok\n");
test('inline runs between blocks become paragraphs', $md('<div>before<p>para</p>after</div>') === "before\n\npara\n\nafter\n");

// -----------------------------------------------------------------------------
section('interface lines');

$ui = '<article><p>Top highlight</p><p>Member-only story</p><h2>Listen</h2><p>Real text.</p><figure><img alt="x"><figcaption>Press enter or click to view image in full size</figcaption></figure><p>Share this, please.</p></article>';
test('DEFAULT_DROP_LINES: a paragraph or heading that is exactly one of them is left out', $md($ui) === "Real text.\n\n[image: x]\n\n*Press enter or click to view image in full size*\n\nShare this, please.\n");
test('...a caption is not a paragraph: kept, as written', str_contains($md($ui), 'Press enter'));
$none = new HtmlToMarkdownConverter([]);
test('an empty list drops nothing', $none->markdown('<article><p>Top highlight</p><p>Real text.</p></article>') === "Top highlight\n\nReal text.\n");
$custom = new HtmlToMarkdownConverter(['Advertisement']);
test('a list of its own replaces the default', $custom->markdown('<article><p>Advertisement</p><p>Top highlight</p></article>') === "Top highlight\n");

// -----------------------------------------------------------------------------
section('rendered back, it says what the article said');

if (class_exists(\League\CommonMark\MarkdownConverter::class)) {
    $article = '<article><h1>Title</h1><p>First <b>strong</b> point, with snake_case and a [bracket].</p><ul><li>item one</li><li>item two</li></ul><blockquote><p>A quote.</p></blockquote><pre><code>code_block()</code></pre></article>';
    $words   = static fn (string $t): string => implode(' ', preg_split('/\W+/u', mb_strtolower(html_entity_decode($t, ENT_QUOTES)), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    $rendered = (new MarkdownRenderer())->render($converter->markdown($article), 'page.md')->html();
    // Tags spaced out on both sides, so words of adjacent blocks stay apart.
    test('the same words, in the same order', $words(strip_tags(str_replace('<', ' <', $rendered))) === $words(strip_tags(str_replace('<', ' <', $article))));
} else {
    echo "  (skipped: league/commonmark absent)\n";
}

exit(summary());
