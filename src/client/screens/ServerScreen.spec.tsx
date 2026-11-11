import { afterEach, expect, test, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, within, waitFor } from '@testing-library/react';
import { createExtensionTestHost, createTestServer } from '@pterodactyl/sdk/testing';
import ServerScreen from './ServerScreen';
import { searchFtb, getFtbVersions } from '../ftb';
import { searchCurseForge, getCurseForgeVersions } from '../curseforge';
import { getProviders, installModpack } from '../installation';

vi.mock('@pterodactyl/sdk', async (original) => ({ ...await original<typeof import('@pterodactyl/sdk')>(), invalidateServerData: vi.fn().mockResolvedValue(undefined) }));

vi.mock('../ftb', () => ({ searchFtb: vi.fn(), getFtbVersions: vi.fn() }));

vi.mock('../curseforge', () => ({ searchCurseForge: vi.fn(), getCurseForgeVersions: vi.fn() }));

vi.mock('../installation', () => ({
    getProviders: vi.fn().mockResolvedValue({ can_install: true, providers: [{ id: 'modrinth', name: 'Modrinth', egg_available: true, message: 'Ready', images: [{ label: 'Java 21', value: 'java:21' }], installer_image: 'installer:17' }, { id: 'curseforge', name: 'CurseForge', egg_available: true, message: 'Ready', images: [{ label: 'Java 21', value: 'cf-java:21' }], installer_image: null }, { id: 'ftb', name: 'FTB', egg_available: true, message: 'Ready', images: [{ label: 'Java 21', value: 'ftb-java:21' }], installer_image: null }] }),
    installModpack: vi.fn().mockResolvedValue({ message: 'Wings accepted the reinstall.' }),
}));

let host: ReturnType<typeof createExtensionTestHost> | undefined;
afterEach(() => { cleanup(); host?.dispose(); vi.unstubAllGlobals(); vi.clearAllMocks(); });

const pack = {
    project_id: 'example', slug: 'example', title: 'Adventure Pack', author: 'Builder',
    description: 'Explore a world of adventures.', icon_url: null, downloads: 12345,
    categories: ['fabric', 'adventure'], versions: ['1.21.1'],
};

function mount() {
    host = createExtensionTestHost({ extensionId: 'modpack-switcher', server: createTestServer() });
    render(<ServerScreen />, { wrapper: host.Wrapper });
}

function mockSearch(total = 1) {
    const fetch = vi.fn().mockImplementation(async (url: string) => ({ ok: true, json: async () => url.includes('/version?')
        ? [{ id: 'v2', name: 'Adventure 2', version_number: '2.0', version_type: 'release', game_versions: ['1.21.1'], loaders: ['fabric'], date_published: '2026-10-01T00:00:00Z', downloads: 1234 }]
        : { hits: [pack], total_hits: total, offset: 0, limit: 12 } }));
    vi.stubGlobal('fetch', fetch);
    return fetch;
}

test('requires a Java choice and explicit wipe confirmation before submitting installation', async () => {
    const fetch = mockSearch();
    mount();
    fireEvent.click(await screen.findByRole('button', { name: 'Select Adventure Pack' }));
    const selected = screen.getByRole('dialog', { name: 'Adventure Pack' });
    expect(within(selected).getByText('Choose a version and Java runtime.')).toBeTruthy();
    fireEvent.click(await within(selected).findByRole('radio', { name: /Adventure 2/ }));
    expect(within(selected).getByText('1.2K downloads')).toBeTruthy();
    expect(within(selected).getByText(/Oct 1, 2026/)).toBeTruthy();
    expect((within(selected).getByRole('button', { name: 'Continue' }) as HTMLButtonElement).disabled).toBe(true);
    fireEvent.keyDown(within(selected).getByLabelText('Java runtime'), { key: 'ArrowDown' });
    fireEvent.click(await screen.findByRole('option', { name: 'Java 21' }));
    fireEvent.click(within(selected).getByRole('button', { name: 'Continue' }));
    fireEvent.click(within(selected).getByRole('radio', { name: /Wipe the server/ }));
    expect(within(selected).getByText(/All worlds, mods, and configuration/)).toBeTruthy();
    expect((within(selected).getByRole('button', { name: 'Wipe and install' }) as HTMLButtonElement).disabled).toBe(true);
    expect(fetch).toHaveBeenCalledTimes(2);
    expect(fetch.mock.calls.every(([, options]) => options.credentials === 'omit' && !options.method)).toBe(true);
    expect(installModpack).not.toHaveBeenCalled();
    fireEvent.click(within(selected).getByRole('checkbox'));
    fireEvent.click(within(selected).getByRole('button', { name: 'Wipe and install' }));
    expect(await within(selected).findByText('Wings accepted the reinstall.')).toBeTruthy();
    expect(installModpack).toHaveBeenCalledWith(expect.any(String), 'example', 'v2', 'java:21', 'modrinth');
    fireEvent.click(within(selected).getByRole('button', { name: 'Done' }));
    expect(screen.queryByRole('dialog')).toBeNull();
});

