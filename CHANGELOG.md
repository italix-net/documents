# Changelog — italix/documents

Format: [Keep a Changelog](https://keepachangelog.com/). Versioning policy: `VERSIONING.md` at the
project root.

## [0.16.0] — 2026-10-24

### Added

- **Translations that know when they fell behind.** `TranslationLayout`, with `LanguageFiles` (one folder per
  document, `{lang}.md`) and `LanguageFolders` (`{lang}/…`). A `Projection` given one records, on import, the
  source version each translation was made from — the one the translator had on disk: the checked-out version,
  or this import's when the source was edited too (sources go first) — and links every document to its language
  and source (`Library::apply_layout()`). `Library::translations()` reports each translation `current`, `stale`,
  `unknown` or `orphan`; `Library::set_translation()` links one by hand. The columns were there since 0.5
  (`lang_c`, `translation_of_node_id`, `source_version_id`); nothing filled or read them through a working copy.
- `TranslationsTest` (33); four mutations each caught.

### Changed

- `Projection::__construct()` takes an optional third argument, the layout. Without one, nothing changes.

## [0.15.1] — 2026-10-24

### Fixed

- **`move()` on a folder moves what is inside it.** It updated the folder's own row only: its documents
  kept their old paths, listed under a folder that no longer existed, while the new folder stayed empty.
  Now every live node below it, at any depth, takes the new path, keeps its id and leaves its former
  path as an alias, so `moved_href()`, `relink()` and `publish()` follow links into the moved folder.
  One transaction. Also refused now: a folder moved inside itself, and a target under a document.
- **`remove()` on a folder removes everything inside it.** Only its direct children were marked
  deleted; their children stayed live under a deleted folder.
- **`Projection::checkout()` deletes what the tree no longer has.** A file the previous checkout wrote,
  moved or removed in the tree since, stayed on disk, and `status()` called it added, so the next
  `import()` brought the document back at its old path, where it would also hide the alias. Such a file
  is now deleted when it is still as checked out, with the folders it leaves empty; one edited since is
  kept and still reported as added.
- `FolderMoveTest` (25); each fix mutation-checked. The prefix match is also exact in PHP, because a MySQL
  collation may compare `path_c` case-blind.

## [0.15.0] — 2026-10-10

### Added

- **`MarkdownChunker` and `MarkdownChunk`** — Markdown into passages for retrieval: split on `##`, then on `###`,
  paragraphs and sentences only for what is too long; never inside a fenced code block; short pieces folded into a
  neighbour; a `code` stable across edits. Moved here from two applications that each held an identical copy; pure
  string work, which is why it is not in `italix/rag` (whose `Chunk` keeps chunking out on purpose). New in the
  move: sizes that cannot work are refused with `InvalidArgumentException`. `MarkdownChunkerTest` (52), every
  rule mutation-checked; its last section chunks the sibling libraries' READMEs when they are installed alongside.

## [0.14.0] — 2026-09-29

### Added

**Pages from any source, and documents from the web**, the half of the crawler's design that belongs here.

- `SavedPage` holds a page as saved: URL, title, time, HTML, and the files it names locally.
- `SingleFileImporter::import_page()` imports one, whatever saved it: `italix/crawler`, or SingleFile
  through `read_page()`. `import()` is now `read_page()` then `import_page()`. A page without a
  `manifest.json` gets one written, so two URLs are told apart the same way for every page.
- `DocumentImporter` takes a PDF or an office file taken from the web:
  - the original: `originals/<host>/<yyyy-mm>/<name>.<ext>`, with a manifest, packing with its site's
    pages;
  - its text: `texts/…/<name>.<ext>.md`, `source_version_id` pointing at the original, when the
    converter given takes the type;
  - otherwise the original alone, and `text_error` says why.
- `PdfToTextConverter`: `pdf` → `txt` through poppler's `pdftotext`.

### Fixed

- **A save the store refused left an empty document behind.** `save()` created the document, then the
  store refused its file (an old `.ppt`), and a document with no version stayed in the tree, where
  `pack_folder()` counted it. A document created by a save that fails is now removed. Found by the
  application's crawl test.
- **`save()` reused `$path_c`** for a carried asset's blob path, so the file was stored with the
  blob's name as its original name. The name is metadata only, so nothing wrong was visible; the
  variable is now its own.

### Changed

Where both importers put things — host, month, slug, and `-2` for another URL — is one internal class,
`WebPlace`. The error for a URL with no usable host now reads "No usable host in the URL".

## [0.13.0] — 2026-09-27

### Added

**Web pages saved with SingleFile, and folders packed cold**.

- `SingleFileImporter` turns a SingleFile self-extracting page (`.u.zip.html`), recognised by content,
  into two documents:
  - `texts/<host>/<yyyy-mm>/<slug>.md`: a header and the main content;
  - `originals/<host>/<yyyy-mm>/<slug>.html`: a bundle, PNG and JPEG re-encoded as WebP when smaller
    (through an injected converter, quality 90), every reference rewritten to match.

  Importing the same file again writes nothing; another URL with the same slug goes to `-2`.
- `HtmlToMarkdownConverter` (`html` → `md`): a page's main content as Markdown. It takes the
  `<article>` with the most text, else `<main>`, else `<body>`, and drops interface lines.
- `Library::pack_folder()` packs every version of every document under a folder, **the latest
  included**, into one archive whose members are shared.

Measured on nine pages of one site: 20.8 MB as saved, 5.8 MB in the store with the texts, once packed.

### Changed

- An archive is deleted only when no version refers to it, since `pack_folder()` shares them.
- `save()` without `assets` on a bundle whose latest version is archived stores its assets again,
  instead of copying rows that point at deleted blobs.
- `suggested_catalog()` accepts `font/woff`, `font/woff2` and `image/avif`: stored, never served.
- The internals of `pack()` are shared with `pack_folder()`; its behaviour is unchanged.

## [0.12.0] — 2026-09-27

### Changed

**Large documents are packed sooner**.

- `pack()` takes `large_bytes_n` (default 400 KB) and `large_min_old_versions_n` (default 2).
- A document whose latest version — file plus assets — is **more than** 400 KB is packed from its third
  version. Any other document is packed from its sixth, as before.
- `'large_bytes_n' => null` restores 0.11.0's behaviour exactly.
- Measured by the test: two old versions of a 500 KB page, 1000 KB loose, 375 KB archived.

## [0.11.0] — 2026-09-26

### Added

**Bundles: a document and its `.assets/` folder** — kind C of the revision archive.

- `save()` takes `'assets' => [name => bytes]`, the version's whole folder. `[]` means none; without
  the key the latest version's assets are carried forward. Saving again with the same file and the same
  assets returns the same version (`ix_doc_version.assets_sha256_c`). An unchanged image is one blob.
- `assets($version_id)`, `read_asset($version_id, $name_c)`, and `checkout_version($version_id, $dir)`
  for any version: kind A, B or C, loose or archived. `Asset` is the new value class, `ix_doc_asset` the
  new table (`install()` adds both).
- `move()` moves the folder with its file. `moved_href()` follows a file of a moved bundle's folder, so
  `relink()` rewrites a Markdown page's links into its own folder and `publish()` follows them.
- `Projection`:
  - `checkout` writes the folder;
  - `status` reports one change per bundle;
  - `import` saves exactly the files now in the folder;
  - a folder with no file beside it is `Change::ORPHAN_ASSETS`, reported, never imported or deleted.
- `publish()` copies a bundle's images and PDFs beside the page (`Projection::PUBLISHED_ASSET_TYPES`)
  and prunes them when they go. Never an HTML asset: that would be a stored blob served as live markup.
- `pack()` puts each distinct asset in the archive once, as `assets/<blob>`. A loose blob is deleted only
  when the latest version uses it neither as its file nor as an asset.

### Changed

- A name ending in `.assets` is reserved: `save()` and `move()` refuse a document under such a folder,
  or called that. The working copy ignores a file with such a name.
- `import()`'s report has an `orphan_assets` key.
- `save()` writes the version, its assets and the new latest in one transaction, or inside the caller's.
- `Library::suggested_catalog()` accepts SVG (`image/svg+xml`) — stored, never served. A drawing
  published as a document is escaped like any page; as an asset `publish()` never copies it. Serving
  SVG as an image is left to the web server's `Content-Security-Policy`.

## [0.10.0] — 2026-09-26

### Added

**The revision archive, for single files and containers** — phase 3 of `FRAMEWORK-DOCUMENTS.md` §11.

`Library::pack($tree_id, ['min_old_versions_n' => 5, 'dry_run' => false])` puts every version but the
latest of each document with enough history into **one `tar.zst` per document**. A container — ODF,
OOXML, EPUB, recognised by its content — goes in **unpacked**, its entries ordered by name and then by
version so equal content sits side by side whatever the document's size; anything else goes in whole.
The latest version is never touched. `read_version()` reads an archived version back: a single file
byte-equal, a container rebuilt from its recipe — every entry byte-equal, `mimetype` first and stored
for ODF — content-equal rather than byte-equal (a signature inside does not survive packing).

The user's case, measured by the test: four versions of an ODT carrying 1 MB of images take **4.00 MB**
side by side and **2.00 MB** packed — the latest as it is, and a 1.00 MB archive holding the picture
once for three versions.

Per document the archive is written, **read back from the store and checked entry by entry**, only then
are its versions marked, in one transaction, and only then are the loose blobs and the previous
archive deleted; a blob the latest version still uses (a revert) never is. Anything failing leaves the
document as it was, reported under `skipped`. A container is unpacked only if its entries have safe
names (`../outside.xml` is archived whole, never unpacked onto a disk), none is encrypted, and its
rebuild reads back entry for entry.

`RevisionArchive` — the files-and-processes half: `tar` and the `zstd` command (`-9 --long=27`, and
`--long=27` to read), the explode, the rebuild and its proof. `ext-zstd` can take the command's place
later.

### Changed

`Library::suggested_catalog()` accepts LibreOffice's formats (odt, ods, odp, odg), EPUB, PowerPoint when
`finfo` names it, and `application/zstd` — the archive is stored like any blob. The first test of an ODT
found it refused. SVG stays refused, deliberately: see "The MIME catalogue".

`tests/ArchiveTest.php` (28), including a store handing back a valid archive with other contents —
zstd's own checksum passes, only the entry-by-entry check refuses it.

## [0.9.0] — 2026-09-26

### Added

**`Library::relink()`** — the sources follow a moved document. In every Markdown document of the tree,
each relative link or image destination (inline, `<angle-bracketed>`, or a `[label]: dest` definition)
that names a former path is rewritten to where the document is now, and the document saved as a new
version: `author_c` from the options, a `note_c` saying what changed, the version it read as its
parent. The aliases stay. `dry_run` reports without saving; a second run finds nothing.

The rewrite is textual — CommonMark gives a link's URL and its block's lines, not its column — and then
**proven against the parser**: the new source's destinations must be the old ones with exactly the
planned replacements, and its HTML, every `href`/`src` blanked, the old one's. A document failing
either is skipped and reported, never saved. A code span over two lines shows why both are needed: the
destinations check passes (the parser never saw a link in the span), the HTML check refuses it.

`Library::moved_href()` — the rule both `publish()` and `relink()` apply, now written once;
`Path::resolve_link()` and `Path::link_to()` — the relative-path arithmetic it rests on, moved out of
`Projection`. `MarkdownLinks` — destinations by the parser, the proposed textual rewrite.

`tests/RelinkTest.php` (27), including a store that saves a concurrent version at the moment `relink()`
reads the document: relink's version is then divergent — flagged, not silently on top. The
existing suites, `PublishMovedLinksTest` among them, pass unchanged.



### Changed

**A published link follows a moved document.** `move()` keeps the former path as an alias, but
`publish()` only swapped `.md` for `.html`: a page still linking the old name was published pointing
at a page that no longer existed. A relative link that names a former path — and no live document —
is now rewritten to where the document is, relative to the page it is on, fragment and query kept,
each segment URL-encoded. A live document at the old path wins over the alias; a link whose document
was removed since is left as written; absolute URLs and links climbing out of the tree are not
followed. Found writing the README's Cookbook.

`tests/PublishMovedLinksTest.php` (12): moves within a folder, across folders, twice, to a name with a
space, a removed target, a live document back at the old path; six of its checks fail against 0.7.4.

### Documentation

The README's status and requirements said 0.1.0, PHP 7.4 and `italix/storage ^2.0`; they now say what
`composer.json` does, and list each optional dependency with the piece that needs it. The Cookbook
shows the moved link (`tests/CookbookTest.php`, 38).

## [0.7.4] — 2026-09-26

### Changed

`PdfPageSplitter`'s own variables say what they hold: a page number is an index (`$page_i`, and a
range's `$first_i`/`$last_i`), `$pages_n` stays the count of pages. Internal only — no behaviour or
signature changes; the file is byte-identical to 0.7.3's once the three names are substituted back.

## [0.7.3] — 2026-09-26

### Changed

Accepts `italix/converters` 0.7 as well (`^0.5 || ^0.6 || ^0.7`): nothing here reads the renamed
options, so 0.7.0's renames do not touch this library.



### Fixed

**`HtmlToPdfConverter` and `DoclingConverter` read driver-scoped values.** Both read their options
unscoped, so `COLORSPACE`, `OPTION_OCR` or `OPTION_IMAGE_MODE` set for them alone
(`->with(DoclingConverter::OPTION_OCR, false, DoclingConverter::class)`) were never seen: a CMYK
request scoped to `HtmlToPdfConverter` rendered an RGB PDF instead of being refused, and Docling ran
with OCR on. Every read now names the driver (`static::class`) — the same fix `italix/media` 2.2.1
made to its two older drivers.

`tests/DoclingOptionsTest.php` (8) checks what reaches Docling's command line through a stand-in
executable the test writes itself, so it runs on every host, Docling installed or not;
`tests/HtmlToPdfConverterTest.php` (+2). The four checks that expect a scoped value to be read fail
against 0.7.1.

## [0.7.1] — 2026-09-26

### Changed

Accepts `italix/converters` 0.6 as well (`^0.5 || ^0.6`): 0.6.0 only adds (grids, `Spans`, an optional
locator on `ConvertedDocument`), and for a 0.x version `^0.5` alone would refuse it. Nothing here uses
the new pieces yet — `PdfPageSplitter` setting a page-range locator is the obvious next step.

## [0.7.0] — 2026-09-03

### Added

`PdfPageSplitter` (`Italix\Converters\Extractor`) and `PdfMerger` (`Italix\Converters\Merger`) —
the first real drivers for the two new cardinalities `italix/converters` 0.4.0 adds, and exact
mirrors of each other: `pdfseparate`/`pdfunite` (poppler-utils), the same shelling-out shape as
`DoclingConverter` and `italix/media`'s `PdfPageToImageConverter`.

`PdfPageSplitter::extract()` with `$boundaries = null` returns every page as its own single-page
PDF. An explicit boundary is a page *range* `[first, last]`, not a single page number — `[3, 3]` is
one page, `[2, 5]` is four pages combined into one piece — so both "every page separately" and
"these three specific ranges" are the same shape. A multi-page boundary is produced by re-combining
the already-split pages via `pdfunite` internally (`PdfMerger`'s own mechanism, kept self-contained
here rather than depending on the sibling class for what is really an implementation detail of
`extract()`).

**A real bug, found only by running this against a real PDF, not by reading the code**: the first
version of the `pdfunite` shell-out reversed its argument order —
`pdfunite <output> <input1> <input2>` instead of `pdfunite <input1> <input2> <output>` (output
last). It failed with `I/O Error: Couldn't open file 'range-2-3.pdf'` / `Could not merge damaged
documents` — an error message that reads like a corrupt-input complaint, not an argument-order one,
which is exactly why it needed to actually run against real files rather than being caught by
inspection. The same reversed-argument mistake was copy-pasted into `PdfMerger::merge()` itself and
fixed there too, from the same discovery.

`tests/PdfPageSplitterTest.php` (13 assertions) and `tests/PdfMergerTest.php` (10 assertions), both
against the real binaries, both checking actual page counts via `pdfinfo` rather than trusting
non-empty bytes — that distinction is what caught the argument-order bug in the first place: a
naive "the output is non-empty" assertion would have passed on the corrupted single-input file
`pdfunite` produced before quitting.

## [0.6.0] — 2026-09-03

### Added

`DoclingConverter` — `pdf`/`docx`/`pptx` to Markdown, via [Docling](https://github.com/docling-project/docling)
shelled out to, the same way `italix/media`'s `PdfPageToImageConverter` shells out to Ghostscript.
Closes the gap the rest of this library's converters leave open: PDF/DOCX/PPTX have only ever been
conversion *targets* here (`HtmlToPdfConverter`), never sources feeding INTO Markdown — the missing
half of turning an arbitrary uploaded document into something `Rendered::text()` can chunk and embed
(see `italix/vectors`' `examples/documents-connector.php`, the reason this was worth building now).

Not a lightweight addition: `docling[standard]` pulls in a real ML stack (torch, torchvision,
transformers, its own layout/table/OCR models) — a 6.2GB virtualenv, verified by installing it fresh
in an isolated venv, not assumed from its listed dependencies. `is_available()` only checks that the
configured binary is on `PATH`; it deliberately does not try to detect whether that binary's model
cache is warm, because a fresh install passes that check yet still blocks on its first real call —
measured directly at 42.8s and ~530MB pulled from huggingface.co and modelscope.cn together, on a
trivial one-paragraph PDF. A deployment that wants predictable first-request latency needs to
pre-warm that cache itself.

**OCR is on by default even for born-digital PDFs with a real text layer, and dominates wall-clock
time** — measured against two real API-docs PDFs from a payment provider, not synthetic fixtures: a 5-page PDF with a
handful of embedded screenshots took 400.35s end-to-end, CPU-only (no GPU on the box this was measured
on). The new `DoclingConverter::OPTION_OCR` generic option (`ConversionOptions::none()->with(...,
false)`) skips it — the one lever that turns minutes into seconds for a PDF that already has real
text. Quality on that same real document, checked by reading the actual output: headings, paragraphs
and a genuine table all survived correctly; two lines had visibly dropped characters traced to that
specific PDF's own font encoding, not reproduced on the synthetic test fixture — a reminder to
spot-check real sources rather than assume uniform quality. Embedded images come through as inline
base64 data URIs by default, worth stripping before chunking.

`pairs()` deliberately covers only the three formats actually run against real files here — Docling
claims support for a much longer list (xlsx, html, images, odt/odp/ods, …) that this class does not
yet declare, on the same "verified before claimed" basis as everything else in this changelog.

**Embedded images turned out to be the bigger cost, on real documents — worse than OCR.** Discovered
running a real 23-page onboarding guide (45 UI screenshots) end to end: 1010.94s and an
**11.3MB** Markdown file, 99.97% of which (11,281,501 of 11,285,187 bytes) was base64 image data
against under 4KB of real prose. `image-export-mode`'s default (`embedded`) is doing exactly what it
says; it is simply the wrong default for feeding a chunker. `DoclingConverter::OPTION_IMAGE_MODE`
exposes Docling's own toggle the same way `OPTION_OCR` does — `placeholder` mode drops the bytes and
just marks where an image was. Left at Docling's own default unless a caller asks otherwise, same
"never silently loosen a driver's default" reasoning as OCR. `referenced` mode is accepted but
documented as not fully usable through this driver: `convert()` returns bytes only, and the temp
output directory Docling would write the referenced image files into is deleted in `finally` before
`convert()` returns — a caller that genuinely needs separate image files needs this driver extended to
return them, not just told the option "worked".

`tests/DoclingConverterTest.php` — 17 assertions against the real driver (a real dompdf-built PDF in,
real Markdown out, a genuinely invalid input throwing `ConversionException` with Docling's own output
attached, registered into a real `ConverterSet`, and a small-scale reproduction of the image-bloat
finding above — proved empirically, not merely asserted: a flat, featureless test image was silently
discarded by Docling's own layout model as noise, so the fixture needed real drawn content before the
embedded-vs-placeholder assertions meant anything), run with OCR explicitly disabled so the suite
stays fast; skips loudly via `IX_DOCLING_BINARY` when Docling is not installed, the same pattern
`italix/vectors`' `PgVectorStoreTest.php` uses for an optional real backend.

## [0.5.0] — 2026-08-28

### Changed — BREAKING

`_c` on function/method names is retired in favor of spelling out what the value actually is —
see `src/Libs/Italix/CONVENTIONS.md`, "`_c` is for variables... only." `_c` stays on variables,
parameters and properties; only method names changed, no behavior. Recorded as MINOR, not MAJOR:
this library stays 0.x deliberately (see `[0.4.0]`), so a breaking change here doesn't borrow a
stability claim it hasn't earned:

- `Node::kind_c()` → `kind_code()`, `Node::extension_c()` → `extension_code()`,
  `Node::path_c()` → `path()`, `Node::lang_c()` → `lang_code()` (a BCP-47 tag — the same
  reasoning as `Contracts\Translator::locale_c()` → `locale_code()`)
- **`Node::name_c()` → `name()`, dropping the postfix entirely** — unlike every other `name_c` in
  this codebase (a technical slug: a command verb, an engine name), a document's title is a
  human-typed string with no fixed vocabulary. Forcing `_code` onto it would have been wrong, not
  just inconsistent.
- `Change::kind_c()` → `kind_code()`, `Change::path_c()` → `path()`
- `Version::storage_path_c()` → `storage_path()`, `archive_path_c()` → `archive_path()`,
  `sha256_c()` → `sha256()`, `mime_c()` → `mime()`, `author_c()` → `author()`,
  `note_c()` → `note()` — none of these are bounded codes: a path is its own type, a hash is a
  hash, an author is an email/username, a note is free-text commentary by definition

Requires `italix/converters` `^0.3` now (was `^0.2`), for the same rename applied there.

### Changed — BREAKING

- **`MarkdownToHtmlConverter::convert()` and `HtmlToPdfConverter::convert()` both gained a required
  fourth parameter**, `Italix\Converters\ConversionOptions $options`, following `italix/converters`
  0.2.0's contract change. `MarkdownToHtmlConverter` does not read it — Markdown to HTML has no
  quality, page or colorspace concept to configure. `HtmlToPdfConverter` reads `COLORSPACE`: dompdf's
  output is RGB only, so a colorspace other than `rgb` now throws `UnsupportedOptionException` instead
  of silently producing an RGB PDF anyway — a print vendor expecting CMYK and receiving RGB is a
  correctness bug, and this is where that request should fail, loudly, not downstream when a proof
  comes back the wrong color.
- Requires `italix/converters` `^0.2` now (was `^0.1`).

## [0.3.0] — 2026-08-27

### Added

- **`HtmlToPdfConverter`** — a second `italix/converters` driver, `html`/`htm` → `pdf`, via
  `dompdf/dompdf` (pure PHP, no external binary or subprocess). dompdf's own conservative defaults
  are kept as-is (`isRemoteEnabled = false`, `isPhpEnabled = false`) — the same reasoning
  `MarkdownRenderer` already applies to raw HTML in Markdown, just for a different driver. Paper size
  is set explicitly to A4 portrait, overriding dompdf's US Letter default. `dompdf/dompdf` is
  `suggest`-only here, same treatment as `league/commonmark`: this library still works without it,
  `is_available()` just reports the html->pdf route as unreachable.

## [0.2.0] — 2026-08-27

### Added

- **`MarkdownToHtmlConverter`** — `MarkdownRenderer` exposed as an `italix/converters` `Converter`
  driver (`md`/`markdown`/`mdown`/`mkd` → `html`), so a `ConverterSet` can route through it alongside
  drivers from other libraries. A thin adapter, not a second implementation: it wraps the existing
  renderer, so the same escaping and unsafe-link rules apply either way. `is_available()` checks
  `league/commonmark`'s presence directly instead of constructing a `MarkdownRenderer` to find out,
  since that constructor throws on a missing dependency — correct for a tree that needs Markdown to
  render, wrong for a driver a `ConverterSet` needs to probe without side effects.

### Changed

- **`composer.json`'s `php` floor raised from `>=7.4` to `>=8.1`** — `italix/converters` is now a
  real dependency (`Converter`, `ConvertedDocument`) and its own contract types use constructor
  property promotion with `readonly`, which is 8.1-only. Per `VERSIONING.md`, "an environment break
  is still a break," which is why this is a MINOR rather than a PATCH despite touching no existing
  public signature. Not a MAJOR/`1.0.0`: this library's own README still calls it "phase 1" of three,
  and reaching `1.0.0` should mean that milestone, not an incidental floor bump — recorded honestly
  as a breaking environment change within the 0.x series rather than treated as a stability claim
  nothing here has earned yet.

## [0.1.1] — 2026-08-13

### Legal

- **Licensed under MPL-2.0**, applied 2026-08-13: the `license` field in `composer.json`, a `LICENSE`
  file, and the Exhibit A notice in every source file — MPL §1.4 defines "Covered Software" per file,
  so the per-file header is what makes the licence apply rather than decoration.

  This is a **first declaration, not a relicensing.** The package carried no licence at all before,
  which in most jurisdictions means all rights reserved: nothing had been granted, so nothing is
  taken away and no consumer's position gets worse. That is why it is recorded here rather than
  treated as a breaking change — unlike `italix/orm`, which went Apache-2.0 → MPL-2.0 and took a
  MAJOR because that direction does narrow what a consumer already had.

## [0.1.0] — 2026-08

Phase 1 of `FRAMEWORK-DOCUMENTS.md`: a versioned tree you can check out, edit with any tool, and
import back. Deliberately **0.x** — the shape is settled but the phases that follow (links, search,
editions, ACL) will move things, and pretending otherwise with a 1.0 would be a promise this cannot
keep yet.

### Added

- **`Library`** — trees, folders, documents, versions. Bytes go to an `italix/storage` `FileStore`
  where nobody chooses a filename; names, structure and history live in a database. Ships an
  `install()` rather than a migration, matching `italix/crypto`.

- **Last-writer-wins that loses nothing.** Every save appends a version and moves `latest`. The
  version it overtook is still there and still readable — which is the only reason
  last-writer-wins is acceptable.

- **`parent_version_id`, and divergence as a fact rather than an error.** A write whose base is no
  longer `latest` is **accepted and flagged** `is_divergent`. It is git's fast-forward test with the
  refusal removed: no merge machinery, no lost work, and both sides plus their common ancestor are on
  record the day somebody wants to build a merge. Passing `null` claims no base and is not divergence
  — flagging every unchecked write would make the flag useless.

- **Identical bytes are not a revision.** Content addressing gives this for free, and it is what makes
  `import` idempotent enough to run from a hook.

- **`Projection`** — the tree as a real directory. `checkout()` writes sources, `status()` reports
  differences, `import()` writes them back, `publish()` renders a static site somewhere else.
  Regenerable, so it never belongs in a backup.

  Sources and rendered HTML go to **separate roots** on purpose: rendering `install.md` beside
  `install.html` would force `import` to know which files it wrote itself, and to decide what to do
  when somebody legitimately adds a document called `install.html`.

- **`ProjectionIndex`** — git's index, as readable JSON. Without it, `import` cannot tell what changed
  and, more importantly, cannot know what the editor was working from.

- **`Path`** — this library lets a human choose the name, so the name is checked rather than replaced:
  traversal, empty segments, NUL and control characters, hidden and flag-shaped names are refused;
  Unicode letters are not, because `città.md` is a reasonable name for an Italian page.

- **`Renderer`** with `MarkdownRenderer` and `PlainRenderer`, selected by **extension** — sniffing
  answers "may this be stored", the extension answers "how should this be read".

  `html_input => 'escape'` and `allow_unsafe_links => false` are **not configurable**. Markdown
  permits raw HTML, and a `<script>` in a `.md` is stored XSS the moment it renders. An application
  that truly needs raw HTML writes its own `Renderer`, which is visible in review.

  Links are read from the parse tree, so a URL inside a code fence is not mistaken for a link.

- **`Library::suggested_catalog()`** — the `italix/storage` default refuses things a documentation
  tree certainly contains. Found by testing: an empty placeholder page is `application/x-empty`; a
  short page whose body is a JSON, XML or **HTML example** sniffs as that. Widening is safe *here*
  because this library never serves a stored blob — every read goes through a renderer and none pass
  raw markup through, which the test suite asserts rather than assumes. SVG stays refused: an image
  would reasonably be served as one.

- **CLI**: `documents:trees`, `documents:checkout`, `documents:status`, `documents:import`,
  `documents:publish`, `documents:log`, `documents:cat`.

### Fixed

- **`publish()` never removed anything.** A document renamed or removed left its page live forever —
  found on a real tree, where a page renamed weeks earlier was still being served. For a withdrawn
  document that is not untidiness, it is a page that should not be readable still being readable.

  Each run now records what it wrote in `.ix-published`, and the next run deletes the pages that were
  in the previous manifest and are not in the new one. **Only those**: a file the library never wrote
  is never touched, which is why this is a manifest rather than "delete every `.html` I did not just
  write" — asserted by a test that drops a foreign file into the publish root and checks it survives.

### Fixed before release

- **The racy second.** `status()` skipped hashing when size and mtime matched the index — and a
  32-byte body replaced by a different 32-byte body within the same second matched both, so a real
  edit read as unchanged and a divergent write went unflagged. Found by a test, not by reading.
  The index now records when it was written and any entry at or after that second is re-hashed, which
  is what git calls "racily clean".

- **Portable schema.** MySQL cannot index a long `VARCHAR` without a prefix length and SQLite parses
  that same syntax as a function call; `CREATE INDEX IF NOT EXISTS` works on SQLite and MariaDB but
  not MySQL. `install()` handles both and stays idempotent on both.

### Not in this release

Wiki links and the link graph, search, editions, ACL, the compressed revision archive, remote
volumes. See `FRAMEWORK-DOCUMENTS.md` §14 for the phasing and §15 for what is parked and why.
