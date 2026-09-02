# Domain Docs

How the engineering skills should consume this repo's domain documentation when exploring the codebase.

## Before exploring, read these

- **`CLAUDE.md`** at the repo root: the standing rules, and section 0 is how the work is done rather than what it does. Read it first.
- **`docs/CHECKPOINT.md`**: what is true today, which decisions reversed earlier ones, and what is next. `CLAUDE.md` says explicitly that this file wins where the two disagree.
- **`CONTEXT.md`** at the repo root.
- **`docs/adr/`**: read ADRs that touch the area you're about to work in.

If any of these files don't exist, **proceed silently**. Don't flag their absence; don't suggest creating them upfront. The `/domain-modeling` skill (reached via `/grill-with-docs` and `/improve-codebase-architecture`) creates them lazily when terms or decisions actually get resolved.

## Two more authorities this repo has, that a glossary does not replace

- **`API_CONTRACT.md` is the agreement with the frontend partner and is authoritative for response shapes.** Changing a shape is a coordination event, not a unilateral change. `.claude/api.md` says so and deliberately does not restate the shapes, because a second copy is what let a stale field survive there for three weeks.
- **`docs/plans/`** holds the record of why things are as they are, each plan carrying a status banner saying whether it is drafted, approved, built or superseded. A plan marked superseded is not a live design, and reading one as though it were is the failure the banner exists to prevent.

## File structure

Single-context repo:

```
/
├── CLAUDE.md
├── CONTEXT.md
├── API_CONTRACT.md
├── .claude/                           standing conventions, loaded by path
├── docs/
│   ├── CHECKPOINT.md
│   ├── adr/
│   └── plans/
└── app/
```

## Use the glossary's vocabulary

When your output names a domain concept (in an issue title, a refactor proposal, a hypothesis, a test name), use the term as defined in `CONTEXT.md`. Don't drift to synonyms the glossary explicitly avoids.

**The frontend keeps its own `CONTEXT.md` and the two are meant to agree.** Where a word appears in both, it means the same thing on both sides of the wire, and a drift is worth naming rather than quietly translating.

If the concept you need isn't in the glossary yet, that's a signal: either you're inventing language the project doesn't use (reconsider) or there's a real gap (note it for `/domain-modeling`).

## Flag ADR conflicts

If your output contradicts an existing ADR, surface it explicitly rather than silently overriding:

> _Contradicts ADR-0001 (one film implementation, two named adapters), but worth reopening because..._

The same goes for a decision in `CLAUDE.md` section 4. Hard rule 14 is explicit: if a decision there appears wrong, say so rather than quietly building around it.
