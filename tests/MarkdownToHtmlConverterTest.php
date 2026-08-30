<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Documents — MarkdownToHtmlConverter as a real italix/converters driver
 *
 * Exercises it two ways: directly (pairs, availability, the HTML it actually
 * produces) and wired into a real ConverterSet, so the thing under test is
 * genuinely "this driver cooperates with the router", not just "this class
 * has the right method names".
 *
 * Run: php src/Libs/Italix/Documents/tests/MarkdownToHtmlConverterTest.php
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
use Italix\Converters\ConverterSet;
use Italix\Converters\NoRouteException;
use Italix\Documents\MarkdownToHtmlConverter;

use function Italix\Testing\{suite, section, test, summary};

suite('Italix Documents — MarkdownToHtmlConverter');

if (!class_exists(\League\CommonMark\MarkdownConverter::class)) {
    echo "  SKIPPED — league/commonmark is absent.\n";
    exit(summary());
}

$driver = new MarkdownToHtmlConverter();

// -----------------------------------------------------------------------------
section('the driver describes itself honestly');

test('pairs() covers every Markdown extension MarkdownRenderer recognizes, all targeting html', $driver->pairs() === [
    ['md', 'html'],
    ['markdown', 'html'],
    ['mdown', 'html'],
    ['mkd', 'html'],
]);
test('is_available() is true — this app requires league/commonmark at the root', $driver->is_available());
test('describe() names both ends of the conversion', str_contains($driver->describe(), 'md') && str_contains($driver->describe(), 'html'));

// -----------------------------------------------------------------------------
section('convert() produces real HTML, not a stub');

$result = $driver->convert("# Hello\n\nA **bold** word.", 'md', 'html', ConversionOptions::none());

test('the extension is html', $result->extension_code() === 'html');
test('the mime type says so too', $result->mime() === 'text/html; charset=utf-8');
test('the heading is really rendered', str_contains($result->bytes(), '<h1>Hello</h1>'));
test('so is the bold span', str_contains($result->bytes(), '<strong>bold</strong>'));

// -----------------------------------------------------------------------------
section('the safety rules documented on MarkdownRenderer survive being routed through here');

$unsafe = $driver->convert('<script>alert(1)</script>', 'md', 'html', ConversionOptions::none());
test('raw HTML in the source is escaped, not passed through', !str_contains($unsafe->bytes(), '<script>'));
test('...specifically escaped, present as literal text', str_contains($unsafe->bytes(), '&lt;script&gt;'));

// -----------------------------------------------------------------------------
section('registered in a real ConverterSet, not just callable on its own');

$set = new ConverterSet([$driver]);

$route = $set->route('md', 'html');
test('a ConverterSet finds this driver for md -> html directly, one hop', count($route) === 1);
test('...naming this exact instance', $route[0][2] === $driver);

$routed = $set->convert("# Hi\n", 'md', 'html');
test('convert() through the set matches calling the driver directly', str_contains($routed->bytes(), '<h1>Hi</h1>'));

$threw = false;
try {
    $set->convert('irrelevant', 'html', 'md');
} catch (NoRouteException $e) {
    $threw = true;
    test('the reverse direction is honestly unsupported — this driver only goes one way', $e->reachable_extensions() === []);
}
test('html -> md has no route: MarkdownRenderer does not implement it, and this driver does not pretend to', $threw);

exit(summary());
