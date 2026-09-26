import {readFileSync} from 'node:fs';
import path from 'node:path';
import {expect, test} from '@playwright/test';
import {AuthHelper} from '../helpers/AuthHelper';
import {E2ESessionHelper} from '../helpers/E2ESessionHelper';
import {SidebarHelper} from '../helpers/SidebarHelper';

const sampleImagePath = path.resolve(process.cwd(), '../backend/tests/Fixtures/sample.jpg');
const sampleImage = readFileSync(sampleImagePath);

type GalleryFixture = {
    id: string;
    name: string;
};

test.describe('Meta-gallery child licensing', () => {
    let helper: E2ESessionHelper;
    let testUser: {email: string; password: string};

    test.beforeEach(async ({request}) => {
        helper = new E2ESessionHelper(request);
        testUser = await helper.createIsolatedUser('super_admin');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('resolves mixed child presets and keeps the group coupon tab available', {
        tag: ['@regression', '@feature:meta-gallery'],
    }, async ({page, request}) => {
        test.setTimeout(120000);

        const suffix = Math.random().toString(36).substring(2, 8);
        const headers = {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            Cookie: helper.getAdminToken(),
        };
        const groupResponse = await request.post('/api/management/gallery-groups', {
            data: {name: `E2E Meta Licensing ${suffix}`},
            headers,
        });
        const groupBody = await groupResponse.text();
        expect(groupResponse.ok(), groupBody).toBeTruthy();
        const groupId = (JSON.parse(groupBody) as {group?: {id?: string}}).group?.id;
        if (!groupId) throw new Error('Meta-gallery group was created without an id');
        helper.trackGroup(groupId);

        const childGroupResponse = await request.post('/api/management/gallery-groups', {
            data: {
                name: `E2E Meta Child ${suffix}`,
                parent_id: groupId,
            },
            headers,
        });
        const childGroupBody = await childGroupResponse.text();
        expect(childGroupResponse.ok(), childGroupBody).toBeTruthy();
        const childGroupId = (JSON.parse(childGroupBody) as {group?: {id?: string}}).group?.id;
        if (!childGroupId) throw new Error('Child meta-gallery group was created without an id');
        helper.trackGroup(childGroupId);
        const childGroupName = `E2E Meta Child ${suffix}`;

        const presetA = await helper.createVolumePreset({
            name: `E2E Meta Preset A ${suffix}`,
            tiers: [
                {min_quantity: 0, price_cents: 4100},
                {min_quantity: 2, price_cents: 3500},
            ],
        });
        const presetB = await helper.createVolumePreset({
            name: `E2E Meta Preset B ${suffix}`,
            tiers: [{min_quantity: 0, price_cents: 6200}],
        });
        helper.trackPreset(String(presetA.id));
        helper.trackPreset(String(presetB.id));

        const definitions = [
            {name: `E2E Meta Scope ${suffix}`, groupId, licensingMode: 'scope_licensing' as const, presetId: null},
            {name: `E2E Meta Volume A Direct ${suffix}`, groupId, licensingMode: 'volume_licensing' as const, presetId: presetA.id},
            {name: `E2E Meta Volume A Child ${suffix}`, groupId: childGroupId, licensingMode: 'volume_licensing' as const, presetId: presetA.id},
            {name: `E2E Meta Volume B ${suffix}`, groupId: childGroupId, licensingMode: 'volume_licensing' as const, presetId: presetB.id},
        ];
        const galleries: GalleryFixture[] = [];
        for (const definition of definitions) {
            const response = await request.post('/api/management/galleries', {
                data: {
                    name: definition.name,
                    slug: `${definition.name.toLowerCase().replace(/[^a-z0-9]+/g, '-')}-${suffix}`,
                    type: 'delivery',
                    is_public: true,
                    gallery_group_id: definition.groupId,
                    licensing_mode: definition.licensingMode,
                    volume_preset_id: definition.presetId,
                },
                headers,
            });
            const responseBody = await response.text();
            expect(response.ok(), responseBody).toBeTruthy();
            const galleryId = (JSON.parse(responseBody) as {gallery?: {id?: string}}).gallery?.id;
            if (!galleryId) throw new Error(`${definition.name} was created without an id`);
            helper.trackGallery(galleryId);
            galleries.push({id: galleryId, name: definition.name});

            const uploadResponse = await request.post('/api/management/upload', {
                headers: {Accept: 'application/json', Cookie: helper.getAdminToken()},
                multipart: {
                    gallery_id: galleryId,
                    lr_uuid: `e2e-meta-${galleryId}`,
                    file: {
                        name: 'sample.jpg',
                        mimeType: 'image/jpeg',
                        buffer: sampleImage,
                    },
                },
            });
            expect(uploadResponse.ok(), await uploadResponse.text()).toBeTruthy();
        }

        const termsRequests: string[] = [];
        page.on('request', requestEvent => {
            const url = new URL(requestEvent.url());
            if (url.pathname === '/api/settings/license-terms' && url.searchParams.has('gallery_id')) {
                termsRequests.push(url.searchParams.get('gallery_id') ?? '');
            }
        });

        await new AuthHelper(page).login(testUser.email, testUser.password);
        await new SidebarHelper(page).navigateTo('Galerien & Ordner');

        const main = page.getByRole('main');
        await main.getByRole('button', {name: 'Alle ausklappen'}).click();
        const nestedGalleryName = definitions[2].name;
        await main.getByRole('link', {name: nestedGalleryName}).click();
        await expect(main.getByRole('heading', {name: nestedGalleryName, exact: true})).toBeVisible();
        await expect(main.getByRole('link', {name: childGroupName, exact: true})).toBeVisible();
        await main.getByRole('link', {name: `E2E Meta Licensing ${suffix}`, exact: true}).click();
        await expect(page).toHaveURL(new RegExp(`/meta/${groupId}$`));

        const pricingSection = main.getByRole('region', {name: 'Preise nach Untergalerie'});
        await expect(pricingSection).toBeVisible({timeout: 15000});
        await expect(main.getByRole('tab', {name: 'Coupons'})).toBeVisible();
        const presetAGroup = pricingSection.getByRole('article')
            .filter({hasText: definitions[1].name});
        const presetBGroup = pricingSection.getByRole('article')
            .filter({hasText: definitions[3].name});
        const scopeGroup = pricingSection.getByRole('article')
            .filter({hasText: definitions[0].name});
        await expect(presetAGroup).toContainText(definitions[2].name);
        await expect(presetAGroup).toContainText('2 Bilder');
        await expect(presetAGroup).toContainText('35.00 €');
        await expect(presetAGroup).toContainText('70.00 €');
        const presetAGalleryIds = await presetAGroup.getAttribute('data-gallery-ids');
        expect(presetAGalleryIds).toContain(galleries[1].id);
        expect(presetAGalleryIds).toContain(galleries[2].id);
        await expect(presetBGroup).toContainText('62.00 €');
        await expect(scopeGroup).toContainText('Scope-Lizenz');
        await expect(pricingSection).toContainText('132.00 €');
        expect(termsRequests).toEqual(expect.arrayContaining(galleries.map(gallery => gallery.id)));
    });
});
