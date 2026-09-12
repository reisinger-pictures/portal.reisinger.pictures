import {describe, it, expect, vi, beforeEach} from 'vitest';
import {screen, fireEvent} from '@testing-library/react';
import {renderWithProviders} from '../../test-setup';
import ManagementPayoutsView from '../management/ManagementPayoutsView';

vi.mock('../../logic/usePayouts', () => ({
    useAdminPayouts: vi.fn(),
}));

vi.mock('../components/UIContext', () => ({
    useUI: vi.fn(),
}));

import {useAdminPayouts} from '../../logic/usePayouts';
import {useUI} from '../components/UIContext';

function renderView() {
    return renderWithProviders(<ManagementPayoutsView />);
}

describe('ManagementPayoutsView', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        vi.mocked(useAdminPayouts).mockReturnValue({
            data: {pools: [], statements: []},
            isLoading: false,
            calculateMonth: vi.fn(),
            updateStatus: vi.fn(),
        } as never);
        vi.mocked(useUI).mockReturnValue({
            showToast: vi.fn(),
            confirm: vi.fn(),
            hasUnsavedChanges: false,
            setUnsavedChanges: vi.fn(),
        });
    });

    it('falls back to a valid month when the month input is cleared', () => {
        renderView();
        const monthInput = screen.getByDisplayValue(String(new Date().getMonth() + 1));

        fireEvent.change(monthInput, {target: {value: '5'}});
        expect((monthInput as HTMLInputElement).value).toBe('5');

        fireEvent.change(monthInput, {target: {value: ''}});
        expect((monthInput as HTMLInputElement).value).toBe('1');
    });

    it('falls back to the current year when the year input is cleared', () => {
        const currentYear = String(new Date().getFullYear());
        renderView();
        const yearInput = screen.getByDisplayValue(currentYear);

        fireEvent.change(yearInput, {target: {value: '2025'}});
        expect((yearInput as HTMLInputElement).value).toBe('2025');

        fireEvent.change(yearInput, {target: {value: ''}});
        expect((yearInput as HTMLInputElement).value).toBe(currentYear);
    });
});