test('paginates and resets the page for a submitted search', async () => {
    const fetch = mockSearch(30);
    mount();
    fireEvent.click(await screen.findByRole('button', { name: 'Next' }));
    await screen.findByText('Page 2 of 3');
    expect(new URL(fetch.mock.calls.at(-1)![0]).searchParams.get('offset')).toBe('12');
    fireEvent.keyDown(screen.getByLabelText('Minecraft version'), { key: 'ArrowDown' });
    fireEvent.click(await screen.findByRole('option', { name: '1.21.1' }));
    fireEvent.change(screen.getByLabelText('Search modpacks'), { target: { value: 'Adventure' } });
    fireEvent.click(screen.getByRole('button', { name: 'Search' }));
    await screen.findByText('Page 1 of 3');
    const params = new URL(fetch.mock.calls.at(-1)![0]).searchParams;
    expect(params.get('query')).toBe('Adventure');
    expect(params.get('facets')).toContain('versions:1.21.1');
    expect(params.get('offset')).toBe('0');
});

test('shows rate limit errors and can retry an empty result', async () => {
    const fetch = vi.fn()
        .mockResolvedValueOnce({ ok: false, status: 429 })
        .mockResolvedValueOnce({ ok: true, json: async () => ({ hits: [], total_hits: 0, offset: 0, limit: 12 }) });
    vi.stubGlobal('fetch', fetch);
    mount();
    expect(await screen.findByRole('alert')).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Try again' }));
    expect(await screen.findByText('No modpacks found')).toBeTruthy();
});

test('shows unavailable provider eggs in red and explains admin action', async () => {
    vi.mocked(getProviders).mockResolvedValueOnce({ can_install: true, providers: [{ id: 'modrinth', name: 'Modrinth', egg_available: false, message: 'Ask an administrator to enable the egg.', images: [], installer_image: null }, { id: 'ftb', name: 'FTB', egg_available: true, message: 'Ready', images: [{ label: 'Java 21', value: 'ftb-java:21' }], installer_image: null }] });
    mockSearch();
    mount();
    expect(await screen.findByText('Modrinth: Ask an administrator to enable the egg.')).toBeTruthy();
    fireEvent.click(await screen.findByRole('button', { name: 'Select Adventure Pack' }));
    const modal = screen.getByRole('dialog');
    expect((within(modal).getByRole('button', { name: 'Continue' }) as HTMLButtonElement).disabled).toBe(true);
});

test('browses CurseForge, loads versions, and submits the selected file to the shared install flow', async () => {
    mockSearch();
    const release = { id: '123456', name: 'Curse version 1', version_number: '1', version_type: 'release' as const, game_versions: ['1.21.1'], loaders: ['NeoForge'], downloads: 100, date_published: '2026-10-01T00:00:00Z' };
    vi.mocked(getCurseForgeVersions).mockImplementation(async (_uuid, _project, page) => ({ versions: [{ ...release, ...(page === 1 ? { id: '123455', name: 'Older release' } : {}) }], nextPage: page === 0 ? 1 : null }));
    vi.mocked(searchCurseForge).mockResolvedValue({ hits: [{ ...pack, project_id: '123', title: 'Curse Adventure' }], total_hits: 30, offset: 0, limit: 12 });
    mount();
    await screen.findByRole('button', { name: 'Select Adventure Pack' });
    fireEvent.keyDown(screen.getByLabelText('Provider'), { key: 'ArrowDown' });
    fireEvent.click(await screen.findByRole('option', { name: 'CurseForge' }));
    expect(await screen.findByText('Curse Adventure')).toBeTruthy();
    expect((screen.getByLabelText('Mod loader') as HTMLInputElement).disabled).toBe(false);
    fireEvent.keyDown(screen.getByLabelText('Mod loader'), { key: 'ArrowDown' });
    fireEvent.click(await screen.findByRole('option', { name: 'NeoForge' }));
    fireEvent.click(screen.getByRole('button', { name: 'Search' }));
    await waitFor(() => expect(searchCurseForge).toHaveBeenLastCalledWith(expect.any(String), '', 'downloads', 0, expect.any(AbortSignal), '', 'neoforge'));
    fireEvent.keyDown(screen.getByLabelText('Minecraft version'), { key: 'ArrowDown' });
    fireEvent.click(await screen.findByRole('option', { name: '1.21.1' }));
    fireEvent.change(screen.getByLabelText('Search modpacks'), { target: { value: 'Adventure' } });
    fireEvent.click(screen.getByRole('button', { name: 'Search' }));
    await screen.findByText('30 modpacks matching “Adventure”');
    expect(searchCurseForge).toHaveBeenLastCalledWith(expect.any(String), 'Adventure', 'downloads', 0, expect.any(AbortSignal), '1.21.1', 'neoforge');
    fireEvent.click(await screen.findByRole('button', { name: 'Next' }));
    await screen.findByText('Page 2 of 3');
    expect(searchCurseForge).toHaveBeenLastCalledWith(expect.any(String), 'Adventure', 'downloads', 1, expect.any(AbortSignal), '1.21.1', 'neoforge');
    fireEvent.click(screen.getByRole('button', { name: 'Select Curse Adventure' }));
    const modal = screen.getByRole('dialog');
    fireEvent.click(await within(modal).findByRole('radio', { name: /Curse version 1/ }));
    fireEvent.click(within(modal).getByRole('button', { name: 'Load older versions' }));
    expect(await within(modal).findByRole('radio', { name: /Older release/ })).toBeTruthy();
    fireEvent.keyDown(within(modal).getByLabelText('Java runtime'), { key: 'ArrowDown' });
    fireEvent.click(await screen.findByRole('option', { name: 'Java 21' }));
    fireEvent.click(within(modal).getByRole('button', { name: 'Continue' }));
    fireEvent.click(within(modal).getByRole('radio', { name: /Wipe the server/ }));
    fireEvent.click(within(modal).getByRole('checkbox'));
    fireEvent.click(within(modal).getByRole('button', { name: 'Wipe and install' }));
    await waitFor(() => expect(installModpack).toHaveBeenCalledWith(expect.any(String), '123', '123456', 'cf-java:21', 'curseforge'));
});

