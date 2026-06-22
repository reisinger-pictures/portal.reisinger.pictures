# React Compiler ESLint Configuration

This project does not use `eslint-plugin-react-compiler` as a separate package.

## Background

This project uses:
- React 19
- `eslint-plugin-react-hooks` v7.1.1

Starting with `eslint-plugin-react-hooks` version 6.0.0+, the React Compiler linting rules are integrated directly into the hooks plugin as part of the "recommended" configuration.

## Why No Separate Plugin?

The separate `eslint-plugin-react-compiler` package is **not needed** because:

1. The necessary React Compiler lint rules are already included in `eslint-plugin-react-hooks` (v6.0.0+)
2. Our ESLint configuration extends the recommended config from `eslint-plugin-react-hooks`
3. This provides all the required linting capabilities for React 19's compiler

## ESLint Configuration

The project's ESLint setup (`eslint.config.js`) includes the necessary rules through:

```js
react.configs.recommended,
```

This configuration already encompasses the React Compiler rules, making any additional plugin redundant.
