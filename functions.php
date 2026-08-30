<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Documents - factory functions
 *
 * @package Italix\Documents
 */

declare(strict_types=1);

namespace Italix\Documents;

use Italix\Storage\FileStore;
use PDO;

if (!function_exists(__NAMESPACE__ . '\document_library')) {

    function document_library(PDO $pdo, FileStore $store, ?RendererSet $renderers = null): Library
    {
        return new Library($pdo, $store, $renderers);
    }
}

if (!function_exists(__NAMESPACE__ . '\projection')) {

    function projection(Library $library, string $root): Projection
    {
        return new Projection($library, $root);
    }
}
