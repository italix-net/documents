<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Documents - LanguageFolders
 *
 * @package Italix\Documents
 */

declare(strict_types=1);

namespace Italix\Documents;

/**
 * One top-level folder per language, the same paths below it: `en/guide/install.md`
 * is translated as `it/guide/install.md` — what Docusaurus and DokuWiki settled
 * on, and the convention a new document's `lang_c` already follows. The folder
 * named after the source language holds the sources. A path outside a language
 * folder is in no language.
 */
final class LanguageFolders implements TranslationLayout
{
    private string $source_lang_c;

    public function __construct(string $source_lang_c)
    {
        if (preg_match(LanguageFiles::LANG_PATTERN, $source_lang_c) !== 1) {
            throw new DocumentsException("\"{$source_lang_c}\" is not a language tag like it, en or de-CH.");
        }

        $this->source_lang_c = $source_lang_c;
    }

    public function locate(string $path_c): ?array
    {
        $parts = explode('/', $path_c, 2);

        if (count($parts) !== 2 || preg_match(LanguageFiles::LANG_PATTERN, $parts[0]) !== 1) {
            return null;
        }

        return [
            'lang_c'        => $parts[0],
            'source_path_c' => $parts[0] === $this->source_lang_c ? null : $this->source_lang_c . '/' . $parts[1],
        ];
    }
}
