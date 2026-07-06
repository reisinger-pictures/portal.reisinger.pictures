import {Navigate, Route, Routes} from 'react-router-dom';
import ErrorMessage from './ui/components/ErrorMessage';
import {useAuth} from './logic/useAuth';
import {usePermissions} from './logic/usePermissions';
import ErrorBoundary from './ui/components/ErrorBoundary';
import UIProvider from './ui/components/UIProvider';
import { useUI } from './ui/components/UIContext';
import { lazy, Suspense, useEffect } from 'react';
import { SWRConfig } from 'swr';
import { CartProvider } from './logic/CartProvider';
import { setGlobalErrorCallback } from './api';
import { t } from "@lingui/core/macro";
import { I18nProvider } from './logic/I18nProvider';

const ResetPassword = lazy(() => import('./ui/ResetPassword'));
const ProtectedDashboard = lazy(() => import('./ui/ProtectedDashboard'));
const GalleryView = lazy(() => import('./ui/GalleryView'));
const InviteView = lazy(() => import('./ui/InviteView'));
const TenantInviteView = lazy(() => import('./ui/TenantInviteView'));
import ContractJoinView from './ui/ContractJoinView';
import ContractSignView from './ui/ContractSignView';
const PhotoDetailView = lazy(() => import('./ui/PhotoDetailView'));
const ManagementMetaGalleryView = lazy(() => import('./ui/management/ManagementMetaGalleryView'));
const SearchView = lazy(() => import('./ui/SearchView'));
const ManagementTenantsView = lazy(() => import('./ui/management/ManagementTenantsView'));
const ManagementTenantDetailView = lazy(() => import('./ui/management/ManagementTenantDetailView'));
const UserProfileView = lazy(() => import('./ui/UserProfileView'));
const Privacy = lazy(() => import('./ui/Privacy'));
const Impressum = lazy(() => import('./ui/Impressum'));
const ClientNotificationsView = lazy(() => import('./ui/client/ClientNotificationsView'));
const ClientCartView = lazy(() => import('./ui/client/ClientCartView'));
const ClientOrdersView = lazy(() => import('./ui/client/ClientOrdersView'));

interface ProtectedRouteProps { children: React.ReactNode; requiredFeature?: 'b2b' }

function ProtectedRoute({children, requiredFeature}: ProtectedRouteProps) {
    const {user, isLoading, isError} = useAuth();
    const {canAccessB2BFeatures} = usePermissions();
    if (isLoading) return <div className="flex h-screen items-center justify-center"><span
        data-testid="app-loader" className="loading loading-spinner loading-lg text-primary"></span></div>;
    if (isError || !user) return <Navigate to="/" replace/>;
    if (requiredFeature === 'b2b' && !canAccessB2BFeatures) return <Navigate to="/" replace/>;
    return <>{children}</>;
}

const SuspenseFallback = () => <div className="flex h-screen items-center justify-center"><span className="loading loading-spinner loading-lg text-primary"></span></div>;

interface GlobalSWRConfigProps { children: React.ReactNode }

const GlobalSWRConfig = ({ children }: GlobalSWRConfigProps) => {
    const { showToast } = useUI();

    useEffect(() => {
        setGlobalErrorCallback((_status, message) => {
            showToast('error', message || t`Ein unerwarteter Serverfehler ist aufgetreten.`);
        });
    }, [showToast]);

    return (
        <SWRConfig value={{ 
            shouldRetryOnError: false,
            onError: (error) => {
                // Fange 500er Serverfehler und Status 0 (Offline/Netzwerk) global ab
                if (error.status >= 500 || error.status === 0) {
                    showToast('error', error.message || t`Ein unerwarteter Serverfehler ist aufgetreten.`);
                }
            }
        }}>
            {children}
        </SWRConfig>
    );
};

