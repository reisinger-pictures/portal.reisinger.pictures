import { Trans } from "@lingui/react/macro";
import { type ReactNode } from 'react';

interface EditableTableRowProps {
    isEditing: boolean;
    onStartEdit: () => void;
    onCancel: () => void;
    onSave: () => Promise<void>;
    onDelete: () => void;
    /** Render view mode columns (excluding actions) */
    renderView: () => ReactNode;
    /** Render edit mode columns (excluding actions) */
    renderEdit: () => ReactNode;
    /** Optional: show saving state on buttons */
    saving?: boolean;
}

function EditableTableRow({
    isEditing,
    onStartEdit,
    onCancel,
    onSave,
    onDelete,
    renderView,
    renderEdit,
    saving,
}: EditableTableRowProps) {
    if (isEditing) {
        return (
            <tr>
                {renderEdit()}
                <td className="text-right whitespace-nowrap">
                    <button
                        onClick={onCancel}
                        className="btn btn-xs btn-ghost mr-1"
                        disabled={saving}
                    >
                        <Trans>Abbrechen</Trans>
                    </button>
                    <button
                        onClick={onSave}
                        className="btn btn-xs btn-primary"
                        disabled={saving}
                    >
                        {saving ? (
                            <span className="loading loading-spinner loading-xs" />
                        ) : (
                            <Trans>Speichern</Trans>
                        )}
                    </button>
                </td>
            </tr>
        );
    }

    return (
        <tr>
            {renderView()}
            <td className="text-right">
                <div className="flex justify-end gap-1">
                    <button
                        onClick={onStartEdit}
                        className="btn btn-xs btn-ghost btn-square"
                    >
                        <span className="iconify mdi--pencil text-base" />
                    </button>
                    <button
                        onClick={onDelete}
                        className="btn btn-xs btn-ghost btn-square text-error"
                    >
                        <span className="iconify mdi--trash-can text-base" />
                    </button>
                </div>
            </td>
        </tr>
    );
}

export default EditableTableRow;
