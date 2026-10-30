<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import {
    Bot,
    Building2,
    CreditCard,
    FileText,
    FilterX,
    Globe,
    KeyRound,
    PlugZap,
    ScrollText,
    SearchX,
    Settings2,
    ShieldCheck,
    UserRound,
    UsersRound,
} from '@lucide/vue';
import { useStorage } from '@vueuse/core';
import type { Component } from 'vue';
import { computed } from 'vue';
import AuditDiff from '@/components/audit/AuditDiff.vue';
import type { DataColumn } from '@/components/common/DataTable.vue';
import DataTable from '@/components/common/DataTable.vue';
import EmptyState from '@/components/common/EmptyState.vue';
import LaravelPagination from '@/components/common/LaravelPagination.vue';
import PageHeader from '@/components/common/PageHeader.vue';
import SearchInput from '@/components/common/SearchInput.vue';
import SegmentedControl from '@/components/common/SegmentedControl.vue';
import StatusBadge from '@/components/common/StatusBadge.vue';
import UserAvatar from '@/components/common/UserAvatar.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useFilters } from '@/composables/useFilters';
import { useFormat } from '@/composables/useFormat';
import { actionDomain, actionTone, groupByDay } from '@/lib/audit';
import { endpoints } from '@/lib/endpoints';
import { humanize, t, tOr } from '@/lib/i18n';
import type { AuditActor, AuditFilters, AuditLog, Paginated } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'ui.nav.audit', href: endpoints.audit.index() }],
    },
});

const props = withDefaults(
    defineProps<{
        logs: Paginated<AuditLog>;
        filters: AuditFilters;
        actions: string[];
        actionLabels?: Record<string, string>;
        actors?: AuditActor[];
    }>(),
    { actions: () => [], actionLabels: () => ({}), actors: () => [] },
);

const fmt = useFormat();

const { filters, reset, active } = useFilters(
    () => endpoints.audit.index().url,
    {
        action: props.filters?.action ?? null,
        actor: props.filters?.actor ?? null,
        from: props.filters?.from ?? null,
        to: props.filters?.to ?? null,
    },
    ['logs', 'filters'],
);

const actionModel = computed({
    get: () => filters.action ?? 'all',
    set: (value: string) => {
        filters.action = value === 'all' ? null : value;
    },
});

const view = useStorage<'timeline' | 'table'>('audit-view', 'timeline');
const viewOptions = computed(() => [
    { value: 'timeline' as const, label: t('ui.audit.view_timeline') },
    { value: 'table' as const, label: t('ui.audit.view_table') },
]);

const actionLabel = (action: string, provided?: string | null): string =>
    provided ||
    props.actionLabels[action] ||
    tOr(`audit.actions.${action}`, humanize(action));

const actorModel = computed({
    get: () => (filters.actor ? String(filters.actor) : 'all'),
    set: (value: string) => {
        filters.actor = value === 'all' ? null : value;
    },
});

const domainIcons: Record<string, Component> = {
    auth: KeyRound,
    user: UserRound,
    member: UsersRound,
    members: UsersRound,
    invitation: UsersRound,
    workspace: Building2,
    billing: CreditCard,
    subscription: CreditCard,
    document: FileText,
    knowledge: FileText,
    telegram: PlugZap,
    integration: PlugZap,
    ai: Bot,
    admin: ShieldCheck,
    settings: Settings2,
};

const iconFor = (action: string): Component =>
    domainIcons[actionDomain(action)] ?? ScrollText;

const toneDot: Record<string, string> = {
    success: 'bg-success-soft text-success-soft-foreground',
    warning: 'bg-warning-soft text-warning-soft-foreground',
    destructive: 'bg-destructive-soft text-destructive-soft-foreground',
    info: 'bg-info-soft text-info-soft-foreground',
    ai: 'bg-ai-soft text-ai-soft-foreground',
    neutral: 'bg-muted text-muted-foreground',
};

const groups = computed(() =>
    groupByDay(props.logs.data, (iso) => fmt.value.date(iso, 'full')),
);

const columns = computed<DataColumn<AuditLog>[]>(() => [
    { id: 'created_at', header: t('ui.audit.when'), sortable: true },
    {
        id: 'actor',
        header: t('ui.audit.actor'),
        accessor: (row) => row.actor?.name ?? '',
        sortable: true,
    },
    { id: 'action', header: t('ui.audit.action'), sortable: true },
    { id: 'subject_label', header: t('ui.audit.subject'), hideBelow: 'md' },
    { id: 'changes', header: t('ui.audit.changes_header'), hideBelow: 'sm' },
    { id: 'ip', header: 'IP', hideBelow: 'lg' },
]);
</script>

