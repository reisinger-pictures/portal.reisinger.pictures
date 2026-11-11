import {existsSync, readFileSync} from 'node:fs';
import {resolve} from 'node:path';

/**
 * Provider-dependent E2E journeys.
 *
 * A checkout that creates a Mollie payment needs a real test-mode account: the
 * server refuses to create a payment without `MOLLIE_KEY` and
 * `MOLLIE_PAYMENT_METHOD` (`MolliePaymentService::isConfigured()`) and answers
 * `503` instead. The specs that complete a payment therefore assert a contract
 * that cannot hold on a stack without credentials, and skipping them is the
 * honest alternative to weakening their assertions.
 *
 * The key is read from the same sources the E2E backend uses: the process
 * environment first (the CI workflow passes it there), then `backend/.env.e2e`
 * and `backend/.env` (the local disposable stack). Activating a Mollie test
 * account — `features/infrastructure/30-mollie-payments.md`, "Cutover and
 * operations" — therefore re-enables these journeys without a code change.
 */
export const MOLLIE_PROVIDER_SKIP_REASON =
    'Requires a configured Mollie test account (MOLLIE_KEY + MOLLIE_PAYMENT_METHOD); '
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
    const method = resolveSetting('MOLLIE_PAYMENT_METHOD');

    return API_KEY_PATTERN.test(key) && method !== '';
};
