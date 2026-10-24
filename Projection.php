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

    /**
     * The asset types publish() copies into the site as they are. A
     * copied asset is a stored blob served raw — the one thing this library
     * otherwise never does — so only types that cannot carry script: no HTML,
     * no SVG, nothing the browser would run.
     */
    public const PUBLISHED_ASSET_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'application/pdf'];

    private Library $library;
    private string $root;
    private ?TranslationLayout $layout;

    /**
     * `$layout`, when the tree keeps translations: import() then records
     * which source version each translation was made from, and links every
     * document to its language and source (Library::apply_layout()).
     */
    public function __construct(Library $library, string $root, ?TranslationLayout $layout = null)
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
        $this->layout  = $layout;
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
     * A file the previous checkout of this tree wrote, that the tree no longer
     * has at that path — moved or removed since — is deleted when it is still
     * as written, so the next import does not bring it back. One edited since
     * is left alone; status() reports it as added.
     *
     * @return int documents written
     */
    public function checkout(int $tree_id): int
    {
        $previous  = ProjectionIndex::load($this->root);
        $previous  = $previous !== null && $previous->tree_id() === $tree_id ? $previous : null;
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

            $files = [$node->path() => $contents];
            foreach (array_keys($this->library->assets($version_id)) as $name_c) {
                $files[$node->path() . '.assets/' . $name_c] = (string) $this->library->read_asset($version_id, $name_c);
            }

            // The file and its folder, each file an entry of its own with the version's id.
            foreach ($files as $path_c => $bytes) {
                $absolute = $this->absolute($path_c);
                $this->ensure_directory(dirname($absolute));

                if (@file_put_contents($absolute, $bytes) === false) {
                    throw new DocumentsException("Cannot write: {$absolute}");
                }

                clearstatcache(true, $absolute);

                $index->record(
                    $path_c,
                    $version_id,
                    hash('sha256', $bytes),
                    strlen($bytes),
                    (int) filemtime($absolute)
                );
            }

            $written_n++;
        }

        foreach ($previous?->paths() ?? [] as $path_c) {
            $absolute = $this->absolute($path_c);

            if ($index->has($path_c) || !is_file($absolute) || !$this->is_unchanged($path_c, (array) $previous->entry($path_c), $previous)) {
                continue;
            }

            @unlink($absolute);

            // Up to the root, the folders this leaves empty.
            $dir = dirname($absolute);
            while (strlen($dir) > strlen($this->root) && @rmdir($dir)) {
                $dir = dirname($dir);
            }
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

        $files   = $this->walk();
        $on_disk = array_fill_keys($files, true);
        $changes = [];
        $seen    = [];
        $folders = [];   // a file on disk => the files of its `.assets/` folder on disk

        // A folder named `*.assets` belongs to the file beside it — or, with none, is an orphan.
        foreach ($files as $path_c) {
            $owner = Path::assets_owner_of($path_c);

            if ($owner === null) {
                continue;
            }

            if (isset($on_disk[$owner])) {
                $folders[$owner][] = $path_c;
            } elseif (!isset($seen[$owner . '.assets'])) {
                $seen[$owner . '.assets'] = true;
                $changes[] = new Change(Change::ORPHAN_ASSETS, $owner . '.assets');
            }
        }

        foreach ($files as $path_c) {
            if (Path::assets_owner_of($path_c) !== null) {
                continue;
            }

            $seen[$path_c] = true;
            $entry         = $index->entry($path_c);

            if ($entry === null) {
                $changes[] = new Change(Change::ADDED, $path_c);

                continue;
            }

            // The folder as the index has it, against the folder on disk: same files, each unchanged.
            $recorded = array_values(array_filter(
                $index->paths(),
                static fn (string $p): bool => str_starts_with($p, $path_c . '.assets/')
            ));
            $present  = $folders[$path_c] ?? [];
            sort($recorded);

            $is_unchanged = $this->is_unchanged($path_c, $entry, $index) && $recorded === $present;
            foreach ($is_unchanged ? $present : [] as $asset_path) {
                $is_unchanged = $is_unchanged && $this->is_unchanged($asset_path, (array) $index->entry($asset_path), $index);
            }

            if ($is_unchanged) {
                $changes[] = new Change(Change::UNCHANGED, $path_c);

                continue;
            }

            $node          = $nodes[$path_c] ?? null;
            $would_diverge = $node !== null
                && $node->latest_version_id() !== null
                && $node->latest_version_id() !== $entry['version_id'];

            $changes[] = new Change(Change::MODIFIED, $path_c, $would_diverge, $entry['version_id']);
        }

        // In the index but not on disk. Reported, never acted on — see Change. A bundle's folder is its file's.
        foreach ($index->paths() as $path_c) {
            if (!isset($seen[$path_c]) && Path::assets_owner_of($path_c) === null) {
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
     * A bundle is saved with every file now in its folder, and nothing else:
     * a file deleted from the folder is not in the new version. An
     * orphan folder is reported, never imported.
     *
     * With a TranslationLayout, a translation is saved with the source version
     * the translator had: the one this import writes, when the source was
     * edited too — sources go first — else the one the checkout wrote. A
     * source not checked out, nor imported now, leaves it unknown.
     *
     * @return array{imported: string[], divergent: string[], missing: string[], orphan_assets: string[], unchanged_n: int}
     */
    public function import(int $tree_id, string $author_c = '', string $note_c = ''): array
    {
        $index   = $this->require_index($tree_id);
        $report  = ['imported' => [], 'divergent' => [], 'missing' => [], 'orphan_assets' => [], 'unchanged_n' => 0];
        $files   = $this->walk();
        $changes = $this->status($tree_id);
        $place   = [];

        if ($this->layout !== null) {
            foreach ($changes as $change) {
                $place[$change->path()] = $this->layout->locate($change->path());
            }

            // Sources before translations, otherwise in path order as status() gives them.
            $is_translation = static fn (Change $c): int => ($place[$c->path()]['source_path_c'] ?? null) === null ? 0 : 1;
            usort($changes, static fn (Change $a, Change $b): int => [$is_translation($a), $a->path()] <=> [$is_translation($b), $b->path()]);
        }

        foreach ($changes as $change) {
            $path_c = $change->path();

            if ($change->kind_code() === Change::UNCHANGED) {
                $report['unchanged_n']++;

                continue;
            }

            if ($change->kind_code() === Change::MISSING) {
                $report['missing'][] = $path_c;

                continue;
            }

            if ($change->kind_code() === Change::ORPHAN_ASSETS) {
                $report['orphan_assets'][] = $path_c;

                continue;
            }

            $absolute = $this->absolute($path_c);
            $contents = @file_get_contents($absolute);

            if ($contents === false) {
                throw new DocumentsException("Cannot read: {$absolute}");
            }

            $assets = [];
            foreach ($files as $file_c) {
                if (str_starts_with($file_c, $path_c . '.assets/')) {
                    $bytes = @file_get_contents($this->absolute($file_c));

                    if ($bytes === false) {
                        throw new DocumentsException('Cannot read: ' . $this->absolute($file_c));
                    }
                    $assets[substr($file_c, strlen($path_c . '.assets/'))] = $bytes;
                }
            }

            $version = $this->library->save($tree_id, $path_c, $contents, [
                'assets'            => $assets,
                'author_c'          => $author_c,
                'note_c'            => $note_c,
                // The base the editor actually had. This is the whole reason the
                // index exists.
                'parent_version_id' => $change->base_version_id(),
            ] + $this->source_version_option($place[$path_c] ?? null, $index));

            clearstatcache(true, $absolute);

            $index->record(
                $path_c,
                $version->id(),
                $version->sha256(),
                (int) filesize($absolute),
                (int) filemtime($absolute)
            );

            foreach ($index->paths() as $recorded_c) {
                if (str_starts_with($recorded_c, $path_c . '.assets/')) {
                    $index->forget($recorded_c);
                }
            }
            foreach ($assets as $name_c => $bytes) {
                $asset_path = $this->absolute($path_c . '.assets/' . $name_c);
                clearstatcache(true, $asset_path);
                $index->record($path_c . '.assets/' . $name_c, $version->id(), hash('sha256', $bytes), strlen($bytes), (int) filemtime($asset_path));
            }

            $report['imported'][] = $path_c;

            if ($version->is_divergent()) {
                $report['divergent'][] = $path_c;
            }
        }

        $index->save($this->root);

        if ($this->layout !== null) {
            $this->library->apply_layout($tree_id, $this->layout);
        }

        return $report;
    }

    /**
     * The `source_version_id` for a translation: what the index holds for its
     * source now — this import's version if the source was just imported, the
     * checked-out one otherwise. Nothing for a source or an unplaced path.
     *
     * @param  array{lang_c: string, source_path_c: ?string}|null $place
     * @return array{source_version_id?: int}
     */
    private function source_version_option(?array $place, ProjectionIndex $index): array
    {
        $source_c = $place['source_path_c'] ?? null;
        $entry    = $source_c === null ? null : $index->entry($source_c);

        return $entry === null ? [] : ['source_version_id' => (int) $entry['version_id']];
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

            // The bundle's folder beside the page, where its relative links point — only what is safe raw.
            foreach ($this->library->assets($version_id) as $name_c => $asset) {
                if (!in_array($asset->mime(), self::PUBLISHED_ASSET_TYPES, true)) {
                    continue;
                }

                $relative_asset = $node->path() . '.assets/' . $name_c;
                $out            = $html_root . '/' . $relative_asset;
                $this->ensure_directory(dirname($out));

                if (@file_put_contents($out, (string) $this->library->read_asset($version_id, $name_c)) === false) {
                    throw new DocumentsException("Cannot write: {$out}");
                }

                $written[] = $relative_asset;
            }
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
            . $this->link_extensions($rendered->html(), $node)
            . "\n</body>\n</html>\n";
    }

    /**
     * `href="install.md"` becomes `href="install.html"` in the published site —
     * and a link to a document that has since moved follows it, by
     * `Library::moved_href()`: the rule `Library::relink()` applies to the
     * sources, applied here to the published page only.
     *
     * A pattern over markup, which this codebase generally refuses to do — the
     * difference is that this markup is **our own output**, produced moments ago
     * by our own renderer with raw HTML already escaped. There is no untrusted
     * input left to mis-parse. Doing it inside the renderer instead would make
     * the renderer know about publishing, which it should not.
     */
    private function link_extensions(string $html, Node $page): string
    {
        return (string) preg_replace_callback(
            '~href="([^"]+)"~',
            function (array $m) use ($page): string {
                $href = $m[1];

                if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $href) === 1 || strpos($href, '//') === 0) {
                    return $m[0];   // absolute or protocol-relative: leave it alone
                }

                $href = $this->library->moved_href($page->tree_id(), $page->path(), $href) ?? $href;

                return 'href="' . preg_replace('~\.(md|markdown|mdown|mkd)($|#|\?)~', '.html$2', $href) . '"';
            },
            $html
        );
    }

    /**
     * Whether the file at <path_c> is still what the index recorded.
     *
     * Size and mtime are the cheap filter; the hash decides.
     *
     * The `< saved_t` guard is not optional. A file changed in the same
     * second the index was written keeps both its size and its mtime if
     * the replacement happens to be the same length — measured, with 32
     * bytes replaced by 32 other bytes — and the shortcut then reports
     * "unchanged" for a file that changed. git calls such entries
     * "racily clean" and re-hashes them; so does this.
     *
     * @param array{version_id: int, sha256_c: string, bytes_n: int, mtime_t: int} $entry
     */
    private function is_unchanged(string $path_c, array $entry, ProjectionIndex $index): bool
    {
        $absolute = $this->absolute($path_c);
        $bytes_n  = (int) filesize($absolute);
        $mtime_t  = (int) filemtime($absolute);

        if ($bytes_n === $entry['bytes_n']
            && $mtime_t === $entry['mtime_t']
            && $mtime_t < $index->saved_t()) {
            return true;
        }

        return hash_file('sha256', $absolute) === $entry['sha256_c'];
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
            // So is a file named `*.assets`: that name is a bundle's folder.
            if (Path::is_clean($relative) && Path::assets_owner_of(Path::name_of($relative) . '/') === null) {
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
