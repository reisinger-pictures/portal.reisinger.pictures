import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { useAuth } from '../../logic/useAuth';

const loginSchema = z.object({
    email: z.string().email('Ungültige E-Mail-Adresse'),
    password: z.string().min(1, 'Passwort erforderlich')
});
type LoginFormValues = z.infer<typeof loginSchema>;

export default function SidebarLoginForm() {
    const { login } = useAuth();
    const [authError, setAuthError] = useState('');
    const { register, handleSubmit, formState: { errors, isSubmitting } } = useForm<LoginFormValues>({
        resolver: zodResolver(loginSchema)
    });

    const onSubmit = async (data: LoginFormValues) => {
        setAuthError('');
        try {
            await login(data.email, data.password);
        } catch {
            setAuthError('Login fehlgeschlagen.');
        }
    };

    return (
        <div className="p-6 border-b border-base-300 bg-base-100">
            <h3 className="font-bold mb-3 flex items-center gap-2"><span className="iconify mdi--login"></span> Anmelden</h3>
            <form onSubmit={handleSubmit(onSubmit)} className="space-y-3">
                <div>
                    <input type="email" placeholder="E-Mail Adresse" {...register('email')} className={`input input-bordered w-full ${errors.email ? 'input-error' : ''}`}/>
                    {errors.email && <p className="text-sm text-error mt-1">{errors.email.message}</p>}
                </div>
                <div>
                    <input type="password" placeholder="Passwort" {...register('password')} className={`input input-bordered w-full ${errors.password ? 'input-error' : ''}`}/>
                </div>
                {authError && <p className="text-sm text-error font-semibold leading-tight">{authError}</p>}
                <button type="submit" className="btn btn-primary w-full mt-2" disabled={isSubmitting}>
                    {isSubmitting ? <span className="loading loading-spinner"></span> : 'Login'}
                </button>
            </form>
        </div>
    );
}
