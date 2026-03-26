import { useParams, useSearchParams } from 'react-router-dom';
import {useAuth} from '../logic/useAuth';
import ManagementGalleryView from './management/ManagementGalleryView';
import ClientGalleryView from './client/ClientGalleryView';

export default function GalleryView() {
    const {user, isLoading} = useAuth();
    const [searchParams] = useSearchParams();
    
    const params = useParams();
    const splat = params['*'];
    const slug = splat ? splat.split('/').pop() : '';

    if (isLoading) return <div className="flex h-screen items-center justify-center"><span className="loading loading-spinner loading-lg text-primary"></span></div>;

    const canManage = user?.is_admin || (user?.is_photographer && user?.my_galleries?.some(g => g.slug === slug));
    const currentView = (canManage && searchParams.get('view') !== 'client') ? 'management' : 'client';

    return currentView === 'management' ? <ManagementGalleryView/> : <ClientGalleryView/>;
}
