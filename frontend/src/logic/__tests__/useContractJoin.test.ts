import { describe, it, expect, vi, beforeEach } from 'vitest';
import { fetchJoinContract, submitJoin } from '../useContractJoin';

vi.mock('../../api', () => ({
    fetcher: vi.fn(),
    apiMutate: vi.fn(),
}));

describe('fetchJoinContract', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('calls fetcher with correct URL', async () => {
        const { fetcher } = await import('../../api');

        vi.mocked(fetcher).mockResolvedValue({
            contract_id: 'c1',
            status: 'active',
            available_roles: ['Model'],
            allow_multiple_roles: false,
            terms_html: '<p>Terms</p>',
        });

        const result = await fetchJoinContract('token-abc');

        expect(fetcher).toHaveBeenCalledWith('/api/contracts/join/token-abc');
        expect(result.contract_id).toBe('c1');
        expect(result.status).toBe('active');
        expect(result.available_roles).toEqual(['Model']);
    });

    it('passes errors from fetcher', async () => {
        const { fetcher } = await import('../../api');

        vi.mocked(fetcher).mockRejectedValue(new Error('API Error'));

        await expect(fetchJoinContract('bad-token')).rejects.toThrow('API Error');
    });

    it('handles different token values', async () => {
        const { fetcher } = await import('../../api');

        vi.mocked(fetcher).mockResolvedValue({ contract_id: 'c2', status: 'pending', available_roles: [], allow_multiple_roles: false, terms_html: '' });

        await fetchJoinContract('another-token');
        expect(fetcher).toHaveBeenCalledWith('/api/contracts/join/another-token');
    });
});

describe('submitJoin', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('calls apiMutate with correct URL, method and body', async () => {
        const { apiMutate } = await import('../../api');

        vi.mocked(apiMutate).mockResolvedValue({
            personal_token: 'pt_123',
            name: 'Max Mustermann',
            roles: ['Model'],
        });

        const result = await submitJoin('token-abc', 'Max Mustermann', 'max@test.com', ['Model']);

        expect(apiMutate).toHaveBeenCalledWith(
            '/api/contracts/join/token-abc',
            'POST',
            { name: 'Max Mustermann', email: 'max@test.com', roles: ['Model'] },
        );
        expect(result.personal_token).toBe('pt_123');
        expect(result.name).toBe('Max Mustermann');
    });

    it('submits with multiple roles', async () => {
        const { apiMutate } = await import('../../api');

        vi.mocked(apiMutate).mockResolvedValue({
            personal_token: 'pt_456',
            name: 'Jane Doe',
            roles: ['Model', 'Hair'],
        });

        const result = await submitJoin('token-xyz', 'Jane Doe', 'jane@test.com', ['Model', 'Hair']);

        expect(apiMutate).toHaveBeenCalledWith(
            '/api/contracts/join/token-xyz',
            'POST',
            { name: 'Jane Doe', email: 'jane@test.com', roles: ['Model', 'Hair'] },
        );
        expect(result.roles).toEqual(['Model', 'Hair']);
    });

    it('propagates API errors', async () => {
        const { apiMutate } = await import('../../api');

        vi.mocked(apiMutate).mockRejectedValue(new Error('Submission failed'));

        await expect(submitJoin('token-abc', 'Max', 'max@test.com', ['Model'])).rejects.toThrow('Submission failed');
    });
});
