// Recent releases plus established modpack targets. Specific patch versions matter
// for mod loaders; keep these selectable rather than grouping them as “1.21.x”.
export const minecraftVersionOptions = [
    { value: '', label: 'Any version' },
    ...[
        '26.3', '26.2', '26.1.2', '26.1.1', '26.1',
        '1.21.11', '1.21.10', '1.21.9', '1.21.8', '1.21.7', '1.21.6',
        '1.21.5', '1.21.4', '1.21.3', '1.21.2', '1.21.1', '1.21',
        '1.20.6', '1.20.4', '1.20.2', '1.20.1', '1.20',
        '1.19.4', '1.19.3', '1.19.2', '1.19', '1.18.2', '1.18.1',
        '1.17.1', '1.16.5', '1.15.2', '1.14.4', '1.12.2', '1.10.2', '1.7.10',
    ].map((version) => ({ value: version, label: version })),
];
