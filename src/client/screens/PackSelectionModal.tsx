import { useState } from 'react';
import { useMutation, useInfiniteQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Button, Dialog, Label, Select, Spinner, httpErrorToHuman, invalidateServerData } from '@pterodactyl/sdk';
import { installModpack, type ProviderInfo, type InstallFilePolicy } from '../installation';
import { getFtbVersions } from '../ftb';
import { getCurseForgeVersions } from '../curseforge';
import { getModpackVersions, type Modpack } from '../modrinth';

export default function PackSelectionModal({ pack, serverUuid, provider, canInstall, providerId = 'modrinth', mode = 'install', currentVersionId, currentJavaImage, onClose }: { mode?: 'install' | 'switch_version'; currentVersionId?: string; currentJavaImage?: string; providerId?: 'modrinth' | 'curseforge' | 'ftb'; pack: Modpack; serverUuid: string; provider?: ProviderInfo; canInstall: boolean; onClose: () => void }) {
    const switching = mode === 'switch_version';
    const queryClient = useQueryClient();
    const [javaImage, setJavaImage] = useState<string | null>(currentJavaImage ?? null);
    const [confirmed, setConfirmed] = useState(false);
    const [versionId, setVersionId] = useState<string | null>(null);
    const [step, setStep] = useState<'version' | 'files'>('version');
    const [filePolicy, setFilePolicy] = useState<InstallFilePolicy | null>(switching ? (providerId === 'ftb' ? 'ftb_update' : 'keep_world') : null);
    const providerName = providerId === 'ftb' ? 'FTB' : providerId === 'curseforge' ? 'CurseForge' : 'Modrinth';
    const versions = useInfiniteQuery({
        queryKey: ['modpack-switcher', providerId, 'versions', serverUuid, pack.project_id],
        initialPageParam: 0,
        queryFn: async ({ signal, pageParam }) => providerId === 'ftb'
            ? getFtbVersions(serverUuid, pack.project_id, signal)
            : providerId === 'curseforge'
            ? getCurseForgeVersions(serverUuid, pack.project_id, pageParam, signal)
            : { versions: await getModpackVersions(pack.project_id, signal), nextPage: null as number | null },
        getNextPageParam: (page) => page.nextPage ?? undefined,
        staleTime: 60_000,
        retry: false,
    });
    const installation = useMutation({
        mutationFn: () => installModpack(serverUuid, pack.project_id, versionId!, javaImage!, providerId, { filePolicy: filePolicy!, mode, expectedVersionId: currentVersionId }),
        retry: false,
        onSuccess: () => { void invalidateServerData(serverUuid, ['server', 'startup', 'files', 'fileContent']); void queryClient.invalidateQueries({ queryKey: ['modpack-switcher', 'current', serverUuid] }); },
    });
    const close = () => { if (!installation.isPending) onClose(); };
    const releases = versions.data?.pages.flatMap((page) => page.versions) ?? [];
    const version = releases.find((item) => item.id === versionId);

    return (
        <Dialog open preventExternalClose={installation.isPending} onClose={close} title={pack.title} description={installation.isSuccess ? undefined : step === 'version' ? (switching ? 'Choose a new modpack version.' : 'Choose a version and Java runtime.') : 'Review your installation.'}>
            <div className="ms:space-y-4">
                {installation.isSuccess ? <Alert type="success">{installation.data.message}</Alert> : <>
                {!provider?.egg_available && <Alert type="danger">Ask an administrator to configure the {providerName} egg ID.</Alert>}
                {installation.isError && <Alert type="danger">{httpErrorToHuman(installation.error)} Check the console and files before retrying.</Alert>}
                {step === 'version' ? <>
                    <p className="ms:text-sm ms:text-muted-foreground">{pack.description}</p>
                    {versions.isPending ? <div role="status" className="ms:flex ms:items-center ms:gap-3"><Spinner size="small" /> Loading versions…</div>
                        : versions.isError && !releases.length ? <Alert type="danger">{versions.error.message}<Button.Text onClick={() => void versions.refetch()}>Try again</Button.Text></Alert>
                        : !releases.length ? <Alert type="info">No versions are available for this modpack.</Alert>
                        : <fieldset>
                            <legend className="ms:mb-2 ms:text-sm ms:font-semibold">Modpack version</legend>
                            <div className="ms:max-h-72 ms:overflow-y-auto ms:rounded-lg ms:border ms:border-border">
                                {releases.map((item) => (
                                    <label key={item.id} className={`ms:flex ms:cursor-pointer ms:items-start ms:gap-3 ms:border-b ms:border-border ms:p-3 ms:last:border-b-0 ms:hover:bg-muted ${versionId === item.id ? 'ms:bg-muted' : ''}`}>
                                        <input type="radio" name="ms-pack-version" value={item.id} disabled={switching && item.id === currentVersionId} checked={versionId === item.id} onChange={() => setVersionId(item.id)} className="ms:mt-1" />
                                        <span className="ms:min-w-0 ms:flex-1">
                                            <span className="ms:flex ms:flex-wrap ms:items-center ms:gap-2">
                                                <span className="ms:text-sm ms:font-semibold">{item.name}</span>{switching && item.id === currentVersionId && <span className="ms:text-xs ms:text-muted-foreground">Current</span>}
                                                <span className="ms:rounded ms:bg-background ms:px-2 ms:py-0.5 ms:text-xs ms:capitalize ms:text-muted-foreground">{item.version_type}</span>
                                            </span>
                                            <span className="ms:mt-1 ms:block ms:text-xs ms:text-muted-foreground">Minecraft {item.game_versions.join(', ')} · {item.loaders.join(', ')}</span>
                                            <span className="ms:mt-2 ms:flex ms:flex-wrap ms:gap-x-4 ms:gap-y-1 ms:text-xs ms:text-muted-foreground">
                                                {item.downloads !== null && <span>{new Intl.NumberFormat('en', { notation: 'compact' }).format(item.downloads)} downloads</span>}
                                                <time dateTime={item.date_published}>{new Date(item.date_published).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' })}</time>
                                            </span>
                                        </span>
                                    </label>
                                ))}
                            </div>
                        </fieldset>}
                    {versions.hasNextPage && <Button.Text isLoading={versions.isFetchingNextPage} onClick={() => void versions.fetchNextPage()}>Load older versions</Button.Text>}
                    {versions.isFetchNextPageError && <Alert type="danger">Could not load older versions. Please try again.</Alert>}

                    {!switching && <div>
                        <Label htmlFor="ms-java">Java runtime</Label>
                        <Select id="ms-java" value={javaImage} placeholder="Choose Java version" onChange={(value) => setJavaImage(String(value))} options={provider?.images ?? []} />
                        <p className="ms:mt-2 ms:text-xs ms:text-muted-foreground">Select the Java version required by this pack.</p>
                    </div>}
                    <a className="ms:inline-block ms:text-sm ms:text-primary ms:underline" href={providerId === 'ftb' ? `https://www.feed-the-beast.com/modpacks/${encodeURIComponent(pack.project_id)}-${encodeURIComponent(pack.slug)}` : providerId === 'curseforge' ? `https://www.curseforge.com/minecraft/modpacks/${encodeURIComponent(pack.slug)}` : `https://modrinth.com/modpack/${encodeURIComponent(pack.slug)}`} target="_blank" rel="noopener noreferrer">View on {providerName} ↗</a>
                </> : <>
                    <div className="ms:rounded-lg ms:bg-muted ms:p-3">
                        <p className="ms:text-sm ms:font-semibold">{version?.name}</p>
                        <p className="ms:mt-1 ms:text-xs ms:text-muted-foreground">Minecraft {version?.game_versions.join(', ')} · {version?.loaders.join(', ')} · {provider?.images.find((image) => image.value === javaImage)?.label}</p>
                    </div>
                    <fieldset className="ms:space-y-3">
                        <legend className="ms:mb-3 ms:text-sm ms:font-semibold">Existing server files</legend>
                        {([
                            ['keep_world', 'Keep worlds & server data', 'Replaces mods, config, libraries, and launch files. Leaves worlds and unrelated files in place; preserves player lists and server.properties.'],
                            ['wipe', 'Wipe the server', 'Deletes all server files, including your worlds. Panel backups are kept.'],
                            ...(switching && providerId === 'ftb' ? [['ftb_update', 'FTB update — keep everything', 'Uses the FTB egg’s update process. Modpack Switcher performs no file cleanup.']] : []),
                        ]).map(([value, label, description]) => (
                            <label key={value} className={`ms:flex ms:cursor-pointer ms:items-start ms:gap-3 ms:rounded-lg ms:border ms:p-4 ${filePolicy === value ? 'ms:border-primary ms:bg-muted' : 'ms:border-border'}`}>
                                <input type="radio" name="ms-file-policy" value={value} disabled={installation.isPending} checked={filePolicy === value} onChange={() => { setFilePolicy(value as InstallFilePolicy); setConfirmed(false); }} className="ms:mt-1" />
                                <span><span className="ms:block ms:text-sm ms:font-semibold">{label}</span><span className="ms:mt-1 ms:block ms:text-sm ms:text-muted-foreground">{description}</span></span>
                            </label>
                        ))}
                    </fieldset>
                    {filePolicy === 'wipe' && <Alert type="danger">All worlds, mods, and configuration will be permanently deleted. Make a backup before continuing.</Alert>}
                    {filePolicy === 'wipe' && <label className="ms:flex ms:items-start ms:gap-3 ms:text-sm"><input type="checkbox" disabled={installation.isPending} checked={confirmed} onChange={(event) => setConfirmed(event.target.checked)} />I understand that all server files will be deleted.</label>}
                    <p className="ms:text-xs ms:text-muted-foreground">Installation progress appears in the console.</p>
                </>}
                </>}
            </div>
            <Dialog.Footer>
                {installation.isSuccess ? <Button onClick={onClose}>Done</Button> : <>
                    <Button.Text disabled={installation.isPending} onClick={close}>Cancel</Button.Text>
                    {step === 'version'
                        ? <Button disabled={!version || (switching && versionId === currentVersionId) || (!switching && !javaImage) || !provider?.egg_available || !canInstall} onClick={() => setStep('files')}>Continue</Button>
                        : <><Button isSecondary disabled={installation.isPending} onClick={() => { setStep('version'); installation.reset(); }}>Back</Button><Button color={filePolicy === 'wipe' ? 'red' : 'primary'} isLoading={installation.isPending} disabled={!filePolicy || (filePolicy === 'wipe' && !confirmed) || !canInstall || !provider?.egg_available || installation.isError} onClick={() => installation.mutate()}>{filePolicy === 'wipe' ? 'Wipe and install' : switching ? 'Switch version' : 'Install modpack'}</Button></>}
                </>}
            </Dialog.Footer>
        </Dialog>
    );
}
