import { describe, it, expect, vi, beforeEach } from 'vitest';
import { renderHook, act } from '@testing-library/react';

vi.mock('swr', () => ({
    default: vi.fn(),
}));

vi.mock('../../api', () => ({
    fetcher: vi.fn(),
    apiMutate: vi.fn(),
    apiUpload: vi.fn(),
}));

import useSWR from 'swr';
import { apiMutate, apiUpload } from '../../api';
import { useModelRegistration } from '../useModelRegistration';
import { useModelInvites, buildCreateInviteBody, inviteLinkFromCreate } from '../useModelInvites';
import { updateModelProfileAccess } from '../modelRegistration';
import { useModels, buildModelsQuery, ageProofDownloadUrl, modelPhotoDownloadUrl, deleteModel, isModelOutdated, parseModelFilters, serializeModelFilters, lifecycleFilterValues, isRestrictedLifecycleFilter } from '../useModels';

const mutate = vi.fn();

function mockSWR() {
    vi.mocked(useSWR).mockReturnValue({
        data: undefined,
        error: undefined,
        isLoading: false,
        mutate,
    } as never);
}

describe('useModelRegistration', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mockSWR();
    });

    it('uses a null SWR key without a token', () => {
        renderHook(() => useModelRegistration(undefined));
        expect(vi.mocked(useSWR).mock.calls[0][0]).toBeNull();
    });

    it('uses the token endpoint as the SWR key', () => {
        renderHook(() => useModelRegistration('tok-123'));
        expect(vi.mocked(useSWR).mock.calls[0][0]).toBe('/api/model-registration/tok-123');
    });

    it('submits via the multipart upload helper', async () => {
        vi.mocked(apiUpload).mockResolvedValue({ success: true, act_id: 'a1', person_count: 1 });
        const { result } = renderHook(() => useModelRegistration('tok-123'));

        let submitted: unknown;
        await act(async () => {
            submitted = await result.current.submit({
                persons: [{ answers: { first_name: 'Maria' }, create_account: false, age_proof: null, photos: [] }],
                act_answers: {},
                manager_index: 0,
            });
        });

        expect(apiUpload).toHaveBeenCalledTimes(1);
        const [url, body] = vi.mocked(apiUpload).mock.calls[0];
        expect(url).toBe('/api/model-registration/tok-123');
        expect(body).toBeInstanceOf(FormData);
        expect((body as FormData).get('persons[0][answers][first_name]')).toBe('Maria');
        expect(submitted).toEqual({ success: true, act_id: 'a1', person_count: 1 });
    });

    it('rejects the submit when no token is present', async () => {
        const { result } = renderHook(() => useModelRegistration(undefined));
        await expect(
            result.current.submit({ persons: [], act_answers: {}, manager_index: 0 }),
        ).rejects.toThrow('Missing token');
    });
});

describe('useModelInvites', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mockSWR();
    });

    it('creates an invite with label only (no email) and revalidates', async () => {
        vi.mocked(apiMutate).mockResolvedValue({
            success: true,
            link: 'http://localhost:4321/model-registrierung/t1',
            invite: { id: 'i1', link: 'http://localhost:4321/model-registrierung/t1' },
        });
        const { result } = renderHook(() => useModelInvites());

        let response: unknown;
        await act(async () => {
            response = await result.current.createInvite({ label: 'WhatsApp Anna' });
        });

        expect(apiMutate).toHaveBeenCalledWith('/api/management/model-invites', 'POST', { label: 'WhatsApp Anna' });
        expect(mutate).toHaveBeenCalledTimes(1);
        expect(response).toEqual({
            success: true,
            link: 'http://localhost:4321/model-registrierung/t1',
            invite: { id: 'i1', link: 'http://localhost:4321/model-registrierung/t1' },
        });
    });

    it('creates an invite with email and label', async () => {
        vi.mocked(apiMutate).mockResolvedValue({ success: true, invite: { id: 'i2' } });
        const { result } = renderHook(() => useModelInvites());

        await act(async () => {
            await result.current.createInvite({ email: 'model@example.com', label: 'Agentur' });
        });

        expect(apiMutate).toHaveBeenCalledWith('/api/management/model-invites', 'POST', {
            email: 'model@example.com',
            label: 'Agentur',
        });
    });

    it('revokes an invite by id and revalidates', async () => {
        vi.mocked(apiMutate).mockResolvedValue({ success: true });
        const { result } = renderHook(() => useModelInvites());

        await act(async () => {
            await result.current.revokeInvite('invite-1');
        });

        expect(apiMutate).toHaveBeenCalledWith('/api/management/model-invites/invite-1', 'DELETE');
        expect(mutate).toHaveBeenCalledTimes(1);
    });
});

