<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Documents - Library
 *
 * @package Italix\Documents
 */

declare(strict_types=1);

namespace Italix\Documents;

use Italix\Storage\FileStore;
use Italix\Storage\HashedPath;
use Italix\Storage\MimeCatalog;
use Italix\Storage\PathStrategy;
use PDO;

/**
 * The tree: folders, documents, versions.
 *
 * ## Where things live
 *
 * Bytes go into an `italix/storage` `FileStore`, content-addressed, where the
 * caller cannot choose a filename. Names, structure and history live in the
 * database. Neither half is authoritative on its own and the split is the point:
 * see §2 of `FRAMEWORK-DOCUMENTS.md`.
 *
 * ## Last writer wins, and nothing is discarded
 *
 * `save()` always appends a version and always moves `latest_version_id` to it.
 * That is safe because the previous version is still there, still extractable,
 * still exactly what it was.
 *
 * What plain last-writer-wins loses is the knowledge that two people wrote from
 * the same base. `parent_version_id` recovers it: pass the version you read, and
 * a write whose parent is no longer `latest` is **accepted and flagged**
 * `is_divergent`. It is git's fast-forward test with the refusal removed — no
 * merge machinery, no lost work, and the information is on record the day
 * somebody wants to build a merge.
 *
 * Passing `null` means "I am not claiming a base", and no check is made. The
 * projection always knows its base, so imports are always checked.
 */
final class Library
{
    private PDO $pdo;
    private FileStore $store;
    private RendererSet $renderers;
    private string $driver_c;

    /** @var callable():int */
    private $clock;

    public function __construct(
        PDO $pdo,
        FileStore $store,
        ?RendererSet $renderers = null,
        ?callable $clock = null
    ) {
        $this->pdo       = $pdo;
        $this->store     = $store;
        $this->renderers = $renderers ?? RendererSet::defaults();
        $this->driver_c  = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $this->clock     = $clock ?? static function (): int {
            return time();
        };
    }

    /**
     * The MIME catalogue a documentation tree actually needs.
     *
     * ## Why the default is not enough
     *
     * A source document is **prose that may quote anything**, and `finfo` sniffs
     * what is quoted. Found by testing rather than by reasoning:
     *
     * - an **empty file** is `application/x-empty` — a placeholder page with
     *   nothing in it yet is completely ordinary
     * - a short page whose body is a **JSON** or **XML** example sniffs as that
     * - a short page demonstrating **HTML markup** sniffs as `text/html`, and a
     *   documentation library that cannot hold a page about HTML is not a
     *   documentation library
     *
     * Longer pages sniff as `text/plain` because the prose dominates, which
     * makes this failure worse rather than better: it appears only sometimes,
     * on short pages, and looks arbitrary to whoever hits it.
     *
     * ## Why widening it is safe *here* and nowhere else
     *
     * The `italix/storage` default refuses `text/html` because an uploaded file
     * served back with `Content-Type: text/html` is stored XSS. **This library
     * never serves a stored blob.** Every read path goes through a `Renderer`,
     * and no shipped renderer passes raw markup through: `MarkdownRenderer`
     * escapes it (`html_input => 'escape'`) and `PlainRenderer` escapes
     * everything. A stored HTML document is published as visible text, never as
     * live markup — asserted by the test suite, not merely intended.
     *
     * **Do not copy this catalogue into a general upload endpoint.** It is safe
     * because of what this library does with the bytes, not because the bytes
     * are harmless.
     *
     * SVG is accepted (a drawing and its `.assets/`) on the same terms:
     * stored, versioned, checked out and archived, never served. A document is
     * published through its renderer, escaped like any other; as an asset,
     * `Projection::publish()` never copies it (PUBLISHED_ASSET_TYPES). An SVG
     * served as an image from the site's origin can run script — letting that
     * happen is the web server's decision (a `Content-Security-Policy`), and
     * this library does not take it for it.
     */
    public static function suggested_catalog(): MimeCatalog
    {
        return MimeCatalog::defaults()->with([
            'application/x-empty' => 'empty',
            'application/json'    => 'json',
            'text/markdown'       => 'md',
            'text/x-markdown'     => 'md',
            'text/html'           => 'html',
            'text/xml'            => 'xml',
            'application/xml'     => 'xml',
            // Containers the revision archive unpacks — LibreOffice's, an
            // EPUB, PowerPoint's when finfo names it — and the archive itself.
            'application/vnd.oasis.opendocument.text'         => 'odt',
            'application/vnd.oasis.opendocument.spreadsheet'  => 'ods',
            'application/vnd.oasis.opendocument.presentation' => 'odp',
            'application/vnd.oasis.opendocument.graphics'     => 'odg',
            'application/epub+zip'                            => 'epub',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
            'application/zstd'                                => 'zst',
            // Stored, never served as it is: see above.
            'image/svg+xml'                                   => 'svg',
            // A saved web page's fonts and AVIF images (SingleFileImporter): stored, and never
            // copied by publish() either — not in Projection::PUBLISHED_ASSET_TYPES.
            'font/woff'                                       => 'woff',
            'font/woff2'                                      => 'woff2',
            'image/avif'                                      => 'avif',
        ]);
    }

    /**
     * The path strategy a store holding documents should use: **no sharding**.
     *
     * `save()` already places every revision of one document in its own folder —
     * `tree/{tree}/{bucket}/{node}` — so the store has nothing left to spread.
     * Sharding on top of that creates directories to hold tens of files and, far
     * worse, scatters a document's history across them, which is precisely what
     * the per-document layout exists to prevent.
     *
     * Offered as a method rather than left in a docblock because the mistake is
     * silent: a store built with the default `HashedPath(2)` works perfectly and
     * only reveals itself when somebody tries to pack a document's history.
     */
    public static function suggested_path_strategy(): PathStrategy
    {
        return new HashedPath(0);
    }

    public function renderers(): RendererSet
    {
        return $this->renderers;
    }

    public function store(): FileStore
    {
        return $this->store;
    }

    // -------------------------------------------------------------------------
    // Schema
    // -------------------------------------------------------------------------

