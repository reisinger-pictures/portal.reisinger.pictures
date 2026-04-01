import {Navigate, Route, Routes} from 'react-router-dom';
import ErrorMessage from './ui/components/ErrorMessage';
import {useAuth} from './logic/useAuth';
import ErrorBoundary from './ui/components/ErrorBoundary';
import { UIProvider } from './ui/components/UIContext';
import { lazy, Suspense } from 'react';
const ResetPassword = lazy(() => import('./ui/ResetPassword'));
const ProtectedDashboard = lazy(() => import('./ui/ProtectedDashboard'));
const GalleryView = lazy(() => import('./ui/GalleryView'));
const InviteView = lazy(() => import('./ui/InviteView'));
const PhotoDetailView = lazy(() => import('./ui/PhotoDetailView'));
const ManagementMetaGalleryView = lazy(() => import('./ui/management/ManagementMetaGalleryView'));
const SearchView = lazy(() => import('./ui/SearchView'));
const Privacy = lazy(() => import('./ui/Privacy'));
const ClientNotificationsView = lazy(() => import('./ui/client/ClientNotificationsView'));

function ProtectedRoute({children}: { children: React.ReactNode }) {
    const {user, isLoading, isError} = useAuth();
    if (isLoading) return <div className="flex h-screen items-center justify-center"><span
        data-testid="app-loader" className="loading loading-spinner loading-lg text-primary"></span></div>;
    if (isError || !user) return <Navigate to="/" replace/>;
    return <>{children}</>;
}

const SuspenseFallback = () => <div className="flex h-screen items-center justify-center"><span className="loading loading-spinner loading-lg text-primary"></span></div>;

export default function App() {
    return (
        <ErrorBoundary fallback={<div className="flex h-screen items-center justify-center p-8">
            <ErrorMessage title="Kritischer Fehler" message="Bitte Seite neu laden." />
        </div>}>
            <UIProvider>
                <Suspense fallback={<SuspenseFallback />}>
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
                </Suspense>
            </UIProvider>
        </ErrorBoundary>
    );
}
