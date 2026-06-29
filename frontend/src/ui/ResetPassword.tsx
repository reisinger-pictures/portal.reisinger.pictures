import { useEffect, useState } from 'react';
import ErrorMessage from './components/ErrorMessage';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { useSWRConfig } from 'swr';
import { apiMutate } from '../api';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';

const resetSchema = z.object({
    password: z.string().min(8, 'Das Passwort muss mindestens 8 Zeichen lang sein.'),
    passwordConfirm: z.string()
}).refine((data) => data.password === data.passwordConfirm, {
    message:"Die Passwörter stimmen nicht überein.",
    path: ["passwordConfirm"],
});

type ResetFormValues = z.infer<typeof resetSchema>;

export default function ResetPassword() {
    const [searchParams] = useSearchParams();
    const token = searchParams.get('token');
    const email = searchParams.get('email');
    const navigate = useNavigate();
    const { mutate } = useSWRConfig();

    const [globalError, setGlobalError] = useState('');

    const {
        register,
        handleSubmit,
        formState: { errors, isSubmitting }
    } = useForm<ResetFormValues>({
        resolver: zodResolver(resetSchema)
    });

    useEffect(() => {
        // Sicherheitsmaßnahme: Eventuell hängengebliebene Cookies/Sessions serverseitig löschen,
        // bevor das Passwort-Reset-Formular abgeschickt wird.
        fetch('/api/auth/logout', { method: 'POST', credentials: 'include' }).catch((e: unknown) => { setGlobalError(e instanceof Error ? e.message : 'Session konnte nicht zurückgesetzt werden.'); });
    }, []);

    const onSubmit = async (data: ResetFormValues) => {
        setGlobalError('');

        try {
            await apiMutate('/api/auth/reset-password', 'POST', { email, token, password: data.password });
            
            // Durch das mutieren der SWR Route weiß die App, dass sie nun eingeloggt ist.
            await mutate(() => true, undefined, { revalidate: true });
            
            // Redirect ins Dashboard
            navigate('/', { replace: true });
        } catch (err: unknown) {
            setGlobalError(err instanceof Error ? err.message : 'Fehler beim Setzen des Passworts. Evtl. ist der Link abgelaufen.');
        }
    };

    if (!token || !email) {
        return <div className="p-8 text-center text-error">Ungültiger Link. Token oder E-Mail fehlen.</div>;
    }

    return (
        <div className="flex h-screen items-center justify-center bg-base-200 p-4">
            <div className="card w-full max-w-sm bg-base-100 shadow-2xl">
                <div className="card-body">
                    <h2 className="card-title text-2xl font-bold mb-2 text-primary">Konto einrichten</h2>
                    <p className="text-sm opacity-70 mb-4">Setze ein neues Passwort für den Account <strong>{email}</strong>.</p>

                    {globalError && <ErrorMessage message={globalError} className="mb-4" />}

                    <form onSubmit={handleSubmit(onSubmit)} className="space-y-4">
                        <div className="form-control">
                            <label className="label"><span className="label-text font-bold">Neues Passwort</span></label>
                            <input 
                                type="password" 
                                {...register('password')} 
                                className={`input input-bordered ${errors.password ? 'input-error' : ''}`}
                            />
                            {errors.password && <span className="text-error text-sm mt-1">{errors.password.message}</span>}
                        </div>
                        
                        <div className="form-control">
                            <label className="label"><span className="label-text font-bold">Passwort bestätigen</span></label>
                            <input 
                                type="password" 
                                {...register('passwordConfirm')} 
                                className={`input input-bordered ${errors.passwordConfirm ? 'input-error' : ''}`}
                            />
                            {errors.passwordConfirm && <span className="text-error text-sm mt-1">{errors.passwordConfirm.message}</span>}
                        </div>
                        
                        <div className="form-control mt-6">
                            <button type="submit" className="btn btn-primary w-full" disabled={isSubmitting}>
                                {isSubmitting ? <span className="loading loading-spinner"></span> : 'Passwort speichern & Anmelden'}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    );
}
