<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Documents - Projection
 *
 * @package Italix\Documents
 */

declare(strict_types=1);

namespace Italix\Documents;

/**
 * The tree as a real directory you can edit.
 *
 * ## Not the truth, and that is what makes it safe
 *
 * `italix/storage` refuses to let a caller name a file, which is its whole
 * safety model. A documentation tree is useless unless `docs/guide/install.md`
 * is a path that `grep`, `vim`, `rsync` and `lynx` can reach. Both hold because
 * this directory is a **projection**: written by the library, from content
 * already stored, with names taken from the database — never from an upload
 * header. Lose it and `checkout()` rebuilds it, which is why it never belongs in
 * a backup.
 *
 * It is git's working tree, and the index file underneath is git's index.
 *
 * ## Two projections, not one
 *
 * `checkout()` writes **sources** — the files you edit and import back.
 * `publish()` writes **rendered HTML** somewhere else entirely.
 *
 * Keeping them apart avoids a problem that looks small and is not: rendering
 * `install.md` to `install.html` beside it means `import` must then know which
 * files it wrote itself, and must decide what to do when somebody legitimately
 * adds a document called `install.html`. Two directories, and neither question
 * arises.
 */
final class Projection
{
    /** Records what the last publish wrote, so the next one can clean up. */
    public const PUBLISHED_MANIFEST = '.ix-published';

    private Library $library;
    private string $root;

    public function __construct(Library $library, string $root)
    {
        $real = realpath($root);

        if ($real === false) {
            if (!@mkdir($root, 0775, true) && !is_dir($root)) {
                throw new DocumentsException("Cannot create the projection root: {$root}");
            }

            $real = realpath($root);
        }

        if ($real === false || !is_dir($real)) {
            throw new DocumentsException("The projection root is not a directory: {$root}");
        }

        $this->library = $library;
        $this->root    = rtrim($real, '/');
    }

    public function root(): string
    {
        return $this->root;
    }

    // -------------------------------------------------------------------------
    // Checkout
    // -------------------------------------------------------------------------

    /**
     * Write every document of the tree to disk and record what was written.
     *
     * @return int documents written
     */
    public function checkout(int $tree_id): int
    {
        $index     = new ProjectionIndex($tree_id, gmdate('Y-m-d H:i:s'));
        $written_n = 0;

        foreach ($this->library->documents($tree_id) as $node) {
            $version_id = $node->latest_version_id();

            if ($version_id === null) {
                continue;
            }

            $contents = $this->library->read_version($version_id);

            if ($contents === null) {
                continue;
            }

            $absolute = $this->absolute($node->path());
            $this->ensure_directory(dirname($absolute));

            if (@file_put_contents($absolute, $contents) === false) {
                throw new DocumentsException("Cannot write: {$absolute}");
            }

            clearstatcache(true, $absolute);

            $index->record(
                $node->path(),
                $version_id,
                hash('sha256', $contents),
                strlen($contents),
                (int) filemtime($absolute)
            );

            $written_n++;
        }

        $index->save($this->root);

        return $written_n;
    }

    // -------------------------------------------------------------------------
    // Status
    // -------------------------------------------------------------------------

    /**
     * What differs between disk and tree.
     *
     * @return Change[]
     */
    public function status(int $tree_id): array
    {
        $index = $this->require_index($tree_id);
        $nodes = [];

        foreach ($this->library->documents($tree_id) as $node) {
            $nodes[$node->path()] = $node;
        }

        $changes = [];
        $seen    = [];

        foreach ($this->walk() as $path_c) {
            $seen[$path_c] = true;
            $absolute      = $this->absolute($path_c);
            $entry         = $index->entry($path_c);

            if ($entry === null) {
                $changes[] = new Change(Change::ADDED, $path_c);

                continue;
            }

            // Size and mtime are the cheap filter; the hash decides.
            //
            // The `< saved_t` guard is not optional. A file changed in the same
            // second the index was written keeps both its size and its mtime if
            // the replacement happens to be the same length — measured, with 32
            // bytes replaced by 32 other bytes — and the shortcut then reports
            // "unchanged" for a file that changed. git calls such entries
            // "racily clean" and re-hashes them; so does this.
            $bytes_n = (int) filesize($absolute);
            $mtime_t = (int) filemtime($absolute);

            if ($bytes_n === $entry['bytes_n']
                && $mtime_t === $entry['mtime_t']
                && $mtime_t < $index->saved_t()) {
                $changes[] = new Change(Change::UNCHANGED, $path_c);

                continue;
            }

            $sha256_c = hash_file('sha256', $absolute);

            if ($sha256_c === $entry['sha256_c']) {
                $changes[] = new Change(Change::UNCHANGED, $path_c);

                continue;
            }

            $node          = $nodes[$path_c] ?? null;
            $would_diverge = $node !== null
                && $node->latest_version_id() !== null
                && $node->latest_version_id() !== $entry['version_id'];

            $changes[] = new Change(Change::MODIFIED, $path_c, $would_diverge, $entry['version_id']);
        }

        // In the index but not on disk. Reported, never acted on — see Change.
        foreach ($index->paths() as $path_c) {
            if (!isset($seen[$path_c])) {
                $changes[] = new Change(Change::MISSING, $path_c, false, $index->entry($path_c)['version_id'] ?? null);
            }
        }

        usort($changes, static function (Change $a, Change $b): int {
            return strcmp($a->path(), $b->path());
        });

        return $changes;
    }

