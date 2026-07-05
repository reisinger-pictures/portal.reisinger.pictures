import '@testing-library/jest-dom/vitest';
import { cleanup, render, type RenderOptions } from '@testing-library/react';
import { afterEach } from 'vitest';
import type { ReactElement } from 'react';
import { I18nProvider } from './logic/I18nProvider';

afterEach(() => {
  cleanup();
});

export function renderWithProviders(
  ui: ReactElement,
  options?: Omit<RenderOptions, 'wrapper'>,
) {
  return render(ui, {
    wrapper: ({ children }: { children: React.ReactNode }) => <I18nProvider>{children}</I18nProvider>,
    ...options
  });
}
