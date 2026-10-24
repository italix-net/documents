<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Documents - LanguageFiles
 *
 * @package Italix\Documents
 */

declare(strict_types=1);

namespace Italix\Documents;

/**
 * One folder per document, one file per language: `books/smilla/it.md`,
 * `books/smilla/en.md`, `books/smilla/de-CH.md`. The file named after the
 * source language is the source; every other language file of the folder
 * translates it. Files of other names (`meta.yaml`, `cover.jpg`) are in no
 * language.
 */
final class LanguageFiles implements TranslationLayout
{
    /** BCP 47 as sites use it: language, optional script, optional region. */
    public const LANG_PATTERN = '/^[a-z]{2,3}(-[A-Z][a-z]{3})?(-([A-Z]{2}|[0-9]{3}))?$/';

    private string $source_lang_c;

    /** @var list<string> */
    private array $extensions;

    /** @param list<string> $extensions the language files' extensions, without the dot */
    public function __construct(string $source_lang_c, array $extensions = ['md'])
    {
        if (preg_match(self::LANG_PATTERN, $source_lang_c) !== 1) {
            throw new DocumentsException("\"{$source_lang_c}\" is not a language tag like it, en or de-CH.");
        }

        $this->source_lang_c = $source_lang_c;
        $this->extensions    = array_values($extensions);
    }

    public function locate(string $path_c): ?array
    {
        $name      = Path::name_of($path_c);
        $extension = Path::extension_of($path_c);
        $lang_c    = $extension === '' ? $name : substr($name, 0, -strlen($extension) - 1);

        if (!in_array($extension, $this->extensions, true) || preg_match(self::LANG_PATTERN, $lang_c) !== 1) {
            return null;
        }

        if ($lang_c === $this->source_lang_c) {
            return ['lang_c' => $lang_c, 'source_path_c' => null];
        }

        $folder = Path::parent_of($path_c);
        $source = $this->source_lang_c . '.' . $extension;

        return ['lang_c' => $lang_c, 'source_path_c' => $folder === null ? $source : $folder . '/' . $source];
    }
}
