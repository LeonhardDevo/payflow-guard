<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    Activity,
    ArrowRight,
    BookOpenText,
    Bot,
    ChartNoAxesCombined,
    CircleDollarSign,
    FileUp,
    Gauge,
    HardDrive,
    MessageSquareText,
    PlugZap,
    Sparkles,
    UsersRound,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed } from 'vue';
import EmptyState from '@/components/common/EmptyState.vue';
import KpiCard from '@/components/common/KpiCard.vue';
import PageHeader from '@/components/common/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { useCan } from '@/composables/useCan';
import { useFormat } from '@/composables/useFormat';
import { endpoints, withQuery } from '@/lib/endpoints';
import { t, tOr } from '@/lib/i18n';
import type { DashboardStat } from '@/types';

const props = withDefaults(defineProps<{ stats?: DashboardStat[] }>(), {
    stats: () => [],
});

const page = usePage();
const { can } = useCan();
const fmt = useFormat();

const greetingKey = computed(() => {
    const hour = new Date().getHours();

    if (hour < 5) {
        return 'ui.dashboard.greeting.night';
    }

    if (hour < 12) {
        return 'ui.dashboard.greeting.morning';
    }

    return hour < 18
        ? 'ui.dashboard.greeting.afternoon'
        : 'ui.dashboard.greeting.evening';
});

const firstName = computed(
    () => page.props.auth.user?.name.split(' ')[0] ?? '',
);

const statIcons: Record<string, Component> = {
    members: UsersRound,
    documents: BookOpenText,
    ai_queries: MessageSquareText,
    storage_mb: HardDrive,
    questions: MessageSquareText,
    answered: Sparkles,
    revenue: CircleDollarSign,
    usage: Gauge,
};

const statFormats: Record<string, (value: number) => string> = {
    storage_mb: (value) => fmt.value.bytes(value * 1024 * 1024),
    revenue: (value) => fmt.value.money(value),
};

const tones = ['primary', 'ai', 'info', 'warning'] as const;

const kpis = computed(() =>
    props.stats.map((stat, index) => ({
        ...stat,
        label: tOr(`ui.dashboard.stats.${stat.key}`, stat.label),
        icon: statIcons[stat.key] ?? Activity,
        tone: tones[index % tones.length],
        format: statFormats[stat.key],
    })),
);

const nextSteps = computed(() => {
    const steps: Array<{ key: string; icon: Component; href: string }> = [
        { key: 'ask', icon: Bot, href: endpoints.assistant.index().url },
    ];

    if (can('knowledge.manage', 'workspace.manage')) {
        steps.unshift({
            key: 'upload',
            icon: FileUp,
            href: withQuery(endpoints.knowledge.index().url, { upload: 1 }),
        });
    }

    if (can('members.manage', 'workspace.manage')) {
        steps.push({
            key: 'invite',
            icon: UsersRound,
            href: withQuery(endpoints.settings.members.index().url, {
                invite: 1,
            }),
        });
    }

    steps.push({
        key: 'telegram',
        icon: PlugZap,
        href: endpoints.settings.integrations.show().url,
    });

    return steps;
});

const previewBars = [42, 58, 36, 70, 52, 80, 64, 90, 72, 60, 84, 76];
</script>

