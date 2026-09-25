import { useEffect, useRef } from 'react';
import { fetchSignContract } from './useContractJoin';

/**
 * Fetch rejects with an AbortError when a caller cancels a request. It is
 * control flow, not a transport failure (`src/api.ts` rethrows it by identity),
 * so a cancelled heartbeat tick must never be reported.
 */
const isAbortError = (error: unknown): boolean =>
    typeof error === 'object' && error !== null && 'name' in error && error.name === 'AbortError';

const describeError = (error: unknown): string =>
    error instanceof Error ? error.message : String(error);

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
        // An in-flight tick can settle after this effect was torn down (unmount
        // or dependency change). Such a result belongs to a dead tree, so it is
        // dropped instead of recording a failure or flagging staleness.
        let cancelled = false;
        // The single interval below remains the only timer: a failing heartbeat
        // schedules no retry of its own and therefore cannot loop. An
        // unchanged failure is recorded once per outage; a success or a
        // different message re-arms the record.
        let lastReportedError: string | null = null;
        const interval = setInterval(() => {
            fetchSignContract(token)
                .then(result => {
                    if (cancelled) return;
                    lastReportedError = null;
                    if (result.contract.content_version !== contentVersion) {
                        onStaleRef.current();
                    }
                })
                .catch((error: unknown) => {
                    // A heartbeat must keep polling, but a failing request must
                    // not disappear silently: it is the only signal that stale
                    // contract changes can no longer be detected. A cancelled
                    // request is control flow and stays unreported.
                    if (cancelled || isAbortError(error)) return;
                    const message = describeError(error);
                    if (message === lastReportedError) return;
                    lastReportedError = message;
                    console.warn('Contract heartbeat request failed:', message);
                });
        }, intervalMs);
        return () => {
            cancelled = true;
            clearInterval(interval);
        };
    }, [token, contentVersion, signed, intervalMs]);
}