    // -------------------------------------------------------------------------
    // Import
    // -------------------------------------------------------------------------

    /**
     * Write changed files back into the tree.
     *
     * Last writer wins: whoever imports last sets `latest`. Nothing is
     * discarded — the version that was `latest` a moment ago is still there and
     * still readable — and a write made from a stale base is flagged
     * `is_divergent` rather than refused.
     *
     * Absent files are **not** deleted. See `Change::MISSING`.
     *
     * @return array{imported: string[], divergent: string[], missing: string[], unchanged_n: int}
     */
    public function import(int $tree_id, string $author_c = '', string $note_c = ''): array
    {
        $index  = $this->require_index($tree_id);
        $report = ['imported' => [], 'divergent' => [], 'missing' => [], 'unchanged_n' => 0];

        foreach ($this->status($tree_id) as $change) {
            $path_c = $change->path();

            if ($change->kind_code() === Change::UNCHANGED) {
                $report['unchanged_n']++;

                continue;
            }

            if ($change->kind_code() === Change::MISSING) {
                $report['missing'][] = $path_c;

                continue;
            }

            $absolute = $this->absolute($path_c);
            $contents = @file_get_contents($absolute);

            if ($contents === false) {
                throw new DocumentsException("Cannot read: {$absolute}");
            }

            $version = $this->library->save($tree_id, $path_c, $contents, [
                'author_c'          => $author_c,
                'note_c'            => $note_c,
                // The base the editor actually had. This is the whole reason the
                // index exists.
                'parent_version_id' => $change->base_version_id(),
            ]);

            clearstatcache(true, $absolute);

            $index->record(
                $path_c,
                $version->id(),
                $version->sha256(),
                (int) filesize($absolute),
                (int) filemtime($absolute)
            );

            $report['imported'][] = $path_c;

            if ($version->is_divergent()) {
                $report['divergent'][] = $path_c;
            }
        }

        $index->save($this->root);

        return $report;
    }

    // -------------------------------------------------------------------------
    // Publish
    // -------------------------------------------------------------------------

    /**
     * Render the tree to static HTML in a separate directory.
     *
     * The result is a static site: no PHP in the request path, readable by
     * `lynx`, servable by nginx from disk. Producing it is the same operation as
     * a checkout, which is why "publish the wiki" is not a subsystem.
     *
     * ## Stale pages are removed
     *
     * A document that was renamed or removed leaves a page behind, and a
     * publish that only ever writes keeps serving it forever. For a withdrawn
     * document that is not untidiness, it is a page that should not be readable
     * still being readable.
     *
     * So each run records what it wrote in `.ix-published`, and the next run
     * deletes the pages that were in the previous manifest and are not in the
     * new one. **Only those**: a file the library never wrote is never touched,
     * which is why this is a manifest rather than "delete every .html I did not
     * just write".
     *
     * @param bool $published_only skip nodes with `is_published` false
     *
     * @return int pages written
     */
    public function publish(int $tree_id, string $html_root, bool $published_only = false): int
    {
        $html_root = rtrim($html_root, '/');

        if (!is_dir($html_root) && !@mkdir($html_root, 0775, true) && !is_dir($html_root)) {
            throw new DocumentsException("Cannot create the publish root: {$html_root}");
        }

        $written_n = 0;
        $written   = [];

        foreach ($this->library->documents($tree_id) as $node) {
            if ($published_only && !$node->is_published()) {
                continue;
            }

            $version_id = $node->latest_version_id();

            if ($version_id === null) {
                continue;
            }

            $source   = (string) $this->library->read_version($version_id);
            $rendered = $this->library->renderers()->render($source, $node->path());

            $relative = Path::with_extension($node->path(), 'html');
            $out      = $html_root . '/' . $relative;
            $this->ensure_directory(dirname($out));

            if (@file_put_contents($out, $this->page($node, $rendered)) === false) {
                throw new DocumentsException("Cannot write: {$out}");
            }

            $written[] = $relative;
            $written_n++;
        }

        $this->prune_published($html_root, $written);

        return $written_n;
    }

