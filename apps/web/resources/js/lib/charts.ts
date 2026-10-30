import type { ChartTheme } from '@/lib/theme';
import { withAlpha } from '@/lib/theme';

export function baseGrid() {
    return {
        left: 8,
        right: 8,
        top: 32,
        bottom: 4,
        outerBoundsMode: 'same' as const,
        outerBoundsContain: 'axisLabel' as const,
    };
}

export function axisTooltip(theme: ChartTheme) {
    return {
        trigger: 'axis' as const,
        backgroundColor: theme.tooltipBackground,
        borderColor: theme.tooltipBorder,
        borderWidth: 1,
        padding: [8, 12],
        textStyle: {
            color: theme.text,
            fontFamily: theme.fontFamily,
            fontSize: 12,
        },
        axisPointer: {
            type: 'line' as const,
            lineStyle: { color: theme.axis, type: 'dashed' as const, width: 1 },
        },
        extraCssText:
            'border-radius: 10px; box-shadow: 0 8px 24px rgba(0,0,0,.12);',
    };
}

export function categoryAxis(theme: ChartTheme, data: string[]) {
    return {
        type: 'category' as const,
        data,
        boundaryGap: true,
        axisLine: { lineStyle: { color: theme.grid } },
        axisTick: { show: false },
        axisLabel: { color: theme.mutedText, fontSize: 11, hideOverlap: true },
    };
}

export function valueAxis(
    theme: ChartTheme,
    formatter?: (value: number) => string,
) {
    return {
        type: 'value' as const,
        splitLine: { lineStyle: { color: theme.grid } },
        axisLabel: {
            color: theme.mutedText,
            fontSize: 11,
            ...(formatter ? { formatter } : {}),
        },
    };
}

export function legend(theme: ChartTheme) {
    return {
        top: 0,
        right: 0,
        icon: 'roundRect',
        itemWidth: 10,
        itemHeight: 10,
        textStyle: { color: theme.mutedText, fontSize: 12 },
    };
}

export function areaFill(color: string) {
    return {
        color: {
            type: 'linear' as const,
            x: 0,
            y: 0,
            x2: 0,
            y2: 1,
            colorStops: [
                { offset: 0, color: withAlpha(color, 0.28) },
                { offset: 1, color: withAlpha(color, 0.02) },
            ],
        },
    };
}
