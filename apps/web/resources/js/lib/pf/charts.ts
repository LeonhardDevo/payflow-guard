import type { ChartTheme } from '@/lib/theme';
import { withAlpha } from '@/lib/theme';
import type { AgingBucket, RiskBand, Tone } from '@/types/payflow';

export const AGING_BUCKETS: AgingBucket[] = [
    'current',
    'd1_30',
    'd31_60',
    'd61_90',
    'd90_plus',
];

export function agingColor(theme: ChartTheme, bucket: AgingBucket): string {
    switch (bucket) {
        case 'current':
            return theme.primary;
        case 'd1_30':
            return withAlpha(theme.warning, 0.6);
        case 'd31_60':
            return theme.warning;
        case 'd61_90':
            return withAlpha(theme.destructive, 0.7);
        default:
            return theme.destructive;
    }
}

export function riskColor(theme: ChartTheme, band: RiskBand): string {
    if (band === 'low') {
        return theme.primary;
    }

    return band === 'medium' ? theme.warning : theme.destructive;
}

export function toneColor(theme: ChartTheme, tone: Tone): string {
    switch (tone) {
        case 'friendly':
            return theme.primary;
        case 'neutral':
            return theme.series[1] ?? theme.ai;
        case 'firm':
            return theme.warning;
        default:
            return theme.destructive;
    }
}

export function hatchDecal(theme: ChartTheme) {
    return {
        symbol: 'rect',
        dashArrayX: [1, 0],
        dashArrayY: [2, 5],
        rotation: Math.PI / 4,
        color: withAlpha(theme.text, 0.35),
    };
}

export function prefersMoreContrast(): boolean {
    return (
        typeof window !== 'undefined' &&
        typeof window.matchMedia === 'function' &&
        window.matchMedia('(prefers-contrast: more)').matches
    );
}
