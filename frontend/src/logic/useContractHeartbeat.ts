import { useEffect, useRef } from 'react';
import { fetchSignContract } from './useContractJoin';

export function useContractHeartbeat(
    token: string | undefined,
    contentVersion: number | null,
    signed: boolean,
    onStale: () => void,
    intervalMs: number = 5000,
): void {
    const onStaleRef = useRef(onStale);

    useEffect(() => {
        onStaleRef.current = onStale;
    });

    useEffect(() => {
        if (!token || contentVersion === null || signed) return;
        const interval = setInterval(() => {
            fetchSignContract(token)
                .then(result => {
                    if (result.contract.content_version !== contentVersion) {
                        onStaleRef.current();
                    }
                })
                .catch(() => {});
        }, intervalMs);
        return () => clearInterval(interval);
    }, [token, contentVersion, signed, intervalMs]);
}