describe('useModelInvites helpers', () => {
    it('omits empty or whitespace-only optional fields from the request body', () => {
        expect(buildCreateInviteBody({})).toEqual({});
        expect(buildCreateInviteBody({ email: '   ', label: '  ' })).toEqual({});
        expect(buildCreateInviteBody({ email: ' a@b.de ', label: ' Notiz ' })).toEqual({
            email: 'a@b.de',
            label: 'Notiz',
        });
    });

    it('prefers the top-level link and falls back to invite.link', () => {
        expect(inviteLinkFromCreate({ link: 'top', invite: { link: 'inner' } })).toBe('top');
        expect(inviteLinkFromCreate({ invite: { link: 'inner' } })).toBe('inner');
        expect(inviteLinkFromCreate({ link: null, invite: { link: 'inner' } })).toBe('inner');
        expect(inviteLinkFromCreate({ invite: { link: null } })).toBeNull();
    });
});

describe('useModels', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mockSWR();
    });

    it('builds the SWR key from the active filters', () => {
        renderHook(() => useModels({ q: 'maria', gender: 'female' }));
        expect(vi.mocked(useSWR).mock.calls[0][0]).toBe('/api/management/models?q=maria&gender=female');
    });

    it('omits empty filters from the key', () => {
        renderHook(() => useModels({ q: '', category: [] }));
        expect(vi.mocked(useSWR).mock.calls[0][0]).toBe('/api/management/models');
    });

    it('serialises multi-select categories, threshold and sort', () => {
        renderHook(() => useModels({
            category: ['portrait', 'sport'],
            willingness_categories: ['bikini', 'sport'],
            willingness_level: 'gerne',
            sort: 'experience',
        }));
        const key = vi.mocked(useSWR).mock.calls[0][0] as string;
        expect(key).toContain('category%5B%5D=portrait');
        expect(key).toContain('category%5B%5D=sport');
        // Threshold travels per category (OR-linked server-side).
        expect(key).toContain('willingness_bikini=gerne');
        expect(key).toContain('willingness_sport=gerne');
        expect(key).toContain('sort=experience');
        expect(key).not.toContain('willingness_category=');
        expect(key).not.toContain('willingness_level=');
    });
});

