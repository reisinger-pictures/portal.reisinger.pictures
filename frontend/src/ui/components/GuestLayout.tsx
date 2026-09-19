import type { ReactNode } from 'react';
import { useBrand } from '../../logic/useBrand';

export interface GuestLayoutProps {
    children: ReactNode;
}

/**
 * Minimal shell for public, login-free flows reached via a magic link
 * (e.g. model registration). Shows branding only — no sidebar, cart, shop
 * login form or gallery search from the authenticated shop layout.
 */
export default function GuestLayout({ children }: GuestLayoutProps) {
    const { logoSrc, portalName } = useBrand();

    return (
        <div className="flex min-h-dvh flex-col bg-base-200">
            <header role="banner" className="border-b border-base-300 bg-base-100">
                <div className="mx-auto flex w-full max-w-4xl items-center gap-3 px-4 py-3">
                    <img src={logoSrc} alt="Logo" className="h-8 w-8 rounded bg-base-100 shadow-sm" />
                    <span className="font-bold">{portalName}</span>
                </div>
            </header>
            <main className="flex flex-1 flex-col overflow-y-auto">
                {children}
            </main>
        </div>
    );
}
