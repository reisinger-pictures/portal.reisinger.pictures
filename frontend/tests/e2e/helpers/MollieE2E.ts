import {existsSync, readFileSync} from 'node:fs';
import {resolve} from 'node:path';

/**
 * Provider-dependent E2E journeys.
 *
 * A checkout that creates a Mollie payment needs a real test-mode account and a
 * webhook URL Mollie can reach: the server refuses to create a payment without
 * `MOLLIE_KEY`, a non-empty `MOLLIE_PAYMENT_METHODS` list (or its deprecated
 * single-value fallback) and an HTTPS `MOLLIE_WEBHOOK_URL`
 * (`MolliePaymentService::canCreatePayment()` and `MollieCheckoutService::
 * webhookUrlIsUsable()`) and answers `503` instead. The specs that complete a
 * payment therefore assert a contract that cannot hold on a stack without that
 * configuration, and skipping them is the honest alternative to weakening
 * their assertions. A local run needs a public HTTPS tunnel for the webhook.
 *
 * The values are read from the same sources the E2E backend uses: the process
 * environment first, then `backend/.env.e2e` and `backend/.env` (the local
 * disposable stack). No workflow sets a `MOLLIE_*` value: `backend/.env.ci`
 * deliberately carries empty ones, because this repository is public and a
 * committed test key or tunnel URL would be a leak. The provider specs
 * therefore skip in CI by name and only a locally exported value or a
 * configured `.env.e2e` enables them — activating a Mollie test account
 * (`features/infrastructure/30-mollie-payments.md`, "Cutover and operations")
 * does so without a code change.
 */
export const MOLLIE_PROVIDER_SKIP_REASON =
    'Requires a configured Mollie test account (MOLLIE_KEY + MOLLIE_PAYMENT_METHODS) and an HTTPS '
    + 'MOLLIE_WEBHOOK_URL reachable from Mollie; '
    + 'see features/infrastructure/30-mollie-payments.md#cutover-and-operations.';

const API_KEY_PATTERN = /^(test|live)_[A-Za-z0-9]{25,}$/;

/**
 * `backend/` relative to the Playwright working directory: `frontend/` for a
 * local run and for every CI step (`working-directory: frontend`), the
 * repository root when Playwright is started from there.
 */
const backendDir = (): string => {
    const fromFrontend = resolve(process.cwd(), '..', 'backend');
    return existsSync(fromFrontend) ? fromFrontend : resolve(process.cwd(), 'backend');
};

const readEnvFile = (fileName: string): Record<string, string> => {
    const path = resolve(backendDir(), fileName);
    if (!existsSync(path)) return {};

    const values: Record<string, string> = {};
    for (const line of readFileSync(path, 'utf8').split('\n')) {
        const match = /^\s*([A-Z0-9_]+)\s*=\s*(.*)\s*$/.exec(line);
        if (!match) continue;
        values[match[1]] = match[2].replace(/^["']|["']$/g, '').trim();
    }
    return values;
};

const resolveSetting = (name: string): string => {
    const fromEnvironment = process.env[name]?.trim();
    if (fromEnvironment) return fromEnvironment;

    return readEnvFile('.env.e2e')[name] || readEnvFile('.env')[name] || '';
};

/** True when the E2E stack can actually create a Mollie payment. */
export const mollieCheckoutConfigured = (): boolean => {
    const key = resolveSetting('MOLLIE_KEY');
    const methods = resolveSetting('MOLLIE_PAYMENT_METHODS') || resolveSetting('MOLLIE_PAYMENT_METHOD');
    const webhookUrl = resolveSetting('MOLLIE_WEBHOOK_URL');

    // The server requires an HTTPS webhook in every environment, because Mollie
    // validates reachability at create time (HTTP 422 otherwise).
    return API_KEY_PATTERN.test(key) && methods !== '' && /^https:\/\//i.test(webhookUrl);
};

/**
 * The configured server key, read from the same sources the E2E backend uses.
 *
 * It exists so a spec can assert that the value never reaches the browser —
 * an assertion that would be vacuous with a hardcoded key. An empty string
 * means "no key configured in this environment".
 */
export const mollieConfiguredApiKey = (): string => resolveSetting('MOLLIE_KEY');
