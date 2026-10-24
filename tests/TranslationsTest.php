<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Documents — translations that know which source they were made from
 *
 * A TranslationLayout says where a tree keeps its translations. With one, a
 * Projection's import records the source version the translator had on disk,
 * and links every document to its language and source; translations() then
 * says which translations fell behind. The source version is the one the
 * translator SAW — so a source changed in the tree after the checkout makes
 * the translation stale, even though the translator imported after it.
 *
 * Run: php src/Libs/Italix/Documents/tests/TranslationsTest.php
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

use Italix\Documents\DocumentsException;
use Italix\Documents\LanguageFiles;
use Italix\Documents\LanguageFolders;
use Italix\Documents\Library;
use Italix\Documents\Projection;
use Italix\Storage\LocalStore;

use function Italix\Testing\{suite, section, test, summary};

suite('Italix Documents — translations');

if (!extension_loaded('pdo_sqlite')) {
    echo "  SKIPPED — pdo_sqlite is absent.\n";
    exit(summary());
}

$base = sys_get_temp_dir() . '/ix-documents-translations-' . bin2hex(random_bytes(6));

/** True when the call throws DocumentsException. */
function translations_throws(callable $fn): bool
{
    try {
        $fn();
    } catch (DocumentsException) {
        return true;
    }

    return false;
}

/** @return array<string, string> path => state */
function translations_states(Library $library, int $tree_id): array
{
    return array_column($library->translations($tree_id), 'state_c', 'path_c');
}

