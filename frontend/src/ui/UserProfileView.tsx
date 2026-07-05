import { Trans } from "@lingui/react/macro";
import ProfileSettingsCard from './management/components/ProfileSettingsCard';
import PageLayout from './components/PageLayout';

export default function UserProfileView() {
    return (
        <PageLayout currentView="profile">
            <div className="p-4 md:p-10 max-w-4xl mx-auto w-full flex flex-col gap-8">
                <h1 className="text-4xl font-bold"><Trans>Mein Profil</Trans></h1>
                <ProfileSettingsCard />
            </div>
        </PageLayout>
    );
}
