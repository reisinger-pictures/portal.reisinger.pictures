import {expect, test} from '@playwright/test';
import {AuthHelper} from '../helpers/AuthHelper';
import {E2ESessionHelper} from '../helpers/E2ESessionHelper';
import {GalleryHelper} from '../helpers/GalleryHelper';

// NOTE: Tests that fundamentally require control over AI service responses
// (e.g. generation flows, error scenarios) have been removed because they
// depend on mocking /api/ai/* endpoints, which violates the internal-API
// mocking ban in 04-testing-guidelines.md. Those scenarios cannot run against
// a real backend without a live AI service.
//
// The tests below exercise real backend integration only.

test.describe('AI Batch Edit Modal (Server-Side)', () => {
    let helper: E2ESessionHelper;
    let testUser = {email: '', password: ''};

    test.beforeEach(async ({request}) => {
        helper = new E2ESessionHelper(request);
        testUser = await helper.createIsolatedUser('photographer');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('shows AI Batch-Edit button for photographer in delivery gallery', { tags: ['@feature:photographer:ai'] }, async ({page}) => {
        const auth = new AuthHelper(page);
        await auth.login(testUser.email, testUser.password);

        const galleryHelper = new GalleryHelper(page, helper);
        await galleryHelper.createAndOpenDeliveryGallery(`AI Test ${Math.random().toString(36).substring(2, 8)}`);

        const aiButton = page.getByRole('button', {name: 'KI Beschriftung'});
        // This test validates real backend integration — AI must be enabled
        // in the test environment for the button to appear.
        await expect(aiButton).toBeVisible();
    });

    test('opens AI Batch-Edit modal when button is clicked', { tags: ['@feature:photographer:ai'] }, async ({page}) => {
        const auth = new AuthHelper(page);
        await auth.login(testUser.email, testUser.password);

        const galleryHelper = new GalleryHelper(page, helper);
        await galleryHelper.createAndOpenDeliveryGallery(`AI Test ${Math.random().toString(36).substring(2, 8)}`);

        const aiButton = page.getByRole('button', {name: 'KI Beschriftung'});
        await aiButton.click();

        await expect(page.getByRole('heading', {name: 'KI Beschriftung'})).toBeVisible();
    });

    // Test 'hides AI button when AI is unconfigured' was removed because it
    // requires mocking /api/ai/status to return disabled state — forbidden by
    // testing guidelines.
});

// Generation Flow tests (AT-03-E1 through AT-03-E3c) were removed because
// they mock /api/ai/generate-metadata, /api/search/locations*,
// /api/management/galleries/*, /api/photos/*/meta, and /api/galleries/*.
// These endpoints are internal and must not be mocked per testing guidelines.
// Without a live AI service these tests cannot validate real backend behavior.
