# The findings registry — the security findings as a number

## Status: Implemented (2026-10-21)

The security findings of the 2026-10-18 source review are a list in prose:
`features/security/2026-10-18-review-closure.md` holds them with their
closure. A list cannot answer "how many open high-severity findings are there,
and did that number grow?" — the number is not a number anywhere, so it
cannot be gated.

The **registry** (`ratchet.findings.json`) is the machine-readable view of that
list. The prose document stays the human record; the registry is what the CI
counts. The two are migrated in the same commit and must not disagree about
the severity of a finding.

## What is in the registry

One entry per finding of the review (`SEC-01`…`SEC-14`):

| Field | Meaning |
|---|---|
| `id` | the identifier of the finding in the review (`SEC-01`) |
| `title` | the finding in one line |
| `severity` | the canonical level: `critical` (P1) or `high` (P2) |
| `status` | the canonical status — all 14 are `fixed` today |
| `status_since` | the day the status was reached |
| `regression_test` | the test that proves the fix |
| `path` | the document that holds the finding |
| `note` | the commits that closed it |

`owner` and `ticket` are mandatory for every entry that is **not** `fixed`: an
open finding has nobody, and a finding without an owner is a graveyard entry.
The closed entries carry their commit and their regression test instead.

## What counts

The rule lives in `ratchet.config.jsonc`:

```jsonc
"count": {
  "registry": "ratchet.findings.json",
  "severities": ["critical", "high"],
  "statuses": ["open", "in-progress"]
}
```

`open` and `in-progress` count; `accepted` and `wontfix` do not count but stay
visible — a decision is not a deletion. The count is **0** today: all 14
findings of the review are closed. The baseline is 0 and the direction is
`ceiling`: the number may only fall.

## The gate

The rule is run by the `ratchet` job of `.github/workflows/ci.yml`, and the
job is a dependency of the `CI gate` aggregate: a red gate fails the gate, so
the automerge does not merge a head with an open high-severity finding.

The job uses the action of the `ratchet` project
(`all-the-rest/ratchet-cli`), pinned to a **commit SHA** — this repository's
supply-chain contract (`tests/infrastructure/ci-security-contract.sh`) allows
no moving tag, and the action's `v1` tag is a moving one. The action installs
the released binary and verifies its SHA-256 against the checksums of the
release; the rules, the direction and the units stay in this repository's
config.

## What is not in the registry

The registry holds the **findings of the 2026-10-18 source review**. Three
classes stay outside it, and each of them is named here rather than left
implicit:

- The **accepted risks** (`features/security/accepted-risks.md`) are decisions
  about a dependency, not findings of the review. They stay in their own
  document.
- The **open work** of the project (`AGENTS.todo.md`) is a task list with its
  own severities (P0–P3). Its severities are the project's, not the canonical
  ones, and a task is not a finding of a review: the two lists are counted by
  different means.
- The **findings of the other reviews** — the `CR-*` series in
  `features/infrastructure/` and the other feature documents — belong to their
  own rounds. They are not findings of the 2026-10-18 review, and folding them
  in would give one number two meanings.

## The mirror is not machine-checked

The registry is maintained **by hand** next to the prose: a finding that is
added to the closure document without the registry is not counted, and no test
compares the two. The rule that binds them is the review of the diff: prose
and registry are migrated in the same commit. The CI runs the gate when the
registry changes — and, since the closure document is an input of the gate,
also when the document changes; it does not compare them.

The `path` of an entry points at the **closure table**, the document that
holds the finding: the table names the closing commits and the regression
test, not the line of code the defect lived in.

