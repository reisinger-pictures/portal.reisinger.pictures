import {describe, it, expect, vi, afterEach} from 'vitest';
import {apiMutate, setGlobalErrorCallback} from '../../api';

describe('setGlobalErrorCallback', () => {
    afterEach(() => {
        setGlobalErrorCallback(null);
        vi.unstubAllGlobals();
    });

    it('stops invoking the previous callback after it is cleared with null', async () => {
        const callback = vi.fn();
        setGlobalErrorCallback(callback);
        setGlobalErrorCallback(null);

        vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('offline')));

        await expect(apiMutate('/api/anything', 'POST')).rejects.toThrow('Netzwerkfehler');
        expect(callback).not.toHaveBeenCalled();
    });

    it('invokes the registered callback on a network error', async () => {
        const callback = vi.fn();
        setGlobalErrorCallback(callback);

        vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('offline')));

        await expect(apiMutate('/api/anything', 'POST')).rejects.toThrow('Netzwerkfehler');
        expect(callback).toHaveBeenCalledWith(0, expect.stringContaining('Netzwerkfehler'));
    });
});
