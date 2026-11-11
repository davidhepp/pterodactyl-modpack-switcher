import { http, httpErrorToHuman } from '@pterodactyl/sdk';
import type { SearchResults, Sort, ModpackVersion } from './modrinth';

export async function searchCurseForge(uuid: string, query: string, sort: Sort, page: number, signal: AbortSignal, minecraft: string, loader: string): Promise<SearchResults> {
    try {
        const { data } = await http.get<SearchResults>(`/api/client/servers/${uuid}/extensions/modpack-switcher/curseforge/search`, {
            signal,
            params: { query, sort, page, minecraft: minecraft || undefined, loader: loader || undefined },
        });
        return data;
    } catch (error) {
        throw new Error(httpErrorToHuman(error));
    }
}

export async function getCurseForgeVersions(uuid: string, project: string, page: number, signal: AbortSignal): Promise<{ versions: ModpackVersion[]; nextPage: number | null }> {
    try {
        return (await http.get(`/api/client/servers/${uuid}/extensions/modpack-switcher/curseforge/projects/${encodeURIComponent(project)}/versions`, { signal, params: { page } })).data;
    } catch (error) {
        throw new Error(httpErrorToHuman(error));
    }
}
