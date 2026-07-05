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
  },
});
