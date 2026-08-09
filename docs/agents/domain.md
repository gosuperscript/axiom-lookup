# Domain docs

This repository uses a single domain context.

## Before exploring

Read these sources when they exist:

- `CONTEXT.md` at the repository root for domain language and invariants.
- Relevant decisions under `docs/adr/`.

Proceed silently when either source does not yet exist. Domain documentation is created lazily when a term or architectural decision is resolved.

## Use the glossary vocabulary

Use terms as defined in `CONTEXT.md` when naming domain concepts in proposals, issues, tests, and code. Avoid drifting to synonyms that the glossary rejects.

If a required concept is absent, first reconsider whether the design is introducing unnecessary language. Add the term only when it represents a genuine domain concept.

## Flag ADR conflicts

Surface any conflict with an existing ADR explicitly rather than silently overriding the recorded decision.
