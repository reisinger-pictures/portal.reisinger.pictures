# E2E Test Execution Strategy

## Status: Active (2026-07-06)

## Motivation

The Playwright E2E suite grew to ~147 tests across 67 files, running in 2 browser projects (Desktop Chrome + Mobile Chrome = ~294 executions). Full runtime: ~7 minutes. Running the full suite after every code change is unsustainable for development velocity.

## Strategy: Tag-Based Tiered Execution

Tests are categorized by **criticality and scope** via Playwright's built-in `tags` mechanism. This allows running only a relevant subset during development and the full suite before deployment.

### Tag Categories

| Tag | Meaning | When to Run | Estimated Count |
|-----|---------|-------------|-----------------|
| `@smoke` | Critical path: login, auth, guest, basic CRUD | After every code change | ~25-30 tests |
| `@regression` | Full functional regression coverage | Before deployment | ~117 tests |
| `@feature:<name>` | Feature-specific (e.g., `@feature:checkout`, `@feature:brand`) | When working on that feature | varies |
| *(no tag)* | Deep edge cases, visual, performance | Before deployment only | ~10-15 tests |

### Execution Flow

```
Development workflow:
  code change → pnpm test:e2e:smoke (1-2 min) ✓
                                        ↓ fail
                              fix + pnpm test:e2e:smoke --last-failed

Feature-specific work:
  code change → pnpm test:e2e:grep @feature:checkout

Pre-deployment:
  pnpm test:e2e (full suite, 7 min)
        ↓ fail
  pnpm test:e2e:failed (retries)
```

### Tagging Rules

1. **Every `@smoke` test MUST run on Desktop Chrome.** If a test cannot run on mobile (e.g., it tests desktop-only layout), it gets `test.skip(({ browserName }) => browserName !== 'chromium', 'Desktop only')` AND the `@smoke` tag, so it still runs on Desktop.

2. **Feature tags are additive:** `@feature:admin` + `@smoke` is fine. When run with `--grep @feature:admin`, both tagged and untagged tests match.

3. **New E2E tests SHOULD include at least one tag** (`@smoke` if critical, `@feature:<name>` if feature-specific, or nothing for deep edge cases).

4. **Device-specific tests** (e.g., mobile-only gestures) use `@mobile` tag in addition to any other tags.

### Adding Tags to Tests

```typescript
import { test } from '@playwright/test';

test('critical path test', { tags: ['@smoke', '@feature:auth'] }, async ({ page }) => {
  // ...
});
```

## Playwright Configuration

The `playwright.config.ts` MUST keep:
- `fullyParallel: true` — parallel execution within a project
- `workers: 8` — local developer machines

Two projects remain: `Desktop Chrome` and `Mobile Chrome`. The `--grep` filter applies to both unless a test explicitly skips mobile.

## CI / Deployment

When CI is set up:
- **PR checks:** `pnpm test:e2e:smoke` only (fast feedback)
- **Deployment pipeline:** `pnpm test:e2e` (full suite)
- **Retries:** `pnpm test:e2e:failed` if the full suite fails

## Future Optimizations

- Move non-critical tests to weekly/monthly schedule
- Use Playwright Sharding (`--shard`) to split across multiple CI runners
- Evaluate which tests truly need both Desktop and Mobile coverage
