# Italix Documents

[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.1-8892BF.svg)](https://php.net/)
[![License](https://img.shields.io/badge/license-MPL%202.0-blue.svg)](LICENSE)

A versioned tree of documents with human names — projected onto a real directory you can open in
`vim`, search with `grep`, copy with `rsync` and read with `lynx`.

**Status: 0.11.0.** Phase 1 of the roadmap:
- trees, versions, checkout/import, Markdown rendering and publishing;
- converters for `italix/converters`: Markdown to HTML, HTML to PDF, PDF/DOCX/PPTX to Markdown
  (Docling), a PDF split into pages or ranges and merged back.

From later phases, already here:
- sources relinked after a move (0.9.0);
- the revision archive (0.10.0);
- bundles, a document and its `.assets/` folder (0.11.0).

Search, editions and ACL are still to come — see `FRAMEWORK-DOCUMENTS.md` at the project root.

## From the command line

The whole cycle, which is how this is meant to be used day to day:

```bash
ix documents:install                                  # once — creates the ix_doc_* tables
ix documents:trees --create=manual --name="Handbook"

ix documents:checkout manual                          # writes the working copy
$EDITOR .../work/manual/en/guide/install.md           # edit with anything

ix documents:status manual                            # what changed
ix documents:import manual --author=you@example.org --note="a round of edits"
ix documents:publish manual                           # renders the static site
```

**The order matters, and it is the thing to get right:**

```
work/  --import-->  store/ + database  --publish-->  site/
you edit            the truth                        what you serve
```

`publish` reads from the **store**, not from `work/`. Editing a file and publishing without
importing first changes nothing in `site/` — deliberately, because a published site should mirror
what is recorded, not what somebody left half-finished in an editor.

`import` is idempotent: run it twice over an unchanged tree and it writes nothing, because identical
bytes are not a new revision. That is what makes it safe to call from a hook.

| verb | |
|---|---|
| `documents:install` | create the tables. Idempotent. |
| `documents:trees` | list trees, or `--create=slug --name="…"` |
| `documents:checkout` | write the tree to the working copy |
| `documents:status` | what the working copy and the tree disagree about |
| `documents:import` | write changed files back. `--author=` `--note=` |
| `documents:publish` | render to static HTML. `--published-only` |
| `documents:log` | the history of one document |
| `documents:cat` | print a document. `--version=N` `--html` |
| `documents:move` | rename or move; the old path becomes an alias |
| `documents:rm` | remove from the tree. **The only thing that deletes** |

Reading `status`:

```
  M D en/guide/install.md      modified, and importing it will diverge
  A   en/new-page.md           on disk, not in the tree yet
  !   en/removed.md            in the tree, absent from disk
```

`!` is a **question, not an instruction**. A missing file may be a document somebody deleted, a
directory still syncing, or a checkout that ran out of disk — and guessing wrong destroys work. So
`import` reports it and `documents:rm` is the only thing that removes anything.

## From PHP

```php
$library = new Library($pdo, new LocalStore(
    '/data/docs',
    Library::suggested_path_strategy(),
    Library::suggested_catalog()
));
$library->install();

$tree_id = $library->create_tree('manual', 'Handbook');
$library->save($tree_id, 'en/guide/install.md', "# Install\n\nSteps.\n", ['author_c' => 'a@example.org']);

$projection = new Projection($library, '/srv/handbook');
$projection->checkout($tree_id);          // now edit /srv/handbook with anything
$projection->import($tree_id, 'a@example.org');
$projection->publish($tree_id, '/srv/handbook-site');
```

The CLI verbs above are thin wrappers over exactly these calls. There is nothing they can do that
this cannot.

## The one idea everything rests on

`italix/storage` refuses to let a caller choose a filename — that is its whole safety model. A
documentation tree is useless unless `en/guide/install.md` is a real path.

Both hold because they are two different objects:

```
TRUTH        content-addressed blobs in italix/storage  +  the index in the database
PROJECTION   a directory with human names — read from, never authoritative
```

It is git's split: `.git/objects` where nobody names anything, and the working tree where everything
does. The projection is written **by the library** from content already stored, with names from the
database — never from an upload header. Lose it and `checkout()` rebuilds it, which is why it never
belongs in a backup.

## Two people wrote at once

Last writer wins. That is safe **only because nothing is discarded**: the version that was `latest` a
moment ago is still there and still readable.

What plain last-writer-wins loses is the knowledge that a divergence happened. `parent_version_id`
recovers it:

```
parent_version_id == latest   →  fast-forward, clean
parent_version_id != latest   →  DIVERGENT — accepted, and flagged
```

git's fast-forward test with the refusal removed. No merge machinery, no lost work, and both sides
plus their common ancestor are on record the day somebody wants to build a merge.

```
$ ix documents:status manual
  M D en/guide/install.md      ← modified, and it will diverge
  A   en/new-page.md
  !   en/removed.md            ← absent from disk: reported, never deleted
```

An absent file is a **question**, not an instruction. It may be a document somebody deleted, or a
directory still syncing, or a checkout that ran out of disk. Guessing wrong destroys work, so
`import` reports it and only `documents:rm` deletes.

## Rendering

Renderers are chosen by **extension**, not by sniffed MIME type. Sniffing answers "may this be
stored", which the store has already settled; the extension declares how the author means it to be
read. A `.md` file is `text/plain` to `finfo` and Markdown to a reader.

Two settings are **not configurable**:

```php
'html_input'         => 'escape'
'allow_unsafe_links' => false
```

Markdown permits raw HTML, and a `<script>` inside a `.md` is stored XSS the moment it renders. An
application that genuinely needs raw HTML writes its own `Renderer` — a deliberate act, visible in
review, rather than a boolean somebody flipped in a config file.

Links are read from the **parse tree**, so a URL inside a code fence is not mistaken for a link.

## Publishing

`publish()` writes rendered HTML to a separate root, rewriting internal `.md` links to `.html`. The
result is a static site: no PHP in the request path, readable by `lynx`, servable from disk.

A link to a document that has been **moved** follows it. `move()` keeps the former path as an alias,
and a relative link that names a former path — not a live document — is published pointing where the
document is now, relative to the page the link is on (from `en/index.md`, `guide/install.md` becomes
`../docs/setup.html` once that document has moved to `docs/setup.md`). A document living at a path wins over an alias of the same name; a link
whose document was removed is left as written.

To make the **sources** say the new names too, `relink()` rewrites them — see the Cookbook. It saves
new versions of the documents it touches, so a working copy checked out before it will see them as
divergent on its next import (nothing is lost): check out again after relinking. The aliases stay
either way — links from outside the tree still need them. `publish()` follows a moved document's
`href`, not yet an image's `src`; after a `relink()` there is nothing left to follow.

A page whose document was renamed or removed is **deleted** on the next publish. Each run records
what it wrote in `.ix-published` and removes what the previous run wrote and this one did not — only
that, so a file the library never published is never touched.

Sources and rendered output go to different directories on purpose. Rendering `install.html` beside
`install.md` would force `import` to know which files it wrote itself, and to decide what to do when
somebody legitimately adds a document called `install.html`. Two roots, and neither question arises.

## Bundles: a document and its folder

A document can carry its own files: `notes.md` with `notes.md.assets/`, `page.html` with
`page.html.assets/`. The folder's name is the declaration. Nothing parses the page to guess what it
uses.

```php
$library->save($tree_id, 'plant/notes.md', $text, ['assets' => ['pump.png' => $png, 'img/valve.png' => $png2]]);
$library->assets($version_id);                   // ['img/valve.png' => Asset, 'pump.png' => Asset]
$library->read_asset($version_id, 'pump.png');   // that version's pump, loose or archived
$library->checkout_version($version_id, $dir);   // ['notes.md', 'notes.md.assets/img/valve.png', …]
```

- `assets` is the version's **whole** folder: what is not in it is not in the version, and `[]` means
  none.
- Without the key, the latest version's assets are **carried forward**. So editing only the text
  (`relink()` does) never drops a page's images.
- An image unchanged between versions is one blob.
- An old version shows its own images: the February drawing, February's pump.

What is **part of** one document goes in its folder. What is **shared** stays a document of its own:
- the logo used by fifty pages is one document with one history, not fifty copies in fifty folders.

**The rules:**
- **A name ending in `.assets` is reserved:** no document lives under such a folder, and none is
  called that.
- **`move()` moves the folder with its file.** `relink()` then rewrites a Markdown page's links into
  its own renamed folder. An HTML or SVG page's links are not rewritten: after moving one, edit them
  by hand.
- **In a working copy the pair is one document.** `checkout` writes the folder, and `status` reports
  one change for the bundle, whichever of its files changed. `import` saves it with exactly the files
  now in the folder: delete a file, and the new version does not have it (the previous one still
  does).
- **An orphan folder** (`x.assets/` with no `x` beside it) is reported as `~`, never imported and
  never deleted.
- **`publish()` copies the images beside the page** — only `PUBLISHED_ASSET_TYPES` (PNG, JPEG, GIF,
  WebP, PDF). A copied asset is a stored file served as it is, and an HTML asset served as it is would
  be live markup on the site. Other types stay in the tree, the checkout and the archive.
- **SVG is stored, never served as it is**: a drawing and its parts (`drawing.svg` +
  `drawing.svg.assets/`) are versioned, checked out and archived like anything else. Published, the
  drawing is an escaped page, and its SVG parts are not copied (see "The MIME catalogue").

`tests/BundleTest.php` proves each of these.

## The revision archive

Old versions can be packed, the latest stays as it is:

```php
$library->pack($tree_id);                                  // 5+ old versions; over 400 KB, 2+
$library->pack($tree_id, ['min_old_versions_n' => 2, 'dry_run' => true]);   // what it would do
$library->pack($tree_id, ['large_bytes_n' => null]);       // the count rule alone
```

**When a document is packed** depends on its size, measured on the latest version (the file plus a
bundle's assets):
- **Over 400 KB** (`large_bytes_n`): from its third version (`large_min_old_versions_n`, 2 old ones).
  From then on the two old versions share almost everything and the second costs almost nothing: two
  old versions of a 500 KB page take 1000 KB loose, 375 KB archived.
- **Any other document**: from its sixth (`min_old_versions_n`, 5 old ones). A small page gains little in
  bytes by packing early.

An archive is rewritten whole at each pack, so a large document that changes every night costs one
rewrite of its history every night.

**A folder can be packed cold**, the latest versions included, into one archive:

```php
$library->pack_folder($tree_id, 'originals/freedium-mirror.cfd/2026-08');
```

- Its members are named by their content, so a file many documents carry (a site's fonts) is stored
  once.
- Every version still reads back with `read_version()`, `read_asset()` and `checkout_version()`. A
  version saved afterwards is loose until the next call.
- This is for what is kept rather than worked on: saved web pages, below. Name folders by month, so an
  archive stops being rewritten once its month is over.
- An archive is deleted only when no version refers to it any more, since several documents can share
  one.

Each document's old versions go into **one `tar.zst`**, beside its other blobs. A LibreOffice, Office or
EPUB file goes in **unpacked**, so the XML of consecutive versions and the images they carry forward
deduplicate: four versions of an ODT with 1 MB of images take 4 MB side by side, 2 MB packed. Anything
else goes in whole, and still gains from compressing the whole history together. A bundle's assets go
in once per distinct file, however many versions carry them.

`read_version()` reads an archived version back as usual. A single file comes back byte for byte; a
container is rebuilt — every entry byte-equal, but the ZIP around them not necessarily, so a digital
signature inside does not survive packing. Packing is for compression, not for keeping signed files.

Nothing is lost on the way: an archive is read back from the store and checked entry by entry before any
version is marked archived, and loose files are deleted only after that. Needs `ext-zip`, and the `tar`
and `zstd` commands. It is a batch job: schedule it at a quiet hour, niced — the library does not pace
itself.

## Cookbook

Every example below runs, as written, in `tests/CookbookTest.php` — on SQLite in memory and a store in
a temporary directory — and what each comment says came back is asserted there.

### A library

```php
use Italix\Documents\Library;
use Italix\Storage\LocalStore;

$library = new Library($pdo, new LocalStore(
    '/data/docs/store',
    Library::suggested_path_strategy(),
    Library::suggested_catalog()
));
$library->install();                               // idempotent

$tree_id = $library->create_tree('manual', 'Handbook');
$library->tree_id_for('manual');                   // the same id, by its slug
```

### Versions: nothing is overwritten

```php
$v1 = $library->save($tree_id, 'en/guide/install.md', "# Install\n\nOne.\n", [
    'author_c' => 'ada@example.org',
    'note_c'   => 'first draft',
]);
$v2 = $library->save($tree_id, 'en/guide/install.md', "# Install\n\nTwo.\n", [
    'author_c'          => 'ada@example.org',
    'parent_version_id' => $v1->id(),              // "I started from v1"
]);

$library->save($tree_id, 'en/guide/install.md', "# Install\n\nTwo.\n");
// the same bytes again are not a new version: it returns v2

$node = $library->node($tree_id, 'en/guide/install.md');
$library->history($node->id());                    // v2, v1 — newest first
$library->read($tree_id, 'en/guide/install.md');   // the latest
$library->read_version($v1->id());                 // any of them
[$v1->author(), $v1->note(), $v1->mime(), $v1->bytes_n()];
// ['ada@example.org', 'first draft', 'text/plain', 16]
```

### Two people wrote at once

```php
$bob = $library->save($tree_id, 'en/guide/install.md', "# Install\n\nBob's.\n", [
    'author_c'          => 'bob@example.org',
    'parent_version_id' => $v1->id(),              // but v2 is latest by now
]);
$bob->is_divergent();                              // true — accepted, and flagged
$library->read_version($v2->id());                 // v2 is still there
```

### Moving, and removing

```php
$moved = $library->move($tree_id, 'en/guide/install.md', 'en/guide/setup.md');
$library->node_for_alias($tree_id, 'en/guide/install.md');   // still finds it, history and all

$library->remove($tree_id, 'en/draft.md');         // gone from the tree ...
$library->read_version($draft_version_id);         // ... its versions still readable by id

$library->move($tree_id, 'en/guide', 'en/manual'); // a folder takes everything inside, each path an alias
```

### Documents that come from other documents

```php
// A PDF is a document like any other (application/pdf) ...
$source = $library->save($tree_id, 'sources/datasheet.pdf', $pdf_bytes);

// ... and the Markdown made from it points at that exact version of it.
$text = $library->save($tree_id, 'derived/datasheet.md', $markdown, [
    'source_version_id' => $source->id(),
    'note_c'            => 'converted from the PDF',
]);
$text->source_version_id();                        // === $source->id()

// A document's language.
$library->save($tree_id, 'it/guida.md', "# Guida\n", ['lang_c' => 'it']);
```

When the PDF gets a new version, the Markdown still says which one it was made from — that is how
you know it needs converting again.

### Translations that know when they fell behind

A `TranslationLayout` says where a tree keeps its translations — `LanguageFiles('it')` for
`books/smilla/it.md` beside `en.md` and `de-CH.md`, `LanguageFolders('en')` for `en/guide/x.md` beside
`it/guide/x.md`. Give it to the working copy, and every import records which source version each
translation was made from:

```php
$projection = new Projection($library, $work_dir, new LanguageFiles('it'));
$projection->import($tree_id, 'anna');   // en.md edited: saved with source_version_id = the it.md the translator had

$library->translations($tree_id);
// [['path_c' => 'books/smilla/en.md', 'lang_c' => 'en', 'source_path_c' => 'books/smilla/it.md',
//   'source_version_id' => 41, 'source_latest_version_id' => 57, 'state_c' => 'stale'], …]
```

- **The version the translator saw**, not the latest: the one the checkout wrote — or the one this import
  writes, when the source was edited too (sources are imported first). A source changed in the tree after
  the checkout makes the translation `stale` even though it was imported after.
- `state_c`: `current`, `stale`, `unknown` (the version does not say — written before the layout, or by
  `save()` without `source_version_id`), `orphan` (the source was removed).
- After every import the projection runs `apply_layout()`: each document the layout places gets its
  `lang_c` and `translation_of_node_id`. Links are by node id, so a move keeps them. `set_translation()`
  links one by hand.

### Rendering

```php
$library->save($tree_id, 'en/index.md', "# Handbook\n\nSee [setup](guide/setup.md).\n");
$page = $library->render($tree_id, 'en/index.md');

$page->html();     // <h1>Handbook</h1> <p>See <a href="guide/setup.md">setup</a>.</p>
$page->text();     // 'Handbook See setup.' — what a search index or a chunker wants
$page->links();    // ['guide/setup.md'], read from the parse tree

// Raw HTML in Markdown is escaped, always: <script> comes out as &lt;script&gt;.
```

### A working copy: checkout, edit with anything, import

```php
use Italix\Documents\Projection;

$projection = new Projection($library, '/srv/handbook');
$projection->checkout($tree_id);                   // every document in the tree, written to disk

// ... edit en/guide/setup.md, add en/new-page.md, delete it/guida.md — with anything ...

foreach ($projection->status($tree_id) as $change) {
    if ($change->is_actionable()) {
        echo $change->describe(), "\n";
    }
}
// M  en/guide/setup.md
// A  en/new-page.md
// !  it/guida.md

$projection->import($tree_id, 'ada@example.org', 'a round of edits');
// ['imported' => ['en/guide/setup.md', 'en/new-page.md'], 'divergent' => [],
//  'missing' => ['it/guida.md'], 'orphan_assets' => [], 'unchanged_n' => 4]

$projection->import($tree_id, 'ada@example.org');  // again: nothing to write
// it/guida.md was reported, not deleted — only remove() deletes
```

### Publishing a static site

```php
$projection->publish($tree_id, '/srv/handbook-site');
// one page per document, rendered from the store (not from the working copy);
// a link to guide/setup.md becomes a link to guide/setup.html — and a page still linking
// guide/install.md, the name before the move, is published linking guide/setup.html too

$library->remove($tree_id, 'en/new-page.md');
$projection->publish($tree_id, '/srv/handbook-site');
// en/new-page.html is gone; a file the library never wrote is left alone
```

### Relinking the sources after a move

```php
$library->relink($tree_id, ['author_c' => 'ada@example.org', 'dry_run' => true]);
// ['rewritten' => ['en/faq.md' => [['guide/install.md', 'guide/setup.md']]], 'skipped' => [], 'unchanged_n' => …]

$library->relink($tree_id, ['author_c' => 'ada@example.org']);
// en/faq.md now says [install](guide/setup.md) — a new version, its note saying what changed
```

Links, images and `[label]: dest` definitions are rewritten; code blocks and code spans never. A
document the parser says would change in any other way is skipped and reported, not saved.

### Converters

```php
use Italix\Converters\ConversionOptions;
use Italix\Documents\HtmlToPdfConverter;
use Italix\Documents\MarkdownToHtmlConverter;
use Italix\Documents\PdfMerger;
use Italix\Documents\PdfPageSplitter;

(new MarkdownToHtmlConverter())->convert("# Title\n\n*text*\n", 'md', 'html', ConversionOptions::none());
// text/html: <h1>Title</h1> <p><em>text</em></p>

$pdf = (new HtmlToPdfConverter())->convert($html, 'html', 'pdf', ConversionOptions::none())->bytes();

// Every page on its own (poppler-utils) ...
$pages = (new PdfPageSplitter())->extract($pdf, 'pdf', null, ConversionOptions::none());

// ... or ranges: [first, last], both included.
$parts = (new PdfPageSplitter())->extract($pdf, 'pdf', [[1, 1], [2, 3]], ConversionOptions::none());
// a one-page PDF and a two-page one

// And back together, in the order given.
(new PdfMerger())->merge([$parts[1]->bytes(), $parts[0]->bytes()], 'pdf', 'pdf', ConversionOptions::none());
```

`DoclingConverter` turns a PDF, DOCX or PPTX into Markdown — see its docblock for the venv it needs.
For a PDF that already has a text layer, OCR off and images as placeholders turn minutes into seconds:

```php
use Italix\Documents\DoclingConverter;

$markdown = (new DoclingConverter('/path/to/venv/bin/docling'))->convert($pdf, 'pdf', 'md', ConversionOptions::none()
    ->with(DoclingConverter::OPTION_OCR, false)
    ->with(DoclingConverter::OPTION_IMAGE_MODE, 'placeholder'));
```

That one needs Docling itself, so the Cookbook test does not run it; `tests/DoclingOptionsTest.php`
checks that both options reach Docling's command line, and `tests/DoclingConverterTest.php` runs the
real thing where it is installed.

### Web pages saved with SingleFile

A page saved by SingleFile as a self-extracting ZIP (`.u.zip.html`) becomes two documents:
- its **text**, alive — the main content as Markdown, to read, search and index;
- its **original**, a bundle to pack cold.

```php
use Italix\Documents\HtmlToMarkdownConverter;
use Italix\Documents\SingleFileImporter;
use Italix\Media\GdImageConverter;

$importer = new SingleFileImporter($library, new HtmlToMarkdownConverter(), new GdImageConverter());
$report   = $importer->import($tree_id, file_get_contents($saved_page), ['author_c' => 'ada']);
// texts/freedium-mirror.cfd/2026-08/<slug>.md       # Title, "Saved from <url> on <time>", the article
// originals/freedium-mirror.cfd/2026-08/<slug>.html  the page, its files in <slug>.html.assets/

$library->pack_folder($tree_id, 'originals/freedium-mirror.cfd/2026-08');   // at night, niced
$library->checkout_version($report['original']->id(), '/tmp/look');          // open it locally
```

**How a page is recognised and placed:**
- The page is recognised by its content (`data-sfz`, a ZIP inside, a manifest), never by its name.
- Its host and month come from its `manifest.json`, so a mirror's pages share a folder, and its fonts
  and CSS a batch.

**What the original keeps:**
- PNG and JPEG become **WebP, quality 90** (`quality` option), whenever that is smaller. The page's
  and the CSS's references are rewritten to match.
- The fonts are kept. The original is faithful to the eye, not to the byte.

**What the text keeps:** the `<article>` with the most text, else `<main>`, else `<body>`. Interface
lines such as "Top highlight" are dropped (`HtmlToMarkdownConverter::DEFAULT_DROP_LINES`, or a list of
your own). `source_version_id` leads from the text to the page.

**Importing again:**
- The same file again writes nothing.
- Another URL with the same slug goes to `<slug>-2`.

Measured on nine pages of one site (20.8 MB as saved): texts 194 KB, the whole store 5.8 MB once the
originals were packed.

A page taken from the web is hostile by definition: an original is never served. Open it from a
checkout, or from a separate origin with a `sandbox` Content-Security-Policy.

**A page saved by something else** — `italix/crawler`, say — goes in the same way, as a `SavedPage`:
its URL, title and time, its HTML, and the files the HTML names locally. A page without a
`manifest.json` gets one, so "is this the same URL?" is answered the same way for every page.

```php
use Italix\Documents\SavedPage;

$importer->import_page($tree_id, new SavedPage($url, $title, '2026-09-29T10:00:00Z', $html, ['images/a.png' => $png]));
```

### PDFs and office files from the web

```php
use Italix\Documents\DocumentImporter;
use Italix\Documents\PdfToTextConverter;

$documents = new DocumentImporter($library, new PdfToTextConverter());
$r = $documents->import($tree_id, 'https://www.example.com/files/report.pdf', '2026-09-29T10:00:00Z', $bytes, 'pdf');
// originals/www.example.com/2026-09/report.pdf      the file, with a manifest: packs with the site's pages
// texts/www.example.com/2026-09/report.pdf.md       "# report.pdf", "Saved from <url> on <time>", the text
```

- **The text** comes from the converter given, when it turns the type into Markdown or plain text:
  - `PdfToTextConverter`: a PDF's text layer, with poppler's `pdftotext`;
  - `DoclingConverter`: PDF, DOCX and PPTX with their structure, where Docling is installed.
- **Without a converter for the type**, or when it fails, the original is kept alone. `text_error`
  says why.
- **A type the store refuses** (an old `.ppt`) is refused before anything is written: no empty
  document is left behind.

### When it cannot work, it says so

```php
$library->save($tree_id, '../x.md', 'x');
// DocumentsException: "../x.md" contains "..", which is traversal.

$library->save($tree_id, 'en/guide', 'x');
// DocumentsException: "en/guide" is a folder.

$library->move($tree_id, 'en/index.md', 'en/guide/setup.md');
// DocumentsException: "en/guide/setup.md" already exists.

(new PdfPageSplitter())->extract($three_pages, 'pdf', [[2, 9]], ConversionOptions::none());
// ConversionException: Requested range [2, 9] includes page 4, which does not exist.
```

## Chunking Markdown for retrieval

`MarkdownChunker` cuts a Markdown document into passages for an embedding model — pure string work, no parser,
no store, no model, so the chunking can be judged for free before anything is embedded:

```php
$chunks = (new MarkdownChunker())->chunk('Orm/README.md', $markdown, ['library' => 'Orm']);

foreach ($chunks as $chunk) {
    $text = $chunk->embeddable_text();   // "Title › Heading (part 2 of 3)\n\nbody…"
    $meta = $chunk->to_metadata();       // flat: caller's metadata + title, heading, part_i, parts_n
    // $chunk->code is stable: re-chunking an edited file names the same passages
}
```

- **Splits on `##`**, then — only for what is too long — on `###`, paragraphs and sentences.
- **Never inside a fenced code block**: a `##` in a fence is not a heading, and a blank line in one is not a
  paragraph break.
- **Folds short pieces** into a neighbour, under a `##` line naming them, instead of shipping them as noise.
- **`code`** comes from the file, the heading trail and the part number — not from the text — so an edited
  paragraph keeps its identity and a re-index updates instead of duplicating.

The defaults (target 2000, max 3200, min 220 characters) suit a model with a generous context. For one that
truncates early pass smaller sizes, e.g. `new MarkdownChunker(300, 500, 80)` for MiniLM's 128 tokens; sizes
that cannot work (`min > target`, `target > max`, `min < 1`) are refused.

## The MIME catalogue

`Library::suggested_catalog()` widens the `italix/storage` default, and the reasons were found by
testing rather than reasoning. A source document is **prose that may quote anything**, and `finfo`
sniffs what is quoted:

| | sniffs as | |
|---|---|---|
| an empty placeholder page | `application/x-empty` | refused by the default |
| a short page whose body is a JSON example | `application/json` | refused by the default |
| a short page demonstrating HTML markup | `text/html` | refused by the default |

Longer pages sniff as `text/plain` because prose dominates — which makes the failure worse, not
better: it appears only sometimes and looks arbitrary.

Widening is safe **here and nowhere else**. This library never serves a stored blob: every read goes
through a `Renderer`, and no shipped renderer passes raw markup through. A stored HTML document is
published as visible text, never as live markup — the test suite asserts this rather than assuming
it. **Do not copy this catalogue into a general upload endpoint.**

SVG is accepted on the same terms: **stored, never served**.
- A drawing published as a document goes through its renderer, escaped like any page.
- As a bundle's part, `publish()` never copies it (`Projection::PUBLISHED_ASSET_TYPES`).
- Serving SVG as an image — where its script would run — is the web server's decision to make, with a
  `Content-Security-Policy` that forbids script. This library does not make it.

## Naming

This library *does* let a human choose the name, so the name is checked rather than replaced.

Refused: `..` and `.` segments, empty segments, NUL and control characters, hidden names (`.foo`),
flag-shaped names (`-rf`), whitespace inside a segment. Allowed: Unicode letters and digits, spaces,
dot, dash, underscore — `città.md` is a reasonable name for an Italian page and refusing it would be
provincial.

## What can be thrown away

`work/` and `site/` are both **projections**: `checkout` and `publish` rebuild them. Neither belongs
in a backup.

What must be backed up is the **store and the database together**. The store alone is a pile of
hash-named blobs; which blob is version 40 of which document lives in the database, and neither half
means anything without the other.

## Requirements

`php >= 8.1`, `ext-mbstring`, `ext-json`, `ext-pdo`, `italix/storage ^3.0`, `italix/converters`
(`^0.5 || ^0.6 || ^0.7`).

Suggestions, each needed only by the piece that uses it:

- `league/commonmark` — `MarkdownRenderer` and `MarkdownToHtmlConverter`. Without it a tree still works
  and Markdown renders as escaped text, because a tree of PDFs and images is a perfectly good tree and
  should not require a Markdown parser to exist.
- `dompdf/dompdf` — `HtmlToPdfConverter`.
- `poppler-utils` (a system package) — `PdfPageSplitter`, `PdfMerger` and `PdfToTextConverter`.
- Docling (a Python venv, ~6 GB) — `DoclingConverter`; see its docblock.
- `ext-zip` and the `tar` and `zstd` commands — `pack()` and reading an archived version or asset.

Tested against SQLite and MySQL/MariaDB. `install()` is idempotent on both.
