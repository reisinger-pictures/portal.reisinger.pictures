import { useParams, useSearchParams } from 'react-router-dom';
import {useAuth} from '../logic/useAuth';
import ManagementGalleryView from './management/ManagementGalleryView';
import ClientGalleryView from './client/ClientGalleryView';
import {useGallery} from '../logic/useGallery';

export default function GalleryView() {
    const {isLoading: authLoading} = useAuth();
    const [searchParams] = useSearchParams();
    
    const params = useParams();
    const splat = params['*'];
    const slug = splat ? splat.split('/').pop() : '';

    const { canManage, isLoading: galleryLoading } = useGallery(slug);

    if (authLoading || galleryLoading) return <div className="flex h-screen items-center justify-center"><span className="loading loading-spinner loading-lg text-primary"></span></div>;

    const currentView = (canManage && searchParams.get('view') !== 'client') ? 'management' : 'client';

    return currentView === 'management' ? <ManagementGalleryView/> : <ClientGalleryView/>;
}
