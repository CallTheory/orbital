# Directories

A directory is a client's phone book — the people their business deals with,
that an agent or an operator looks up while a call is happening. Employees to
transfer to, an on-call rota, patients, members, contractors.

Directory entries never have logins. They are records to look things up in,
not accounts. The client's actual portal users live on the client's **Users**
sub-page.

## The field schema comes first

Every client's directory has its own **schema**, set on **Directory Fields**
under that client. This is unusual and it is the point: a dental practice and
a plumbing contractor do not keep the same columns, and forcing both into a
fixed name-and-number shape means the useful detail ends up in a notes field
nobody can search.

Each field definition carries:

| Field | Purpose |
|---|---|
| **Key** | The stable internal name |
| **Label** | What operators see |
| **Type** | What kind of value it holds |
| **Options** | The allowed values, for choice-type fields |
| **Role** | Marks a field as filling a well-known job — the name, the phone number — so the rest of the platform can find it without being told the client's column names |
| **Required** | Whether an entry can be saved without it |
| **Help text / Placeholder** | Guidance shown on the form |
| **Sort order** | Where it appears |

**Set the schema before you load entries.** The form, the list columns, the CSV
import targets, and the smart-ingest mapping are all generated from it. Loading
a thousand rows and then discovering the schema was wrong is a much worse
afternoon than the reverse.

The **role** marking is the part worth understanding. It is what lets an
orchestration say "read back their phone number" without knowing that this
particular client calls the column `mobile_primary`.

## Adding entries

Three ways, on the client's **Directory** sub-page.

**By hand.** *Add Entry* opens a form built from the schema.

**CSV or Excel.** *Import CSV / Excel* maps columns onto the schema's fields.
Right for an export out of the client's own system, where the columns are
already consistent.

**Smart ingest.** *Smart ingest (any format)* takes an arbitrary file or pasted
text and parses it into rows shaped to this client's schema. It is a
conversation: you see the proposed rows, and you can correct it in plain
language — "merge rows 2 and 3", "the role column should map to title" — until
the set is right, then commit.

This is the one for what clients actually send you, which is a PDF staff list,
a screenshot of a whiteboard, or an email with names in a paragraph.

Two things to know about it: it sends the content you give it to a model
provider, so treat it the way you treat the rest of your AI configuration; and
its state lives on the page, so a refresh mid-session loses the working set.
Commit when you are happy rather than leaving it open.

## Shared directories

**Preferences → Shared Directories.** A phone book curated by you, the platform
operator, and attached to one or more clients.

Attached clients see its entries during call-time lookups alongside their own.
Use it for the things that are the same across many accounts — your own
escalation numbers, a shared after-hours service, a regional poison control
line — rather than copying the same six rows into thirty directories and
maintaining none of them.

A shared directory has its own field schema, separate from any client's.

## Keeping it current

A directory goes wrong quietly. The number that has changed still looks like a
number, and the agent reads it out with exactly the confidence it had when the
number was right.

Make it part of the client review. It is the same failure mode as a stale
[knowledge base](knowledge-bases.md), and it has the same fix: someone has to
decide to look.

## See also

- [Clients](clients.md) — where a client's directory lives
- [Orchestrations](orchestrations.md) — how a script reaches into the directory
- [Knowledge Bases](knowledge-bases.md) — the other body of per-client content
