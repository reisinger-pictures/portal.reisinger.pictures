import { t } from "@lingui/core/macro";
import { Trans } from "@lingui/react/macro";
import { useEffect } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { useUI } from '../../components/UIContext';

const createUserSchema = () => z.object({
    name: z.string().min(1, t`Name ist erforderlich`),
    email: z.string().email(t`Ungültige E-Mail-Adresse`)
});
type UserFormValues = z.infer<ReturnType<typeof createUserSchema>>;

interface Props {
    isOpen: boolean;
    onClose: () => void;
    onCreate: (name: string, email: string) => Promise<void>;
}

export default function CreateUserModal({ isOpen, onClose, onCreate }: Props) {
    "use no memo";
    const { showToast } = useUI();
    const userSchema = createUserSchema();

    const { register, handleSubmit, reset, formState: { errors, isSubmitting } } = useForm<UserFormValues>({
        resolver: zodResolver(userSchema)
    });

    // A cancelled invitation must not leave its values (or validation errors)
    // behind for the next time the modal is opened.
    useEffect(() => {
        if (isOpen) {
            reset({ name: '', email: '' });
        }
    }, [isOpen, reset]);

    const handleClose = () => {
        reset();
        onClose();
    };

    if (!isOpen) return null;

    const onSubmit = async (data: UserFormValues) => {
        try {
            await onCreate(data.name, data.email);
            reset();
            onClose();
            showToast('success', t`Nutzer angelegt! Eine E-Mail zur Einrichtung wurde verschickt.`);
        } catch (err: unknown) {
            const createError = err instanceof Error ? err.message : 'Nutzer konnte nicht angelegt werden.';
            showToast('error', t`Fehler: ${createError}`);
        }
    };

    return (
        <div className="modal modal-open">
            <div className="modal-box relative">
                <button type="button" className="btn btn-circle btn-ghost absolute right-2 top-2" onClick={handleClose}>✕</button>
                <h3 className="font-bold text-lg mb-4"><Trans>Neuen Nutzer einladen</Trans></h3>
                <p className="text-sm opacity-70 mb-4"><Trans>Der Nutzer erhält eine E-Mail mit einem Link, um sein Passwort festzulegen.</Trans></p>
                <form onSubmit={handleSubmit(onSubmit)} className="space-y-4" noValidate>
                    <div className="form-control">
                        <label className="label"><span className="label-text font-bold"><Trans>Name</Trans></span></label>
                        <input type="text" required {...register('name')} className={`input input-bordered ${errors.name ? 'input-error' : ''}`} />
                        {errors.name && <span className="text-error text-sm mt-1">{errors.name.message}</span>}
                    </div>
                    <div className="form-control">
                        <label className="label"><span className="label-text font-bold"><Trans>E-Mail Adresse</Trans></span></label>
                        <input type="email" required {...register('email')} className={`input input-bordered ${errors.email ? 'input-error' : ''}`} />
                        {errors.email && <span className="text-error text-sm mt-1">{errors.email.message}</span>}
                    </div>
                    <div className="modal-action col-span-full">
                        <button type="button" className="btn btn-ghost" onClick={handleClose}><Trans>Abbrechen</Trans></button>
                        <button type="submit" className="btn btn-primary" disabled={isSubmitting}>
                            {isSubmitting ? <span className="loading loading-spinner"></span> : <Trans>Nutzer anlegen & Einladen</Trans>}
                        </button>
                    </div>
                </form>
            </div>
            <div className="modal-backdrop" onClick={handleClose}></div>
        </div>
    );
}
