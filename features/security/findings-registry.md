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

One entry per reviewed finding. The registry started with the 14 findings of the
2026-10-18 architecture and security review (`SEC-01`…`SEC-14`) and also carries the
eight open findings of the 2026-10-18 audit against snapshot `f3aea9e0`. The two
series number differently: the review uses two digits (`SEC-02`), the snapshot audit
one (`SEC-2`).

| Field | Meaning |
|---|---|
| `id` | the identifier of the finding in its review (`SEC-01`) |
| `title` | the finding in one line |
| `severity` | the canonical level: `critical` (P1), `high` (P2), `medium`, `low` |
| `status` | the canonical status — `fixed` for the 14 review findings, `open` for the eight snapshot-audit findings |
| `status_since` | the day the status was reached |
| `regression_test` | the test that proves the fix |
| `path` | the document that holds the finding |
| `note` | the substance of the finding: the commits that closed it, or what it needs |

`owner` and `ticket` are mandatory for every entry that is **not** `fixed`: an
open finding has nobody, and a finding without an owner is a graveyard entry.
The closed entries carry their commit and their regression test instead.

### The open findings

All eight are `open`; each one is a GitHub issue, and the issue carries the
decision or the fix that closes it.

| ID | Severity | Status | Issue | Title (registry wording) | Note (registry wording) |
|---|---|---|---|---|---|
| `AUTH-2` | `high` | `open` | #24 | Account-Existence-Oracle bei Registrierung | Registrierung verrät über `unique:users` existierende Adressen; Fix erfordert generische Antwort plus Benachrichtigung an bestehende Adresse (bedürfe Owner-Entscheidung) |
| `PAY-3` | `high` | `open` | #25 | paid ohne Settlement-Prüfung über Management-Route setzbar | `OrderController::updateStatus()` schreibt `paid` direkt; download-berechtigt. Admin-only, aber keine Prüfung. Entscheidung nötig. |
| `PAY-4` | `medium` | `open` | #26 | charge.dispute.closed nicht abonniert / Wiederherstellung ungeklärt | `disputed` sperrt Downloads, aber gewonnener Dispute wird nicht automatisch bearbeitet - Owner-Entscheidung |
| `PAY-5` | `medium` | `open` | #27 | Teil-Refunds nicht erfasst, Payout-Berechnung gefährdet | Refund-Beträge werden nicht gespeichert; `paid` bleibt `paid` |
| `SEC-2` | `medium` | `open` | #28 | FTPS ohne erzwungenes AUTH TLS | Bewusste Kompatibilitätsentscheidung für Kamera, aber dokumentiert offen |
| `MEDIA-5` | `medium` | `open` | #29 | SFTPGo-Symlinks / realpath-Konfinierung nicht bestätigt | `is_file()`/`fopen()` folgen Symlinks, keine realpath-Konfinierung |
| `OPS-2` | `medium` | `open` | #30 | Klartext-Kommunikation im internen Netz | JWT-Fallback sendet Credentials; nur intern erreichbar |
| `OPS-3` | `medium` | `open` | #31 | Unsichere Dateirechte / Sync-Rechte | `sync.sh` setzt F666/D2777, read-write mounts |

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
visible — a decision is not a deletion. The count is **2** today: `AUTH-2` and
`PAY-3` are `open` and `high`; the six `medium` findings stay visible and do not
count. The baseline is 2 and the direction is `ceiling`: the number may only
fall.

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

The registry holds the **findings of the 2026-10-18 reviews** — the architecture
and security review and the audit against snapshot `f3aea9e0`. Three classes
stay outside it, and each of them is named here rather than left implicit:

- The **accepted risks** (`features/security/accepted-risks.md`) are decisions
  about a dependency, not findings of the review. They stay in their own
  document.
- The **open work** of the project lives in GitHub issues. An issue may name a
  finding and carry the decision or the fix, but an issue is not itself a
  registry entry: the registry holds findings with a canonical severity and
  status, the tracker holds work. The three layers are distinct —
  `ratchet.findings.json` is the machine-readable registry, the GitHub issues
  are the work list, and `features/security/*` is the human record.
- The **findings of further reviews** — the `CR-*` series in
  `features/infrastructure/` and the other feature documents — belong to their
  own rounds. They are not findings of the 2026-10-18 reviews, and folding them
  in would give one number two meanings.

## The mirror is not machine-checked

The registry is maintained **by hand** next to the prose: a finding that is
added to the closure document without the registry is not counted, and no test
compares the two. The rule that binds them is the review of the diff: prose
and registry are migrated in the same commit. The CI runs the gate when the
registry changes — and, since the closure document is an input of the gate,
also when the document changes; it does not compare them.

The `path` of an entry points at the document that holds the finding: for the
14 review findings the **closure table**, which names the closing commits and
the regression test, and for the eight snapshot-audit findings this document,
whose table above is their human record. Neither path is the line of code the
defect lived in.

