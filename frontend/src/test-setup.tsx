import '@testing-library/jest-dom/vitest';
import { cleanup, render, type RenderOptions } from '@testing-library/react';
import { afterEach } from 'vitest';
import type { ReactElement } from 'react';
import { I18nProvider } from './logic/I18nProvider';

// jsdom ships no ResizeObserver; @dnd-kit/dom references it at module scope.
// A minimal no-op observer keeps the real dnd-kit module importable in tests.
class MemoryResizeObserver implements ResizeObserver {
  private readonly callback: ResizeObserverCallback;

  constructor(callback: ResizeObserverCallback) {
    this.callback = callback;
  }

  observe(target: Element): void {
    this.callback([{ target } as ResizeObserverEntry], this);
  }

  unobserve(): void {}
  disconnect(): void {}
}

Object.defineProperty(globalThis, 'ResizeObserver', {
  value: MemoryResizeObserver,
  writable: true,
  configurable: true,
});

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

// jsdom ships no window.matchMedia. `useMediaQuery` degrades to `false` when it is
// missing, but a minimal polyfill lets components that read media queries render
// deterministically. Tests that need a specific value override `window.matchMedia`
// themselves (the property stays writable/configurable).
class MemoryMediaQueryList implements MediaQueryList {
  readonly media: string;
  readonly matches = false;
  onchange: ((this: MediaQueryList, ev: MediaQueryListEvent) => unknown) | null = null;

  constructor(media: string) {
    this.media = media;
  }

  addListener(): void {}
  removeListener(): void {}
  addEventListener(): void {}
  removeEventListener(): void {}

  dispatchEvent(): boolean {
    return false;
  }
}

Object.defineProperty(globalThis, 'matchMedia', {
  value: (query: string): MediaQueryList => new MemoryMediaQueryList(query),
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
