# Accepted Security and Dependency Risks

This file records risks that remain intentionally accepted. Reassess an entry when its stated boundary changes; do not treat acceptance as permission to weaken adjacent controls.

## C8: `braces` in the development dependency chain

The `braces` advisory is accepted while it remains reachable only through development/build tooling, its glob inputs remain repository-controlled, and the repository's real `micromatch` consumers continue to use picomatch-based APIs that do not enter the affected brace-expansion paths. Do not redirect those consumers to `parse()`, `braces()`, or `braceExpand()`. Do not add an unreviewed fork/commit override. Reassess if the dependency path, call sites, input trust, or upstream patch status changes.

**Reachability is not the same as classification (measured 2026-10-21).** The advisory is reachable only at build time — the delivered bundle contains neither `micromatch` nor `braces` (0 hits in `dist/`), and the repository's own code has no call site for `braceExpand`/`braces` (0 hits in `src/`, `tests/`, `scripts/`). But the dependency path is `.>@lingui/vite-plugin>@lingui/cli>micromatch>braces`, and `@lingui/vite-plugin` stands in `dependencies` — so `pnpm audit --prod` counts the alert even though nothing runs it at request time. The accepted risk covers the **execution path**, not the dependency classification: an unchanged build-time-only path stays inside C8, while a dependency that moves into the request path leaves it. `braces@3.0.3` is the highest published version and the advisory has no patched version (`first_patched_version: null`), so a version bump cannot close the alert today.

**The GitHub alert was dismissed on 2026-10-22** (owner decision): `GHSA-vfj7-8cjw-p6xm` (`braces`, `high`, `frontend/pnpm-lock.yaml`) is closed with `dismissed_reason=tolerable_risk` and a comment that names C8. The dismissal is the record of the acceptance in GitHub, not a change of the accepted boundary: the boundary above is unchanged, and a patched version would reopen the question (the dismissal is replaced automatically as soon as a fixed version is available).

The two `@vitest/mocker` advisories that were open at the same time (**GHSA-qxg6-89mg-rqv8** and **GHSA-xp26-3454-mjgp**, both `medium`) were **not** accepted: they are fixable in `4.1.12`, and the vitest family was raised to that version (`frontend/package.json`, `^4.1.12`, commit `c72329ec`). An alert that can be closed by a version bump is closed by the bump and not by a dismissal.
