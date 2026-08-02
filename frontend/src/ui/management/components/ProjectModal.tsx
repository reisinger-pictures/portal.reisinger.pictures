import { t } from "@lingui/core/macro";
import { Trans } from "@lingui/react/macro";
import { useEffect } from 'react';
import { useForm, Controller } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { useUI } from '../../components/UIContext';
import AutocompleteInput from '../../components/AutocompleteInput';
import { Customer } from '../../../api';
import { Project, ProjectInput } from '../../../logic/useProjectsBoard';
import { useUsers } from '../../../logic/useUsers';

const priceMessage = t`Bitte einen gültigen Betrag eingeben`;

export interface BoardStatusOption { value: string; label: string; }

const projectSchema = z.object({
    client_name: z.string().min(1, t`Kundenname ist erforderlich`),
    email: z.string().email(t`Bitte eine gültige E-Mail angeben`).optional().or(z.literal('')),
    phone: z.string().optional(),
    package: z.string().optional(),
    price_eur: z.string().optional().refine(
        (val) => val === undefined || val === '' || (!Number.isNaN(Number(val)) && Number(val) >= 0),
        { message: priceMessage }
    ),
    payment_status: z.string().optional(),
    status: z.string().optional(),
    assignee_id: z.string().optional(),
});

type ProjectFormValues = z.infer<typeof projectSchema>;

interface Props {
    isOpen: boolean;
    onClose: () => void;
    defaultStatus: string;
    statusOptions: BoardStatusOption[];
    editing?: Project | null;
    onSave: (payload: ProjectInput & { status?: string }) => Promise<void>;
    initial?: { client_name?: string; email?: string };
}

const paymentOptions = [
    { value: 'open', label: t`Offen` },
    { value: 'partly_paid', label: t`Teilbezahlt` },
    { value: 'paid', label: t`Bezahlt` },
];

