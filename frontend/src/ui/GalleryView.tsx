import {useAuth} from '../logic/useAuth';
import ManagementGalleryView from './management/ManagementGalleryView';
import ClientGalleryView from './client/ClientGalleryView';

export default function GalleryView() {
    const {user, isLoading} = useAuth();
    if (isLoading) return <div className="flex h-screen items-center justify-center"><span className="loading loading-spinner loading-lg text-primary"></span></div>;
    return (user?.is_admin || user?.is_photographer) ? <ManagementGalleryView/> : <ClientGalleryView/>;
}
