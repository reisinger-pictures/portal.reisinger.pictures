import {useAuth} from '../logic/useAuth';
import {usePermissions} from '../logic/usePermissions';
import ManagementDashboard from './management/ManagementDashboard';
import ClientDashboard from './client/ClientDashboard';
import SearchView from './SearchView';

export default function ProtectedDashboard() {
    const {user, isLoading} = useAuth();
    const {isStaff} = usePermissions();

    if (isLoading) return <div className="flex h-screen items-center justify-center"><span
        data-testid="app-loader" className="loading loading-spinner loading-lg"></span></div>;

    // Fallback für nicht angemeldete Gäste
    if (!user) return <SearchView/>;

    // Rollen-Weiche für authentifizierte Nutzer
    if (isStaff) {
        return <ManagementDashboard/>;
    }

    return <ClientDashboard/>;
}
