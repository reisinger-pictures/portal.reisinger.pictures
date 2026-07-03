import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import UserTable from '../management/components/UserTable';
import { UserDetailed, UserRole } from '../../logic/useUsers';

describe('B5 regression: users?.filter crash (SWR undefined response)', () => {
    it('renders without crashing when users is undefined', () => {
        expect(() =>
            render(
                <UserTable
                    users={undefined}
                    searchTerm=""
                    onEdit={vi.fn()}
                />,
            ),
        ).not.toThrow();

        expect(screen.getByText('Name')).toBeInTheDocument();
        expect(screen.getByText('E-Mail')).toBeInTheDocument();
    });

    it('renders "Keine Nutzer gefunden" when users is an empty array', () => {
        render(
            <UserTable
                users={[]}
                searchTerm=""
                onEdit={vi.fn()}
            />,
        );

        expect(screen.getByText('Name')).toBeInTheDocument();
        expect(screen.getByText((c) => c.includes('Keine Nutzer gefunden'))).toBeInTheDocument();
    });

    it('renders user rows when users is provided', () => {
        const users: UserDetailed[] = [
            {
                id: 'u1',
                name: 'Alice',
                email: 'alice@example.com',
                is_super_admin: false,
                is_admin: false,
                is_photographer: false,
                is_pending: false,
                can_edit_metadata: false,
                flatrate_level: 'none',
                can_purchase_upgrades: true,
                roles: [],
                gallery_groups: [],
                galleries: [],
            },
            {
                id: 'u2',
                name: 'Bob',
                email: 'bob@example.com',
                is_super_admin: false,
                is_admin: false,
                is_photographer: true,
                is_pending: false,
                can_edit_metadata: false,
                flatrate_level: 'none',
                can_purchase_upgrades: false,
                roles: [{ id: '1', name: UserRole.PHOTOGRAPHER }],
                gallery_groups: [],
                galleries: [],
            },
        ];

        render(
            <UserTable
                users={users}
                searchTerm=""
                onEdit={vi.fn()}
            />,
        );

        expect(screen.getByText('Alice')).toBeInTheDocument();
        expect(screen.getByText('Bob')).toBeInTheDocument();
        expect(screen.getByText('Fotograf')).toBeInTheDocument();
    });
});
