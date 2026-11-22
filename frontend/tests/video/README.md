# Demo video category

This directory is the dedicated category for **demo/promo videos**. It is
deliberately kept separate from the other two Playwright sets:

| Set | Config | `testDir` | Video |
|---|---|---|---|
| Functional E2E suite | `playwright.config.ts` | `tests/e2e` | `off` |
| UI review screenshots | `playwright.screenshots.config.ts` | `tests/screenshots` | `off` |
| **Demo videos** | `playwright.video.config.ts` | `tests/video` | only here |

The demo specs are **not** run by `pnpm test:e2e`, `pnpm test:e2e:smoke` or
`pnpm test:screenshots`. They run only with:

```bash
cd frontend && pnpm test:demo-video
```

Every test in this category carries the `@demo-video` tag in addition to a
functional tag (`@feature:...`), as required by the E2E tag policy.

**Multiple videos and different flows:** The config loads every `*.spec.ts` in
this directory; `harness.ts` only provides the shared infrastructure
(unrecorded login, the boot phase trimmed from the recording, the image check,
publish-on-success). Each spec describes its **own** flow and writes its film to
`public/demo-videos/<spec-file-name>.webm` (derived from `testInfo.file`). A new
video with a different flow is therefore a new spec file — no change to this
config, the harness or any other spec.

## Recording contract

- Login runs in an **unrecorded** browser context. The app boot is measured
  while the recording is running and at publish time the shared harness cuts
  that many seconds from the start of the raw capture (frame-accurate
  VP9/WebM re-encode). No white boot or login screen appears in the video, and
  every future spec inherits this guarantee without a per-spec flag.
- The concrete story lives in the spec. For `promo-video.spec.ts` it is: two
  real photos from `fixtures/`, upload defaults including the correct author,
  a metadata edit restored from the version history, and both images in the
  detail view at the end — as an **example** only.
- The video is published to
  `frontend/public/demo-videos/<spec-file-name>.webm` only when the test
  **succeeds**. On any failure — in particular when an image is not really
  loaded (`naturalWidth === 0`) — the test fails and **no** downloadable video
  is produced. A trim/conversion failure likewise deletes the raw capture and
  the target video.
- The raw capture lives under `test-results/demo-video/<test>/raw/` during the
  run and is deleted afterwards, on success as well as on failure.
