import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Alert, Button, Input, Label, Select, ServerContentBlock, Spinner, TitledGreyBox, useCurrentServerRequired } from '@pterodactyl/sdk';
import { PAGE_SIZE, searchModpacks, type Modpack, type Sort } from '../modrinth';

import { minecraftVersionOptions } from '../minecraftVersions';
import { getProviders } from '../installation';
import { searchFtb } from '../ftb';
import { searchCurseForge } from '../curseforge';
import PackSelectionModal from './PackSelectionModal';

const number = new Intl.NumberFormat('en', { notation: 'compact', maximumFractionDigits: 1 });

function PackIcon({ pack }: { pack: Modpack }) {
    const [failed, setFailed] = useState(false);
    return (
        <div className="ms:flex ms:h-16 ms:w-16 ms:shrink-0 ms:items-center ms:justify-center ms:overflow-hidden ms:rounded-xl ms:border ms:border-border ms:bg-muted ms:text-2xl ms:font-semibold" aria-hidden="true">
            {pack.icon_url && !failed
                ? <img src={pack.icon_url} alt="" loading="lazy" referrerPolicy="no-referrer" onError={() => setFailed(true)} className="ms:h-full ms:w-full ms:object-cover" />
                : pack.title.slice(0, 1)}
        </div>
    );
}

