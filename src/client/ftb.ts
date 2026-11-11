import { http, httpErrorToHuman } from '@pterodactyl/sdk';
import type { SearchResults, Sort, ModpackVersion } from './modrinth';

export async function searchFtb(uuid: string, query: string, sort: Sort, page: number, signal: AbortSignal, minecraft: string, loader: string): Promise<SearchResults> {
    try {
        return (await http.get<SearchResults>(`/api/client/servers/${uuid}/extensions/modpack-switcher/ftb/search`, {
            signal,
            timeout: 60_000,
            params: { query, sort, page, minecraft: minecraft || undefined, loader: loader || undefined },
        })).data;
    } catch (error) {
        throw new Error(httpErrorToHuman(error));
    }
}

export async function getFtbVersions(uuid: string, project: string, signal: AbortSignal): Promise<{ versions: ModpackVersion[]; nextPage: number | null }> {
    try {
        return (await http.get(`/api/client/servers/${uuid}/extensions/modpack-switcher/ftb/projects/${encodeURIComponent(project)}/versions`, { signal })).data;
    } catch (error) {
        throw new Error(httpErrorToHuman(error));
    }
}
