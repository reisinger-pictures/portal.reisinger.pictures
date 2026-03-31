import {useAuth} from '../logic/useAuth';
import ManagementDashboard from './management/ManagementDashboard';
import ClientDashboard from './client/ClientDashboard';
import AnonymousDashboard from './anonymous/AnonymousDashboard';

export default function ProtectedDashboard() {
    const {user, isLoading} = useAuth();

    if (isLoading) return <div className="flex h-screen items-center justify-center"><span
        data-testid="app-loader" className="loading loading-spinner loading-lg"></span></div>;

    // Fallback für nicht angemeldete Gäste
    if (!user) return <AnonymousDashboard/>;

    // Rollen-Weiche für authentifizierte Nutzer
    if (user.is_admin || user.is_photographer) {
        return <ManagementDashboard/>;
    }

    return <ClientDashboard/>;
}