export default function ProjectModal({ isOpen, onClose, editing, onSave, initial, defaultStatus, statusOptions }: Props) {
    const { register, control, handleSubmit, reset, setValue, formState: { errors, isSubmitting } } = useForm<ProjectFormValues>({
        resolver: zodResolver(projectSchema),
        defaultValues: {
            client_name: '',
            email: '',
            phone: '',
            package: '',
            price_eur: '',
            payment_status: 'open',
            status: '',
            assignee_id: '',
        },
    });
    const { showToast } = useUI();
    const { users } = useUsers();

    useEffect(() => {
        if (isOpen) {
            reset({
                client_name: editing?.client_name ?? initial?.client_name ?? '',
                email: editing?.email ?? initial?.email ?? '',
                phone: editing?.phone ?? '',
                package: editing?.package ?? '',
                price_eur: editing?.price_cents != null ? String(editing.price_cents / 100) : '',
                payment_status: editing?.payment_status ?? 'open',
                status: editing?.status ?? defaultStatus ?? '',
                assignee_id: editing?.assignee?.id ?? '',
            });
        }
    }, [isOpen, editing, initial, defaultStatus, reset]);

    if (!isOpen) return null;

    const onSubmit = async (data: ProjectFormValues) => {
        const priceEur = data.price_eur === undefined || data.price_eur === '' ? undefined : Number(data.price_eur);
        const input: ProjectInput = {
            client_name: data.client_name,
            email: data.email || '',
            phone: data.phone || null,
            package: data.package || null,
            price_cents: priceEur != null ? Math.round(priceEur * 100) : undefined,
            payment_status: data.payment_status || 'open',
        };
        if (data.assignee_id) {
            input.assignee_id = data.assignee_id;
        }
        const payload: ProjectInput & { status?: string } = { ...input, status: data.status || undefined };
        try {
            await onSave(payload);
            onClose();
        } catch (err: unknown) {
            showToast('error', err instanceof Error ? err.message : t`Speichern fehlgeschlagen`);
        }
    };

    return (
        <div className="modal modal-open">
            <div className="modal-box max-w-2xl">
                <button type="button" className="btn btn-circle btn-ghost absolute right-2 top-2" onClick={onClose}>✕</button>
                <h3 className="font-bold text-xl mb-6">
                    {editing ? <Trans>Projekt bearbeiten</Trans> : <Trans>Neues Projekt anlegen</Trans>}
                </h3>
                <form onSubmit={handleSubmit(onSubmit)} noValidate>
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div className="form-control md:col-span-2">
                            {editing ? (
                                <>
                                    <label className="label"><span className="label-text font-bold"><Trans>Kundenname</Trans></span></label>
                                    <input required type="text" {...register('client_name')} className={`input input-bordered ${errors.client_name ? 'input-error' : ''}`} />
                                </>
                            ) : (
                                <Controller
                                    name="client_name"
                                    control={control}
                                    render={({ field }) => (
                                        <AutocompleteInput<Customer>
                                            label={t`Kundenname`}
                                            required
                                            value={field.value}
                                            onChange={field.onChange}
                                            onSelect={(c) => {
                                                field.onChange(c.name || '');
                                                setValue('email', c.email || '');
                                            }}
                                            endpoint="/api/management/customers?q="
                                            mapResponse={(data) => data.map(c => ({ id: c.id, title: c.name || c.company || t`Unbekannt`, subtitle: c.email || '', raw: c }))}
                                        />
                                    )}
                                />
                            )}
                            {errors.client_name && <span className="text-error text-xs mt-1">{errors.client_name.message}</span>}
                        </div>
                        <div className="form-control">
                            <label className="label"><span className="label-text font-bold"><Trans>E-Mail</Trans></span></label>
                            <input type="email" {...register('email')} className="input input-bordered" />
                            {errors.email && <span className="text-error text-xs mt-1">{errors.email.message}</span>}
                        </div>
                        <div className="form-control">
                            <label className="label"><span className="label-text font-bold"><Trans>Telefon</Trans></span></label>
                            <input type="text" {...register('phone')} className="input input-bordered" />
                        </div>
                        <div className="form-control">
                            <label className="label"><span className="label-text font-bold"><Trans>Paket</Trans></span></label>
                            <input type="text" {...register('package')} className="input input-bordered" />
                        </div>
                        <div className="form-control">
                            <label className="label"><span className="label-text font-bold"><Trans>Preis (€)</Trans></span></label>
                            <div className="join w-full">
                                <input type="number" min="0" step="0.01" placeholder="0.00" {...register('price_eur')} className={`input input-bordered join-item w-full ${errors.price_eur ? 'input-error' : ''}`} />
                                <span className="btn btn-disabled join-item px-3 no-animation">€</span>
                            </div>
                            {errors.price_eur && <span className="text-error text-xs mt-1">{errors.price_eur.message}</span>}
                        </div>
                        <div className="form-control">
                            <label className="label"><span className="label-text font-bold"><Trans>Zahlungsstatus</Trans></span></label>
                            <select {...register('payment_status')} className="select select-bordered">
                                {paymentOptions.map(opt => <option key={opt.value} value={opt.value}>{opt.label}</option>)}
                            </select>
                        </div>
                        <div className="form-control">
                            <label className="label"><span className="label-text font-bold"><Trans>Status</Trans></span></label>
                            <select {...register('status')} className="select select-bordered">
                                {statusOptions.map(opt => <option key={opt.value} value={opt.value}>{opt.label}</option>)}
                            </select>
                        </div>
                        <div className="form-control">
                            <label className="label"><span className="label-text font-bold"><Trans>Zuständig</Trans></span></label>
                            <select {...register('assignee_id')} className="select select-bordered">
                                <option value=""><Trans>Keine Zuweisung</Trans></option>
                                {users?.map(u => <option key={u.id} value={u.id}>{u.name}</option>)}
                            </select>
                        </div>
                    </div>
                    <div className="modal-action">
                        <button type="button" className="btn btn-ghost" onClick={onClose}><Trans>Abbrechen</Trans></button>
                        <button type="submit" className="btn btn-primary" disabled={isSubmitting}>
                            {isSubmitting ? <span className="loading loading-spinner"></span> : <Trans>Speichern</Trans>}
                        </button>
                    </div>
                </form>
            </div>
            <div className="modal-backdrop" onClick={onClose}></div>
        </div>
    );
}