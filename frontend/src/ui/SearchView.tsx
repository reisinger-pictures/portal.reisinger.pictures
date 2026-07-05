import PageLayout from './components/PageLayout';
import ResponsiveImage from './components/ResponsiveImage';
import ErrorMessage from './components/ErrorMessage';
import {Link, useNavigate, useSearchParams} from 'react-router-dom';
import {useSearch} from '../logic/useSearch';
import HighlightText from './components/HighlightText';

export default function SearchView() {
    const [searchParams] = useSearchParams();
    const query = searchParams.get('q') || '';
    const {results, isLoading, isError} = useSearch(query);
    const navigate = useNavigate();

    return (
        <PageLayout currentView="search">
            <div className="container mx-auto max-w-7xl p-4 md:p-8">
                <h1 className="text-2xl md:text-3xl font-bold mb-8 text-center">
                    {query ? <>Suchergebnisse für <span
                        className="text-primary">"{query}"</span></> : 'Neueste Entdeckungen'}
                </h1>

                {isLoading && <div className="flex justify-center p-10"><span
                    className="loading loading-spinner loading-lg text-primary"></span></div>}
                {isError && <ErrorMessage message="Fehler beim Laden der Ergebnisse." />}

                {!isLoading && !isError && results && (
                    <div className="space-y-12">
                        <section>
                            <h2 className="text-xl font-bold mb-4 flex items-center gap-2 border-b border-base-300 pb-2">
                                <span className="iconify mdi--folder-multiple text-primary"></span> Galerien
                                ({results.galleries.length})
                            </h2>
                            {results.galleries.length > 0 ? (
                                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                                    {results.galleries.map(g => (
                                        <div key={g.id}
                                             className="card bg-base-100 shadow-xl cursor-pointer hover:shadow-2xl border border-base-300 transition-shadow transition-transform hover:-translate-y-1"
                                             onClick={() => navigate('/' + g.full_path)}>
                                            <div className="card-body p-4 flex flex-row items-center">
                                                <div className="text-2xl mr-2"></div>
                                                <h3 className="card-title text-base text-primary truncate flex-1"><HighlightText text={g.name} highlight={query} /></h3>
                                                <span
                                                    className="iconify mdi--chevron-right text-xl opacity-50"></span>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            ) : <p className="opacity-50">Keine passenden Galerien gefunden.</p>}
                        </section>

                        <section>
                            <h2 className="text-xl font-bold mb-4 flex items-center gap-2 border-b border-base-300 pb-2">
                                <span className="iconify mdi--image-multiple text-primary"></span> Fotos
                                ({results.photos.length})
                            </h2>
                            {results.photos.length > 0 ? (
                                <div
                                    className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 gap-4">
                                    {results.photos.map(p => (
                                        <Link key={p.id} to={`/photos/${p.id}`}
                                              className="block relative aspect-square bg-base-300 rounded overflow-hidden group shadow-md hover:shadow-xl transition-shadow">
                                            <ResponsiveImage src={p.thumb_url} srcSet={p.srcset} alt={p.title || 'Bild'} containerClassName="absolute inset-0 w-full h-full" className="object-cover w-full h-full group-hover:scale-105 transition-transform" />
                                            
                                        </Link>
                                    ))}
                                </div>
                            ) : <p className="opacity-50">Keine passenden Fotos gefunden.</p>}
                        </section>
                    </div>
                )}
            </div>
        </PageLayout>
    );
}