    /**
     * Create the tables if absent. Idempotent.
     *
     * A method rather than a migration, matching `italix/crypto`: this library
     * ships no migration runner and an application that prefers its own writes
     * one against this shape.
     */
    public function install(): void
    {
        $is_mysql = $this->driver_c === 'mysql';
        $pk       = $is_mysql ? 'BIGINT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $big      = $is_mysql ? 'BIGINT' : 'INTEGER';
        $engine   = $is_mysql ? ' ENGINE=InnoDB' : '';

        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS ix_doc_tree (
                id {$pk},
                slug_c VARCHAR(120) NOT NULL,
                name_c VARCHAR(200) NOT NULL,
                owner_c VARCHAR(191) NOT NULL DEFAULT '',
                insert_dt VARCHAR(19) NOT NULL
            ){$engine}"
        );
        $this->index('ix_doc_tree_slug', 'ix_doc_tree', ['slug_c'], true);

        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS ix_doc_node (
                id {$pk},
                tree_id {$big} NOT NULL,
                parent_id {$big} NULL,
                kind_c VARCHAR(10) NOT NULL,
                name_c VARCHAR(191) NOT NULL,
                path_c VARCHAR(1024) NOT NULL,
                latest_version_id {$big} NULL,
                lang_c VARCHAR(16) NULL,
                translation_of_node_id {$big} NULL,
                sort_n INTEGER NOT NULL DEFAULT 0,
                is_published INTEGER NOT NULL DEFAULT 1,
                insert_dt VARCHAR(19) NOT NULL,
                update_dt VARCHAR(19) NOT NULL,
                delete_dt VARCHAR(19) NULL
            ){$engine}"
        );
        // path_c is only unique among live nodes, so the index cannot enforce
        // it — a deleted `install.md` must not stop a new one being created.
        $this->index('ix_doc_node_path', 'ix_doc_node', ['tree_id', 'path_c'], false, ['path_c' => 191]);
        $this->index('ix_doc_node_parent', 'ix_doc_node', ['parent_id']);

        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS ix_doc_version (
                id {$pk},
                node_id {$big} NOT NULL,
                parent_version_id {$big} NULL,
                storage_path_c VARCHAR(512) NOT NULL,
                sha256_c VARCHAR(64) NOT NULL,
                mime_c VARCHAR(100) NOT NULL,
                bytes_n {$big} NOT NULL,
                author_c VARCHAR(191) NOT NULL DEFAULT '',
                note_c VARCHAR(500) NOT NULL DEFAULT '',
                is_divergent INTEGER NOT NULL DEFAULT 0,
                source_version_id {$big} NULL,
                archive_path_c VARCHAR(512) NULL,
                insert_dt VARCHAR(19) NOT NULL
            ){$engine}"
        );
        $this->index('ix_doc_version_node', 'ix_doc_version', ['node_id', 'id']);

        // Present and unused until phase 3. Added now because `install()` on an
        // existing table cannot introduce it later without a migration, and this
        // library ships no migration runner — the same reason `lang_c` and
        // `source_version_id` are already here.
        //
        // The contract, so phase 3 has nothing to invent:
        //
        //   archive_path_c IS NULL   the blob is loose at storage_path_c
        //   archive_path_c IS SET    the blob is an entry inside that archive,
        //                            named by the basename of storage_path_c
        //
        // Which keeps `storage_path_c` meaningful in both states and makes
        // packing reversible: unpack, clear the column, nothing else moves.
        $this->add_column('ix_doc_version', 'archive_path_c', 'VARCHAR(512) NULL');

        // Bundles: a version's `.assets/` files, one row each, and what makes
        // two saves of a bundle the same version — null for every version
        // without assets, which is every version written before this.
        $this->add_column('ix_doc_version', 'assets_sha256_c', 'VARCHAR(64) NULL');

        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS ix_doc_asset (
                id {$pk},
                version_id {$big} NOT NULL,
                name_c VARCHAR(1024) NOT NULL,
                storage_path_c VARCHAR(512) NOT NULL,
                sha256_c VARCHAR(64) NOT NULL,
                mime_c VARCHAR(100) NOT NULL,
                bytes_n {$big} NOT NULL
            ){$engine}"
        );
        $this->index('ix_doc_asset_version', 'ix_doc_asset', ['version_id']);

        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS ix_doc_alias (
                id {$pk},
                tree_id {$big} NOT NULL,
                path_c VARCHAR(1024) NOT NULL,
                node_id {$big} NOT NULL,
                insert_dt VARCHAR(19) NOT NULL
            ){$engine}"
        );
        $this->index('ix_doc_alias_path', 'ix_doc_alias', ['tree_id', 'path_c'], false, ['path_c' => 191]);
    }

    /**
     * Add a column to an existing table, if it is missing.
     *
     * `CREATE TABLE IF NOT EXISTS` does nothing to a table that already exists,
     * so a schema that grows would silently leave older installations behind —
     * and `install()` promising idempotency has to mean "brings the schema up to
     * date", not "creates it the first time".
     *
     * `ALTER TABLE … ADD COLUMN` has no portable `IF NOT EXISTS`, so a duplicate
     * is caught the same way a duplicate index is.
     */
    private function add_column(string $table_c, string $column_c, string $definition_c): void
    {
        try {
            $this->pdo->exec("ALTER TABLE {$table_c} ADD COLUMN {$column_c} {$definition_c}");
        } catch (\PDOException $e) {
            if (strpos($e->getMessage(), 'uplicate') === false
                && strpos($e->getMessage(), 'already exists') === false) {
                throw $e;
            }
        }
    }

    /**
     * Where one document's blobs live: `tree/{tree}/{bucket}/{node}`.
     *
     * Every revision of a document lands in **one folder**, which is what phase
     * 3's per-document `tar.zst` archive needs — packing, verifying and then
     * dropping the loose blobs all become one subtree operation instead of a
     * scatter across 256 directories driven by content hash.
     *
     * The bucket exists for the same reason `BucketedPath` does: without it, the
     * folder holding the documents grows one entry per document forever, and a
     * manual with fifty thousand pages has the listing problem one level up.
     * `node_id % 256` spreads sequential ids perfectly evenly and stays
     * computable from the id alone.
     *
     * The bucket sits **inside** `tree/{tree}` rather than above it, so a whole
     * tree is still exactly one subtree — the property that makes moving or
     * deleting a tree an `rsync` of one path.
     *
     * **The cost, stated plainly:** content addressing no longer deduplicates
     * *across* documents. Two pages with byte-identical content used to share
     * one blob and now do not. Within a document nothing is lost — `save()`
     * already returns the existing version when the bytes match — and in a
     * documentation tree cross-page duplication is mostly empty placeholders.
     * That is a small, known price for making the archive possible.
     *
     * The store should therefore use `HashedPath(0)`: this folder is already
     * per-document, so sharding inside it would create directories for tens of
     * files.
     */
    private function folder_for(int $tree_id, int $node_id): string
    {
        return sprintf('tree/%d/%02x/%d', $tree_id, $node_id % 256, $node_id);
    }

    /**
     * Create an index, portably.
     *
     * Two dialect differences meet here, and both bite silently:
     *
     * - MySQL cannot index a long `VARCHAR` without a **prefix length**
     *   (`path(191)`), and SQLite parses that same syntax as a function call
     *   and fails with "no such function: path_c".
     * - `CREATE INDEX IF NOT EXISTS` works on SQLite and MariaDB but **not on
     *   MySQL**, so `install()` would stop being idempotent on the one engine
     *   most likely to be running in production.
     *
     * So the prefix is added only for MySQL, and a duplicate index is caught
     * rather than announced — which is what "idempotent" has to mean here.
     *
     * @param string[]             $columns
     * @param array<string, int>   $prefix_n column => prefix length, MySQL only
     */
    private function index(
        string $name_c,
        string $table_c,
        array $columns,
        bool $is_unique = false,
        array $prefix_n = []
    ): void {
        $is_mysql = $this->driver_c === 'mysql';

        $parts = array_map(
            static function (string $column) use ($is_mysql, $prefix_n): string {
                return $is_mysql && isset($prefix_n[$column])
                    ? $column . '(' . $prefix_n[$column] . ')'
                    : $column;
            },
            $columns
        );

        $sql = 'CREATE ' . ($is_unique ? 'UNIQUE ' : '') . 'INDEX '
             . ($is_mysql ? '' : 'IF NOT EXISTS ')
             . $name_c . ' ON ' . $table_c . ' (' . implode(', ', $parts) . ')';

        try {
            $this->pdo->exec($sql);
        } catch (\PDOException $e) {
            // "Duplicate key name" (MySQL 1061) is the idempotent path. Anything
            // else is a real problem and must not be swallowed.
            if (strpos($e->getMessage(), 'uplicate') === false
                && strpos($e->getMessage(), 'already exists') === false) {
                throw $e;
            }
        }
    }

    // -------------------------------------------------------------------------
    // Trees
    // -------------------------------------------------------------------------

    public function create_tree(string $slug_c, string $name_c, string $owner_c = ''): int
    {
        if (preg_match('~^[a-z0-9][a-z0-9-]{0,119}$~', $slug_c) !== 1) {
            throw new DocumentsException(
                "\"{$slug_c}\" is not a usable tree slug: lowercase letters, digits and dashes."
            );
        }

        if ($this->tree_id_for($slug_c) !== null) {
            throw new DocumentsException("A tree named \"{$slug_c}\" already exists.");
        }

        $st = $this->pdo->prepare(
            'INSERT INTO ix_doc_tree (slug_c, name_c, owner_c, insert_dt) VALUES (?, ?, ?, ?)'
        );
        $st->execute([$slug_c, $name_c, $owner_c, $this->now_dt()]);

        return (int) $this->pdo->lastInsertId();
    }

    public function tree_id_for(string $slug_c): ?int
    {
        $st = $this->pdo->prepare('SELECT id FROM ix_doc_tree WHERE slug_c = ?');
        $st->execute([$slug_c]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : (int) $row['id'];
    }

    /**
     * @return array<int, array{id: int, slug_c: string, name_c: string}>
     */
    public function trees(): array
    {
        $out = [];

        foreach ($this->pdo->query('SELECT id, slug_c, name_c FROM ix_doc_tree ORDER BY slug_c') as $row) {
            $out[] = ['id' => (int) $row['id'], 'slug_c' => (string) $row['slug_c'], 'name_c' => (string) $row['name_c']];
        }

        return $out;
    }

    // -------------------------------------------------------------------------
    // Reading
    // -------------------------------------------------------------------------

    public function node(int $tree_id, string $path_c): ?Node
    {
        $st = $this->pdo->prepare(
            'SELECT * FROM ix_doc_node WHERE tree_id = ? AND path_c = ? AND delete_dt IS NULL'
        );
        $st->execute([$tree_id, Path::clean($path_c)]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : Node::from_row($row);
    }

    public function node_by_id(int $node_id): ?Node
    {
        $st = $this->pdo->prepare('SELECT * FROM ix_doc_node WHERE id = ?');
        $st->execute([$node_id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : Node::from_row($row);
    }

    /**
     * Every live document in the tree, folders excluded, in path order.
     *
     * @return Node[]
     */
    public function documents(int $tree_id): array
    {
        $st = $this->pdo->prepare(
            'SELECT * FROM ix_doc_node
              WHERE tree_id = ? AND kind_c = ? AND delete_dt IS NULL
              ORDER BY path_c'
        );
        $st->execute([$tree_id, Node::KIND_DOCUMENT]);

        return array_map(
            static function (array $row): Node {
                return Node::from_row($row);
            },
            $st->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    public function version(int $version_id): ?Version
    {
        $st = $this->pdo->prepare('SELECT * FROM ix_doc_version WHERE id = ?');
        $st->execute([$version_id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : Version::from_row($row);
    }

    /**
     * Newest first.
     *
     * @return Version[]
     */
    public function history(int $node_id): array
    {
        $st = $this->pdo->prepare('SELECT * FROM ix_doc_version WHERE node_id = ? ORDER BY id DESC');
        $st->execute([$node_id]);

        return array_map(
            static function (array $row): Version {
                return Version::from_row($row);
            },
            $st->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    /** The current contents, or null when there is no such document. */
    public function read(int $tree_id, string $path_c): ?string
    {
        $node = $this->node($tree_id, $path_c);

        if ($node === null || $node->latest_version_id() === null) {
            return null;
        }

        return $this->read_version($node->latest_version_id());
    }

    public function read_version(int $version_id): ?string
    {
        $version = $this->version($version_id);

        if ($version === null) {
            return null;
        }

        if ($version->is_archived()) {
            return $this->read_archived($version);
        }

        $bytes = $this->store->read($version->storage_path());

        // The row survived and the blob did not: a broken deployment, not a
        // missing document, and worth telling apart from `null` for "no such
        // path".
        if ($bytes === null) {
            throw new DocumentsException(
                "Version {$version_id} points at missing storage: {$version->storage_path()}"
            );
        }

        return $bytes;
    }

    /**
     * A version's assets — the files of its `.assets/` folder — by name,
     * sorted. Empty for a version without assets, or no such version.
     *
     * @return array<string, Asset>
     */
    public function assets(int $version_id): array
    {
        $st = $this->pdo->prepare('SELECT * FROM ix_doc_asset WHERE version_id = ? ORDER BY id');
        $st->execute([$version_id]);

        $assets = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $assets[(string) $row['name_c']] = Asset::from_row($row);
        }
        ksort($assets, SORT_STRING);

        return $assets;
    }

    /**
     * One asset's bytes, loose or archived — or null when the version has no
     * asset of that name.
     */
    public function read_asset(int $version_id, string $name_c): ?string
    {
        $version = $this->version($version_id);
        $asset   = $version === null ? null : ($this->assets($version_id)[$name_c] ?? null);

        if ($version === null || $asset === null) {
            return null;
        }

        if ($version->is_archived()) {
            return $this->read_archived_asset($version, $asset);
        }

        $bytes = $this->store->read($asset->storage_path());

        if ($bytes === null) {
            throw new DocumentsException(
                "Asset \"{$name_c}\" of version {$version_id} points at missing storage: {$asset->storage_path()}"
            );
        }

        return $bytes;
    }

    /**
     * Writes a version — any kind, loose or archived — into <dir>: the file,
     * under its document's name, and its `.assets/` folder when it has one.
     * <dir> must be empty or absent: nothing is overwritten.
     *
     * @return list<string> the paths written, relative to <dir>
     * @throws DocumentsException no such version, a directory that is not empty, or a file that cannot be written
     */
    public function checkout_version(int $version_id, string $dir): array
    {
        $version = $this->version($version_id);
        $node    = $version === null ? null : $this->node_by_id($version->node_id());

        if ($node === null) {
            throw new DocumentsException("There is no version {$version_id}.");
        }

        if (is_dir($dir) && (new \FilesystemIterator($dir))->valid()) {
            throw new DocumentsException("Cannot check out version {$version_id} into {$dir}: it is not empty.");
        }

        $files = [$node->name() => (string) $this->read_version($version_id)];
        foreach (array_keys($this->assets($version_id)) as $name_c) {
            $files[$node->name() . '.assets/' . $name_c] = (string) $this->read_asset($version_id, $name_c);
        }

        foreach ($files as $relative => $bytes) {
            $file = rtrim($dir, '/') . '/' . $relative;

            if ((!is_dir(dirname($file)) && !@mkdir(dirname($file), 0775, true) && !is_dir(dirname($file)))
                || @file_put_contents($file, $bytes) === false) {
                throw new DocumentsException("Cannot write: {$file}");
            }
        }

        return array_keys($files);
    }

    public function render(int $tree_id, string $path_c): ?Rendered
    {
        $source = $this->read($tree_id, $path_c);

        return $source === null ? null : $this->renderers->render($source, Path::clean($path_c));
    }

    /** The node a former path used to name, for a `301`. */
    public function node_for_alias(int $tree_id, string $path_c): ?Node
    {
        $st = $this->pdo->prepare(
            'SELECT node_id FROM ix_doc_alias WHERE tree_id = ? AND path_c = ? ORDER BY id DESC'
        );
        $st->execute([$tree_id, Path::clean($path_c)]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->node_by_id((int) $row['node_id']);
    }

    /**
     * Where a relative link on the page <page_path_c> should point, when the
     * path it names is a former path of a document that has moved: that
     * document's path now, relative to the page, fragment and query kept.
     * Null when there is nothing to follow — an absolute URL, a link out of
     * the tree, a path a live document holds (it wins over an alias of the
     * same name), no alias, or an alias whose document was removed since.
     * What publish() follows on the page it writes, and relink() in the source.
     */
    public function moved_href(int $tree_id, string $page_path_c, string $href): ?string
    {
        if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $href) === 1 || strpos($href, '//') === 0) {
            return null;
        }

        $split  = strcspn($href, '#?');
        $folder = Path::parent_of($page_path_c);
        $target = Path::resolve_link($folder, rawurldecode(substr($href, 0, $split)));

        if ($target === null || !Path::is_clean($target) || $this->node($tree_id, $target) !== null) {
            return null;
        }

        $moved = $this->node_for_alias($tree_id, $target);
        $asset = '';

        // A file of a moved bundle's folder, which moved with it.
        $owner = Path::assets_owner_of($target);
        if ($moved === null && $owner !== null && $this->node($tree_id, $owner) === null) {
            $name_c = substr($target, strlen($owner) + strlen('.assets/'));
            $moved  = $this->node_for_alias($tree_id, $owner);
            $asset  = '.assets/' . $name_c;

            if ($moved !== null && !isset($this->assets((int) $moved->latest_version_id())[$name_c])) {
                return null;
            }
        }

        if ($moved === null || $this->node($tree_id, $moved->path()) === null) {
            return null;
        }

        return Path::link_to($folder, $moved->path() . $asset) . substr($href, $split);
    }

    // -------------------------------------------------------------------------
    // Writing
    // -------------------------------------------------------------------------

    /**
     * Save contents at a path, creating the document and its folders if needed.
     *
     * @param array{
     *     author_c?: string,
     *     note_c?: string,
     *     parent_version_id?: int|null,
     *     lang_c?: string|null,
     *     source_version_id?: int|null,
     *     assets?: array<string, string>
     * } $options
     *
     * `assets` — name => bytes — is the version's whole `.assets/` folder:
     * what is not in it is not in the version, and `[]` means none. Without
     * the key the latest version's assets are carried forward, so a caller
     * editing only the text (relink()) never drops a page's images.
     *
     * @return Version the version written, or the existing one when the bytes
     *                 — the file's and its assets' — are identical: an
     *                 unchanged save is not a revision
     */
    public function save(int $tree_id, string $path_c, string $contents, array $options = []): Version
    {
        $path_c = Path::clean($path_c);
        $assets = array_key_exists('assets', $options) ? self::clean_assets((array) $options['assets']) : null;
        $node   = $this->node($tree_id, $path_c);
        $is_new = $node === null;
        $node ??= $this->create_document($tree_id, $path_c, $options);

        if ($node->is_folder()) {
            throw new DocumentsException("\"{$path_c}\" is a folder.");
        }

        $sha256_c = hash('sha256', $contents);
        $latest   = $node->latest_version_id() === null ? null : $this->version($node->latest_version_id());

        $assets_sha256_c = $assets === null
            ? $latest?->assets_sha256()
            : self::assets_digest(array_map(static fn (string $bytes): string => hash('sha256', $bytes), $assets));

        // Identical bytes are not a new revision. Content addressing gives this
        // for free and it is what keeps `import` idempotent: running it twice
        // over an unchanged tree writes nothing. For a bundle, identical means
        // the file and every asset.
        if ($latest !== null && $latest->sha256() === $sha256_c && $latest->assets_sha256() === $assets_sha256_c) {
            return $latest;
        }

        $claimed_parent_id = $options['parent_version_id'] ?? null;

        // A write is divergent when the writer named a base and that base is no
        // longer where the document is. Naming no base makes no claim, so it is
        // not divergence — it is simply unchecked, and the projection always
        // names one.
        $is_divergent = $claimed_parent_id !== null
            && $node->latest_version_id() !== null
            && $claimed_parent_id !== $node->latest_version_id();

        $folder = $this->folder_for($tree_id, $node->id());
        $rows   = [];   // [name, storage path, sha256, mime, bytes_n]

        try {
            if ($assets === null) {
                foreach ($latest === null ? [] : $this->assets($latest->id()) as $asset) {
                    // An archived version's blobs are no longer loose — stored again, from its archive.
                    $blob_path_c = $latest->is_archived()
                        ? $this->store->put_contents($folder, (string) $this->read_asset($latest->id(), $asset->name()), Path::name_of($asset->name()))->path()
                        : $asset->storage_path();
                    $rows[] = [$asset->name(), $blob_path_c, $asset->sha256(), $asset->mime(), $asset->bytes_n()];
                }
            }

            // The assets first: one the catalogue refuses stops the save before the file is stored.
            foreach ($assets ?? [] as $name_c => $bytes) {
                $blob   = $this->store->put_contents($folder, $bytes, Path::name_of($name_c));
                $rows[] = [$name_c, $blob->path(), hash('sha256', $bytes), $blob->mime(), strlen($bytes)];
            }

            $stored = $this->store->put_contents($folder, $contents, Path::name_of($path_c));
        } catch (\Throwable $e) {
            // A document this save created, whose first version the store refused: not left behind empty.
            if ($is_new) {
                $this->pdo->prepare('DELETE FROM ix_doc_node WHERE id = ? AND latest_version_id IS NULL')->execute([$node->id()]);
            }

            throw $e;
        }

        // The version and its assets together — inside the caller's transaction when there is one.
        $is_own = !$this->pdo->inTransaction();
        if ($is_own) {
            $this->pdo->beginTransaction();
        }

        try {
            $version_id = $this->insert_version($node, $stored, $sha256_c, strlen($contents), $claimed_parent_id, $is_divergent, $assets_sha256_c, $options);

            $in = $this->pdo->prepare(
                'INSERT INTO ix_doc_asset (version_id, name_c, storage_path_c, sha256_c, mime_c, bytes_n) VALUES (?, ?, ?, ?, ?, ?)'
            );
            foreach ($rows as $row) {
                $in->execute([$version_id, ...$row]);
            }

            $up = $this->pdo->prepare('UPDATE ix_doc_node SET latest_version_id = ?, update_dt = ? WHERE id = ?');
            $up->execute([$version_id, $this->now_dt(), $node->id()]);

            if ($is_own) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($is_own) {
                $this->pdo->rollBack();
            }

            throw $e;
        }

        $version = $this->version($version_id);

        if ($version === null) {
            throw new DocumentsException('The version disappeared immediately after being written.');
        }

        return $version;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function insert_version(
        Node $node,
        \Italix\Storage\StoredFile $stored,
        string $sha256_c,
        int $bytes_n,
        ?int $claimed_parent_id,
        bool $is_divergent,
        ?string $assets_sha256_c,
        array $options
    ): int {
        $st = $this->pdo->prepare(
            'INSERT INTO ix_doc_version
                (node_id, parent_version_id, storage_path_c, sha256_c, mime_c, bytes_n,
                 author_c, note_c, is_divergent, source_version_id, assets_sha256_c, insert_dt)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $st->execute([
            $node->id(),
            $claimed_parent_id ?? $node->latest_version_id(),
            $stored->path(),
            $sha256_c,
            $stored->mime(),
            $bytes_n,
            (string) ($options['author_c'] ?? ''),
            mb_substr((string) ($options['note_c'] ?? ''), 0, 500),
            $is_divergent ? 1 : 0,
            $options['source_version_id'] ?? null,
            $assets_sha256_c,
            $this->now_dt(),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * The asset names checked with the tree's rules, sorted. Refused: a name
     * the tree would refuse, one named twice, and one that is also another's
     * folder (`img` beside `img/pump.png` cannot both be on a disk).
     *
     * @param  array<array-key, mixed> $assets
     * @return array<string, string>
     */
    private static function clean_assets(array $assets): array
    {
        $clean = [];
        foreach ($assets as $name => $bytes) {
            $name_c = Path::clean((string) $name);

            if (isset($clean[$name_c])) {
                throw new DocumentsException("The asset \"{$name_c}\" is named twice.");
            }
            $clean[$name_c] = (string) $bytes;
        }
        ksort($clean, SORT_STRING);

        foreach (array_keys($clean) as $name_c) {
            foreach (array_keys($clean) as $other_c) {
                if (str_starts_with((string) $other_c, $name_c . '/')) {
                    throw new DocumentsException("The asset \"{$name_c}\" is also the folder of \"{$other_c}\".");
                }
            }
        }

        return $clean;
    }

    /**
     * Null for no assets; otherwise the sha256 of `name TAB sha256`
     * lines, in the order given — sorted by name, as clean_assets() returns them.
     *
     * @param array<string, string> $sha256s name => sha256
     */
    private static function assets_digest(array $sha256s): ?string
    {
        if ($sha256s === []) {
            return null;
        }

        $lines = '';
        foreach ($sha256s as $name_c => $sha256_c) {
            $lines .= $name_c . "\t" . $sha256_c . "\n";
        }

        return hash('sha256', $lines);
    }

    /**
     * Rename or move. The former path becomes an alias, so links and URLs that
     * used it still resolve — the same treatment a stale media slug gets.
     *
     * A folder moves with everything live inside it, at any depth: each node
     * keeps its id and leaves its own former path as an alias. One transaction.
     */
    public function move(int $tree_id, string $from_c, string $to_c): Node
    {
        $from_c = Path::clean($from_c);
        $to_c   = Path::clean($to_c);

        $node = $this->node($tree_id, $from_c);

        if ($node === null) {
            throw new DocumentsException("There is no \"{$from_c}\" in this tree.");
        }

        if ($this->node($tree_id, $to_c) !== null) {
            throw new DocumentsException("\"{$to_c}\" already exists.");
        }

        if (str_starts_with($to_c, $from_c . '/')) {
            throw new DocumentsException("\"{$from_c}\" cannot move inside itself.");
        }

        self::assert_not_reserved($to_c);

        for ($above = Path::parent_of($to_c); $above !== null; $above = Path::parent_of($above)) {
            if ($this->node($tree_id, $above)?->is_folder() === false) {
                throw new DocumentsException("\"{$above}\" is a document, not a folder.");
            }
        }

        $now    = $this->now_dt();
        $inside = $node->is_folder() ? $this->paths_inside($tree_id, $from_c) : [];

        $this->pdo->beginTransaction();
        try {
            $parent_id = $this->ensure_folders($tree_id, $to_c);

            $st = $this->pdo->prepare(
                'UPDATE ix_doc_node SET path_c = ?, name_c = ?, parent_id = ?, update_dt = ? WHERE id = ?'
            );
            $st->execute([$to_c, Path::name_of($to_c), $parent_id, $now, $node->id()]);

            $al = $this->pdo->prepare(
                'INSERT INTO ix_doc_alias (tree_id, path_c, node_id, insert_dt) VALUES (?, ?, ?, ?)'
            );
            $al->execute([$tree_id, $from_c, $node->id(), $now]);

            // Inside the folder only the paths change: every parent_id still holds.
            $path = $this->pdo->prepare('UPDATE ix_doc_node SET path_c = ?, update_dt = ? WHERE id = ?');
            foreach ($inside as $id => $path_c) {
                $path->execute([$to_c . substr($path_c, strlen($from_c)), $now, $id]);
                $al->execute([$tree_id, $path_c, $id, $now]);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }

        $moved = $this->node_by_id($node->id());

        if ($moved === null) {
            throw new DocumentsException('The node disappeared immediately after being moved.');
        }

        return $moved;
    }

    /**
     * Point the sources at where moved documents are now: in every Markdown
     * document of the tree, each relative link or image destination that names
     * a former path (moved_href()) is rewritten, and the document saved as a
     * new version — `author_c` from the options, `note_c` saying what changed
     * unless given, its latest version as the parent, so never divergent. The
     * aliases stay: links from outside the tree still need them.
     *
     * The rewrite is textual and then proven (MarkdownLinks): the new source's
     * destinations must be the old ones with exactly the planned replacements,
     * and its HTML, every href and src blanked, the old one's. A document that
     * fails either is skipped and reported — never saved. `dry_run` reports
     * without saving. A second run finds nothing to do.
     *
     * @param  array{author_c?: string, note_c?: string, dry_run?: bool} $options
     * @return array{rewritten: array<string, list<array{0: string, 1: string}>>, skipped: array<string, string>, unchanged_n: int}
     */
    public function relink(int $tree_id, array $options = []): array
    {
        $report = ['rewritten' => [], 'skipped' => [], 'unchanged_n' => 0];

        foreach ($this->documents($tree_id) as $node) {
            if (!$this->renderers->for_extension($node->extension_code()) instanceof MarkdownRenderer) {
                continue;
            }

            $source = (string) $this->read($tree_id, $node->path());
            $map    = [];

            foreach (MarkdownLinks::destinations($source) as $destination) {
                $moved = $this->moved_href($tree_id, $node->path(), $destination);

                if ($moved !== null) {
                    $map[$destination] = $moved;
                }
            }

            if ($map === []) {
                $report['unchanged_n']++;

                continue;
            }

            $rewritten = MarkdownLinks::rewrite($source, $map);
            $expected  = array_map(static fn (string $d): string => $map[$d] ?? $d, MarkdownLinks::destinations($source));

            if (MarkdownLinks::destinations($rewritten) !== $expected) {
                $report['skipped'][$node->path()] = 'the text could not be rewritten to exactly the planned destinations';

                continue;
            }

            if (self::without_targets($this->renderers->render($rewritten, $node->path())->html())
                !== self::without_targets($this->renderers->render($source, $node->path())->html())
            ) {
                $report['skipped'][$node->path()] = 'the rewrite would change more than link destinations';

                continue;
            }

            $changes = [];
            foreach ($map as $from => $to) {
                $changes[] = [$from, $to];
            }
            $report['rewritten'][$node->path()] = $changes;

            if (!($options['dry_run'] ?? false)) {
                $this->save($tree_id, $node->path(), $rewritten, [
                    'author_c'          => $options['author_c'] ?? '',
                    'note_c'            => $options['note_c'] ?? 'relink: ' . implode(', ', array_map(static fn (array $c): string => "{$c[0]} -> {$c[1]}", $changes)),
                    'parent_version_id' => $node->latest_version_id(),
                ]);
            }
        }

        return $report;
    }

    /** <html> with every href and src value blanked — what must not change when only destinations do. */
    private static function without_targets(string $html): string
    {
        return (string) preg_replace('~\b(href|src)="[^"]*"~', '$1=""', $html);
    }

    // -------------------------------------------------------------------------
    // The revision archive
    // -------------------------------------------------------------------------

    /**
     * Pack the old versions of the tree's documents: for each document with
     * at least `min_old_versions_n` versions besides the latest (default 5)
     * — or, for a document whose latest version, file and assets, is more
     * than `large_bytes_n` bytes (default 400 KB), at least
     * `large_min_old_versions_n` (default 2: packed from its third version;
     * `large_bytes_n` null turns this off) — every one of them goes into one
     * `tar.zst` of that document —
     * a container unpacked into its entries when its rebuild is proven, whole
     * otherwise; entries ordered by name, then version. The latest
     * version is never touched.
     *
     * Per document: the archive is written to the store, read back and
     * checked entry by entry; only then are its versions marked archived, in
     * one transaction; only then are the loose blobs and the previous archive
     * deleted. A document that fails anywhere is skipped and reported,
     * with nothing marked and nothing deleted. A blob the latest version still
     * uses (a revert) is never deleted. `dry_run` builds every archive and
     * reports its size without storing anything.
     *
     * Politeness is the caller's: this does the whole tree, now.
     *
     * @param  array{min_old_versions_n?: int, large_bytes_n?: int|null, large_min_old_versions_n?: int, dry_run?: bool} $options
     * @return array{packed: array<string, array{versions_n: int, unpacked_n: int, whole_n: int, loose_bytes_n: int, archive_bytes_n: int}>, skipped: array<string, string>, below_threshold_n: int, unchanged_n: int}
     * @throws DocumentsException tar or zstd missing
     */
    public function pack(int $tree_id, array $options = []): array
    {
        if (!RevisionArchive::is_available()) {
            throw new DocumentsException('Packing needs the tar and zstd commands on PATH.');
        }

        $minimum_n       = max(1, (int) ($options['min_old_versions_n'] ?? 5));
        $large_bytes_n   = array_key_exists('large_bytes_n', $options) ? $options['large_bytes_n'] : 400 * 1024;
        $large_minimum_n = max(1, (int) ($options['large_min_old_versions_n'] ?? 2));
        $dry_run         = (bool) ($options['dry_run'] ?? false);
        $report    = ['packed' => [], 'skipped' => [], 'below_threshold_n' => 0, 'unchanged_n' => 0];

        foreach ($this->documents($tree_id) as $node) {
            $old = array_values(array_filter(
                $this->history($node->id()),
                static fn (Version $v): bool => $v->id() !== $node->latest_version_id()
            ));

            // A large document — its latest version, file and assets — from fewer old versions.
            $is_large = $large_bytes_n !== null && $this->latest_bytes_n($node) > (int) $large_bytes_n;

            if (count($old) < ($is_large ? $large_minimum_n : $minimum_n)) {
                $report['below_threshold_n']++;

                continue;
            }

            if (array_filter($old, static fn (Version $v): bool => !$v->is_archived()) === []) {
                $report['unchanged_n']++;

                continue;
            }

            $work = sys_get_temp_dir() . '/ix-documents-pack-' . bin2hex(random_bytes(6));
            mkdir($work, 0700, true);

            try {
                $report['packed'][$node->path()] = $this->pack_document($tree_id, $node, array_reverse($old), $work, $dry_run);
            } catch (DocumentsException | \Italix\Storage\StorageException $e) {
                $report['skipped'][$node->path()] = $e->getMessage();
            } finally {
                RevisionArchive::remove_tree($work);
            }
        }

        return $report;
    }

    /** The latest version's bytes: the file and, for a bundle, its assets. */
    private function latest_bytes_n(Node $node): int
    {
        $latest = $node->latest_version_id() === null ? null : $this->version($node->latest_version_id());

        if ($latest === null) {
            return 0;
        }

        $bytes_n = $latest->bytes_n();
        foreach ($this->assets($latest->id()) as $asset) {
            $bytes_n += $asset->bytes_n();
        }

        return $bytes_n;
    }

    /**
     * @param  list<Version> $old oldest first
     * @return array{versions_n: int, unpacked_n: int, whole_n: int, loose_bytes_n: int, archive_bytes_n: int}
     */
    private function pack_document(int $tree_id, Node $node, array $old, string $work, bool $dry_run): array
    {
        $stats   = ['versions_n' => count($old), 'unpacked_n' => 0, 'whole_n' => 0, 'loose_bytes_n' => 0, 'archive_bytes_n' => 0];
        $members = $this->archive_members($old, $work, $stats);
        $archive = RevisionArchive::write($members, $work);
        $stats['archive_bytes_n'] = strlen($archive);

        if ($dry_run) {
            return $stats;
        }

        $previous = array_values(array_unique(array_filter(array_map(static fn (Version $v): ?string => $v->archive_path(), $old))));
        $stored_c = $this->store_archive($this->folder_for($tree_id, $node->id()), $archive, $members, $old, $work);

        // Only now: the loose blobs — never one the latest version still uses, file or asset — and the
        // archives nothing refers to any more.
        $latest = $this->version((int) $node->latest_version_id());
        $keep   = [];
        if ($latest !== null) {
            $keep[$latest->storage_path()] = true;
            foreach ($this->assets($latest->id()) as $asset) {
                $keep[$asset->storage_path()] = true;
            }
        }
        $this->delete_loose($old, $keep);
        $this->delete_unreferenced_archives($previous, $stored_c);

        return $stats;
    }

    /**
     * Pack a folder cold: every version of every document under
     * <folder_c> — **the latest included** — into one archive of that folder.
     * The members are named by their content, so a file many documents carry
     * (a site's font) is one member; read_version() and read_asset() read a
     * shared archive as they read a document's own. Rewritten whole at each
     * call, proven and marked as pack() does, then every loose blob of
     * those versions deleted, and every archive nothing refers to any more.
     * `dry_run` builds the archive and reports without storing.
     *
     * An explicit call on one folder, never a property of the tree: pack()
     * leaves the latest version loose everywhere else. Politeness is the
     * caller's.
     *
     * @param  array{dry_run?: bool} $options
     * @return array{documents_n: int, versions_n: int, unpacked_n: int, whole_n: int, loose_bytes_n: int, archive_bytes_n: int, is_unchanged: bool}
     * @throws DocumentsException no such folder, tar or zstd missing, or an archive that fails its check
     */
    public function pack_folder(int $tree_id, string $folder_c, array $options = []): array
    {
        if (!RevisionArchive::is_available()) {
            throw new DocumentsException('Packing needs the tar and zstd commands on PATH.');
        }

        $folder_c = Path::clean($folder_c);
        $folder   = $this->node($tree_id, $folder_c);

        if ($folder === null || !$folder->is_folder()) {
            throw new DocumentsException("There is no folder \"{$folder_c}\" in this tree.");
        }

        $nodes    = array_values(array_filter($this->documents($tree_id), static fn (Node $n): bool => str_starts_with($n->path(), $folder_c . '/')));
        $versions = [];
        foreach ($nodes as $node) {
            array_push($versions, ...array_reverse($this->history($node->id())));
        }

        $stats = ['documents_n' => count($nodes), 'versions_n' => count($versions), 'unpacked_n' => 0, 'whole_n' => 0, 'loose_bytes_n' => 0, 'archive_bytes_n' => 0, 'is_unchanged' => false];
        $archives     = array_values(array_unique(array_map(static fn (Version $v): string => (string) $v->archive_path(), $versions)));
        $store_folder = sprintf('tree/%d/folders/%d', $tree_id, $folder->id());

        // Nothing loose, and all of it in this folder's own archive already — not a parent folder's: unchanged.
        if ($versions === [] || (count($archives) === 1 && str_starts_with($archives[0], $store_folder . '/'))) {
            $stats['is_unchanged'] = true;

            return $stats;
        }

        $work = sys_get_temp_dir() . '/ix-documents-pack-' . bin2hex(random_bytes(6));
        mkdir($work, 0700, true);

        try {
            $members = $this->archive_members($versions, $work, $stats);
            $archive = RevisionArchive::write($members, $work);
            $stats['archive_bytes_n'] = strlen($archive);

            if (!($options['dry_run'] ?? false)) {
                $stored_c = $this->store_archive($store_folder, $archive, $members, $versions, $work);
                $this->delete_loose($versions, []);
                $this->delete_unreferenced_archives(array_values(array_filter($archives)), $stored_c);
            }
        } finally {
            RevisionArchive::remove_tree($work);
        }

        return $stats;
    }

    /**
     * The archive's members for <versions>, in the order that makes equal content adjacent: each
     * version's file whole or unpacked, each asset once by its blob. Adds to <stats>' counts.
     *
     * @param  list<Version> $versions
     * @param  array<string, int|bool> $stats
     * @return list<array{0: string, 1: string}>
     */
    private function archive_members(array $versions, string $work, array &$stats): array
    {
        $rows = [];   // [sort key, version position, member path, bytes]
        $seen = [];

        foreach ($versions as $position => $version) {
            $stats['loose_bytes_n'] += $version->bytes_n();

            // Each asset once, by its blob — whose name is its content — sorted by its name.
            foreach ($this->assets($version->id()) as $name_c => $asset) {
                $stats['loose_bytes_n'] += $asset->bytes_n();
                $asset_member = 'assets/' . basename($asset->storage_path());

                if (!isset($seen[$asset_member])) {
                    $seen[$asset_member] = true;
                    $rows[] = ['assets/' . $name_c, $position, $asset_member, (string) $this->read_asset($version->id(), $name_c)];
                }
            }

            $member = basename($version->storage_path());

            // Same basename, same bytes: the store names blobs by their hash.
            if (isset($seen[$member])) {
                continue;
            }
            $seen[$member] = true;

            $bytes    = (string) $this->read_version($version->id());
            $exploded = RevisionArchive::explode($bytes, $work);

            if ($exploded === null) {
                $rows[] = ['', $position, $member, $bytes];
                $stats['whole_n']++;

                continue;
            }

            $rows[] = ['recipe.json', $position, $member . '/recipe.json', (string) json_encode($exploded['recipe'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)];
            foreach ($exploded['entries'] as $name => $content) {
                if (!str_ends_with($name, '/')) {
                    $rows[] = ['entries/' . $name, $position, $member . '/entries/' . $name, $content];
                }
            }
            $stats['unpacked_n']++;
        }

        // Equal content adjacent, whatever the document's size.
        usort($rows, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return array_map(static fn (array $r): array => [$r[2], $r[3]], $rows);
    }

    /**
     * <archive> into the store under <store_folder>, read back and checked member by member, then
     * <versions> marked as inside it, in one transaction. A failure discards it, unless something already
     * refers to those very bytes, and leaves every version as it was.
     *
     * @param  list<array{0: string, 1: string}> $members
     * @param  list<Version>                     $versions
     * @return string the archive's path in the store
     */
    private function store_archive(string $store_folder, string $archive, array $members, array $versions, string $work): string
    {
        $stored = $this->store->put_contents($store_folder, $archive, 'versions.tar.zst');

        try {
            // What the store holds, read back, entry by entry.
            $check = $work . '/check';
            RevisionArchive::extract((string) $this->store->read($stored->path()), [], $check);
            foreach ($members as [$member, $bytes]) {
                if (!is_file($check . '/' . $member) || hash_file('sha256', $check . '/' . $member) !== hash('sha256', $bytes)) {
                    throw new DocumentsException("The archive does not hold \"{$member}\" as written.");
                }
            }
        } catch (\Throwable $e) {
            $this->delete_unreferenced_archives([$stored->path()], null);

            throw $e instanceof DocumentsException ? $e : new DocumentsException($e->getMessage());
        }

        $ids = array_map(static fn (Version $v): int => $v->id(), $versions);

        $this->pdo->beginTransaction();
        try {
            $st = $this->pdo->prepare(
                'UPDATE ix_doc_version SET archive_path_c = ? WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'
            );
            $st->execute([$stored->path(), ...$ids]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            $this->delete_unreferenced_archives([$stored->path()], null);

            throw $e;
        }

        return $stored->path();
    }

    /**
     * The loose blobs of <versions> — file and assets — that were loose before this pack, except <keep>.
     *
     * @param list<Version>        $versions as they were before being marked
     * @param array<string, bool>  $keep     storage paths still in use
     */
    private function delete_loose(array $versions, array $keep): void
    {
        foreach ($versions as $version) {
            if ($version->is_archived()) {
                continue;
            }
            $loose = [$version->storage_path()];
            foreach ($this->assets($version->id()) as $asset) {
                $loose[] = $asset->storage_path();
            }
            foreach ($loose as $path) {
                if (!isset($keep[$path])) {
                    $this->store->delete($path);
                    $keep[$path] = true;   // deleted once
                }
            }
        }
    }

    /**
     * Each of <paths> but <except> deleted — only when no version refers to it any more. An archive
     * can be shared (pack_folder()), so "the previous archive of this document" is not enough.
     *
     * @param list<string> $paths
     */
    private function delete_unreferenced_archives(array $paths, ?string $except): void
    {
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM ix_doc_version WHERE archive_path_c = ?');

        foreach ($paths as $path) {
            if ($path === $except) {
                continue;
            }
            $st->execute([$path]);

            if ((int) $st->fetchColumn() === 0) {
                $this->store->delete($path);
            }
        }
    }

    /**
     * An archived version's bytes: its member extracted from the
     * archive — the file itself, or a container rebuilt from its recipe and
     * entries, every entry checked against the recipe.
     *
     * @throws DocumentsException the archive missing, or not holding the version as recorded
     */
    private function read_archived(Version $version): string
    {
        $archive = $this->store->read((string) $version->archive_path());

        if ($archive === null) {
            throw new DocumentsException("Version {$version->id()} points at a missing archive: {$version->archive_path()}");
        }

        $member = basename($version->storage_path());
        $work   = sys_get_temp_dir() . '/ix-documents-read-' . bin2hex(random_bytes(6));

        try {
            RevisionArchive::extract($archive, [$member], $work);
            $path = $work . '/' . $member;

            if (is_file($path)) {
                $bytes = (string) file_get_contents($path);

                if (hash('sha256', $bytes) !== $version->sha256()) {
                    throw new DocumentsException("Version {$version->id()} does not come back from its archive as saved.");
                }

                return $bytes;
            }

            $recipe = json_decode((string) @file_get_contents($path . '/recipe.json'), true);

            if (!is_array($recipe) || ($recipe['format'] ?? null) !== RevisionArchive::RECIPE_FORMAT) {
                throw new DocumentsException("Version {$version->id()} has no readable recipe in its archive.");
            }

            return RevisionArchive::rebuild(
                $recipe,
                static fn (string $name): string => (string) file_get_contents($path . '/entries/' . $name),
                $work
            );
        } finally {
            RevisionArchive::remove_tree($work);
        }
    }

    /**
     * An archived version's asset: `assets/<blob basename>` extracted and
     * checked against its row.
     *
     * @throws DocumentsException the archive missing, or not holding the asset as recorded
     */
    private function read_archived_asset(Version $version, Asset $asset): string
    {
        $archive = $this->store->read((string) $version->archive_path());

        if ($archive === null) {
            throw new DocumentsException("Version {$version->id()} points at a missing archive: {$version->archive_path()}");
        }

        $member = 'assets/' . basename($asset->storage_path());
        $work   = sys_get_temp_dir() . '/ix-documents-read-' . bin2hex(random_bytes(6));

        try {
            RevisionArchive::extract($archive, [$member], $work);
            $bytes = (string) @file_get_contents($work . '/' . $member);

            if (hash('sha256', $bytes) !== $asset->sha256()) {
                throw new DocumentsException("Asset \"{$asset->name()}\" of version {$version->id()} does not come back from its archive as saved.");
            }

            return $bytes;
        } finally {
            RevisionArchive::remove_tree($work);
        }
    }

    /**
     * Soft delete. The versions stay, so the history of a removed document is
     * still readable and the document can be restored by its id.
     */
    public function remove(int $tree_id, string $path_c): void
    {
        $node = $this->node($tree_id, $path_c);

        if ($node === null) {
            throw new DocumentsException('There is no "' . Path::clean($path_c) . '" in this tree.');
        }

        // A folder takes everything inside it, at any depth.
        $ids = [$node->id(), ...array_keys($node->is_folder() ? $this->paths_inside($tree_id, $node->path()) : [])];

        $st = $this->pdo->prepare(
            'UPDATE ix_doc_node SET delete_dt = ? WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'
        );
        $st->execute([$this->now_dt(), ...$ids]);
    }

    /**
     * The live nodes below a folder, at any depth: id => path. The prefix is
     * compared in PHP too, because a MySQL collation may compare it case-blind.
     *
     * @return array<int, string>
     */
    private function paths_inside(int $tree_id, string $folder_c): array
    {
        $prefix = $folder_c . '/';
        $st     = $this->pdo->prepare(
            'SELECT id, path_c FROM ix_doc_node WHERE tree_id = ? AND delete_dt IS NULL AND SUBSTR(path_c, 1, ?) = ?'
        );
        $st->execute([$tree_id, mb_strlen($prefix), $prefix]);

        $paths = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (str_starts_with($row['path_c'], $prefix)) {
                $paths[(int) $row['id']] = $row['path_c'];
            }
        }

        return $paths;
    }

    // -------------------------------------------------------------------------
    // Translations
    // -------------------------------------------------------------------------

    /**
     * Record a document's language and the document it translates — null for
     * none, which makes it a source (or a document outside any translation).
     * Recorded by node id, so the link survives a move of either. Writes only
     * when something changes; `update_dt` is left alone, as a renumber leaves it.
     */
    public function set_translation(int $tree_id, string $path_c, ?string $lang_c, ?string $source_path_c): void
    {
        $node = $this->node($tree_id, $path_c);

        if ($node === null || $node->is_folder()) {
            throw new DocumentsException('There is no document "' . Path::clean($path_c) . '" in this tree.');
        }

        $source_id = null;

        if ($source_path_c !== null) {
            $source = $this->node($tree_id, $source_path_c);

            if ($source === null || $source->is_folder() || $source->id() === $node->id()) {
                throw new DocumentsException('"' . Path::clean($source_path_c) . "\" is no other document of this tree: it cannot be the source of \"{$node->path()}\".");
            }

            $source_id = $source->id();
        }

        if ($node->lang_code() === $lang_c && $node->translation_of_node_id() === $source_id) {
            return;
        }

        $st = $this->pdo->prepare('UPDATE ix_doc_node SET lang_c = ?, translation_of_node_id = ? WHERE id = ?');
        $st->execute([$lang_c, $source_id, $node->id()]);
    }

    /**
     * Record a layout over the whole tree: every document the layout places
     * gets its language and its source (when the source exists); one it does
     * not place is left as it is. Idempotent, and what Projection::import()
     * runs after every import, so documents moved, created or imported before
     * the layout was given all end up linked.
     *
     * @return int how many documents changed
     */
    public function apply_layout(int $tree_id, TranslationLayout $layout): int
    {
        $changed_n  = 0;
        $id_by_path = [];
        $st         = null;

        foreach ($this->documents($tree_id) as $node) {
            $id_by_path[$node->path()] = $node->id();
        }

        foreach ($this->documents($tree_id) as $node) {
            $place = $layout->locate($node->path());

            if ($place === null) {
                continue;
            }

            // No document where the layout puts the source: the link it had stays, so translations() says orphan.
            $source_id = $place['source_path_c'] === null
                ? null
                : ($id_by_path[$place['source_path_c']] ?? $node->translation_of_node_id());

            if ($node->lang_code() !== $place['lang_c'] || $node->translation_of_node_id() !== $source_id) {
                $st ??= $this->pdo->prepare('UPDATE ix_doc_node SET lang_c = ?, translation_of_node_id = ? WHERE id = ?');
                $st->execute([$place['lang_c'], $source_id, $node->id()]);
                $changed_n++;
            }
        }

        return $changed_n;
    }

    /**
     * Every translation of the tree and whether it still matches its source:
     *
     *   current  its latest version was made from the source's latest version
     *   stale    the source has a newer version than the one it was made from
     *   unknown  its latest version does not say which source version it was
     *            made from — written before the layout was given, or by save()
     *            without `source_version_id`
     *   orphan   its source was removed
     *
     * @return list<array{path_c: string, lang_c: ?string, source_path_c: ?string, source_version_id: ?int, source_latest_version_id: ?int, state_c: string}> in path order
     */
    public function translations(int $tree_id): array
    {
        $out  = [];
        $live = [];

        foreach ($this->documents($tree_id) as $node) {
            $live[$node->id()] = $node;
        }

        foreach ($live as $node) {
            if ($node->translation_of_node_id() === null) {
                continue;
            }

            $source  = $live[$node->translation_of_node_id()] ?? null;
            $made_of = $node->latest_version_id() === null ? null : $this->version($node->latest_version_id())?->source_version_id();
            $latest  = $source?->latest_version_id();

            $out[] = [
                'path_c'                   => $node->path(),
                'lang_c'                   => $node->lang_code(),
                'source_path_c'            => $source?->path(),
                'source_version_id'        => $made_of,
                'source_latest_version_id' => $latest,
                'state_c'                  => $source === null ? 'orphan' : ($made_of === null ? 'unknown' : ($made_of === $latest ? 'current' : 'stale')),
            ];
        }

        return $out;
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $options
     */
    private function create_document(int $tree_id, string $path_c, array $options): Node
    {
        self::assert_not_reserved($path_c);

        $parent_id = $this->ensure_folders($tree_id, $path_c);
        $now       = $this->now_dt();

        $st = $this->pdo->prepare(
            'INSERT INTO ix_doc_node
                (tree_id, parent_id, kind_c, name_c, path_c, lang_c, translation_of_node_id,
                 sort_n, is_published, insert_dt, update_dt)
             VALUES (?, ?, ?, ?, ?, ?, ?, 0, 1, ?, ?)'
        );
        $st->execute([
            $tree_id,
            $parent_id,
            Node::KIND_DOCUMENT,
            Path::name_of($path_c),
            $path_c,
            $options['lang_c'] ?? self::lang_from_path($path_c),
            $options['translation_of_node_id'] ?? null,
            $now,
            $now,
        ]);

        $node = $this->node_by_id((int) $this->pdo->lastInsertId());

        if ($node === null) {
            throw new DocumentsException('The node disappeared immediately after being created.');
        }

        return $node;
    }

    /**
     * A name ending in `.assets` is a bundle's folder, never a document
     * or a folder of documents — the name itself too, since a file `x.assets`
     * would sit where the folder of `x` goes.
     */
    private static function assert_not_reserved(string $path_c): void
    {
        if (Path::assets_owner_of($path_c . '/') !== null) {
            throw new DocumentsException(
                "\"{$path_c}\": a name ending in \".assets\" is reserved for a document's own folder of assets."
            );
        }
    }

    /**
     * Create every missing folder above a path. Returns the immediate parent id.
     */
    private function ensure_folders(int $tree_id, string $path_c): ?int
    {
        $parent_path = Path::parent_of($path_c);

        if ($parent_path === null) {
            return null;
        }

        $parent = $this->node($tree_id, $parent_path);

        if ($parent !== null) {
            return $parent->id();
        }

        $grandparent_id = $this->ensure_folders($tree_id, $parent_path);
        $now            = $this->now_dt();

        $st = $this->pdo->prepare(
            'INSERT INTO ix_doc_node
                (tree_id, parent_id, kind_c, name_c, path_c, lang_c, sort_n, is_published, insert_dt, update_dt)
             VALUES (?, ?, ?, ?, ?, ?, 0, 1, ?, ?)'
        );
        $st->execute([
            $tree_id,
            $grandparent_id,
            Node::KIND_FOLDER,
            Path::name_of($parent_path),
            $parent_path,
            self::lang_from_path($parent_path . '/x'),
            $now,
            $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * The language implied by a top-level folder, when it looks like one.
     *
     * `en/guide/install.md` is English by convention, and the convention is
     * worth honouring because it is what Docusaurus and DokuWiki both settled
     * on. Only a two- or five-character BCP-47-shaped first segment counts, so a
     * tree whose top level is `manual/` is simply language-neutral rather than
     * being assigned a language nobody asked for.
     */
    private static function lang_from_path(string $path_c): ?string
    {
        $first = explode('/', $path_c)[0];

        return preg_match('~^[a-z]{2}(-[A-Za-z]{2,4})?$~', $first) === 1 ? $first : null;
    }

    private function now_dt(): string
    {
        return gmdate('Y-m-d H:i:s', ($this->clock)());
    }
}