export default function App() {
    return (
        <ErrorBoundary fallback={<div className="flex h-screen items-center justify-center p-8">
            <ErrorMessage title={t`Kritischer Fehler`} message={t`Bitte Seite neu laden.`} />
        </div>}>
            <I18nProvider>
            <UIProvider>
                <CartProvider>
                <GlobalSWRConfig>
                    <Suspense fallback={<SuspenseFallback />}>
                        <Routes>
                            <Route path="/reset-password" element={<ResetPassword/>}/>

                            <Route path="/meta/:id" element={
                                <ProtectedRoute><ErrorBoundary><ManagementMetaGalleryView/></ErrorBoundary></ProtectedRoute>}/>
                            <Route path="/galleries" element={<ProtectedRoute><ErrorBoundary><ProtectedDashboard/></ErrorBoundary></ProtectedRoute>}/>
                            <Route path="/galleries/*" element={<ErrorBoundary><GalleryView/></ErrorBoundary>}/>
                            <Route path="/photos/:id" element={<ErrorBoundary><PhotoDetailView/></ErrorBoundary>}/>
                            <Route path="/invite/:token" element={<ErrorBoundary><InviteView/></ErrorBoundary>}/>
                            <Route path="/tenant-invite/:token" element={<ErrorBoundary><TenantInviteView/></ErrorBoundary>}/>
                            <Route path="/contracts/join/:token" element={<ErrorBoundary><ContractJoinView/></ErrorBoundary>}/>
                            <Route path="/contracts/sign/:token" element={<ErrorBoundary><ContractSignView/></ErrorBoundary>}/>

                            <Route path="/search" element={<ErrorBoundary><SearchView/></ErrorBoundary>}/>

                            {/* Dashboard-Weiche (ProtectedDashboard) für alle Root- und App-Views */}
                            <Route path="/" element={<ErrorBoundary><ProtectedDashboard/></ErrorBoundary>}/>

                            <Route path="/users"
                                element={<ProtectedRoute><ErrorBoundary><ProtectedDashboard/></ErrorBoundary></ProtectedRoute>}/>
                            <Route path="/profile" element={<ProtectedRoute><ErrorBoundary><UserProfileView/></ErrorBoundary></ProtectedRoute>}/>
                            <Route path="/settings"
                                element={<ProtectedRoute><ErrorBoundary><ProtectedDashboard/></ErrorBoundary></ProtectedRoute>}/>
                            <Route path="/stats"
                                element={<ProtectedRoute><ErrorBoundary><ProtectedDashboard/></ErrorBoundary></ProtectedRoute>}/>
                                            <Route path="/privacy" element={<ErrorBoundary><Privacy/></ErrorBoundary>}/>
                            <Route path="/impressum" element={<ErrorBoundary><Impressum/></ErrorBoundary>}/>
                            <Route path="/notifications" element={<ProtectedRoute><ErrorBoundary><ClientNotificationsView/></ErrorBoundary></ProtectedRoute>}/>
                            <Route path="/cart" element={<ProtectedRoute><ErrorBoundary><ClientCartView/></ErrorBoundary></ProtectedRoute>}/>
                            <Route path="/orders" element={<ProtectedRoute><ErrorBoundary><ClientOrdersView/></ErrorBoundary></ProtectedRoute>}/>
                            <Route path="/tenants" element={<ProtectedRoute requiredFeature="b2b"><ErrorBoundary><ManagementTenantsView/></ErrorBoundary></ProtectedRoute>}/>
                            <Route path="/tenants/:id" element={<ProtectedRoute requiredFeature="b2b"><ErrorBoundary><ManagementTenantDetailView/></ErrorBoundary></ProtectedRoute>}/>
                            <Route path="/admin-orders" element={<ProtectedRoute requiredFeature="b2b"><ErrorBoundary><ProtectedDashboard/></ErrorBoundary></ProtectedRoute>}/>
                            <Route path="/admin-manual-invoice" element={<ProtectedRoute requiredFeature="b2b"><ErrorBoundary><ProtectedDashboard/></ErrorBoundary></ProtectedRoute>}/>
                            <Route path="/admin-manual-offer" element={<ProtectedRoute requiredFeature="b2b"><ErrorBoundary><ProtectedDashboard/></ErrorBoundary></ProtectedRoute>}/>
                            <Route path="/admin-customers" element={<ProtectedRoute requiredFeature="b2b"><ErrorBoundary><ProtectedDashboard/></ErrorBoundary></ProtectedRoute>}/>
                            <Route path="/admin-products" element={<ProtectedRoute requiredFeature="b2b"><ErrorBoundary><ProtectedDashboard/></ErrorBoundary></ProtectedRoute>}/>
                            <Route path="/admin-snippets" element={<ProtectedRoute requiredFeature="b2b"><ErrorBoundary><ProtectedDashboard/></ErrorBoundary></ProtectedRoute>}/>
                            <Route path="/admin-payouts" element={<ProtectedRoute requiredFeature="b2b"><ErrorBoundary><ProtectedDashboard/></ErrorBoundary></ProtectedRoute>}/>
                            <Route path="/admin-contracts" element={<ProtectedRoute requiredFeature="b2b"><ErrorBoundary><ProtectedDashboard/></ErrorBoundary></ProtectedRoute>}/>
                            <Route path="/admin-coupons" element={<ProtectedRoute><ErrorBoundary><ProtectedDashboard/></ErrorBoundary></ProtectedRoute>}/>
                            <Route path="/my-payouts" element={<ProtectedRoute><ErrorBoundary><ProtectedDashboard/></ErrorBoundary></ProtectedRoute>}/>
                            <Route path="*" element={<Navigate to="/" replace/>}/>
                        </Routes>
                    </Suspense>
                </GlobalSWRConfig>
                </CartProvider>
            </UIProvider>
            </I18nProvider>
        </ErrorBoundary>
    );
}