test('browses FTB and installs the exact selected version through the shared flow', async () => {
    mockSearch();
    vi.mocked(getFtbVersions).mockResolvedValue({ versions: [{ id: '9001', name: 'FTB release 1.0.0', version_number: '1.0.0', version_type: 'release', game_versions: ['1.21.1'], loaders: ['neoforge'], date_published: '2026-10-01T00:00:00Z', downloads: null }], nextPage: null });
    vi.mocked(searchFtb).mockResolvedValue({ hits: [{ ...pack, project_id: '125', slug: 'ftb-evolution', title: 'FTB Evolution' }], total_hits: 30, offset: 0, limit: 12 });
    mount();
    await screen.findByRole('button', { name: 'Select Adventure Pack' });
    fireEvent.keyDown(screen.getByLabelText('Provider'), { key: 'ArrowDown' });
    fireEvent.click(await screen.findByRole('option', { name: 'FTB' }));
    expect(await screen.findByText('FTB Evolution')).toBeTruthy();
    fireEvent.keyDown(screen.getByLabelText('Minecraft version'), { key: 'ArrowDown' });
    fireEvent.click(await screen.findByRole('option', { name: '1.21.1' }));
    fireEvent.keyDown(screen.getByLabelText('Mod loader'), { key: 'ArrowDown' });
    fireEvent.click(await screen.findByRole('option', { name: 'NeoForge' }));
    fireEvent.change(screen.getByLabelText('Search modpacks'), { target: { value: 'Evolution' } });
    fireEvent.click(screen.getByRole('button', { name: 'Search' }));
    await waitFor(() => expect(searchFtb).toHaveBeenLastCalledWith(expect.any(String), 'Evolution', 'downloads', 0, expect.any(AbortSignal), '1.21.1', 'neoforge'));
    fireEvent.click(await screen.findByRole('button', { name: 'Next' }));
    await screen.findByText('Page 2 of 3');
    expect(searchFtb).toHaveBeenLastCalledWith(expect.any(String), 'Evolution', 'downloads', 1, expect.any(AbortSignal), '1.21.1', 'neoforge');
    fireEvent.click(screen.getByRole('button', { name: 'Select FTB Evolution' }));
    const modal = screen.getByRole('dialog');
    fireEvent.click(await within(modal).findByRole('radio', { name: /FTB release 1.0.0/ }));
    expect(within(modal).queryByText(/downloads/)).toBeNull();
    expect(within(modal).getByRole('link', { name: /View on FTB/ }).getAttribute('href')).toBe('https://www.feed-the-beast.com/modpacks/125-ftb-evolution');
    fireEvent.keyDown(within(modal).getByLabelText('Java runtime'), { key: 'ArrowDown' });
    fireEvent.click(await screen.findByRole('option', { name: 'Java 21' }));
    fireEvent.click(within(modal).getByRole('button', { name: 'Continue' }));
    fireEvent.click(within(modal).getByRole('radio', { name: /Wipe the server/ }));
    fireEvent.click(within(modal).getByRole('checkbox'));
    fireEvent.click(within(modal).getByRole('button', { name: 'Wipe and install' }));
    await waitFor(() => expect(installModpack).toHaveBeenCalledWith(expect.any(String), '125', '9001', 'ftb-java:21', 'ftb'));
});
