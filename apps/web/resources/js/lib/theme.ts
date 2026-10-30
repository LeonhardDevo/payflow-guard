export type ChartTheme = {
    series: string[];
    text: string;
    mutedText: string;
    grid: string;
    axis: string;
    background: string;
    tooltipBackground: string;
    tooltipBorder: string;
    primary: string;
    warning: string;
    destructive: string;
    ai: string;
    fontFamily: string;
    monoFamily: string;
    dark: boolean;
};

let canvasContext: CanvasRenderingContext2D | null | undefined;

function context(): CanvasRenderingContext2D | null {
    if (canvasContext !== undefined) {
        return canvasContext;
    }

    if (typeof document === 'undefined') {
        canvasContext = null;

        return null;
    }

    const canvas = document.createElement('canvas');
    canvas.width = 1;
    canvas.height = 1;
    canvasContext = canvas.getContext('2d', { willReadFrequently: true });

    return canvasContext;
}

export function toRgb(color: string, fallback = 'rgb(100, 116, 139)'): string {
    const ctx = context();

    if (!ctx || !color) {
        return fallback;
    }

    ctx.clearRect(0, 0, 1, 1);
    ctx.fillStyle = '#000';
    ctx.fillStyle = color;
    ctx.fillRect(0, 0, 1, 1);

    const [r, g, b, a] = ctx.getImageData(0, 0, 1, 1).data;
    const alpha = Math.round(((a ?? 255) / 255) * 100) / 100;

    return alpha >= 1
        ? `rgb(${r}, ${g}, ${b})`
        : `rgba(${r}, ${g}, ${b}, ${alpha})`;
}

export function cssVar(name: string, element?: Element): string {
    if (typeof window === 'undefined') {
        return '';
    }

    return getComputedStyle(element ?? document.documentElement)
        .getPropertyValue(name)
        .trim();
}

export function withAlpha(rgb: string, alpha: number): string {
    const match = rgb.match(/rgba?\((\d+),\s*(\d+),\s*(\d+)/);

    if (!match) {
        return rgb;
    }

    return `rgba(${match[1]}, ${match[2]}, ${match[3]}, ${alpha})`;
}

export function readChartTheme(): ChartTheme {
    const resolve = (name: string, fallback?: string) =>
        toRgb(cssVar(name), fallback);

    return {
        series: [
            '--chart-1',
            '--chart-2',
            '--chart-3',
            '--chart-4',
            '--chart-5',
            '--chart-6',
        ].map((name) => resolve(name)),
        text: resolve('--foreground'),
        mutedText: resolve('--muted-foreground'),
        grid: resolve('--chart-grid'),
        axis: resolve('--chart-axis'),
        background: resolve('--card'),
        tooltipBackground: resolve('--popover'),
        tooltipBorder: resolve('--border'),
        primary: resolve('--primary'),
        warning: resolve('--warning'),
        destructive: resolve('--destructive'),
        ai: resolve('--ai'),
        fontFamily: cssVar('--brand-font-sans') || 'system-ui, sans-serif',
        monoFamily: cssVar('--brand-font-mono') || 'ui-monospace, monospace',
        dark:
            typeof document !== 'undefined' &&
            document.documentElement.classList.contains('dark'),
    };
}
