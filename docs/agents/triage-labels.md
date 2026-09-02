# Triage Labels

The skills speak in terms of five canonical triage roles. This file maps those roles to the actual label strings used in this repo's issue tracker.

| Label in mattpocock/skills | Label in our tracker | Meaning                                  |
| -------------------------- | -------------------- | ---------------------------------------- |
| `needs-triage`             | `needs-triage`       | Maintainer needs to evaluate this issue  |
| `needs-info`               | `needs-info`         | Waiting on reporter for more information |
| `ready-for-agent`          | `ready-for-agent`    | Fully specified, ready for an AFK agent  |
| `ready-for-human`          | `ready-for-human`    | Requires human implementation            |
| `wontfix`                  | `wontfix`            | Will not be actioned                     |

When a skill mentions a role (e.g. "apply the AFK-ready triage label"), use the corresponding label string from this table.

**Two of the five exist on the repo today**, `ready-for-agent` and `wontfix`, checked 29 August 2026. The other three are the names to create rather than names already there, so `gh issue edit --add-label needs-info` fails until somebody makes it. Create it with `gh label create`; do not invent a near miss, because a second spelling of a role is how a query silently returns nothing.

Edit the right-hand column to match whatever vocabulary you actually use.
