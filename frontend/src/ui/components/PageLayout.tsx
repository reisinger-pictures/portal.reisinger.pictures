import React from 'react';
import DashboardLayout from './DashboardLayout';
import GlobalSearchHeader from './GlobalSearchHeader';

export interface PageLayoutProps {
    children: React.ReactNode;
    currentView?: string;
}

export default function PageLayout({ children, currentView }: PageLayoutProps) {
    return (
        <DashboardLayout
            currentView={currentView}
            mainClassName="bg-base-200"
            header={({ onMenuClick }) => <GlobalSearchHeader onMenuClick={onMenuClick} />}
        >
            {children}
        </DashboardLayout>
    );
}
