import { Navigate, useParams } from 'react-router-dom';
import ErrorMessage from '../components/ErrorMessage';
import { useGallery } from '../../logic/useGallery';
import PageLayout from '../components/PageLayout';
import SelectionView from './SelectionView';
import DeliveryView from './DeliveryView';

export default function ClientGalleryView() {
    const params = useParams();
    const splat = params['*'];
    const slug = splat ? splat.split('/').pop() : '';

    const galleryData = useGallery(slug);

    if (galleryData.isLoading && galleryData.photos.length === 0) {
        return (
            <PageLayout>
                <div className="flex h-full items-center justify-center">
                    <span className="loading loading-spinner"></span>
                </div>
            </PageLayout>
        );
    }

    if (galleryData.isError?.message === 'Unauthenticated' || galleryData.isError?.message?.includes('401')) {
        return <Navigate to="/" replace/>;
    }

    if (galleryData.isError || !galleryData.gallery) {
        return (
            <PageLayout>
                <div className="p-8"><ErrorMessage message="Galerie nicht gefunden oder Zugriff verweigert." /></div>
            </PageLayout>
        );
    }

    if (galleryData.gallery.type === 'selection') {
        return <SelectionView galleryData={galleryData} />;
    }

    return <DeliveryView galleryData={galleryData} />;
}