import '@testing-library/jest-dom/vitest';
import { cleanup, render, type RenderOptions } from '@testing-library/react';
import { afterEach } from 'vitest';
import type { ReactElement } from 'react';
import { I18nProvider } from './logic/I18nProvider';

// Node.js 22+ ships an experimental global `localStorage` that is `undefined`
// by default and shadows any jsdom implementation. Production code and tests
// reference the bare `localStorage` global (as in a browser). We install a
// lightweight spec-compliant polyfill so tests have a working Storage.
class MemoryStorage implements Storage {
  private store = new Map<string, string>();

  get length(): number {
    return this.store.size;
  }

  clear(): void {
    this.store.clear();
  }

  getItem(key: string): string | null {
    return this.store.has(key) ? this.store.get(key)! : null;
  }

  key(index: number): string | null {
    return Array.from(this.store.keys())[index] ?? null;
  }

  removeItem(key: string): void {
    this.store.delete(key);
  }

  setItem(key: string, value: string): void {
    this.store.set(key, String(value));
  }
}

Object.defineProperty(globalThis, 'localStorage', {
  value: new MemoryStorage(),
  writable: true,
  configurable: true,
});

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
