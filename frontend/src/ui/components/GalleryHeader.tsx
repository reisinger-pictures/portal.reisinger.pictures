import { useNavigate, useSearchParams } from 'react-router-dom';
import { useAuth } from '../../logic/useAuth';

export default function GalleryHeader({ gallery, breadcrumbs }: { gallery: {id: string, name: string}, breadcrumbs: Array<{name: string, full_path?: string}> }) {
    const navigate = useNavigate();
    const { user } = useAuth();
    const [searchParams, setSearchParams] = useSearchParams();
    
    const canManage = user?.is_admin || (user?.is_photographer && user?.my_galleries?.some(g => g.id === gallery.id));
    const currentView = searchParams.get('view') === 'client' ? 'client' : 'management';

    return (
        <div className="mb-6 w-full">
            <div className="text-sm breadcrumbs mb-4 w-full">
                <ul>
                    <li><a onClick={() => navigate('/')}>Dashboard</a></li>
                    {canManage && <li><a onClick={() => navigate('/galleries')}>Galerien</a></li>}
                    {breadcrumbs?.map((bc, idx) => (
                        <li key={idx}>
                            {canManage ? <a onClick={() => navigate('/' + bc.full_path)} className="opacity-80 hover:opacity-100">{bc.name}</a> : <span>{bc.name}</span>}
                        </li>
                    ))}
                    {/* Das <span> kapselt die Limitierung, damit das <li> sein Slash-Trennzeichen von DaisyUI behält */}
                    <li><span className="opacity-50 truncate max-w-[200px]">{gallery.name}</span></li>
                </ul>
            </div>

            {canManage && (
                <div role="tablist" className="tabs tabs-lifted w-full border-b border-base-300">
                    <button 
                        role="tab" 
                        onClick={() => setSearchParams({view: 'management'})} 
                        className={`tab tab-lg whitespace-nowrap ${currentView === 'management' ? 'tab-active font-bold [--tab-bg:oklch(var(--b2))] [--tab-border-color:oklch(var(--b3))]' : 'text-base-content/70'}`}
                    >
                        <span className="iconify mdi--cog mr-2 text-lg"></span> Verwaltung
                    </button>
                    <button 
                        role="tab" 
                        onClick={() => setSearchParams({view: 'client'})} 
                        className={`tab tab-lg whitespace-nowrap ${currentView === 'client' ? 'tab-active font-bold [--tab-bg:oklch(var(--b2))] [--tab-border-color:oklch(var(--b3))]' : 'text-base-content/70'}`}
                    >
                        <span className="iconify mdi--eye mr-2 text-lg"></span> Kundenansicht
                    </button>
                    {/* Optischer Füller, damit die gezogene Linie rechts bündig abschließt */}
                    <div role="tab" className="tab flex-1 cursor-default pointer-events-none hidden sm:block border-b-base-300"></div>
                </div>
            )}
        </div>
    );
}
