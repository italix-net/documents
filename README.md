# Italix Documents

[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.1-8892BF.svg)](https://php.net/)
[![License](https://img.shields.io/badge/license-MPL%202.0-blue.svg)](LICENSE)

A versioned tree of documents with human names — projected onto a real directory you can open in
`vim`, search with `grep`, copy with `rsync` and read with `lynx`.

**Status: 0.1.0, phase 1.** Trees, versions, checkout/import, Markdown rendering. Links, search,
editions and ACL are phases 2 and 3 — see `FRAMEWORK-DOCUMENTS.md` at the project root.

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

A page whose document was renamed or removed is **deleted** on the next publish. Each run records
what it wrote in `.ix-published` and removes what the previous run wrote and this one did not — only
that, so a file the library never published is never touched.

Sources and rendered output go to different directories on purpose. Rendering `install.html` beside
`install.md` would force `import` to know which files it wrote itself, and to decide what to do when
somebody legitimately adds a document called `install.html`. Two roots, and neither question arises.

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

SVG stays refused: an image would reasonably be served as one, and then the guarantee no longer
covers it.

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

`php >= 7.4`, `ext-mbstring`, `ext-json`, `ext-pdo`, `italix/storage ^2.0`.

`league/commonmark` is a **suggestion**: without it a tree still works and Markdown renders as escaped
text, because a tree of PDFs and images is a perfectly good tree and should not require a Markdown
parser to exist.

Tested against SQLite and MySQL/MariaDB. `install()` is idempotent on both.
