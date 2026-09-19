import useSWR from 'swr';
import {
    fetchModelRegistration,
    submitModelRegistration,
    type ModelRegistrationCheck,
    type ModelRegistrationSubmitResult,
    type RegistrationFormValues,
} from './modelRegistration';

/**
 * Public registration flow: load the catalogue for a token and submit the act.
 * A 404/410 from `check` surfaces as an ApiError (status + message) so the view
 * can distinguish "unknown" from "expired/already redeemed".
 */
export function useModelRegistration(token: string | undefined) {
    const { data, error, isLoading } = useSWR<ModelRegistrationCheck>(
        token ? `/api/model-registration/${token}` : null,
        () => fetchModelRegistration(token as string),
        { shouldRetryOnError: false, revalidateOnFocus: false },
    );

    const submit = (values: RegistrationFormValues): Promise<ModelRegistrationSubmitResult> => {
        if (!token) return Promise.reject(new Error('Missing token'));
        return submitModelRegistration(token, values);
    };

    return { registration: data, error, isLoading, submit };
}
