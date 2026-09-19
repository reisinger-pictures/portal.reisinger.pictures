// Per-state seed helpers for the UI-review screenshot manifest.
//
// Seeds resolve dynamic ids/credentials from data created at runtime (invite
// tokens, a registered model) so the manifest never hard-codes anything. They
// are per-worker cached: each Playwright worker gets its own module instance,
// so one worker performs one API login/invite instead of one per screenshot
// test — the backend throttles logins per IP.
//
// The screenshot harness runs against the LOCAL DEV backend (portal.test via
// the :4322 Vite proxy). QUEUE_CONNECTION=sync there, so invite mails reach
// Mailpit immediately; MailpitHelper polls up to 10 s for the message.

import { readFileSync } from 'node:fs';
import path from 'node:path';
import type { APIRequestContext } from '@playwright/test';
import { E2ESessionHelper } from '../e2e/helpers/E2ESessionHelper';
import { MailpitHelper } from '../e2e/helpers/MailpitHelper';

/** Short random suffix so repeated runs never collide on unique constraints. */
function uniqueId(): string {
    return Math.random().toString(36).slice(2, 10);
}

interface OpenInvite {
    token: string;
    email: string;
}

interface RegisteredModel {
    /** Search term typed into the Models "Suche" field so the seeded row shows. */
    query: string;
}

let openInviteCache: OpenInvite | null = null;
let inviteListCache: Record<string, unknown> | null = null;
let registeredModelCache: RegisteredModel | null = null;

/**
 * Create one admin invite and resolve its magic-link token from Mailpit. The
 * invite API response intentionally does not leak the token, so the mail is the
 * only source (mirrors tests/e2e/crm/model-registration.spec.ts).
 */
async function createInviteToken(request: APIRequestContext, prefix: string): Promise<OpenInvite> {
    const helper = new E2ESessionHelper(request);
    const email = `ui-review-${prefix}-${uniqueId()}@example.com`;
    await helper.createModelInvite(email);

    const mailpit = new MailpitHelper(request);
    const token = await mailpit.extractModelRegistrationToken(email);
    if (!token) {
        throw new Error(`UI-review seed: no invite token was mailed to ${email}. Is Mailpit up and the queue worker running?`);
    }

    return { token, email };
}

/**
 * Filled state of the public registration page: an open (unused, unexpired)
 * invite token.
 */
export async function seedOpenModelInvite(request: APIRequestContext): Promise<Record<string, unknown>> {
    openInviteCache ??= await createInviteToken(request, 'open');
    return { token: openInviteCache.token };
}

/**
 * Filled state of the admin invite list: guarantee at least one invite exists
 * in the brand. Returns the seeded e-mail for reference (not used by the spec).
 */
export async function seedModelInviteList(request: APIRequestContext): Promise<Record<string, unknown>> {
    if (!inviteListCache) {
        const invite = await createInviteToken(request, 'list');
        inviteListCache = { email: invite.email };
    }
    return inviteListCache;
}

/**
 * Filled state of the admin Models search: register one person through the
 * public API using the current single-v1 catalogue (enriched with willingness,
 * stock, photo consent and a mandatory age proof). The returned `q` is the
 * spec's search term so the seeded row is guaranteed to be visible.
 */
export async function seedRegisteredModel(request: APIRequestContext): Promise<Record<string, unknown>> {
    if (!registeredModelCache) {
        const invite = await createInviteToken(request, 'model');
        const firstName = `UIReview${uniqueId()}`;
        const email = `ui-review-model-${uniqueId()}@example.com`;
        const ageProof = readFileSync(path.resolve(process.cwd(), '../backend/tests/Fixtures/sample.jpg'));

        // Willingness is required for every category (and stock) in the current
        // catalogue; `experience_*` stays optional. Portrait is the showcased
        // category, hence "gerne".
        const willingness = [
            'willingness_portrait',
            'willingness_fashion',
            'willingness_business',
            'willingness_boudoir',
            'willingness_bikini',
            'willingness_akt',
            'willingness_sport',
            'willingness_couple_family',
        ];

        const fields: Record<string, string> = {
            'persons[0][answers][first_name]': firstName,
            'persons[0][answers][last_name]': 'Modell',
            'persons[0][answers][birthdate]': '1995-05-05',
            'persons[0][answers][gender]': 'weiblich',
            'persons[0][answers][email]': email,
            'persons[0][answers][phone]': '+43 660 1234567',
            'persons[0][answers][street]': 'Teststraße 1',
            'persons[0][answers][zip]': '4020',
            'persons[0][answers][city]': 'Linz',
            'persons[0][answers][country]': 'Österreich',
            // Experience > "keine" marks the category as in use.
            'persons[0][answers][experience_portrait]': '0',
            'persons[0][answers][consent_privacy]': '1',
            'persons[0][answers][consent_accuracy]': '1',
            'persons[0][answers][consent_contact]': '1',
            'persons[0][answers][consent_photos]': '1',
            'persons[0][answers][consent_all_persons]': '1',
            'persons[0][create_account]': '0',
            'manager_index': '0',
        };
        for (const key of willingness) {
            fields[`persons[0][answers][${key}]`] = key === 'willingness_portrait' ? 'gerne' : 'nein';
        }
        fields['persons[0][answers][willingness_stock]'] = 'nein';

        const response = await request.post(`/api/model-registration/${invite.token}`, {
            headers: { Accept: 'application/json' },
            multipart: {
                ...fields,
                // `age_proof` is mandatory for every person in the current catalogue.
                'persons[0][age_proof]': { name: 'sample.jpg', mimeType: 'image/jpeg', buffer: ageProof },
            },
        });

        if (!response.ok()) {
            throw new Error(`UI-review seed: model registration failed (${response.status()}): ${await response.text()}`);
        }

        registeredModelCache = { query: firstName };
    }

    return { q: registeredModelCache.query };
}
