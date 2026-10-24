<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Documents - Path
 *
 * @package Italix\Documents
 */

declare(strict_types=1);

namespace Italix\Documents;

/**
 * A path inside a tree: `en/guide/install.md`.
 *
 * Unlike `italix/storage`, this library *does* let a human choose the name —
 * that is the entire point of a documentation tree. So the name is checked here
 * rather than replaced, and the checks are the ones that matter when the name
 * later becomes a real file in a real directory (the projection, §2 of the
 * specification).
 *
 * Refused, and why:
 *
 * - `..` or `.` as a segment — traversal, the only one that is a security bug
 * - an empty segment (`a//b`) — resolves differently on different systems
 * - `/`, `\` or NUL inside a segment — a separator smuggled through a name
 * - a leading dot — hidden files, and `.ix-documents` is ours
 * - a leading dash — a name that reads as a command-line flag
 * - control characters — they survive a database and ruin a terminal
 *
 * Allowed: Unicode letters and digits, space, dot, dash, underscore. `città.md`
 * is a reasonable name for an Italian page and refusing it would be provincial.
 */
final class Path
{
    public const MAX_SEGMENT_N = 120;
    public const MAX_PATH_N    = 1024;

    private function __construct()
    {
    }

    /**
     * Normalise and validate. Returns the canonical form.
     *
     * @throws DocumentsException
     */
    public static function clean(string $path_c): string
    {
        $path_c = str_replace('\\', '/', trim($path_c));
        $path_c = trim($path_c, '/');

        if ($path_c === '') {
            throw new DocumentsException('A path cannot be empty.');
        }

        if (mb_strlen($path_c) > self::MAX_PATH_N) {
            throw new DocumentsException('That path is longer than ' . self::MAX_PATH_N . ' characters.');
        }

        $segments = explode('/', $path_c);

        foreach ($segments as $segment) {
            self::assert_segment($segment, $path_c);
        }

        return implode('/', $segments);
    }

    /**
     * True when the path is acceptable, without throwing — for callers that are
     * filtering rather than asserting.
     */
    public static function is_clean(string $path_c): bool
    {
        try {
            self::clean($path_c);

            return true;
        } catch (DocumentsException $e) {
            return false;
        }
    }

    public static function parent_of(string $path_c): ?string
    {
        $at = mb_strrpos($path_c, '/');

        return $at === false ? null : mb_substr($path_c, 0, $at);
    }

    public static function name_of(string $path_c): string
    {
        $at = mb_strrpos($path_c, '/');

        return $at === false ? $path_c : mb_substr($path_c, $at + 1);
    }

    /**
     * The extension, lowercased, without the dot. Empty when there is none.
     *
     * This is what chooses a renderer — deliberately *not* the sniffed MIME
     * type. Sniffing answers "may this be stored", which `italix/storage` has
     * already decided; the extension answers "how should this be read", which is
     * a different question with a different right answer. A `.md` file is
     * `text/plain` to `finfo` and Markdown to a reader.
     */
    public static function extension_of(string $path_c): string
    {
        $name = self::name_of($path_c);
        $at   = mb_strrpos($name, '.');

        return $at === false || $at === 0 ? '' : mb_strtolower(mb_substr($name, $at + 1));
    }

    /**
     * `en/guide/install.md` with a new extension, or with none.
     */
    public static function with_extension(string $path_c, string $extension_c): string
    {
        $parent = self::parent_of($path_c);
        $name   = self::name_of($path_c);
        $at     = mb_strrpos($name, '.');
        $stem   = $at === false || $at === 0 ? $name : mb_substr($name, 0, $at);

        return ($parent === null ? '' : $parent . '/') . $stem . ($extension_c === '' ? '' : '.' . $extension_c);
    }

    /**
     * Every ancestor folder, nearest first: `en/guide`, `en`.
     *
     * @return string[]
     */
    public static function ancestors_of(string $path_c): array
    {
        $out    = [];
        $parent = self::parent_of($path_c);

        while ($parent !== null) {
            $out[]  = $parent;
            $parent = self::parent_of($parent);
        }

        return $out;
    }

    /**
     * A relative link, as written on a page in <folder_c>, as a tree path — `.`
     * and `..` resolved. Null when it climbs out of the tree, starts at the
     * root (`/`), or is empty (a bare fragment). Not checked: the result is a
     * candidate to look up, and `is_clean()` says whether it can be one.
     */
    public static function resolve_link(?string $folder_c, string $link_c): ?string
    {
        if ($link_c === '' || $link_c[0] === '/') {
            return null;
        }

        $segments = $folder_c === null ? [] : explode('/', $folder_c);

        foreach (explode('/', $link_c) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($segments === []) {
                    return null;
                }
                array_pop($segments);

                continue;
            }
            $segments[] = $segment;
        }

        return $segments === [] ? null : implode('/', $segments);
    }

    /**
     * The relative link from a page in <folder_c> to the tree path <target_c>,
     * each segment URL-encoded — the inverse of resolve_link().
     */
    public static function link_to(?string $folder_c, string $target_c): string
    {
        $from = $folder_c === null ? [] : explode('/', $folder_c);
        $to   = explode('/', $target_c);

        while ($from !== [] && count($to) > 1 && $from[0] === $to[0]) {
            array_shift($from);
            array_shift($to);
        }

        return str_repeat('../', count($from)) . implode('/', array_map('rawurlencode', $to));
    }

    /**
     * The document whose bundle folder holds <path_c> — the path before its
     * first folder named `*.assets` — or null when no folder of it is one.
     * `notes.md.assets/img/pump.png` belongs to `notes.md`.
     */
    public static function assets_owner_of(string $path_c): ?string
    {
        return preg_match('~^((?:[^/]+/)*?[^/]+)\.assets/~', $path_c, $m) === 1 ? $m[1] : null;
    }

    private static function assert_segment(string $segment, string $path_c): void
    {
        if ($segment === '') {
            throw new DocumentsException("\"{$path_c}\" has an empty segment.");
        }

        if ($segment === '.' || $segment === '..') {
            throw new DocumentsException("\"{$path_c}\" contains \"{$segment}\", which is traversal.");
        }

        if (mb_strlen($segment) > self::MAX_SEGMENT_N) {
            throw new DocumentsException(
                "\"{$segment}\" is longer than " . self::MAX_SEGMENT_N . ' characters.'
            );
        }

        if (strpos($segment, "\0") !== false || preg_match('/[\x00-\x1F\x7F]/', $segment) === 1) {
            throw new DocumentsException("\"{$path_c}\" contains a control character.");
        }

        if ($segment[0] === '.') {
            throw new DocumentsException(
                "\"{$segment}\" starts with a dot: hidden names are reserved for the library."
            );
        }

        if ($segment[0] === '-') {
            throw new DocumentsException("\"{$segment}\" starts with a dash, which reads as a flag.");
        }

        if (preg_match('~^[\p{L}\p{N} ._-]+$~u', $segment) !== 1) {
            throw new DocumentsException(
                "\"{$segment}\" is not a usable name: letters, digits, space, dot, dash and underscore."
            );
        }

        if (trim($segment) !== $segment) {
            throw new DocumentsException("\"{$segment}\" has leading or trailing whitespace.");
        }
    }
}
