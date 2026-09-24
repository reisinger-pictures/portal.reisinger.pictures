# React Compiler ESLint Configuration

The frontend uses the React Compiler through the Vite/Babel pipeline and does not
install `eslint-plugin-react-compiler` as a separate package.

## Background

This project uses:
- React 19
- `eslint-plugin-react-hooks` v7.1.1

The compiler is enabled with `reactCompilerPreset()` in `vite.config.ts` and
performs automatic memoization. Manual `useMemo`, `useCallback`, `React.memo`, and
`forwardRef` are therefore not part of the frontend policy; write plain
functions/values and let the compiler handle memoization.

## Why No Separate Plugin?

The separate `eslint-plugin-react-compiler` package is **not needed** because:

1. The necessary React Compiler lint rules are already included in
   `eslint-plugin-react-hooks` (v6.0.0+).
2. `frontend/eslint.config.js` imports the hooks plugin and applies its
   `recommended.rules` set.
3. The Vite/Babel compiler preset, not a separate ESLint plugin, performs the
   automatic transform.

## ESLint Configuration

The project's ESLint setup (`eslint.config.js`) includes the necessary rules via:

```js
...reactHooks.configs.recommended.rules,
```

This configuration covers the React Compiler lint rules; the separate
`eslint-plugin-react-compiler` package would be redundant.