export default function ServerScreen() {
    const server = useCurrentServerRequired();
    const providers = useQuery({ queryKey: ['modpack-switcher', 'providers', server.attributes.uuid], queryFn: () => getProviders(server.attributes.uuid), retry: false });
    const [providerId, setProviderId] = useState('modrinth');
    const activeProvider = providers.data?.providers.find((provider) => provider.id === providerId);
    const providerName = providerId === 'ftb' ? 'FTB' : providerId === 'curseforge' ? 'CurseForge' : 'Modrinth';
    const [input, setInput] = useState('');
    const [query, setQuery] = useState('');
    const [minecraftInput, setMinecraftInput] = useState('');
    const [loaderInput, setLoaderInput] = useState('');
    const [filters, setFilters] = useState({ minecraft: '', loader: '' });
    const [sort, setSort] = useState<Sort>('downloads');
    const [page, setPage] = useState(0);
    const [selected, setSelected] = useState<Modpack | null>(null);
    const results = useQuery({
        queryKey: ['modpack-switcher', providerId, server.attributes.uuid, query, sort, page, filters],
        queryFn: ({ signal }) => providerId === 'ftb'
            ? searchFtb(server.attributes.uuid, query, sort, page, signal, filters.minecraft, filters.loader)
            : providerId === 'curseforge'
            ? searchCurseForge(server.attributes.uuid, query, sort, page, signal, filters.minecraft, filters.loader)
            : searchModpacks(query, sort, page, signal, filters.minecraft, filters.loader),
        staleTime: 60_000,
        retry: false,
    });

    return (
        <ServerContentBlock title="Modpack Switcher">
            <div className="ms:space-y-6">
                <div>
                    <p className="ms:mb-2 ms:text-xs ms:font-semibold ms:uppercase ms:tracking-widest ms:text-muted-foreground">Modpack library</p>
                    <h1 className="ms:text-2xl ms:font-semibold ms:text-foreground">Find your next modpack</h1>
                    <p className="ms:mt-2 ms:text-sm ms:text-muted-foreground">Find and explore Minecraft modpacks from Modrinth, CurseForge, and FTB.</p>
                </div>
                <TitledGreyBox title="Browse modpacks">
                    <form className="ms:space-y-4" onSubmit={(event) => { event.preventDefault(); setQuery(input.trim()); setFilters({ minecraft: minecraftInput.trim(), loader: loaderInput }); setPage(0); }}>
                        <div className="ms:flex ms:flex-col ms:gap-4 ms:sm:flex-row ms:sm:items-end">
                            <div className="ms:w-full ms:sm:w-48">
                                <Label htmlFor="ms-provider">Provider</Label>
                                <Select id="ms-provider" value={providerId} onChange={(value) => { setProviderId(String(value)); setPage(0); setSelected(null); }} options={providers.data?.providers.map((provider) => ({
                                    value: provider.id,
                                    label: <span className={provider.egg_available ? '' : 'ms:text-destructive'}>{provider.name}{!provider.egg_available ? ' — Egg unavailable' : ''}</span>,
                                    textLabel: provider.name,
                                })) ?? [{ value: 'modrinth', label: 'Modrinth' }, { value: 'curseforge', label: 'CurseForge' }, { value: 'ftb', label: 'FTB' }]} />
                            </div>
                            <div className="ms:min-w-0 ms:flex-1">
                                <Label htmlFor="ms-search">Search modpacks</Label>
                                <Input id="ms-search" placeholder="Search adventure, technology, exploration…" value={input} maxLength={200} onChange={(event) => setInput(event.target.value)} />
                            </div>
                            <Button type="submit">Search</Button>
                        </div>
                        <div className="ms:grid ms:gap-4 ms:sm:grid-cols-3">
                            <div>
                                <Label htmlFor="ms-minecraft">Minecraft version</Label>
                                <Select id="ms-minecraft" value={minecraftInput} options={minecraftVersionOptions} onChange={(value) => { setMinecraftInput(String(value)); }} />
                            </div>
                            <div>
                                <Label htmlFor="ms-loader">Mod loader</Label>
                                <Select id="ms-loader" value={loaderInput} onChange={(value) => setLoaderInput(String(value))} options={[
                                    { value: '', label: 'Any loader' },
                                    { value: 'fabric', label: 'Fabric' },
                                    { value: 'forge', label: 'Forge' },
                                    { value: 'neoforge', label: 'NeoForge' },
                                    { value: 'quilt', label: 'Quilt' },
                                ]} />
                            </div>
                            <div>
                                <Label htmlFor="ms-sort">Sort by</Label>
                                <Select id="ms-sort" value={sort} onChange={(value) => { setSort(value as Sort); setPage(0); }} options={[
                                    { value: 'downloads', label: providerId === 'ftb' ? 'Most installed' : 'Most downloaded' },
                                    { value: 'relevance', label: providerId === 'curseforge' ? 'Popularity' : 'Relevance' },
                                    { value: 'updated', label: 'Recently updated' },
                                    { value: 'newest', label: 'Newest' },
                                ]} />
                            </div>
                        </div>
                        <div className="ms:flex ms:flex-wrap ms:items-center ms:justify-between ms:gap-3 ms:text-xs ms:text-muted-foreground">
                            <span>Filters apply when you search.</span>
                            <Button.Text onClick={() => { setInput(''); setQuery(''); setMinecraftInput(''); setLoaderInput(''); setFilters({ minecraft: '', loader: '' }); setSort('downloads'); setPage(0); }}>Reset filters</Button.Text>
                        </div>
                    </form>
                </TitledGreyBox>

                {providers.isError && <Alert type="danger">Could not check provider eggs. Installation is unavailable.<Button.Text onClick={() => void providers.refetch()}>Retry provider check</Button.Text></Alert>}
                {providers.data?.providers.filter((provider) => !provider.egg_available).map((provider) => <Alert key={provider.id} type="danger">{provider.name}: {provider.message}</Alert>)}
                {providers.data && !providers.data.can_install && <Alert type="info">Only the server owner or an administrator may switch modpacks.</Alert>}
                {selected && <PackSelectionModal key={`${providerId}-${selected.project_id}`} pack={selected} providerId={providerId as 'modrinth' | 'curseforge' | 'ftb'} serverUuid={server.attributes.uuid} provider={activeProvider} canInstall={providers.data?.can_install ?? false} onClose={() => setSelected(null)} />}

                {results.isPending ? <div role="status" className="ms:flex ms:items-center ms:justify-center ms:gap-3 ms:py-12"><Spinner size="small" /> {providerId === 'ftb' ? 'Loading FTB catalog…' : 'Loading modpacks…'}</div>
                    : results.isError ? <Alert type="danger"><div>{results.error.message}</div><Button.Text onClick={() => void results.refetch()}>Try again</Button.Text></Alert>
                    : results.data && <>
                        <div className="ms:flex ms:items-center ms:justify-between ms:text-sm ms:text-muted-foreground" aria-live="polite">
                            <span>{results.data.total_hits.toLocaleString()} modpacks{query ? ` matching “${query}”` : ''}</span>
                            <span>Powered by {providerName}</span>
                        </div>
                        {results.data.hits.length === 0 ? <TitledGreyBox title="No modpacks found"><p className="ms:text-sm ms:text-muted-foreground">Try another search term to find a pack.</p></TitledGreyBox>
                            : <div className="ms:grid ms:gap-4 ms:md:grid-cols-2 ms:xl:grid-cols-3">
                                {results.data.hits.map((pack) => {
                                    const active = selected?.project_id === pack.project_id;
                                    return <article key={pack.project_id} className={`ms:flex ms:flex-col ms:gap-4 ms:rounded-xl ms:border ms:bg-card ms:p-5 ${active ? 'ms:border-primary' : 'ms:border-border'}`}>
                                        <div className="ms:flex ms:items-start ms:gap-3">
                                            <PackIcon pack={pack} />
                                            <div className="ms:min-w-0"><h2 className="ms:font-semibold ms:break-words">{pack.title}</h2><p className="ms:mt-1 ms:text-xs ms:text-muted-foreground">by {pack.author}</p></div>
                                        </div>
                                        <p className="ms:line-clamp-3 ms:text-sm ms:text-muted-foreground">{pack.description}</p>
                                        <div className="ms:flex ms:flex-wrap ms:gap-2">{pack.categories.slice(0, 4).map((category) => <span key={category} className="ms:rounded-md ms:bg-muted ms:px-2 ms:py-1 ms:text-xs ms:text-muted-foreground">{category}</span>)}</div>
                                        <div className="ms:mt-auto ms:flex ms:items-center ms:justify-between ms:gap-3 ms:pt-2">
                                            <span className="ms:text-xs ms:text-muted-foreground" title={`${pack.downloads.toLocaleString()} ${providerId === 'ftb' ? 'installs' : 'downloads'}`}>{number.format(pack.downloads)} {providerId === 'ftb' ? 'installs' : 'downloads'}</span>
                                            <Button size="small" isSecondary={!active} aria-label={`Select ${pack.title}`} aria-pressed={active} onClick={() => setSelected(pack)}>{active ? 'Selected' : 'Select pack'}</Button>
                                        </div>
                                    </article>;
                                })}
                            </div>}
                        <nav aria-label="Modpack pages" className="ms:flex ms:items-center ms:justify-center ms:gap-4">
                            <Button isSecondary disabled={page === 0} onClick={() => setPage(page - 1)}>Previous</Button>
                            <span className="ms:text-sm ms:text-muted-foreground">Page {page + 1} of {Math.max(1, Math.ceil(results.data.total_hits / PAGE_SIZE))}</span>
                            <Button isSecondary disabled={(page + 1) * PAGE_SIZE >= results.data.total_hits} onClick={() => setPage(page + 1)}>Next</Button>
                        </nav>
                    </>}
            </div>
        </ServerContentBlock>
    );
}
