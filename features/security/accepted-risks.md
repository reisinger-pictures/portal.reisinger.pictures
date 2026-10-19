# Accepted Security and Dependency Risks

This file records risks that remain intentionally accepted. Reassess an entry when its stated boundary changes; do not treat acceptance as permission to weaken adjacent controls.

## C8: `braces` in the development dependency chain

The `braces` advisory is accepted while it remains reachable only through development/build tooling, its glob inputs remain repository-controlled, and the repository's real `micromatch` consumers continue to use picomatch-based APIs that do not enter the affected brace-expansion paths. Do not redirect those consumers to `parse()`, `braces()`, or `braceExpand()`. Do not add an unreviewed fork/commit override. Reassess if the dependency path, call sites, input trust, or upstream patch status changes.
