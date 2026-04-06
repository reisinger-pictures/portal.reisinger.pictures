import { useEffect } from 'react';
import { useAuth } from '../../../logic/useAuth';
import { apiMutate } from '../../../api';
import { useUI } from '../../components/UIContext';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';

const profileSchema = z.object({
    name: z.string().min(1, 'Name ist erforderlich'),
    metadata_copyright: z.string().optional(),
    ftp_slug: z.string().optional()
});
type ProfileFormValues = z.infer<typeof profileSchema>;

export default function ProfileSettingsCard() {
    const { user, mutate: mutateUser } = useAuth();
    const { showToast } = useUI();

    const profileForm = useForm<ProfileFormValues>({
        resolver: zodResolver(profileSchema),
        defaultValues: { name: '', metadata_copyright: '', ftp_slug: '' }
    });

    useEffect(() => {
        if (user) {
            profileForm.reset({
                name: user.name || '',
                metadata_copyright: user.metadata_copyright || '',
                ftp_slug: user.ftp_slug || ''
            });
        }
    }, [user, profileForm]);

    const onSubmit = async (data: ProfileFormValues) => {
        try {
            const payload: Record<string, unknown> = { name: data.name, metadata_copyright: data.metadata_copyright };
            if (user?.is_photographer) payload.ftp_slug = data.ftp_slug;
            
            await apiMutate('/api/auth/profile', 'PUT', payload);
            await mutateUser();
            showToast('success', 'Profil aktualisiert');
        } catch {
            showToast('error', 'Fehler beim Speichern');
        }
    };

    return (
        <div className="card bg-base-200 border border-base-300">
            <div className="card-body">
                <h2 className="card-title text-2xl mb-4">Profil & Standardwerte</h2>
                
                <form onSubmit={profileForm.handleSubmit(onSubmit)} className="space-y-6">
                    <div className="form-control">
                        <label className="label"><span className="label-text font-bold">Dein Name</span></label>
                        <input 
                            type="text" 
                            {...profileForm.register('name')} 
                            className={`input input-bordered w-full ${profileForm.formState.errors.name ? 'input-error' : ''}`}
                        />
                    </div>
                    
                    {user?.is_photographer && (
                        <div className="form-control">
                            <label className="label">
                                <span className="label-text font-bold">FTP Upload Ordner (Slug)</span>
                                <span className="label-text-alt opacity-70">Der Ordnername für deine FTP-Uploads. Muss eindeutig sein.</span>
                            </label>
                            <div className="join w-full">
                                <span className="btn no-animation join-item bg-base-300 border-base-300 font-mono text-sm px-3 opacity-70 cursor-default">/</span>
                                <input 
                                    type="text" 
                                    placeholder="z.B. max" 
                                    {...profileForm.register('ftp_slug')} 
                                    className="input input-bordered join-item w-full font-mono text-sm"
                                />
                            </div>
                        </div>
                    )}
                    <div className="form-control">
                        <label className="label">
                            <span className="label-text font-bold">Standard-Urheber (IPTC Copyright)</span>
                            <span className="label-text-alt opacity-70">Dieser Wert wird in neue Bilder geschrieben, falls die Galerie Metadaten anwendet.</span>
                        </label>
                        <input 
                            type="text" 
                            placeholder="z.B. Max Mustermann" 
                            {...profileForm.register('metadata_copyright')} 
                            className="input input-bordered w-full"
                        />
                    </div>
                    <div>
                        <button type="submit" disabled={profileForm.formState.isSubmitting} className="btn btn-primary">
                            {profileForm.formState.isSubmitting ? <span className="loading loading-spinner"></span> : 'Profil speichern'}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}
