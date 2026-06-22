import { describe, it, expect } from 'vitest';

describe('Vitest Smoke Test', () => {
  it('runs a basic assertion', () => {
    expect(1 + 1).toBe(2);
  });

  it('handles async/await', async () => {
    const result = await Promise.resolve(42);
    expect(result).toBe(42);
  });

  it('has node environment available', () => {
    expect(typeof process).toBe('object');
    expect(typeof global).toBe('object');
  });
});