<template>
    <div
        class="mx-auto flex w-full max-w-7xl flex-col gap-6 px-4 py-6 sm:px-6 lg:py-8"
    >
        <PageHeader
            :eyebrow="page.props.workspace?.name ?? null"
            :title="t(greetingKey, { name: firstName })"
            :description="t('ui.dashboard.subtitle')"
        >
            <template #actions>
                <Button as-child variant="outline">
                    <Link :href="endpoints.assistant.history()">
                        <MessageSquareText class="size-4" aria-hidden="true" />
                        {{ t('ui.nav.history') }}
                    </Link>
                </Button>
                <Button as-child>
                    <Link :href="endpoints.assistant.index()">
                        <Sparkles class="size-4" aria-hidden="true" />
                        {{ t('ui.dashboard.ask') }}
                    </Link>
                </Button>
            </template>
        </PageHeader>

        <section
            :aria-label="t('ui.dashboard.kpis')"
            data-test="dashboard-stats"
        >
            <div
                v-if="kpis.length"
                class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4"
            >
                <KpiCard
                    v-for="kpi in kpis"
                    :key="kpi.key"
                    :label="kpi.label"
                    :value="kpi.value"
                    :delta="
                        typeof kpi.delta === 'number' ? kpi.delta / 100 : null
                    "
                    :icon="kpi.icon"
                    :tone="kpi.tone"
                    :format="kpi.format"
                    :data-stat="kpi.key"
                />
            </div>
            <div v-else class="rounded-2xl border border-dashed bg-card/60">
                <EmptyState
                    compact
                    :icon="ChartNoAxesCombined"
                    :title="t('ui.dashboard.no_stats_title')"
                    :description="t('ui.dashboard.no_stats_body')"
                />
            </div>
        </section>

        <div
            class="grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]"
        >
            <section
                class="relative overflow-hidden rounded-2xl border bg-card p-5 shadow-soft sm:p-6"
                :aria-labelledby="'activity-title'"
            >
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2
                            id="activity-title"
                            class="font-display text-base font-semibold"
                        >
                            {{ t('ui.dashboard.activity_title') }}
                        </h2>
                        <p class="mt-0.5 text-sm text-muted-foreground">
                            {{ t('ui.dashboard.activity_body') }}
                        </p>
                    </div>
                </div>
                <div class="relative mt-6 h-56">
                    <div
                        class="flex h-full items-end gap-2 opacity-60 sm:gap-3"
                        aria-hidden="true"
                    >
                        <div
                            v-for="(height, index) in previewBars"
                            :key="index"
                            class="flex-1 rounded-t-md bg-gradient-to-t from-muted to-muted/40"
                            :style="{ height: `${height}%` }"
                        />
                    </div>
                    <div
                        class="absolute inset-0 flex items-center justify-center p-4"
                    >
                        <p
                            class="flex max-w-sm items-center gap-2.5 rounded-xl border bg-card/95 px-4 py-3 text-sm text-muted-foreground shadow-soft backdrop-blur"
                        >
                            <ChartNoAxesCombined
                                class="size-5 shrink-0 text-primary"
                                aria-hidden="true"
                            />
                            {{ t('ui.dashboard.activity_placeholder') }}
                        </p>
                    </div>
                </div>
            </section>

            <section
                class="rounded-2xl border bg-card p-5 shadow-soft sm:p-6"
                aria-labelledby="next-steps-title"
            >
                <h2
                    id="next-steps-title"
                    class="font-display text-base font-semibold"
                >
                    {{ t('ui.dashboard.next_steps_title') }}
                </h2>
                <p class="mt-0.5 text-sm text-muted-foreground">
                    {{ t('ui.dashboard.next_steps_body') }}
                </p>
                <ul class="mt-4 space-y-2">
                    <li v-for="step in nextSteps" :key="step.key">
                        <Link
                            :href="step.href"
                            class="group flex items-center gap-3 rounded-xl border bg-background/60 p-3 transition-colors hover:border-primary/40 hover:bg-primary-soft/40"
                        >
                            <span
                                class="inline-flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary-soft text-primary-soft-foreground"
                                aria-hidden="true"
                            >
                                <component :is="step.icon" class="size-4" />
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block text-sm font-medium">{{
                                    t(`ui.dashboard.steps.${step.key}.title`)
                                }}</span>
                                <span
                                    class="block truncate text-xs text-muted-foreground"
                                    >{{
                                        t(`ui.dashboard.steps.${step.key}.body`)
                                    }}</span
                                >
                            </span>
                            <ArrowRight
                                class="size-4 text-muted-foreground transition-transform group-hover:translate-x-0.5"
                                aria-hidden="true"
                            />
                        </Link>
                    </li>
                </ul>
            </section>
        </div>
    </div>
</template>
