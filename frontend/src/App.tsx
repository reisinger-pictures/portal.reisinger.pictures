import {Navigate, Route, Routes} from 'react-router-dom';
import ErrorMessage from './ui/components/ErrorMessage';
import {useAuth} from './logic/useAuth';
import ResetPassword from './ui/ResetPassword';
import ProtectedDashboard from './ui/ProtectedDashboard';
import GalleryView from './ui/GalleryView';
import InviteView from './ui/InviteView';
import PhotoDetailView from './ui/PhotoDetailView';
import ManagementMetaGalleryView from './ui/management/ManagementMetaGalleryView';
import SearchView from './ui/SearchView';
import ErrorBoundary from './ui/components/ErrorBoundary';
import { UIProvider } from './ui/components/UIContext';
import Privacy from './ui/Privacy';
import ClientNotificationsView from './ui/client/ClientNotificationsView';

function ProtectedRoute({children}: { children: React.ReactNode }) {
    const {user, isLoading, isError} = useAuth();
    if (isLoading) return <div className="flex h-screen items-center justify-center"><span
        className="loading loading-spinner loading-lg text-primary"></span></div>;
    if (isError || !user) return <Navigate to="/" replace/>;
    return <>{children}</>;
}

export default function App() {
    return (
        <ErrorBoundary fallback={<div className="flex h-screen items-center justify-center p-8">
            <ErrorMessage title="Kritischer Fehler" message="Bitte Seite neu laden." />
        </div>}>
            <UIProvider>
                <Routes>
                <Route path="/reset-password" element={<ResetPassword/>}/>

                <Route path="/meta/:id" element={
                    <ProtectedRoute><ErrorBoundary><ManagementMetaGalleryView/></ErrorBoundary></ProtectedRoute>}/>
                <Route path="/galleries" element={<ProtectedRoute><ErrorBoundary><ProtectedDashboard/></ErrorBoundary></ProtectedRoute>}/>
                <Route path="/galleries/*" element={<ErrorBoundary><GalleryView/></ErrorBoundary>}/>
                <Route path="/photos/:id" element={<ErrorBoundary><PhotoDetailView/></ErrorBoundary>}/>
                <Route path="/invite/:token" element={<ErrorBoundary><InviteView/></ErrorBoundary>}/>

                <Route path="/search" element={<ErrorBoundary><SearchView/></ErrorBoundary>}/>

                {/* Dashboard-Weiche (ProtectedDashboard) für alle Root- und App-Views */}
                <Route path="/" element={<ErrorBoundary><ProtectedDashboard/></ErrorBoundary>}/>

                <Route path="/users"
                       element={<ProtectedRoute><ErrorBoundary><ProtectedDashboard/></ErrorBoundary></ProtectedRoute>}/>
                <Route path="/settings"
                       element={<ProtectedRoute><ErrorBoundary><ProtectedDashboard/></ErrorBoundary></ProtectedRoute>}/>
                <Route path="/stats"
                       element={<ProtectedRoute><ErrorBoundary><ProtectedDashboard/></ErrorBoundary></ProtectedRoute>}/>
                                <Route path="/privacy" element={<ErrorBoundary><Privacy/></ErrorBoundary>}/>
                <Route path="/notifications" element={<ProtectedRoute><ErrorBoundary><ClientNotificationsView/></ErrorBoundary></ProtectedRoute>}/>
                <Route path="*" element={<Navigate to="/" replace/>}/>
            </Routes>
            </UIProvider>
        </ErrorBoundary>
    );
}
