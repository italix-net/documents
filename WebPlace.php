<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Documents - WebPlace
 *
 * @package Italix\Documents
 */

declare(strict_types=1);

namespace Italix\Documents;

/**
 * Where a thing taken from the web goes in a tree, for SingleFileImporter
 * and DocumentImporter alike: `<host>/<yyyy-mm>/<name>`,
 * and a name taken by another URL left to it.
 *
 * @internal
 */
final class WebPlace
{
    public const SLUG_N = 100;

    private function __construct()
    {
    }

    /** The URL's host, lowercased — a mirror's own. */
    public static function host_of(string $url_c): string
    {
        $host = strtolower((string) parse_url($url_c, PHP_URL_HOST));

        if (preg_match('~^https?://~i', $url_c) !== 1 || $host === '' || !Path::is_clean($host)) {
            throw new DocumentsException("No usable host in the URL: \"{$url_c}\".");
        }

        return $host;
    }

    /** The month of an ISO 8601 time, in UTC — `2026-08` for 23:30 at -02:00 on 31 July. */
    public static function month_of(string $time_c): string
    {
        try {
            return (new \DateTimeImmutable($time_c))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m');
        } catch (\Exception $e) {
            throw new DocumentsException("Not a time: \"{$time_c}\".");
        }
    }

    /** The last non-empty segment of the URL's path, in the tree's characters, at most SLUG_N; else `index`. */
    public static function slug_of(string $url_c): string
    {
        $segments = array_values(array_filter(explode('/', (string) parse_url($url_c, PHP_URL_PATH)), static fn (string $s): bool => $s !== ''));
        $slug     = rawurldecode((string) end($segments));
        $slug     = (string) preg_replace('~[^\p{L}\p{N}._-]+~u', '-', $slug);
        $slug     = trim((string) preg_replace('~-{2,}~', '-', $slug), '-._ ');
        $slug     = trim(mb_substr($slug, 0, self::SLUG_N), '-._ ');

        return $slug === '' ? 'index' : $slug;
    }

    /**
     * <base>, or `<base>-2`, `-3`… — the first for which
     * `<folder>/<name><suffix>` is free, or already holds this URL's
     * original (its `manifest.json` says so). Never somebody else's.
     */
    public static function free_name(Library $library, int $tree_id, string $folder_c, string $base_c, string $suffix_c, string $url_c): string
    {
        for ($n = 1; $n <= 1000; $n++) {
            $candidate = $n === 1 ? $base_c : $base_c . '-' . $n;
            $node      = $library->node($tree_id, $folder_c . '/' . $candidate . $suffix_c);

            if ($node === null || $node->latest_version_id() === null) {
                return $candidate;
            }

            $manifest = json_decode((string) $library->read_asset($node->latest_version_id(), 'manifest.json'), true);
            if (is_array($manifest) && ($manifest['originalUrl'] ?? null) === $url_c) {
                return $candidate;
            }
        }

        throw new DocumentsException("A thousand originals already reduce to \"{$base_c}\" in {$folder_c}.");
    }

    /** The `manifest.json` an original carries when nothing gave it one — SingleFile's fields. */
    public static function manifest(string $url_c, string $title, string $time_c, ?string $index_c): string
    {
        $manifest = ['originalUrl' => $url_c, 'title' => $title, 'archiveTime' => $time_c];
        if ($index_c !== null) {
            $manifest['indexFilename'] = $index_c;
        }

        return (string) json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
