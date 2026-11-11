import { http } from '@pterodactyl/sdk';

export interface ProviderInfo {
    id: string;
    name: string;
    egg_available: boolean;
    message: string;
    images: { label: string; value: string }[];
    installer_image: string | null;
}
export interface ProviderCatalog {
    providers: ProviderInfo[];
    can_install: boolean;
}
const base = (uuid: string) => `/api/client/servers/${uuid}/extensions/modpack-switcher`;
export async function getProviders(uuid: string): Promise<ProviderCatalog> {
    return (await http.get<ProviderCatalog>(`${base(uuid)}/providers`)).data;
}
export async function installModpack(uuid: string, project: string, version: string, image: string, provider: 'modrinth' | 'curseforge' | 'ftb' = 'modrinth') {
    return (await http.post<{ message: string }>(`${base(uuid)}/install`, {
        provider, project_id: project, version_id: version,
        java_image: image, file_policy: 'wipe', confirm_wipe: true,
    }, { timeout: 120_000 })).data;
}
