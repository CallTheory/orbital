# Publishing the documentation

`docs/` is the single documentation tree, and it has one destination:
`learn.calltheory.com/orbital`, rendered by our own documentation system.

There is no build step in this repo and no site config to keep in sync. The
files here are the artifact — copy them across and the renderer does the rest.

There used to be a separate public tree. There is no longer, because Orbital is
AGPL and the split was inherited from when it was not — almost nothing in here
is worth withholding from the people who can already read the source.

## Copying to the wiki

```bash
rsync -av --delete \
  --exclude 'plans/' \
  --exclude 'release/' \
  --exclude 'publishing.md' \
  docs/ ../learn.calltheory.com/content/orbital/
```

`--delete` matters: a page removed here should disappear there rather than
linger as an orphan nobody maintains.

### The exclude list, and why each is on it

| Excluded | Because |
|---|---|
| `plans/` | Design documents for work that is not built. Publishing them invites "when is this shipping?" — [`roadmap.md`](roadmap.md) is the public answer to that question instead. |
| `release/` | Describes tagging across our own repositories. Accurate, and useless to anyone else. |
| `publishing.md` | This page. It is about publishing, not about Orbital. |

Nothing is on that list because it is secret. If something genuinely sensitive
ever needs documenting, it does not belong in a docs tree at all.

## Structure comes from the tree

There is no navigation file. The directory layout and the filenames are the
structure, and each page's title is its H1.

So where a new page goes is decided by where you put it. A new admin topic is
`docs/admin/<topic>.md` and needs nothing else to appear. Add a line to
[`index.md`](index.md) as well, so the page is reachable by reading rather than
only by searching.

[`reference/admin-navigation.md`](reference/admin-navigation.md) maps every
admin panel page to the guide covering it. When you add a page, add its row —
that table is what keeps the docs and the panel from drifting apart.

## Writing rules

**Plain CommonMark.** Tables, fenced code, blockquotes, lists, links. Use
blockquotes for callouts.

**No Material-specific syntax.** No `!!! note` admonitions, no `=== "tabbed"`
blocks, no `{: .attr }` lists. These were never portable and nothing renders
them now.

**Mermaid works.** A code fence tagged `mermaid` renders as a diagram. Use it where
a diagram genuinely explains something a paragraph cannot — a sequence with
branches, a state machine. Plain ASCII boxes are fine too, and several pages
use them; both render, so pick whichever communicates better rather than
whichever is newer.

**One H1, on the first line.** The renderer takes the page title from it.

**No frontmatter.**

**Relative links with the `.md` extension.** `[Clients](clients.md)` within a
folder, `[Backups](../admin/backups.md)` across one.

**Write for the reader, not the maintainer.** "The queue rings the group's
members", not "QueueMemberSyncer expands members into the realtime
queue_members table". Pointing at a source file for further reading is fine —
readers can see it — but the page should stand on its own.

**Name the gaps.** Where something is half-built or not built at all, say so in
a blockquote on the page and link to [`roadmap.md`](roadmap.md). A doc that
describes an intended feature as a shipped one costs more trust than the
missing feature does.

## Checking before you copy

```bash
python3 - <<'CHECK'
import re, pathlib
skip = {'plans', 'release'}
problems = []
for f in sorted(pathlib.Path('docs').rglob('*.md')):
    if set(f.parts) & skip or f.name == 'publishing.md':
        continue
    t = f.read_text()
    if t.startswith('---'):
        problems.append(f"{f}: has frontmatter")
    if not t.lstrip().startswith('# '):
        problems.append(f"{f}: does not open with an H1")
    body = re.sub(r'```.*?```', '', t, flags=re.S)
    if len(re.findall(r'^# ', body, re.M)) != 1:
        problems.append(f"{f}: needs exactly one H1")
    if re.search(r'^\s*!!!|^\s*===\s*"|\{:\s*[.#]', body, re.M):
        problems.append(f"{f}: uses Material-only syntax")
    for link in re.findall(r'\]\(([^)#]+?\.md)(?:#[^)]*)?\)', t):
        if link.startswith(('http://', 'https://')):
            continue          # absolute URL, not ours to resolve
        if not (f.parent / link).resolve().exists():
            problems.append(f"{f}: broken link -> {link}")
print('\n'.join(problems) if problems else 'clean')
CHECK
```

## Keeping it in step

Documentation goes stale silently — nothing fails, the pages just quietly
describe a product that no longer exists. Two habits prevent it:

- **When you change behavior a user can see, change the page in the same
  commit.**
- **When you add a feature, decide which page covers it before you finish.**
  A feature with no page is a feature nobody finds.
