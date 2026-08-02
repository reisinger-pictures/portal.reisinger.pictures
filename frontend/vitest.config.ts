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
    include: ['src/**/*.test.ts', 'src/**/*.test.tsx'],
    setupFiles: ['src/test-setup.tsx'],
    css: true,
    // console.error aus erwarteten Fehlerpfaden in Tests unterdrücken
    printConsole: false,
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