<template>
    <Head :title="t('ui.audit.title')" />

    <div
        class="mx-auto flex w-full max-w-7xl flex-col gap-6 px-4 py-6 sm:px-6 lg:py-8"
    >
        <PageHeader
            :icon="ScrollText"
            :title="t('ui.audit.title')"
            :description="t('ui.audit.description')"
        >
            <template #actions>
                <SegmentedControl
                    v-model="view"
                    :options="viewOptions"
                    :label="t('ui.audit.view')"
                />
            </template>
        </PageHeader>

        <section
            class="grid grid-cols-1 gap-3 rounded-2xl border bg-card p-4 shadow-soft sm:grid-cols-2 lg:grid-cols-[1.2fr_1fr_0.8fr_0.8fr_auto] lg:items-end"
            :aria-label="t('ui.common.filters')"
        >
            <div class="grid gap-1.5">
                <Label
                    for="audit-action"
                    class="text-xs text-muted-foreground"
                    >{{ t('ui.audit.action') }}</Label
                >
                <Select v-model="actionModel">
                    <SelectTrigger id="audit-action" class="w-full">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">{{
                            t('ui.audit.all_actions')
                        }}</SelectItem>
                        <SelectItem
                            v-for="action in actions"
                            :key="action"
                            :value="action"
                        >
                            {{ actionLabel(action) }}
                        </SelectItem>
                    </SelectContent>
                </Select>
            </div>
            <div v-if="actors.length" class="grid gap-1.5">
                <Label
                    for="audit-actor"
                    class="text-xs text-muted-foreground"
                    >{{ t('ui.audit.actor') }}</Label
                >
                <Select v-model="actorModel">
                    <SelectTrigger id="audit-actor" class="w-full">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">{{
                            t('ui.audit.all_actors')
                        }}</SelectItem>
                        <SelectItem
                            v-for="actor in actors"
                            :key="actor.id"
                            :value="String(actor.id)"
                        >
                            {{ actor.name }}
                        </SelectItem>
                    </SelectContent>
                </Select>
            </div>
            <div v-else class="grid gap-1.5">
                <span class="text-xs text-muted-foreground">{{
                    t('ui.audit.actor')
                }}</span>
                <SearchInput
                    v-model="filters.actor"
                    :placeholder="t('ui.audit.actor_placeholder')"
                    :label="t('ui.audit.actor')"
                    class="sm:max-w-none"
                />
            </div>
            <div class="grid gap-1.5">
                <Label for="audit-from" class="text-xs text-muted-foreground">{{
                    t('ui.audit.from')
                }}</Label>
                <Input
                    id="audit-from"
                    :model-value="filters.from ?? ''"
                    type="date"
                    :max="filters.to ?? undefined"
                    @update:model-value="
                        (value) => (filters.from = String(value) || null)
                    "
                />
            </div>
            <div class="grid gap-1.5">
                <Label for="audit-to" class="text-xs text-muted-foreground">{{
                    t('ui.audit.to')
                }}</Label>
                <Input
                    id="audit-to"
                    :model-value="filters.to ?? ''"
                    type="date"
                    :min="filters.from ?? undefined"
                    @update:model-value="
                        (value) => (filters.to = String(value) || null)
                    "
                />
            </div>
            <Button variant="ghost" :disabled="!active()" @click="reset">
                <FilterX class="size-4" aria-hidden="true" />
                {{ t('ui.common.clear_filters') }}
            </Button>
        </section>

        <section
            v-if="view === 'timeline'"
            :aria-label="t('ui.audit.view_timeline')"
            class="space-y-6"
        >
            <div
                v-if="!logs.data.length"
                class="rounded-2xl border bg-card shadow-soft"
            >
                <EmptyState
                    :icon="active() ? SearchX : ScrollText"
                    tone="info"
                    :title="
                        active()
                            ? t('ui.audit.empty_filtered')
                            : t('ui.audit.empty_title')
                    "
                    :description="
                        active()
                            ? t('ui.common.try_other_filters')
                            : t('ui.audit.empty_body')
                    "
                >
                    <Button v-if="active()" variant="outline" @click="reset">{{
                        t('ui.common.clear_filters')
                    }}</Button>
                </EmptyState>
            </div>
            <div v-for="group in groups" :key="group.day" class="space-y-3">
                <h2
                    class="sticky top-14 z-10 -mx-1 bg-background/85 px-1 py-1 text-xs font-semibold tracking-wider text-muted-foreground uppercase backdrop-blur"
                >
                    {{ group.day }}
                </h2>
                <ol class="relative ml-4 space-y-1 border-l pl-6">
                    <li
                        v-for="log in group.items"
                        :key="log.id"
                        class="relative"
                        data-test="audit-row"
                        :data-action="log.action"
                    >
                        <span
                            :class="[
                                'absolute top-3 -left-[2.3rem] inline-flex size-7 items-center justify-center rounded-full ring-4 ring-background',
                                toneDot[actionTone(log.action)],
                            ]"
                            aria-hidden="true"
                        >
                            <component
                                :is="iconFor(log.action)"
                                class="size-3.5"
                            />
                        </span>
                        <div
                            class="flex flex-col gap-2 rounded-xl border bg-card px-4 py-3 shadow-soft sm:flex-row sm:items-center sm:justify-between"
                        >
                            <div class="flex min-w-0 items-start gap-3">
                                <UserAvatar
                                    v-if="log.actor"
                                    :name="log.actor.name"
                                    size="xs"
                                    class="mt-0.5"
                                />
                                <span
                                    v-else
                                    class="mt-0.5 inline-flex size-6 items-center justify-center rounded-lg bg-muted text-muted-foreground"
                                    aria-hidden="true"
                                >
                                    <Bot class="size-3.5" />
                                </span>
                                <div class="min-w-0 text-sm">
                                    <p class="leading-snug">
                                        <span class="font-semibold">{{
                                            log.actor?.name ??
                                            t('ui.audit.system')
                                        }}</span>
                                        <span class="text-muted-foreground">
                                            ·
                                        </span>
                                        <span>{{
                                            actionLabel(
                                                log.action,
                                                log.action_label,
                                            )
                                        }}</span>
                                        <template v-if="log.subject_label">
                                            <span class="text-muted-foreground">
                                                ·
                                            </span>
                                            <span class="font-medium">{{
                                                log.subject_label
                                            }}</span>
                                        </template>
                                    </p>
                                    <p
                                        class="mt-0.5 flex flex-wrap items-center gap-x-2 text-xs text-muted-foreground"
                                    >
                                        <time :datetime="log.created_at">{{
                                            fmt.time(log.created_at)
                                        }}</time>
                                        <span
                                            v-if="log.ip"
                                            class="inline-flex items-center gap-1 font-mono"
                                        >
                                            <Globe
                                                class="size-3"
                                                aria-hidden="true"
                                            />{{ log.ip }}
                                        </span>
                                        <span class="font-mono">{{
                                            log.action
                                        }}</span>
                                    </p>
                                </div>
                            </div>
                            <AuditDiff :changes="log.changes" />
                        </div>
                    </li>
                </ol>
            </div>
            <LaravelPagination
                v-if="logs.data.length"
                :links="logs.links"
                :meta="logs.meta"
                :count="logs.data.length"
                :only="['logs', 'filters']"
            />
        </section>

        <DataTable
            v-else
            :columns="columns"
            :rows="logs.data"
            :row-key="(log) => log.id"
            :row-attrs="
                (log) => ({
                    'data-test': 'audit-row',
                    'data-action': log.action,
                })
            "
            :caption="t('ui.audit.title')"
        >
            <template #cell-created_at="{ row }">
                <span class="whitespace-nowrap text-muted-foreground">{{
                    fmt.dateTime(row.created_at, 'short')
                }}</span>
            </template>
            <template #cell-actor="{ row }">
                <span class="flex items-center gap-2">
                    <UserAvatar
                        v-if="row.actor"
                        :name="row.actor.name"
                        size="xs"
                    />
                    <span class="truncate">{{
                        row.actor?.name ?? t('ui.audit.system')
                    }}</span>
                </span>
            </template>
            <template #cell-action="{ row }">
                <StatusBadge
                    :tone="actionTone(row.action)"
                    :label="actionLabel(row.action, row.action_label)"
                />
            </template>
            <template #cell-subject_label="{ row }">
                <span class="text-muted-foreground">{{
                    row.subject_label ??
                    (row.subject_type
                        ? `${row.subject_type} #${row.subject_id}`
                        : '—')
                }}</span>
            </template>
            <template #cell-changes="{ row }">
                <AuditDiff :changes="row.changes" />
            </template>
            <template #cell-ip="{ row }">
                <span class="font-mono text-xs text-muted-foreground">{{
                    row.ip ?? '—'
                }}</span>
            </template>
            <template #empty>
                <EmptyState
                    :icon="active() ? SearchX : ScrollText"
                    tone="info"
                    :title="
                        active()
                            ? t('ui.audit.empty_filtered')
                            : t('ui.audit.empty_title')
                    "
                    :description="
                        active()
                            ? t('ui.common.try_other_filters')
                            : t('ui.audit.empty_body')
                    "
                />
            </template>
            <template #footer>
                <LaravelPagination
                    :links="logs.links"
                    :meta="logs.meta"
                    :count="logs.data.length"
                    :only="['logs', 'filters']"
                />
            </template>
        </DataTable>
    </div>
</template>
