# Issue tracker: GitHub

Issues and specs for this repo live as GitHub issues on `YoussefSanad/TheWorldTarot`. Use the `gh` CLI for all operations.

**This repo and the frontend are two trackers, not one.** The Next.js app is `YoussefSanad/theworldtarot-frontend` and files its own issues, per `CLAUDE.md` section 1. A fault found from the frontend that turns out to be ours is opened here with the captured evidence, and the reverse. Never assume a bare `#42` is this repo's: say which.

## Conventions

- **Create an issue**: `gh issue create --title "..." --body "..."`. Use a heredoc for multi-line bodies.
- **Read an issue**: `gh issue view <number> --comments`, filtering comments by `jq` and also fetching labels.
- **List issues**: `gh issue list --state open --json number,title,body,labels,comments --jq '[.[] | {number, title, body, labels: [.labels[].name], comments: [.comments[].body]}]'` with appropriate `--label` and `--state` filters.
- **Comment on an issue**: `gh issue comment <number> --body "..."`
- **Apply / remove labels**: `gh issue edit <number> --add-label "..."` / `--remove-label "..."`
- **Close**: `gh issue close <number> --comment "..."`

Infer the repo from `git remote -v`; `gh` does this automatically when run inside a clone.

## Keeping the issue current

**Progress on an issue is always written back to the issue.** Not to the branch, not to a commit body alone, and not to a chat transcript that nobody can read afterwards. The issue is the one surface a person who was not in the room can open and learn where the work actually stands.

This is not optional and it is not the last step. Write to the issue at each of these:

- **When the work is picked up**, if anyone else could pick up the same thing. `gh issue edit <n> --add-assignee @me`
- **When a `Done when` box lands**, tick it. Do not save the whole checklist for the end, since a half-ticked list is exactly the information somebody arriving mid-flight needs
- **When the work ships**, comment with the commit SHA, the branch, and what was verified rather than assumed. A reviewer should not have to re-derive the mapping from box to code
- **When it is blocked, or the spec turns out to be wrong**, say so in a comment the same day. An issue that has gone quiet is indistinguishable from an issue nobody started
- **When the decision behind it changes**, per `CLAUDE.md` section 0.6, struck through and dated rather than quietly edited away

**Say which state the work is in, because this repo distinguishes them.** Committed, pushed and deployed are three different things here, and `docs/CHECKPOINT.md` is the authority on which version is live. "Done" on its own does not say whether a customer can reach it. An issue whose code is merged but unshipped stays open and says why.

**Ticking a box is a claim that somebody checked.** Tick what was verified against the tree, and where a box asserts something a later reader cannot re-derive, say that in the comment instead of implying it was proved.

## Pull requests as a triage surface

**PRs as a request surface: no.** _(Set to `yes` if this repo treats external PRs as feature requests; `/triage` reads this flag.)_

When set to `yes`, PRs run through the same labels and states as issues, using the `gh pr` equivalents:

- **Read a PR**: `gh pr view <number> --comments` and `gh pr diff <number>` for the diff.
- **List external PRs for triage**: `gh pr list --state open --json number,title,body,labels,author,authorAssociation,comments` then keep only `authorAssociation` of `CONTRIBUTOR`, `FIRST_TIME_CONTRIBUTOR`, or `NONE` (drop `OWNER`/`MEMBER`/`COLLABORATOR`).
- **Comment / label / close**: `gh pr comment`, `gh pr edit --add-label`/`--remove-label`, `gh pr close`.

GitHub shares one number space across issues and PRs, so a bare `#42` may be either: resolve with `gh pr view 42` and fall back to `gh issue view 42`.

## When a skill says "publish to the issue tracker"

Create a GitHub issue.

## When a skill says "fetch the relevant ticket"

Run `gh issue view <number> --comments`.

## What a ticket here is not

**A ticket does not replace a plan.** `CLAUDE.md` section 0.2 requires an approved `docs/plans/` document before implementation starts on a milestone, and section 0.1 requires options rather than a choice for anything touching auth, payments or the AI gate. An issue labelled `ready-for-agent` means the step is scoped, not that either of those has been skipped.

**Not every change here has had one.** The auth work of August 2026 was built with no ticket in this repo at all, which is why the review in `AUTH-REVIEW-FIXES.md` had to reconstruct its spec from `API_CONTRACT.md`, the commit bodies and `docs/plans/unclaimed-account-plan.md`. Where a ticket is missing, those three are the spec, in that order.

## Wayfinding operations

Used by `/wayfinder`. The **map** is a single issue with **child** issues as tickets.

- **Map**: a single issue labelled `wayfinder:map`, holding the Notes / Decisions-so-far / Fog body. `gh issue create --label wayfinder:map`.
- **Child ticket**: an issue linked to the map as a GitHub sub-issue (`gh api` on the sub-issues endpoint). Where sub-issues aren't enabled, add the child to a task list in the map body and put `Part of #<map>` at the top of the child body. Labels: `wayfinder:<type>` (`research`/`prototype`/`grilling`/`task`). Once claimed, the ticket is assigned to the driving dev.
- **Blocking**: GitHub's **native issue dependencies**, the canonical, UI-visible representation. Add an edge with `gh api --method POST repos/<owner>/<repo>/issues/<child>/dependencies/blocked_by -F issue_id=<blocker-db-id>`, where `<blocker-db-id>` is the blocker's numeric **database id** (`gh api repos/<owner>/<repo>/issues/<n> --jq .id`, _not_ the `#number` or `node_id`). GitHub reports `issue_dependencies_summary.blocked_by` (open blockers only, the live gate). Where dependencies aren't available, fall back to a `Blocked by: #<n>, #<n>` line at the top of the child body. A ticket is unblocked when every blocker is closed.
- **Frontier query**: list the map's open children (`gh issue list --state open`, scoped to the map's sub-issues / task list), drop any with an open blocker (`issue_dependencies_summary.blocked_by > 0`, or an open issue in the `Blocked by` line) or an assignee; first in map order wins.
- **Claim**: `gh issue edit <n> --add-assignee @me`, the session's first write.
- **Resolve**: `gh issue comment <n> --body "<answer>"`, then `gh issue close <n>`, then append a context pointer (gist + link) to the map's Decisions-so-far.
