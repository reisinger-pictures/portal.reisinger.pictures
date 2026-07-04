# Task Board — Portal Reisinger Pictures

## Session: Dependency Upgrades 2026-07-04 — ✅ ALL DONE

All upgrades from the Dependency Audit have been completed successfully.

| Upgrade | Type | Status | Verification |
|---|---|---|---|
| Meilisearch v1.42 → v1.48.3 | major | ✅ Applied per AGENTS.md docs | Docker images updated |
| laravel/framework 13.6.0 → 13.18.1 | minor | ✅ `composer update` | 853 tests pass |
| laravel/pail 1.2.6 → 1.2.7 | patch | ✅ `composer update` | 853 tests pass |
| laravel/pint 1.29.1 → 1.29.3 | patch | ✅ `composer update` | 853 tests pass |
| laravel/scout 11.1.0 → 11.3.0 | minor | ✅ `composer update` | 853 tests pass |
| php-open-source-saver/jwt-auth 2.9.0 → 2.9.2 | patch | ✅ `composer update` | 853 tests pass |
| phpunit/phpunit 12.5.23 → 13.2.2 | **major** | ✅ Constraint → `^13.2.2` | 853 tests pass |
| stripe/stripe-php 13.18.0 → 20.3.0 | **major** | ✅ Constraint → `^20.3.0` | 853 tests pass, no code changes needed |
| symfony/html-sanitizer 8.0.8 → 8.1.1 | minor | ✅ `composer update` | 853 tests pass |
| All frontend packages | minor/patch | ✅ `pnpm update` | lint+build clean |
| @types/node 25.6.0 → 26.1.0 | **major** | ✅ Constraint → `^26.1.0` | lint+build clean |
| repomix 1.13.1 → 1.16.0 | minor | ✅ `pnpm install` (switched from npm) | verified |
