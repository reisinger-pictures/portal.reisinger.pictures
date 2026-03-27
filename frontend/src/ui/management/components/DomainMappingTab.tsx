import { useState } from 'react';
import {DomainMapping, Role} from '../../../logic/useUsers';
import {FlatGroup} from '../../../logic/useGalleries';

interface DomainMappingTabProps {
    mappings?: DomainMapping[];
    roles?: Role[];
    flatGroups: FlatGroup[];
    onCreateMapping: (domain: string, role_id: string | null, group_id: string | null) => Promise<void>;
    onDeleteMapping: (id: string) => Promise<void>;
}

export default function DomainMappingTab({
                                             mappings,
                                             roles,
                                             flatGroups,
                                             onCreateMapping,
                                             onDeleteMapping
                                         }: DomainMappingTabProps) {
    const [newDomain, setNewDomain] = useState('');
    const [newRole, setNewRole] = useState<string>('');
    const [newGroup, setNewGroup] = useState<string>('');

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault();
        await onCreateMapping(newDomain, newRole === '' ? null : newRole, newGroup === '' ? null : newGroup);
        setNewDomain('');
        setNewRole('');
        setNewGroup('');
    };

    return (
        <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
            <div className="md:col-span-2 overflow-x-auto bg-base-100 rounded-box border border-base-300">
                <table className="table table-zebra">
                    <thead>
                    <tr>
                        <th>Domain</th>
                        <th>Zugewiesene Rolle</th>
                        <th>Zugewiesene Gruppe</th>
                        <th>Aktion</th>
                    </tr>
                    </thead>
                    <tbody>
                    {mappings?.map(m => (
                        <tr key={m.id}>
                            <td className="font-bold">@{m.domain}</td>
                            <td>{m.role?.name || '-'}</td>
                            <td>{m.gallery_group?.name || '-'}</td>
                            <td>
                                <button className="btn btn-xs btn-error btn-outline"
                                        onClick={() => onDeleteMapping(m.id)}>Löschen
                                </button>
                            </td>
                        </tr>
                    ))}
                    </tbody>
                </table>
            </div>
            <div>
                <div className="card bg-base-200 border border-base-300">
                    <div className="card-body p-6">
                        <h3 className="card-title text-lg mb-2">Neues Mapping</h3>
                        <form onSubmit={handleSubmit} className="space-y-4">
                            <div><input type="text" required value={newDomain}
                                        onChange={e => setNewDomain(e.target.value)} placeholder="firma.de"
                                        className="input input-sm input-bordered w-full"/></div>
                            <div>
                                <select value={newRole}
                                        onChange={e => setNewRole(e.target.value)}
                                        className="select select-sm select-bordered w-full">
                                    <option value="">-- Keine Rolle --</option>
                                    {roles?.map(r => <option key={r.id} value={r.id}>{r.name}</option>)}
                                </select>
                            </div>
                            <div>
                                <select value={newGroup}
                                        onChange={e => setNewGroup(e.target.value)}
                                        className="select select-sm select-bordered w-full">
                                    <option value="">-- Keine Gruppe --</option>
                                    {flatGroups.map(g => <option key={g.id}
                                                                 value={g.id}>{'- '.repeat(g.depth)}{g.name}</option>)}
                                </select>
                            </div>
                            <button type="submit" className="btn btn-primary btn-sm w-full">Hinzufügen</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    );
}
