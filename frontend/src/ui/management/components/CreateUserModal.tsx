import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { useUI } from '../../components/UIContext';

const userSchema = z.object({
    name: z.string().min(1, 'Name ist erforderlich'),
    email: z.string().email('Ungültige E-Mail-Adresse')
});
type UserFormValues = z.infer<typeof userSchema>;

interface Props {
    isOpen: boolean;
    onClose: () => void;
    onCreate: (name: string, email: string) => Promise<void>;
}

export default function CreateUserModal({ isOpen, onClose, onCreate }: Props) {
    const { showToast } = useUI();
    
    const { register, handleSubmit, reset, formState: { errors, isSubmitting } } = useForm<UserFormValues>({
        resolver: zodResolver(userSchema)
    });

    if (!isOpen) return null;

    const onSubmit = async (data: UserFormValues) => {
        try {
            await onCreate(data.name, data.email);
            reset();
            onClose();
            showToast('success', 'Nutzer angelegt! Eine E-Mail zur Einrichtung wurde verschickt.');
        } catch (err: unknown) {
            showToast('error', 'Fehler: ' + (err instanceof Error ? err.message : 'Nutzer konnte nicht angelegt werden.'));
        }
    };

    return (
        <div className="modal modal-open">
            <div className="modal-box relative">
                <button type="button" className="btn btn-sm btn-circle btn-ghost absolute right-2 top-2" onClick={onClose}>✕</button>
                <h3 className="font-bold text-lg mb-4">Neuen Nutzer einladen</h3>
                <p className="text-sm opacity-70 mb-4">Der Nutzer erhält eine E-Mail mit einem Link, um sein Passwort festzulegen.</p>
                <form onSubmit={handleSubmit(onSubmit)} className="space-y-4">
                    <div className="form-control">
                        <label className="label"><span className="label-text font-bold">Name</span></label>
                        <input type="text" {...register('name')} className={`input input-bordered ${errors.name ? 'input-error' : ''}`} />
                        {errors.name && <span className="text-error text-xs mt-1">{errors.name.message}</span>}
                    </div>
                    <div className="form-control">
                        <label className="label"><span className="label-text font-bold">E-Mail Adresse</span></label>
                        <input type="email" {...register('email')} className={`input input-bordered ${errors.email ? 'input-error' : ''}`} />
                        {errors.email && <span className="text-error text-xs mt-1">{errors.email.message}</span>}
                    </div>
                    <div className="modal-action">
                        <button type="button" className="btn btn-ghost" onClick={onClose}>Abbrechen</button>
                        <button type="submit" className="btn btn-primary" disabled={isSubmitting}>
                            {isSubmitting ? <span className="loading loading-spinner"></span> : 'Nutzer anlegen & Einladen'}
                        </button>
                    </div>
                </form>
            </div>
            <div className="modal-backdrop" onClick={onClose}></div>
        </div>
    );
}
