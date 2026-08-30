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
     * SVG stays refused: it is an *image*, so an application would reasonably
     * serve it as one, and then the guarantee above no longer covers it.
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
     *     source_version_id?: int|null
     * } $options
     *
     * @return Version the version written, or the existing one when the bytes
     *                 are identical — an unchanged save is not a revision
     */
    public function save(int $tree_id, string $path_c, string $contents, array $options = []): Version
    {
        $path_c = Path::clean($path_c);
        $node   = $this->node($tree_id, $path_c) ?? $this->create_document($tree_id, $path_c, $options);

        if ($node->is_folder()) {
            throw new DocumentsException("\"{$path_c}\" is a folder.");
        }

        $sha256_c = hash('sha256', $contents);
        $latest   = $node->latest_version_id() === null ? null : $this->version($node->latest_version_id());

        // Identical bytes are not a new revision. Content addressing gives this
        // for free and it is what keeps `import` idempotent: running it twice
        // over an unchanged tree writes nothing.
        if ($latest !== null && $latest->sha256() === $sha256_c) {
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

        $stored = $this->store->put_contents($this->folder_for($tree_id, $node->id()), $contents, Path::name_of($path_c));

        $st = $this->pdo->prepare(
            'INSERT INTO ix_doc_version
                (node_id, parent_version_id, storage_path_c, sha256_c, mime_c, bytes_n,
                 author_c, note_c, is_divergent, source_version_id, insert_dt)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $st->execute([
            $node->id(),
            $claimed_parent_id ?? $node->latest_version_id(),
            $stored->path(),
            $sha256_c,
            $stored->mime(),
            strlen($contents),
            (string) ($options['author_c'] ?? ''),
            mb_substr((string) ($options['note_c'] ?? ''), 0, 500),
            $is_divergent ? 1 : 0,
            $options['source_version_id'] ?? null,
            $this->now_dt(),
        ]);

        $version_id = (int) $this->pdo->lastInsertId();

        $up = $this->pdo->prepare('UPDATE ix_doc_node SET latest_version_id = ?, update_dt = ? WHERE id = ?');
        $up->execute([$version_id, $this->now_dt(), $node->id()]);

        $version = $this->version($version_id);

        if ($version === null) {
            throw new DocumentsException('The version disappeared immediately after being written.');
        }

        return $version;
    }

    /**
     * Rename or move. The former path becomes an alias, so links and URLs that
     * used it still resolve — the same treatment a stale media slug gets.
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

        $parent_id = $this->ensure_folders($tree_id, $to_c);

        $st = $this->pdo->prepare(
            'UPDATE ix_doc_node SET path_c = ?, name_c = ?, parent_id = ?, update_dt = ? WHERE id = ?'
        );
        $st->execute([$to_c, Path::name_of($to_c), $parent_id, $this->now_dt(), $node->id()]);

        $al = $this->pdo->prepare(
            'INSERT INTO ix_doc_alias (tree_id, path_c, node_id, insert_dt) VALUES (?, ?, ?, ?)'
        );
        $al->execute([$tree_id, $from_c, $node->id(), $this->now_dt()]);

        $moved = $this->node_by_id($node->id());

        if ($moved === null) {
            throw new DocumentsException('The node disappeared immediately after being moved.');
        }

        return $moved;
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

        $st = $this->pdo->prepare('UPDATE ix_doc_node SET delete_dt = ? WHERE id = ? OR parent_id = ?');
        $st->execute([$this->now_dt(), $node->id(), $node->id()]);
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $options
     */
    private function create_document(int $tree_id, string $path_c, array $options): Node
    {
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
