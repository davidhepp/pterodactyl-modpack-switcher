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
export async function installModpack(uuid: string, project: string, version: string, image: string, provider: 'modrinth' | 'curseforge' | 'ftb' = 'modrinth', options: { filePolicy: InstallFilePolicy; mode: 'install' | 'switch_version'; expectedVersionId?: string } = { filePolicy: 'wipe', mode: 'install' }) {
    return (await http.post<{ message: string }>(`${base(uuid)}/install`, {
        provider, project_id: project, version_id: version,
        mode: options.mode, expected_version_id: options.expectedVersionId,
        java_image: options.mode === 'install' ? image : undefined, file_policy: options.filePolicy,
        ...(options.filePolicy === 'wipe' ? { confirm_wipe: true } : {}),
    }, { timeout: 120_000 })).data;
}

export interface InstalledPack {
    provider: 'modrinth' | 'curseforge' | 'ftb';
    project_id: string;
    version_id: string;
    version_name: string;
    pack: import('./modrinth').Modpack;
    minecraft: string[];
    loaders: string[];
    java_image: string;
    java_label: string;
    status: string | null;
    skip_scripts: boolean;
}
export type InstallFilePolicy = 'wipe' | 'keep_world' | 'ftb_update';
export async function getCurrentInstallation(uuid: string): Promise<{ installed: InstalledPack | null }> {
    return (await http.get(`${base(uuid)}/current`)).data;
}
