import {useState} from 'react';
import { useSearchParams } from 'react-router-dom';
import {useStats} from '../../logic/useStats';
import {Legend, Pie, PieChart, ResponsiveContainer, Tooltip} from 'recharts';

export default function ManagementStatsView() {
    const [searchParams, setSearchParams] = useSearchParams();
    const page = parseInt(searchParams.get('page') || '1', 10);
    const {stats, logs, isLoading} = useStats(page);

    const rawChartData = stats ? [
        ...stats.domain_stats.map(d => ({name: '@' + d.domain, value: d.count})),
        ...(stats.guest_downloads > 0 ? [{name: 'Anonyme Gäste', value: stats.guest_downloads}] : [])
    ].sort((a, b) => b.value - a.value) : [];

    const COLORS = ['#2A9D8F', '#E9C46A', '#F4A261', '#E76F51', '#264653', '#8AB17D', '#B5838D'];

    // Recharts 3.x Migration: Injiziere 'fill' direkt in die Daten, statt <Cell> zu verwenden
    const chartData = rawChartData.map((entry, index) => ({
        ...entry,
        fill: COLORS[index % COLORS.length]
    }));

    if (isLoading && !stats) return <div className="p-10 flex justify-center"><span
        className="loading loading-spinner loading-lg"></span></div>;

    return (
        <div className="p-4 md:p-10 max-w-6xl mx-auto w-full">
            <h1 className="text-3xl md:text-4xl font-bold mb-6">Statistiken & Audit-Logs</h1>

            {/* Kacheln (Statistiken) */}
            <div className="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                <div className="stat bg-base-200 rounded-box border border-base-300 shadow-sm">
                    <div className="stat-title text-base-content/70">Zugeordnete Galerien</div>
                    <div className="stat-value text-primary">{stats?.galleries_count || 0}</div>
                </div>
                <div className="stat bg-base-200 rounded-box border border-base-300 shadow-sm">
                    <div className="stat-title text-base-content/70">Downloads Gesamt</div>
                    <div className="stat-value text-secondary-content">{stats?.total_downloads || 0}</div>
                </div>
                <div className="stat bg-base-200 rounded-box border border-base-300 shadow-sm">
                    <div className="stat-title text-base-content/70">Anonyme Gäste</div>
                    <div className="stat-value text-accent">{stats?.guest_downloads || 0}</div>
                </div>
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-4 gap-6">

                {/* Linke Seite: Domain Auswertung & Top Listen */}
                <div className="lg:col-span-1 flex flex-col gap-6">

                    {/* Domain Chart */}
                    <div className="card bg-base-200 border border-base-300 shadow-sm">
                        <div className="card-body p-4 md:p-6">
                            <h2 className="card-title text-lg mb-4 flex items-center gap-2">
                                <span className="iconify mdi--domain text-primary"></span> Top Domains
                            </h2>
                            <div className="bg-base-100 rounded-box border border-base-300 p-4"
                                 style={{height: '300px'}}>
                                {chartData.length > 0 ? (
                                    <ResponsiveContainer width="100%" height="100%">
                                        <PieChart>
                                            <Pie
                                                data={chartData}
                                                cx="50%"
                                                cy="50%"
                                                innerRadius={60}
                                                outerRadius={90}
                                                paddingAngle={5}
                                                dataKey="value"
                                            />
                                            <Tooltip
                                                contentStyle={{
                                                    backgroundColor: 'oklch(var(--b1))',
                                                    borderColor: 'oklch(var(--b3))',
                                                    borderRadius: '0.5rem'
                                                }}
                                                itemStyle={{color: 'oklch(var(--bc))'}}
                                            />
                                            <Legend verticalAlign="bottom" height={36}/>
                                        </PieChart>
                                    </ResponsiveContainer>
                                ) : (
                                    <div
                                        className="flex h-full items-center justify-center opacity-50 text-sm text-center">
                                        Noch keine Download-Daten vorhanden.
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>

                    {/* Top 5 Listen */}
                    <div className="card bg-base-200 border border-base-300 shadow-sm">
                        <div className="card-body p-4 md:p-6">
                            <h2 className="card-title text-lg mb-4 flex items-center gap-2">
                                <span className="iconify mdi--trophy text-warning"></span> Top 5 Galerien
                            </h2>
                            <ul className="menu bg-base-100 rounded-box border border-base-300 p-2">
                                {stats?.top_galleries?.map((g, i) => (
                                    <li key={i}><a className="flex justify-between">
                                        <span className="truncate" title={g.name}>{g.name}</span>
                                        <span className="badge badge-primary">{g.count} DLs</span>
                                    </a></li>
                                ))}
                                {(!stats?.top_galleries || stats.top_galleries.length === 0) &&
                                    <li className="disabled"><a>Keine Daten</a></li>}
                            </ul>

                            <h2 className="card-title text-lg mb-4 mt-6 flex items-center gap-2">
                                <span className="iconify mdi--star text-secondary"></span> Top 5 Bilder
                            </h2>
                            <ul className="menu bg-base-100 rounded-box border border-base-300 p-2">
                                {stats?.top_photos?.map((p, i) => (
                                    <li key={i}><a className="flex justify-between">
                                        <span className="truncate max-w-[150px]" title={p.name}>{p.name}</span>
                                        <span className="badge badge-secondary">{p.count} DLs</span>
                                    </a></li>
                                ))}
                                {(!stats?.top_photos || stats.top_photos.length === 0) &&
                                    <li className="disabled"><a>Keine Daten</a></li>}
                            </ul>
                        </div>
                    </div>

                </div>

                {/* Rechte Seite: Log Tabelle */}
                <div className="lg:col-span-3">
                    <div className="card bg-base-200 border border-base-300 shadow-sm">
                        <div className="card-body p-0 overflow-hidden">
                            <div className="p-4 border-b border-base-300 flex items-center gap-2">
                                <span className="iconify mdi--format-list-bulleted text-xl text-primary"></span>
                                <h2 className="font-bold text-lg">Letzte Aktivitäten</h2>
                            </div>
                            <div className="overflow-x-auto">
                                <table className="table table-zebra w-full">
                                    <thead>
                                    <tr>
                                        <th>Datum / Zeit</th>
                                        <th>Benutzer / Gast</th>
                                        <th>Galerie</th>
                                        <th>Datei</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    {logs?.data.map(log => (
                                        <tr key={log.id}>
                                            <td className="whitespace-nowrap text-xs opacity-70">
                                                {new Date(log.created_at).toLocaleString('de-DE')}
                                            </td>
                                            <td className="font-bold">{log.user_name_snapshot || 'Anonymer Gast'}</td>
                                            <td className="max-w-[150px] truncate"
                                                title={log.gallery_name_snapshot || '-'}>{log.gallery_name_snapshot || '-'}</td>
                                            <td>
                                                <div className="flex items-center gap-2">
                                                    {log.item_type === 'full_zip'
                                                        ? <span className="badge badge-secondary badge-xs">ZIP</span>
                                                        : <span className="badge badge-accent badge-xs">JPG</span>}
                                                    <span className="truncate max-w-[150px] md:max-w-[200px] text-xs"
                                                          title={log.item_identifier}>
                                                            {log.item_identifier}
                                                        </span>
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                    {(!logs?.data || logs.data.length === 0) && (
                                        <tr>
                                            <td colSpan={4} className="text-center opacity-50 py-8">Noch keine Downloads
                                                aufgezeichnet.
                                            </td>
                                        </tr>
                                    )}
                                    </tbody>
                                </table>
                            </div>

                            {logs && logs.last_page > 1 && (
                                <div
                                    className="flex justify-between items-center p-4 border-t border-base-300 bg-base-100 flex-wrap gap-2">
                                    <button className="btn btn-sm btn-outline" disabled={page === 1}
                                            onClick={() => setSearchParams(prev => { prev.set('page', String(page - 1)); return prev; })}>&larr; Zurück
                                    </button>
                                    <span className="text-sm font-semibold">Seite {page} von {logs.last_page}</span>
                                    <button className="btn btn-sm btn-outline" disabled={page === logs.last_page}
                                            onClick={() => setSearchParams(prev => { prev.set('page', String(page + 1)); return prev; })}>Weiter &rarr;</button>
                                </div>
                            )}
                        </div>
                    </div>
                </div>

            </div>
        </div>
    );
}