describe('useModels helpers', () => {
    it('builds an empty query when no filter is set', () => {
        expect(buildModelsQuery({})).toBe('');
    });

    it('omits sort for the "best match" default and sends newest explicitly', () => {
        expect(buildModelsQuery({ sort: '' })).toBe('');
        expect(buildModelsQuery({ sort: 'newest' })).toBe('?sort=newest');
    });

    it('omits the active lifecycle default and sends inactive/all explicitly', () => {
        expect(buildModelsQuery({ lifecycle_status: '' })).toBe('');
        expect(buildModelsQuery({ lifecycle_status: 'inactive' })).toBe('?lifecycle_status=inactive');
        expect(buildModelsQuery({ lifecycle_status: 'all' })).toBe('?lifecycle_status=all');
    });

    it('exposes inactive/all lifecycle filter options to super-admins only', () => {
        expect(lifecycleFilterValues(true)).toEqual(['', 'inactive', 'all']);
        expect(lifecycleFilterValues(false)).toEqual(['']);
    });

    it('flags inactive/all as restricted for non-super-admins', () => {
        expect(isRestrictedLifecycleFilter('inactive', false)).toBe(true);
        expect(isRestrictedLifecycleFilter('all', false)).toBe(true);
        expect(isRestrictedLifecycleFilter('', false)).toBe(false);
        expect(isRestrictedLifecycleFilter(undefined, false)).toBe(false);
        expect(isRestrictedLifecycleFilter('inactive', true)).toBe(false);
        expect(isRestrictedLifecycleFilter('all', true)).toBe(false);
    });

    it('serialises and trims active filters', () => {
        expect(buildModelsQuery({ q: ' maria ', gender: 'female', category: [], age_min: '20' }))
            .toBe('?q=maria&gender=female&age_min=20');
        expect(buildModelsQuery({ category: ['bikini', 'akt'] }))
            .toBe('?category%5B%5D=bikini&category%5B%5D=akt');
    });

    it('persists selected categories as a meta list while "Egal" (no threshold)', () => {
        // Without a level the per-category params cannot carry the selection;
        // the meta list keeps the threshold slider enabled until a level is set.
        expect(buildModelsQuery({ willingness_categories: ['bikini'] }))
            .toBe('?willingness_category%5B%5D=bikini');
        expect(buildModelsQuery({ willingness_categories: ['bikini'], willingness_level: '' }))
            .toBe('?willingness_category%5B%5D=bikini');
        expect(parseModelFilters(new URLSearchParams('willingness_category%5B%5D=bikini&willingness_category%5B%5D=sport')))
            .toEqual({ willingness_categories: ['bikini', 'sport'] });
    });

    it('round-trips filters through the URL params', () => {
        const filters = {
            q: 'maria',
            category: ['bikini', 'akt'],
            willingness_categories: ['bikini', 'sport'],
            willingness_level: 'gerne',
            sort: 'willingness' as const,
            lifecycle_status: 'inactive',
        };
        const params = serializeModelFilters(filters);
        expect(params.getAll('category[]')).toEqual(['bikini', 'akt']);
        expect(params.getAll('willingness_bikini')).toEqual(['gerne']);
        expect(params.getAll('willingness_sport')).toEqual(['gerne']);
        expect(params.get('sort')).toBe('willingness');
        expect(parseModelFilters(params)).toEqual(filters);
    });

    it('ignores the model deeplink param when parsing filters', () => {
        const params = new URLSearchParams('?model=cust-1&q=maria');
        expect(parseModelFilters(params)).toEqual({ q: 'maria' });
    });

    it('skips willingness alias keys instead of reading them as categories', () => {
        const params = new URLSearchParams('willingness_level=gerne&willingness_levels=gerne&willingness_bikini=gerne');
        expect(parseModelFilters(params)).toEqual({ willingness_categories: ['bikini'], willingness_level: 'gerne' });
    });

    it('builds the auth-gated age proof download url', () => {
        expect(ageProofDownloadUrl('abc')).toBe('/api/management/models/abc/age-proof');
        // The photo download URL was the one export of this module no test
        // touched. It is the file-delivery path: if it silently broke, downloads
        // would 404 at runtime and no unit test would notice, because the value
        // is assembled by string interpolation that TypeScript cannot check.
        expect(modelPhotoDownloadUrl('abc', 'p1')).toBe('/api/management/models/abc/photos/p1');
    });

    it('deletes a model customer via the management endpoint (DSGVO)', async () => {
        vi.mocked(apiMutate).mockResolvedValue({ success: true });
        await deleteModel('cust-1');
        expect(apiMutate).toHaveBeenCalledWith('/api/management/models/cust-1', 'DELETE');
    });

    it('sends the profile photo payload only when photos are provided', async () => {
        vi.mocked(apiMutate).mockResolvedValue({ success: true, catalog_version: 'v1', last_confirmed_at: null });
        await updateModelProfileAccess('tok', { first_name: 'Maria' });
        expect(apiMutate).toHaveBeenLastCalledWith('/api/model-profil/tok', 'POST', { answers: { first_name: 'Maria' } });

        await updateModelProfileAccess('tok', { first_name: 'Maria' }, [{ id: 'p1', visibility: 'public', is_primary: true }]);
        expect(apiMutate).toHaveBeenLastCalledWith('/api/model-profil/tok', 'POST', {
            answers: { first_name: 'Maria' },
            photos: [{ id: 'p1', visibility: 'public', is_primary: true }],
        });
    });

    it('switches to multipart when an age proof has to be uploaded', async () => {
        vi.mocked(apiUpload).mockResolvedValue({ success: true, catalog_version: 'v1', last_confirmed_at: null });
        const file = new File(['x'], 'id.jpg', { type: 'image/jpeg' });
        await updateModelProfileAccess(
            'tok',
            { first_name: 'Maria', preferred_contact: ['E-Mail'] },
            [{ id: 'p1', visibility: 'public', is_primary: true }],
            file,
        );

        const [url, body] = vi.mocked(apiUpload).mock.calls.at(-1)!;
        expect(url).toBe('/api/model-profil/tok');
        expect(body).toBeInstanceOf(FormData);
        const formData = body as FormData;
        expect(formData.get('answers[first_name]')).toBe('Maria');
        expect(formData.getAll('answers[preferred_contact][]')).toEqual(['E-Mail']);
        expect(formData.get('photos[0][id]')).toBe('p1');
        expect(formData.get('photos[0][is_primary]')).toBe('1');
        expect(formData.get('age_proof')).toBe(file);
    });

    it('detects outdated catalog versions', () => {
        // Current catalogue is v1 (single-v1); equal or newer versions are current.
        expect(isModelOutdated({ catalog_version: 'v1' })).toBe(false);
        expect(isModelOutdated({ catalog_version: 'v2' })).toBe(false);
        expect(isModelOutdated({ catalog_version: 'v0' })).toBe(true);
        expect(isModelOutdated({ catalog_version: null })).toBe(true);
    });
});
