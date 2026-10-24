<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Documents - TranslationLayout
 *
 * @package Italix\Documents
 */

declare(strict_types=1);

namespace Italix\Documents;

/**
 * Where a tree keeps its translations: which path is in which language, and
 * which path it is translated from. A convention of the tree, not of a file —
 * so it is given to the Projection that imports into the tree, which then
 * records it (Library::set_translation(), and `source_version_id` on every
 * translated version), and Library::translations() can say which translations
 * fell behind their source.
 *
 * Two are shipped: LanguageFiles (`guide/install/it.md` beside `en.md`) and
 * LanguageFolders (`it/guide/install.md` beside `en/…`).
 */
interface TranslationLayout
{
    /**
     * Null for a path that is in no language this layout knows; otherwise its
     * language and the path it translates — null for the source itself.
     *
     * @return array{lang_c: string, source_path_c: ?string}|null
     */
    public function locate(string $path_c): ?array;
}
