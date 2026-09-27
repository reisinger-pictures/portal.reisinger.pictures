import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react';
import lingui from '@lingui/vite-plugin';
import babel from '@rolldown/plugin-babel';
import { linguiTransformerBabelPreset } from '@lingui/vite-plugin';

export default defineConfig({
  plugins: [react(), lingui(), babel({ presets: [linguiTransformerBabelPreset()] })],
  test: {
    environment: 'jsdom',
    globals: false,
    include: ['src/**/*.test.ts', 'src/**/*.test.tsx', 'scripts/**/*.test.mjs', 'tests/e2e/helpers/**/*.test.ts'],
    setupFiles: ['src/test-setup.tsx'],
    css: true,
    // console.error aus erwarteten Fehlerpfaden in Tests unterdrücken
    printConsole: false,
    // Per-test budget, derived from measurement — not a round-number guess.
    //
    // Vitest's 5000 ms default is only 1.7x above the slowest *ordinary* test
    // this suite actually produces, which leaves nothing for CPU contention.
    // Measured on an 18-core host running the full 132-file suite at a load
    // average of ~23-27 (this repo is developed in parallel with other
    // workstreams, so the machine is routinely oversubscribed):
    //
    //   slowest ordinary test   2.94s   TextSnippetModal "keeps the entered
    //                                     snippet data open when saving rejects"
    //   11 tests                > 2.0s
    //   inflation, quiet->busy  up to ~12x  (ModelRegistrationForm: 0.76s -> 5.67s
    //                                     for the identical test, run twice)
    //
    // 10000 ms is 3.4x the slowest observed ordinary test and still catches a
    // genuine hang: it is 1/15 of the E2E per-test budget (120s) and 1/150 of
    // the E2E globalTimeout (25 min). Tests whose cost is structural rather
    // than incidental carry their own, larger budget next to the reason — see
    // the describe-level timeout in src/logic/__tests__/ModelRegistrationForm.test.tsx.
    // Budgets are never a substitute for a fix: scripts/check-i18n.mjs was
    // optimised 2.7x (parse only files that can reference the macro module,
    // no parent-node linking) instead of being given a bigger allowance.
    testTimeout: 10_000,
    coverage: {
      provider: 'v8',
      include: [
        'src/logic/useProjectsBoard.ts',
        'src/logic/useProductionBoard.ts',
        'src/logic/useProjectPdfDrop.ts',
        'src/logic/usePermissions.ts',
        'src/ui/components/KanbanBoard.tsx',
        'src/ui/management/ManagementBoardsView.tsx',
        'src/ui/management/ManagementProjectsBoard.tsx',
        'src/ui/photographer/PhotographerProductionBoard.tsx',
        'src/ui/management/components/ProjectModal.tsx',
        'src/ui/photographer/components/PhotoJobModal.tsx',
      ],
      exclude: ['**/*.test.*', '**/node_modules/**', '**/dist/**'],
      reporter: ['text', 'html'],
      reportsDirectory: 'coverage',
    },
  },
});