try {
    // -------------------------------------------------------------------------
    section('LanguageFiles: one folder per document, one file per language');

    $files = new LanguageFiles('it');
    test('the source', $files->locate('books/smilla/it.md') === ['lang_c' => 'it', 'source_path_c' => null]);
    test('a translation names the source beside it', $files->locate('books/smilla/en.md') === ['lang_c' => 'en', 'source_path_c' => 'books/smilla/it.md']);
    test('a regional tag', $files->locate('books/smilla/de-CH.md') === ['lang_c' => 'de-CH', 'source_path_c' => 'books/smilla/it.md']);
    test('at the top of the tree', $files->locate('en.md') === ['lang_c' => 'en', 'source_path_c' => 'it.md']);
    test('meta.yaml is in no language', $files->locate('books/smilla/meta.yaml') === null);
    test('nor is notes.md, nor IT.md, nor en.txt', $files->locate('books/smilla/notes.md') === null
        && $files->locate('books/smilla/IT.md') === null && $files->locate('books/smilla/en.txt') === null);
    test('other extensions when asked', (new LanguageFiles('it', ['md', 'txt']))->locate('a/en.txt') === ['lang_c' => 'en', 'source_path_c' => 'a/it.txt']);
    test('a source language that is no tag is refused', translations_throws(fn () => new LanguageFiles('Italian')));

    // -------------------------------------------------------------------------
    section('LanguageFolders: one top-level folder per language');

    $folders = new LanguageFolders('en');
    test('the source', $folders->locate('en/guide/install.md') === ['lang_c' => 'en', 'source_path_c' => null]);
    test('a translation names the same path in the source folder', $folders->locate('it/guide/install.md') === ['lang_c' => 'it', 'source_path_c' => 'en/guide/install.md']);
    test('outside a language folder: none', $folders->locate('manual/install.md') === null && $folders->locate('it') === null);

    // -------------------------------------------------------------------------
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $library = new Library($pdo, new LocalStore($base . '/store', Library::suggested_path_strategy(), Library::suggested_catalog()));
    $library->install();
    $tree_id = $library->create_tree('site', 'Site');
    $work    = $base . '/work';

    $library->save($tree_id, 'books/smilla/it.md', "# Smilla\n");
    $library->save($tree_id, 'books/smilla/meta.yaml', "title: Smilla\n");
    $projection = new Projection($library, $work, new LanguageFiles('it'));
    $projection->checkout($tree_id);

    section('import(): a new translation is linked, made from the source on disk');

    file_put_contents($work . '/books/smilla/en.md', "# Smilla, in English\n");
    $projection->import($tree_id, 'translator');
    $en = $library->node($tree_id, 'books/smilla/en.md');
    $it = $library->node($tree_id, 'books/smilla/it.md');
    test('its language is recorded', $en->lang_code() === 'en');
    test('...and its source, by node id', $en->translation_of_node_id() === $it->id());
    test('the source gets its language too, and no source', $it->lang_code() === 'it' && $it->translation_of_node_id() === null);
    test('meta.yaml is left as it was', $library->node($tree_id, 'books/smilla/meta.yaml')->lang_code() === null);
    test('the version says which source version it translates', $library->version($en->latest_version_id())->source_version_id() === $it->latest_version_id());
    test('translations(): current', $library->translations($tree_id) === [[
        'path_c' => 'books/smilla/en.md', 'lang_c' => 'en', 'source_path_c' => 'books/smilla/it.md',
        'source_version_id' => $it->latest_version_id(), 'source_latest_version_id' => $it->latest_version_id(), 'state_c' => 'current',
    ]]);

    // -------------------------------------------------------------------------
    section('the source changes: the translation is stale');

    file_put_contents($work . '/books/smilla/it.md', "# Smilla\n\nUn paragrafo in più.\n");
    $projection->import($tree_id, 'editor');
    test('stale', translations_states($library, $tree_id) === ['books/smilla/en.md' => 'stale']);

    file_put_contents($work . '/books/smilla/en.md', "# Smilla, in English\n\nOne more paragraph.\n");
    $projection->import($tree_id, 'translator');
    test('translated again from the source on disk: current', translations_states($library, $tree_id) === ['books/smilla/en.md' => 'current']);

    // -------------------------------------------------------------------------
    section('both in one import: the source goes first');

    file_put_contents($work . '/books/smilla/it.md', "# Smilla\n\nDue paragrafi in più.\n");
    file_put_contents($work . '/books/smilla/en.md', "# Smilla, in English\n\nTwo more paragraphs.\n");
    $projection->import($tree_id, 'both');
    test('the translation is made from the version this import wrote: current', translations_states($library, $tree_id) === ['books/smilla/en.md' => 'current']
        && $library->version($library->node($tree_id, 'books/smilla/en.md')->latest_version_id())->source_version_id() === $library->node($tree_id, 'books/smilla/it.md')->latest_version_id());

    // -------------------------------------------------------------------------
    section('the source changed in the tree after the checkout');

    $library->save($tree_id, 'books/smilla/it.md', "# Smilla\n\nScritto da un altro, dopo il checkout.\n");
    file_put_contents($work . '/books/smilla/en.md', "# Smilla, in English\n\nTranslated from what I had.\n");
    $projection->import($tree_id, 'translator');
    test('made from the version the translator saw, not the latest: stale', translations_states($library, $tree_id) === ['books/smilla/en.md' => 'stale']);

    // -------------------------------------------------------------------------
    section('unknown, orphan, and a move');

    $library->save($tree_id, 'books/other/it.md', "# Altro\n");
    $library->save($tree_id, 'books/other/de.md', "# Anderes\n");
    test('apply_layout() links documents saved without the layout: the two new ones', $library->apply_layout($tree_id, new LanguageFiles('it')) === 2
        && $library->node($tree_id, 'books/other/de.md')->translation_of_node_id() === $library->node($tree_id, 'books/other/it.md')->id());
    test('...and a second run changes nothing', $library->apply_layout($tree_id, new LanguageFiles('it')) === 0);
    test('a translation saved without a source version: unknown', translations_states($library, $tree_id)['books/other/de.md'] === 'unknown');

    $library->move($tree_id, 'books/other', 'books/renamed');
    test('a move keeps the link: it is by node id', translations_states($library, $tree_id)['books/renamed/de.md'] === 'unknown'
        && $library->translations($tree_id)[0]['source_path_c'] === 'books/renamed/it.md');

    $library->remove($tree_id, 'books/renamed/it.md');
    $library->apply_layout($tree_id, new LanguageFiles('it'));
    test('the source removed: orphan, and apply_layout() keeps the link to say so', translations_states($library, $tree_id)['books/renamed/de.md'] === 'orphan');

    // -------------------------------------------------------------------------
    section('set_translation()');

    $library->set_translation($tree_id, 'books/smilla/meta.yaml', null, 'books/smilla/it.md');
    test('any document can be linked by hand', $library->node($tree_id, 'books/smilla/meta.yaml')->translation_of_node_id() === $library->node($tree_id, 'books/smilla/it.md')->id());
    $library->set_translation($tree_id, 'books/smilla/meta.yaml', null, null);
    test('...and unlinked', $library->node($tree_id, 'books/smilla/meta.yaml')->translation_of_node_id() === null);
    test('refused: no such document', translations_throws(fn () => $library->set_translation($tree_id, 'books/nope.md', 'en', null)));
    test('refused: a folder', translations_throws(fn () => $library->set_translation($tree_id, 'books/smilla', 'en', null)));
    test('refused: a source that does not exist', translations_throws(fn () => $library->set_translation($tree_id, 'books/smilla/en.md', 'en', 'books/nope.md')));
    test('refused: itself', translations_throws(fn () => $library->set_translation($tree_id, 'books/smilla/en.md', 'en', 'books/smilla/en.md')));

    // -------------------------------------------------------------------------
    section('without a layout, import() records nothing of this');

    $plain_tree = $library->create_tree('plain', 'Plain');
    $plain      = new Projection($library, $base . '/plain');
    $plain->checkout($plain_tree);
    mkdir($base . '/plain/a', 0775, true);
    file_put_contents($base . '/plain/a/it.md', "# A\n");
    file_put_contents($base . '/plain/a/en.md', "# A\n\nEnglish.\n");
    $plain->import($plain_tree);
    test('no language, no link, no source version', $library->node($plain_tree, 'a/en.md')->translation_of_node_id() === null
        && $library->version($library->node($plain_tree, 'a/en.md')->latest_version_id())->source_version_id() === null
        && $library->translations($plain_tree) === []);
} finally {
    if (is_dir($base)) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir((string) $f) : @unlink((string) $f);
        }
        @rmdir($base);
    }
}

exit(summary());
