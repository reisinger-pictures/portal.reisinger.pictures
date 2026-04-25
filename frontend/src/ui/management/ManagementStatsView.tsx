import { useSearchParams } from 'react-router-dom';
import {useStats} from '../../logic/useStats';
import {Legend, Pie, PieChart, ResponsiveContainer, Tooltip} from 'recharts';

export default function ManagementStatsView() {
    const [searchParams, setSearchParams] = useSearchParams();
    const page = parseInt(searchParams.get('page') || '1', 10);
    const qualityFilter = searchParams.get('tier');
    
    const {stats, logs, isLoading} = useStats(page, qualityFilter);

    const filteredLogs = logs?.data;

    const rawChartData = stats ? [
        ...stats.domain_stats.map(d => ({name: '@' + d.domain, value: d.count})),
        ...(stats.guest_downloads > 0 ? [{name: 'Anonyme Gäste', value: stats.guest_downloads}] : [])
    ].sort((a, b) => b.value - a.value) : [];

    const COLORS = ['#2A9D8F', '#E9C46A', '#F4A261', '#E76F51', '#264653', '#8AB17D', '#B5838D'];

    const chartData = rawChartData.map((entry, index) => ({
        ...entry,
        fill: COLORS[index % COLORS.length]
    }));

    if (isLoading && !stats) return <div className="p-10 flex justify-center"><span
        className="loading loading-spinner loading-lg"></span></div>;

    return (
        <div className="p-2 md:p-8 max-w-7xl mx-auto w-full">
            <div className="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 gap-4">
                <h1 className="text-3xl md:text-4xl font-bold">Statistiken & Audit-Logs</h1>
                <div role="tablist" className="tabs tabs-boxed w-full md:w-auto bg-base-200 border border-base-300 p-1 flex-wrap shadow-sm">
                    <a role="tab" className={`tab ${!qualityFilter ? 'tab-active font-bold' : ''}`} onClick={() => setSearchParams(prev => { prev.delete('tier'); prev.set('page', '1'); return prev; })}>Alle Auflösungen</a>
                    <a role="tab" className={`tab ${qualityFilter === 'web' ? 'tab-active font-bold' : ''}`} onClick={() => setSearchParams(prev => { prev.set('tier', 'web'); prev.set('page', '1'); return prev; })}>WEB</a>
                    <a role="tab" className={`tab ${qualityFilter === 'print' ? 'tab-active font-bold' : ''}`} onClick={() => setSearchParams(prev => { prev.set('tier', 'print'); prev.set('page', '1'); return prev; })}>PRINT</a>
                    <a role="tab" className={`tab ${qualityFilter === 'original' ? 'tab-active font-bold' : ''}`} onClick={() => setSearchParams(prev => { prev.set('tier', 'original'); prev.set('page', '1'); return prev; })}>ORIGINAL</a>
                </div>
            </div>

            <div className="grid grid-cols-2 lg:grid-cols-3 gap-4 *:odd:max-lg:last:col-span-2 mb-8">
                <div className="stat bg-base-100 rounded-box border border-base-300 shadow-sm">
                    <div className="stat-title text-base-content/70">Zugeordnete Galerien</div>
                    <div className="stat-value text-primary">{stats?.galleries_count || 0}</div>
                </div>
                <div className="stat bg-base-100 rounded-box border border-base-300 shadow-sm">
                    <div className="stat-title text-base-content/70">Anonyme Gäste</div>
                    <div className="stat-value text-accent">{stats?.guest_downloads || 0}</div>
                </div>
                <div className="stat bg-base-100 rounded-box border border-base-300 shadow-sm">
                    <div className="stat-title text-base-content/70">Downloads Gesamt</div>
                    <div className="stat-value text-primary">{stats?.total_downloads || 0}</div>
                </div>
            </div>

            <div className="flex flex-col gap-8">
                {/* Volle Breite: Letzte Aktivitäten GANZ OBEN */}
                <div className="card bg-base-100 border border-base-300 shadow-sm h-full">
                    <div className="card-body p-0 overflow-hidden">
                        <div className="p-4 border-b border-base-300 flex items-center gap-2 bg-base-200/50">
                            <span className="iconify mdi--format-list-bulleted text-xl text-primary"></span>
                            <h2 className="font-bold text-lg">Letzte Aktivitäten</h2>
                        </div>
                        <div className="overflow-x-auto scrollbar-thin scrollbar-thumb-base-300">
                            <table className="table table-zebra table-sm md:table-md w-full">
                                <thead>
                                <tr>
                                    <th>Datum / Zeit</th>
                                    <th>Benutzer / Gast</th>
                                    <th>Galerie</th>
                                    <th>Typ</th>
                                    <th>Qualität</th>
                                </tr>
                                </thead>
                                
                                <tbody>
                                {filteredLogs?.map(log => (
                                    <tr key={log.id}>
                                        <td className="whitespace-nowrap text-sm opacity-70">
                                            {new Date(log.created_at).toLocaleString('de-DE')}
                                        </td>
                                        <td className="font-bold whitespace-nowrap">{log.user_name_snapshot || 'Anonymer Gast'}</td>
                                        <td className="max-w-xs truncate"
                                            title={log.gallery_name_snapshot || '-'}>{log.gallery_name_snapshot || '-'}</td>
                                        <td>
                                            <div className="flex items-center gap-3">
                                                {log.item_type !== 'full_zip' && (
                                                    <div className="relative shrink-0">
                                                        {log.thumb_url ? (
                                                            <img src={log.thumb_url} alt="" className="w-10 h-10 rounded object-cover shadow-sm" />
                                                        ) : (
                                                            <div className="w-10 h-10 bg-base-300 rounded flex items-center justify-center">
                                                                <span className="iconify mdi--image-off opacity-30"></span>
                                                            </div>
                                                        )}
                                                    </div>
                                                )}
                                                <div className="flex flex-col gap-1">
                                                    {log.item_type === 'full_zip' ? (
                                                        <div className="flex items-center gap-2">
                                                            <span className="badge badge-secondary badge-sm font-bold uppercase">ZIP</span>
                                                            <span className="text-sm font-medium whitespace-nowrap opacity-80">({log.payload?.photo_count || '?'} Bilder)</span>
                                                        </div>
                                                    ) : (
                                                        <span className="badge badge-ghost badge-sm font-bold uppercase">BILD</span>
                                                    )}
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div className="flex items-center gap-2">
                                                <span className={`badge badge-sm font-bold uppercase ${ log.resolution_tier === 'original' ? 'badge-warning' : log.resolution_tier === 'print' ? 'badge-info' : 'badge-ghost' }`}>
                                                    {log.resolution_tier || 'web'}
                                                </span>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                                {(!filteredLogs || logs?.data.length === 0) && (
                                    <tr>
                                        <td colSpan={5} className="text-center opacity-50 py-8">
                                            Noch keine Downloads aufgezeichnet.
                                        </td>
                                    </tr>
                                )}
                                </tbody>
                            </table>
                        </div>

                        {logs && logs.last_page > 1 && (
                            <div
                                className="flex justify-between items-center p-4 border-t border-base-300 bg-base-200/50 flex-wrap gap-2">
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

                {/* Halbe Breite: Top Domains und Top Galerien NEBENEINANDER */}
                <div className="grid grid-cols-1 xl:grid-cols-2 gap-8">
                    <div className="card bg-base-100 border border-base-300 shadow-sm h-full">
                        <div className="card-body p-4 md:p-6">
                            <h2 className="card-title text-lg mb-4 flex items-center gap-2">
                                <span className="iconify mdi--domain text-primary"></span> Top Domains
                            </h2>
                            <div className="bg-base-200 rounded-box border border-base-300 p-2 md:p-4 w-full h-[300px] flex flex-col justify-center overflow-hidden">
                                {chartData.length > 0 ? (
                                    <ResponsiveContainer width="100%" height="100%" minHeight={100} minWidth={100}>
                                        <PieChart>
                                            <Pie
                                                data={chartData}
                                                cx="50%"
                                                cy="50%"
                                                innerRadius={50}
                                                outerRadius={80}
                                                paddingAngle={5}
                                                dataKey="value"
                                            />
                                            <Tooltip
                                                contentStyle={{
                                                    backgroundColor: 'var(--color-base-100)',
                                                    borderColor: 'var(--color-base-300)',
                                                    borderRadius: '0.5rem',
                                                    color: 'var(--color-base-content)',
                                                    boxShadow: '0 4px 6px -1px rgb(0 0 0 / 0.1)'
                                                }}
                                                itemStyle={{color: 'var(--color-base-content)', fontWeight: 'bold'}}
                                            />
                                            <Legend verticalAlign="bottom" height={36} wrapperStyle={{ fontSize:"12px" }} />
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

                    <div className="card bg-base-100 border border-base-300 shadow-sm h-full">
                        <div className="card-body p-4 md:p-6">
                            <h2 className="card-title text-lg mb-4 flex items-center gap-2">
                                <span className="iconify mdi--trophy text-warning"></span> Top 5 Galerien
                            </h2>
                            <ul className="flex flex-col gap-2">
                                {stats?.top_galleries?.map((g, i) => (
                                    <li key={i} className="bg-base-200 rounded-lg border border-base-300 p-3 hover:bg-base-300 transition-colors">
                                        <a className="flex justify-between items-center gap-4">
                                            <span className="truncate min-w-0 font-medium" title={g.name}>{g.name}</span>
                                            <span className="badge badge-primary shrink-0 whitespace-nowrap">{g.count} Downloads</span>
                                        </a>
                                    </li>
                                ))}
                                {(!stats?.top_galleries || stats.top_galleries.length === 0) &&
                                    <div className="flex w-full items-center justify-center py-6 opacity-50 text-sm text-center">Keine Daten vorhanden.</div>}
                            </ul>


                        </div>
                    </div>
                </div>

            </div>
        </div>
    );
}
