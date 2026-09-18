# Knowledge Bases

A knowledge base gives an AI agent access to a client's own material — an
FAQ, a services list, an employee handbook — so it can answer questions
specific to that business instead of guessing.

## How it works

Documents are split into passages and indexed. When a caller asks something,
the agent retrieves the passages most relevant to the question and answers
from those.

The practical consequence: **an agent answers from what you gave it.** If a
client's opening hours are not in the knowledge base, the agent does not
know them, and a good agent will say so rather than invent them. Filling
gaps is a content problem, not a prompt problem.

## Isolation

Every knowledge base belongs to exactly one client, and retrieval is scoped
to that client's stores. One client's documents can never surface in another
client's call.

This is enforced in the platform rather than in the prompt, because a rule
that lives only in an instruction is a rule a model can be talked out of.

## Where they live

Knowledge bases are called **knowledge stores** in the admin panel, and they
are managed from one cross-client page: **Conversational AI → Knowledge
Stores**, which is super-admin only.

Each store still belongs to exactly one client. The page is a platform-wide
view of every client's stores rather than a shared pool — you pick the owning
client when you create one.

## Setting one up

1. Create a store under **Conversational AI → Knowledge Stores**, assign it to
   a client, and give it a name that says what is in it — "Northgate Dental
   FAQ" rather than "Docs".
2. Add content. Upload a file, paste text, or add a URL. Ingestion runs on the
   queue, so a large document does not block the page — the store shows as
   indexing until it finishes.
3. Attach it to the client's [persona](ai-agents.md), or to an individual
   intake goal that needs it.

A client can have several. Separate stores for "services and pricing" and
"clinical policies" retrieve more precisely than one store holding both.

## Re-indexing

Changing the embedding provider or model means existing passages were indexed
against a different one. Re-index the store from its row when that happens —
mixed embeddings retrieve badly in a way that looks like the content being
wrong rather than the index being stale.

## What to put in

**Good content** is the answer to a question somebody actually asks: opening
hours, what is and is not treated, parking, whether a referral is needed,
what to do in an emergency.

**Poor content** is anything long, structural, or written for a different
purpose. Marketing brochures, full contracts, and internal process documents
retrieve badly because the passage that matches the question is buried in
material that does not.

Short, direct, question-shaped entries work best. If a client hands you a
40-page PDF, the useful move is to pull the ten things people phone about.

## Keeping it current

Nothing expires on its own. A knowledge base with last year's hours will
confidently tell callers last year's hours.

Make it part of the client review: when their details change, their
knowledge base changes with them. This is the most common way an AI agent
starts giving wrong answers, and it never announces itself — the agent
sounds exactly as confident as it did when the content was right.

## What it is not

A knowledge base is not the script. What the agent *collects* is the
[orchestration](orchestrations.md); the knowledge base is what it can
*answer*. Putting intake questions here does not work — they are not
retrieved reliably, and the human-operator script would not get them.

## See also

- [AI Agents](ai-agents.md) — attaching a store to a persona
- [Orchestrations](orchestrations.md) — what the agent collects
