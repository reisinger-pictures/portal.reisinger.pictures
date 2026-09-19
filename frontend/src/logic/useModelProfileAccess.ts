import useSWR from 'swr';
import {
    confirmModelProfileAccess,
    fetchModelProfileAccess,
    fetchMyModels,
    updateModelProfileAccess,
    type AnswersRecord,
    type ModelProfileAccess,
    type ModelProfileConfirmResult,
    type ModelProfilePhotoUpdate,
    type ModelProfileUpdateResult,
    type MyModel,
} from './modelRegistration';

/**
 * Public profile access over the 24h magic link. Tokens are reusable while
 * active; a 404/410 surfaces as an ApiError (status + message).
 */
export function useModelProfileAccess(token: string | undefined) {
    const key = token ? `/api/model-profil/${token}` : null;
    const { data, error, isLoading, mutate } = useSWR<ModelProfileAccess>(
        key,
        () => fetchModelProfileAccess(token as string),
        { shouldRetryOnError: false, revalidateOnFocus: false },
    );

    const update = (
        answers: AnswersRecord,
        photos?: ModelProfilePhotoUpdate[],
        ageProof?: File | null,
    ): Promise<ModelProfileUpdateResult> => {
        if (!token) return Promise.reject(new Error('Missing token'));
        return updateModelProfileAccess(token, answers, photos, ageProof).then(result => {
            void mutate();
            return result;
        });
    };

    const confirm = (): Promise<ModelProfileConfirmResult> => {
        if (!token) return Promise.reject(new Error('Missing token'));
        return confirmModelProfileAccess(token).then(result => {
            void mutate();
            return result;
        });
    };

    return { profile: data, error, isLoading, mutate, update, confirm };
}

/**
 * „Meine Profile" for the logged-in portal account. Disabled until an account
 * is present so guests/sidebars do not fire a 401.
 */
export function useMyModels(enabled: boolean) {
    const { data, error, isLoading } = useSWR<MyModel[]>(
        enabled ? '/api/me/models' : null,
        fetchMyModels,
        { shouldRetryOnError: false, revalidateOnFocus: false },
    );

    return { models: data, error, isLoading };
}
