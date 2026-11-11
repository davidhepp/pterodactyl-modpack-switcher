export interface Modpack {
    project_id: string;
    slug: string;
    title: string;
    author: string;
    description: string;
    icon_url: string | null;
    downloads: number;
    categories: string[];
    versions: string[];
}

export interface SearchResults {
    hits: Modpack[];
    total_hits: number;
    offset: number;
    limit: number;
}

export const PAGE_SIZE = 12;
export type Sort = 'relevance' | 'downloads' | 'updated' | 'newest';

export async function searchModpacks(query: string, sort: Sort, page: number, signal: AbortSignal, minecraft = '', loader = ''): Promise<SearchResults> {
    const facets = [['project_type:modpack'], ['server_side!=unsupported']];
    if (minecraft.trim()) facets.push([`versions:${minecraft.trim()}`]);
    if (loader) facets.push([`categories:${loader}`]);
    const params = new URLSearchParams({
        query,
        facets: JSON.stringify(facets),
        index: sort,
        offset: String(page * PAGE_SIZE),
        limit: String(PAGE_SIZE),
    });
    // Use an isolated public request: never send Panel credentials to Modrinth.
    const response = await fetch(`https://api.modrinth.com/v2/search?${params}`, {
        signal,
        credentials: 'omit',
        headers: { Accept: 'application/json' },
    });
    if (!response.ok) {
        throw new Error(response.status === 429
            ? 'Modrinth is receiving too many requests. Please wait a moment and try again.'
            : 'Could not reach Modrinth. Please try again.');
    }
    return response.json();
}

export interface ModpackVersion {
    id: string;
    name: string;
    version_number: string;
    version_type: 'release' | 'beta' | 'alpha';
    game_versions: string[];
    loaders: string[];
    date_published: string;
    downloads: number | null;
}

export async function getModpackVersions(projectId: string, signal: AbortSignal): Promise<ModpackVersion[]> {
    const response = await fetch(`https://api.modrinth.com/v2/project/${encodeURIComponent(projectId)}/version?include_changelog=false`, {
        signal,
        credentials: 'omit',
        headers: { Accept: 'application/json' },
    });
    if (!response.ok) {
        throw new Error(response.status === 429
            ? 'Modrinth is receiving too many requests. Please wait a moment and try again.'
            : 'Could not load this modpack’s versions. Please try again.');
    }
    const versions: ModpackVersion[] = await response.json();
    return versions.sort((a, b) => Date.parse(b.date_published) - Date.parse(a.date_published));
}