    /**
     * Delete pages this library published before and did not publish now.
     *
     * @param string[] $written relative paths written by the run that just finished
     */
    private function prune_published(string $html_root, array $written): void
    {
        $manifest_path = $html_root . '/' . self::PUBLISHED_MANIFEST;
        $previous      = [];

        if (is_file($manifest_path)) {
            $raw = json_decode((string) file_get_contents($manifest_path), true);

            if (is_array($raw)) {
                $previous = array_map('strval', $raw['pages'] ?? []);
            }
        }

        foreach (array_diff($previous, $written) as $stale) {
            // The manifest is ours, but it is a file on disk and could have been
            // edited: the path is re-checked before anything is deleted.
            if (!Path::is_clean(preg_replace('~\.html$~', '', $stale) . '.html')) {
                continue;
            }

            @unlink($html_root . '/' . $stale);
        }

        $json = json_encode(
            ['written_dt' => gmdate('Y-m-d H:i:s'), 'pages' => array_values($written)],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        if (@file_put_contents($manifest_path, (string) $json) === false) {
            throw new DocumentsException("Cannot write the publish manifest: {$manifest_path}");
        }
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    private function page(Node $node, Rendered $rendered): string
    {
        $title = htmlspecialchars($node->name(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $lang  = htmlspecialchars($node->lang_code() ?? 'en', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return "<!doctype html>\n<html lang=\"{$lang}\">\n<head>\n<meta charset=\"utf-8\">\n"
            . "<title>{$title}</title>\n</head>\n<body>\n"
            . $this->link_extensions($rendered->html())
            . "\n</body>\n</html>\n";
    }

    /**
     * `href="install.md"` becomes `href="install.html"` in the published site.
     *
     * A pattern over markup, which this codebase generally refuses to do — the
     * difference is that this markup is **our own output**, produced moments ago
     * by our own renderer with raw HTML already escaped. There is no untrusted
     * input left to mis-parse. Doing it inside the renderer instead would make
     * the renderer know about publishing, which it should not.
     */
    private function link_extensions(string $html): string
    {
        return (string) preg_replace_callback(
            '~href="([^"]+)"~',
            static function (array $m): string {
                $href = $m[1];

                if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $href) === 1 || strpos($href, '//') === 0) {
                    return $m[0];   // absolute or protocol-relative: leave it alone
                }

                return 'href="' . preg_replace('~\.(md|markdown|mdown|mkd)($|#|\?)~', '.html$2', $href) . '"';
            },
            $html
        );
    }

    /**
     * Every file under the root, as tree-relative paths, excluding our own
     * state directory and anything whose name the tree would not accept.
     *
     * @return string[]
     */
    private function walk(): array
    {
        $paths    = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            if (!$item->isFile()) {
                continue;
            }

            $relative = substr((string) $item->getPathname(), strlen($this->root) + 1);

            if (strncmp($relative, ProjectionIndex::DIR_NAME . '/', strlen(ProjectionIndex::DIR_NAME) + 1) === 0) {
                continue;
            }

            // A name the tree would refuse is skipped rather than failing the
            // whole import: an editor's swap file should not stop a commit.
            if (Path::is_clean($relative)) {
                $paths[] = $relative;
            }
        }

        sort($paths);

        return $paths;
    }

    private function require_index(int $tree_id): ProjectionIndex
    {
        $index = ProjectionIndex::load($this->root);

        if ($index === null) {
            throw new DocumentsException(
                "There is no checkout at {$this->root}. Run documents:checkout first."
            );
        }

        if ($index->tree_id() !== $tree_id) {
            throw new DocumentsException(
                "This projection holds tree {$index->tree_id()}, not {$tree_id}."
            );
        }

        return $index;
    }

    private function absolute(string $path_c): string
    {
        // The path came from the database or from walking our own root, and both
        // are already constrained — but it is about to become a real file, so it
        // is checked where it is used rather than where it was produced.
        return $this->root . '/' . Path::clean($path_c);
    }

    private function ensure_directory(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new DocumentsException("Cannot create the directory: {$dir}");
        }
    }
}
