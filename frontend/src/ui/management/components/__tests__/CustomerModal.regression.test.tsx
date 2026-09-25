import { beforeEach, describe, it, expect, vi } from 'vitest';
import { fireEvent, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import useSWR from 'swr';
import { renderWithProviders } from '../../../../test-setup';
import CustomerModal from '../CustomerModal';

vi.mock('swr', () => ({
    default: vi.fn(),
}));

const locations = [
    {
        id: 'location-vienna',
        type: 'city' as const,
        name: 'Wien',
        state: 'Wien',
        country: 'Österreich',
        iso_country: 'AT',
        postal_code: '1010',
    },
    {
        id: 'location-graz',
        type: 'city' as const,
        name: 'Graz',
        state: 'Steiermark',
        country: 'Österreich',
        iso_country: 'AT',
        postal_code: '8010',
    },
];

describe('CustomerModal save regression', () => {
    beforeEach(() => {
        vi.mocked(useSWR).mockReturnValue({
            data: locations,
            error: undefined,
            isValidating: false,
            mutate: vi.fn(),
        } as never);
    });

    it('associates every labeled field and preserves required semantics', () => {
        renderWithProviders(
            <CustomerModal isOpen onClose={vi.fn()} onSave={vi.fn()} />,
        );

        const textFields = [
            ['Name / Ansprechpartner', true],
            ['Firma', false],
            ['E-Mail Adresse', false],
            ['Geburtsdatum', false],
            ['U-ID (Umsatzsteuer-ID)', false],
            ['Straße & Hausnummer', false],
        ] as const;
        const textControls = textFields.map(([name]) => screen.getByLabelText(name));
        const comboControls = ['PLZ', 'Stadt', 'Land'].map(name => screen.getByRole('combobox', {name}));
        const controls = [...textControls, ...comboControls];

        for (const [name, required] of textFields) {
            const control = screen.getByLabelText(name);
            expect(control).toHaveAccessibleName(name);
            expect(control).toHaveAttribute('id');
            if (required) {
                expect(control).toBeRequired();
            } else {
                expect(control).not.toBeRequired();
            }
        }
        for (const control of comboControls) {
            expect(control).toHaveAttribute('id');
            expect(control).not.toBeRequired();
        }
        expect(new Set(controls.map(control => control.getAttribute('id'))).size).toBe(controls.length);
        const locationGroup = screen.getByRole('group', {name: 'PLZ & Stadt'});
        expect(locationGroup).toHaveAttribute('aria-labelledby');
        expect(locationGroup).toContainElement(comboControls[0]);
        expect(locationGroup).toContainElement(comboControls[1]);
    });

    it('keeps adjacent combobox relationships unique and clears stale active descendants', async () => {
        const user = userEvent.setup();
        renderWithProviders(
            <CustomerModal isOpen onClose={vi.fn()} onSave={vi.fn()} />,
        );

        const zipInput = screen.getByRole('combobox', {name: 'PLZ'});
        const cityInput = screen.getByRole('combobox', {name: 'Stadt'});

        expect(zipInput).not.toHaveAttribute('aria-controls');
        expect(zipInput).not.toHaveAttribute('aria-activedescendant');
        expect(cityInput).not.toHaveAttribute('aria-controls');
        expect(cityInput).not.toHaveAttribute('aria-activedescendant');

        await user.click(zipInput);
        await user.keyboard('{ArrowDown}');

        const zipListboxId = zipInput.getAttribute('aria-controls');
        const zipActiveId = zipInput.getAttribute('aria-activedescendant');
        expect(zipListboxId).toBe(`${zipInput.id}-listbox`);
        expect(zipActiveId).toBe(`${zipInput.id}-option-0`);
        expect(document.getElementById(zipListboxId ?? '')).toBe(screen.getByRole('listbox'));
        expect(document.getElementById(zipActiveId ?? '')).toBe(screen.getAllByRole('option')[0]);

        fireEvent.blur(zipInput);
        expect(zipInput).toHaveAttribute('aria-expanded', 'false');
        expect(zipInput).not.toHaveAttribute('aria-controls');
        expect(zipInput).not.toHaveAttribute('aria-activedescendant');

        fireEvent.focus(zipInput);
        await user.keyboard('{ArrowDown}');
        await user.click(cityInput);
        await user.keyboard('{ArrowDown}');

        const cityListboxId = cityInput.getAttribute('aria-controls');
        const cityActiveId = cityInput.getAttribute('aria-activedescendant');
        expect(zipInput).not.toHaveAttribute('aria-controls');
        expect(zipInput).not.toHaveAttribute('aria-activedescendant');
        expect(cityListboxId).toBe(`${cityInput.id}-listbox`);
        expect(cityActiveId).toBe(`${cityInput.id}-option-0`);
        expect(cityListboxId).not.toBe(zipListboxId);
        expect(cityActiveId).not.toBe(zipActiveId);
        expect(document.getElementById(zipListboxId ?? '')).not.toBeInTheDocument();
        expect(document.getElementById(zipActiveId ?? '')).not.toBeInTheDocument();

        await user.tab();

        expect(cityInput).not.toHaveAttribute('aria-controls');
        expect(cityInput).not.toHaveAttribute('aria-activedescendant');
        expect(cityInput).toHaveAttribute('aria-expanded', 'false');
        expect(document.getElementById(cityListboxId ?? '')).not.toBeInTheDocument();
        expect(document.getElementById(cityActiveId ?? '')).not.toBeInTheDocument();
    });

    it('resets the active option when fetched results shrink', async () => {
        const user = userEvent.setup();
        const onClose = vi.fn();
        const onSave = vi.fn();
        const {rerender} = renderWithProviders(
            <CustomerModal isOpen onClose={onClose} onSave={onSave} />,
        );
        const zipInput = screen.getByRole('combobox', {name: 'PLZ'});

        await user.click(zipInput);
        await user.keyboard('{ArrowDown}{ArrowDown}');
        expect(zipInput).toHaveAttribute('aria-activedescendant', `${zipInput.id}-option-1`);

        vi.mocked(useSWR).mockReturnValue({
            data: locations.slice(0, 1),
            error: undefined,
            isValidating: false,
            mutate: vi.fn(),
        } as never);
        rerender(<CustomerModal isOpen onClose={onClose} onSave={onSave} />);

        expect(zipInput).toHaveAttribute('aria-expanded', 'true');
        expect(zipInput).not.toHaveAttribute('aria-activedescendant');

        await user.keyboard('{ArrowDown}{Enter}');

        expect(zipInput).toHaveValue('1010');
        expect(zipInput).toHaveAttribute('aria-expanded', 'false');
        expect(zipInput).not.toHaveAttribute('aria-activedescendant');
    });

    it('clears active descendants on Escape, outside interaction, and selection', async () => {
        const user = userEvent.setup();
        const onClose = vi.fn();
        renderWithProviders(
            <CustomerModal isOpen onClose={onClose} onSave={vi.fn()} />,
        );

        const zipInput = screen.getByRole('combobox', {name: 'PLZ'});
        const expectClosed = () => {
            expect(zipInput).toHaveAttribute('aria-expanded', 'false');
            expect(zipInput).not.toHaveAttribute('aria-controls');
            expect(zipInput).not.toHaveAttribute('aria-activedescendant');
        };

        await user.click(zipInput);
        await user.keyboard('{ArrowDown}{Escape}');
        expectClosed();

        await user.click(zipInput);
        await user.keyboard('{ArrowDown}');
        await user.click(screen.getByRole('button', {name: 'Abbrechen'}));
        expectClosed();
        expect(onClose).toHaveBeenCalledTimes(1);

        await user.click(zipInput);
        await user.keyboard('{ArrowDown}');
        await user.click(screen.getByRole('option', {name: '1010Wien'}));
        expectClosed();
    });

    it('keeps entered customer data open when saving rejects', async () => {
        const user = userEvent.setup();
        const onClose = vi.fn();
        const onSave = vi.fn().mockRejectedValue(new Error('Speichern fehlgeschlagen'));
        const { container } = renderWithProviders(
            <CustomerModal isOpen onClose={onClose} onSave={onSave} />,
        );

        const nameInput = container.querySelector('input[name="name"]') as HTMLInputElement;
        await user.type(nameInput, 'Max Mustermann');
        await user.click(screen.getByRole('button', { name: 'Speichern' }));

        await waitFor(() => expect(onSave).toHaveBeenCalledTimes(1));
        await waitFor(() => expect(screen.getByRole('button', { name: 'Speichern' })).toBeEnabled());

        expect(onClose).not.toHaveBeenCalled();
        expect(nameInput).toHaveValue('Max Mustermann');
        expect(screen.getByText('Neuen Kunden anlegen')).toBeInTheDocument();
    });
});
