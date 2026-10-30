<script setup lang="ts">
import { Head, Link, router, useHttp } from '@inertiajs/vue3';
import {
    BellRing,
    CircleX,
    Eye,
    FilterX,
    MailCheck,
    RotateCw,
    SearchX,
    Send,
    Settings2,
    TriangleAlert,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import type { DataColumn } from '@/components/common/DataTable.vue';
import DataTable from '@/components/common/DataTable.vue';
import EmptyState from '@/components/common/EmptyState.vue';
import KpiCard from '@/components/common/KpiCard.vue';
import LaravelPagination from '@/components/common/LaravelPagination.vue';
import PageHeader from '@/components/common/PageHeader.vue';
import SearchInput from '@/components/common/SearchInput.vue';
import StatusBadge from '@/components/common/StatusBadge.vue';
import ClientClassBadge from '@/components/pf/ClientClassBadge.vue';
import ToneChip from '@/components/pf/ToneChip.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import { useFilters } from '@/composables/useFilters';
import { useFormat } from '@/composables/useFormat';
import { usePf } from '@/composables/pf/usePf';
import { describeHttpError } from '@/lib/http-errors';
import { t } from '@/lib/i18n';
import { REMINDER_STATUS_TONE, TONES } from '@/lib/pf/status';
import { sanitizeAnswer } from '@/lib/sanitize';
import { show as showInvoice } from '@/routes/invoices';
import { cancel, index, retry, sendNow, show } from '@/routes/reminders';
import { collections } from '@/routes/settings';
import type {
    ReminderDetail,
    ReminderListItem,
    ReminderTab,
    RemindersIndexProps,
} from '@/types/payflow';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'pf.nav.reminders', href: index() }],
    },
});

const props = defineProps<RemindersIndexProps>();
const pf = usePf();
const fmt = useFormat();

const { filters, reset, active } = useFilters(
    () => index().url,
    {
        tab: props.tab === 'upcoming' ? null : props.tab,
        tone: props.filters.tone,
        q: props.filters.q,
    } as { tab: string | null; tone: string | null; q: string | null },
    ['reminders', 'counts', 'filters', 'tab'],
);

const narrowed = () => Boolean(filters.tone || filters.q);
const tabs: ReminderTab[] = ['upcoming', 'sent', 'failed', 'skipped'];
const currentTab = computed(() => (filters.tab ?? 'upcoming') as ReminderTab);

function setTab(tab: ReminderTab): void {
    filters.tab = tab === 'upcoming' ? null : tab;
}

const toneModel = computed({
    get: () => filters.tone ?? 'all',
    set: (value: string) => {
        filters.tone = value === 'all' ? null : value;
    },
});

const busy = ref<string | null>(null);

function act(kind: 'send' | 'cancel' | 'retry', row: ReminderListItem): void {
    const url =
        kind === 'send'
            ? sendNow(row.id).url
            : kind === 'cancel'
              ? cancel(row.id).url
              : retry(row.id).url;
    busy.value = `${kind}-${row.id}`;
    router.post(
        url,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                busy.value = null;
            },
        },
    );
}

const viewOpen = ref(false);
const detail = ref<ReminderDetail | null>(null);
const detailError = ref<string | null>(null);
const detailHttp = useHttp<Record<string, never>, ReminderDetail>({});

async function view(row: ReminderListItem): Promise<void> {
    detail.value = null;
    detailError.value = null;
    viewOpen.value = true;

    try {
        detail.value = (await detailHttp.get(show(row.id).url)) ?? null;
    } catch (error) {
        detailError.value =
            describeHttpError(error).message ?? t('pf.reminder.preview_failed');
    }
}

const columns = computed<DataColumn<ReminderListItem>[]>(() => [
    {
        id: 'when',
        header:
            currentTab.value === 'upcoming'
                ? t('pf.reminders.scheduled_for')
                : t('pf.reminders.when'),
    },
    { id: 'invoice', header: t('pf.invoice.number') },
    { id: 'tone', header: t('pf.reminder.tone'), hideBelow: 'sm' },
    { id: 'status', header: t('pf.invoice.status'), hideBelow: 'md' },
    { id: 'recipient', header: t('pf.reminder.to'), hideBelow: 'lg' },
    {
        id: 'actions',
        header: t('ui.common.actions'),
        srOnlyHeader: true,
        align: 'right',
    },
]);

const canSend = computed(() => pf.can('reminders.send'));
</script>

<template>
    <Head :title="t('pf.reminders.title')" />

    <div
        class="mx-auto flex w-full max-w-7xl flex-col gap-6 px-4 py-6 sm:px-6 lg:py-8"
    >
        <PageHeader
            :icon="BellRing"
            :title="t('pf.reminders.title')"
            :description="t('pf.reminders.description')"
        >
            <template v-if="pf.can('reminders.configure')" #actions>
                <Button variant="outline" as-child>
                    <Link :href="collections().url">
                        <Settings2 class="size-4" aria-hidden="true" />
                        {{ t('pf.reminders.configure') }}
                    </Link>
                </Button>
            </template>
        </PageHeader>

        <div
            class="grid grid-cols-1 gap-3 sm:grid-cols-3"
            data-test="reminder-stats"
        >
            <KpiCard
                :label="t('pf.reminders.sent_30d')"
                :value="stats.sent_30d"
                :icon="Send"
            />
            <KpiCard
                :label="t('pf.reminders.paid_within_7d')"
                :value="
                    stats.paid_within_7d_rate === null
                        ? '—'
                        : fmt.percent(stats.paid_within_7d_rate)
                "
                :icon="MailCheck"
                tone="ai"
                :hint="t('pf.reminders.paid_within_7d_hint')"
            />
            <KpiCard
                :label="t('pf.reminders.failed_7d')"
                :value="stats.failed_7d"
                :icon="TriangleAlert"
                tone="warning"
            />
        </div>

        <nav
            class="-mx-4 overflow-x-auto px-4 sm:mx-0 sm:px-0"
            :aria-label="t('pf.reminders.title')"
        >
            <ul class="flex min-w-max gap-1 border-b">
                <li v-for="tab in tabs" :key="tab">
                    <button
                        type="button"
                        :class="[
                            '-mb-px inline-flex items-center gap-2 border-b-2 px-3 py-2 text-sm transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                            currentTab === tab
                                ? 'border-primary font-medium text-foreground'
                                : 'border-transparent text-muted-foreground hover:text-foreground',
                        ]"
                        :aria-current="currentTab === tab ? 'page' : undefined"
                        :data-test="`reminder-tab-${tab}`"
                        @click="setTab(tab)"
                    >
                        {{ t(`pf.reminders.tabs.${tab}`) }}
                        <span
                            :class="[
                                'rounded-full px-1.5 text-xs tabular-nums',
                                tab === 'failed' && counts.failed
                                    ? 'bg-destructive-soft text-destructive-soft-foreground'
                                    : 'bg-muted text-muted-foreground',
                            ]"
                            >{{ counts[tab] ?? 0 }}</span
                        >
                    </button>
                </li>
            </ul>
        </nav>

        <section
            class="grid grid-cols-1 gap-3 rounded-2xl border bg-card p-4 shadow-soft sm:grid-cols-[1.5fr_1fr_auto] sm:items-end"
            :aria-label="t('ui.common.filters')"
        >
            <div class="grid gap-1.5">
                <span class="text-xs text-muted-foreground">{{
                    t('ui.common.search')
                }}</span>
                <SearchInput
                    v-model="filters.q"
                    :placeholder="t('pf.reminders.search')"
                    :label="t('pf.reminders.search')"
                    class="sm:max-w-none"
                />
            </div>
            <div class="grid gap-1.5">
                <Label for="rem-tone" class="text-xs text-muted-foreground">{{
                    t('pf.reminder.tone')
                }}</Label>
                <Select v-model="toneModel">
                    <SelectTrigger id="rem-tone" class="w-full"
                        ><SelectValue
                    /></SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">{{
                            t('ui.common.all')
                        }}</SelectItem>
                        <SelectItem
                            v-for="tone in TONES"
                            :key="tone"
                            :value="tone"
                            >{{ t(`pf.tone.${tone}`) }}</SelectItem
                        >
                    </SelectContent>
                </Select>
            </div>
            <Button variant="ghost" :disabled="!narrowed()" @click="reset">
                <FilterX class="size-4" aria-hidden="true" />
                {{ t('ui.common.clear_filters') }}
            </Button>
        </section>

        <DataTable
            :columns="columns"
            :rows="reminders.data"
            :row-key="(row) => row.id"
            :row-attrs="
                (row) => ({
                    'data-test': 'reminder-row',
                    'data-status': row.status,
                    'data-tone': row.tone,
                })
            "
            :caption="t(`pf.reminders.tabs.${currentTab}`)"
        >
            <template #cell-when="{ row }">
                <span class="whitespace-nowrap">{{
                    fmt.dateTime(
                        row.sent_at ?? row.failed_at ?? row.scheduled_for,
                        'short',
                    )
                }}</span>
                <p
                    v-if="row.next_retry_at"
                    class="text-xs text-muted-foreground"
                >
                    {{
                        t('pf.reminders.retry_at', {
                            date: fmt.dateTime(row.next_retry_at, 'short'),
                        })
                    }}
                </p>
                <p
                    v-if="row.is_first_contact"
                    class="text-xs text-muted-foreground"
                >
                    {{ t('pf.reminders.first_contact') }}
                </p>
            </template>
            <template #cell-invoice="{ row }">
                <Link
                    :href="showInvoice(row.invoice.id).url"
                    class="font-mono text-sm font-medium hover:underline"
                >
                    {{ row.invoice.number ?? t('pf.invoice.draft_number') }}
                </Link>
                <p
                    class="flex flex-wrap items-center gap-1.5 text-xs text-muted-foreground"
                >
                    <span class="truncate">{{ row.client.name }}</span>
                    <ClientClassBadge
                        v-if="row.client.late_payer_class === 'chronic'"
                        :value="row.client.late_payer_class"
                    />
                </p>
                <p class="text-xs text-muted-foreground tabular-nums">
                    {{ pf.money(row.invoice.balance_due) }}
                    <template v-if="row.invoice.days_overdue > 0">
                        ·
                        {{
                            t('pf.invoice.overdue_days', {
                                days: row.invoice.days_overdue,
                            })
                        }}</template
                    >
                </p>
            </template>
            <template #cell-tone="{ row }">
                <ToneChip :tone="row.tone" />
                <p
                    v-if="row.tone_reasons.length"
                    class="mt-1 max-w-56 text-xs text-muted-foreground"
                >
                    {{ pf.reason(row.tone_reasons[0]) }}
                </p>
            </template>
            <template #cell-status="{ row }">
                <StatusBadge
                    :tone="REMINDER_STATUS_TONE[row.status]"
                    :label="t(`pf.reminder_status.${row.status}`)"
                />
                <p
                    v-if="row.skip_reason"
                    class="mt-1 text-xs text-muted-foreground"
                >
                    {{ t(`pf.skip_reason.${row.skip_reason}`) }}
                </p>
                <p
                    v-if="row.error"
                    class="mt-1 max-w-56 truncate text-xs text-destructive"
                    :title="row.error"
                >
                    {{ row.error }}
                </p>
            </template>
            <template #cell-recipient="{ row }">
                <span class="text-xs text-muted-foreground">{{
                    row.recipient_email ?? '—'
                }}</span>
            </template>
            <template #cell-actions="{ row }">
                <span class="flex justify-end gap-1">
                    <Button
                        v-if="row.status !== 'scheduled'"
                        size="icon"
                        variant="ghost"
                        class="size-8"
                        :aria-label="t('pf.reminders.view')"
                        @click="view(row)"
                    >
                        <Eye class="size-4" aria-hidden="true" />
                    </Button>
                    <template v-if="canSend && row.status === 'scheduled'">
                        <Button
                            size="sm"
                            variant="outline"
                            :disabled="Boolean(busy)"
                            :data-test="`send-now-${row.id}`"
                            @click="act('send', row)"
                        >
                            <Spinner
                                v-if="busy === `send-${row.id}`"
                                class="size-4"
                            />
                            <Send v-else class="size-4" aria-hidden="true" />
                            <span class="hidden lg:inline">{{
                                t('pf.reminders.send_now')
                            }}</span>
                        </Button>
                        <Button
                            size="icon"
                            variant="ghost"
                            class="size-8"
                            :disabled="Boolean(busy)"
                            :aria-label="t('pf.reminders.cancel')"
                            @click="act('cancel', row)"
                        >
                            <Spinner
                                v-if="busy === `cancel-${row.id}`"
                                class="size-4"
                            />
                            <CircleX v-else class="size-4" aria-hidden="true" />
                        </Button>
                    </template>
                    <Button
                        v-if="canSend && row.status === 'failed'"
                        size="sm"
                        variant="outline"
                        :disabled="Boolean(busy)"
                        @click="act('retry', row)"
                    >
                        <Spinner
                            v-if="busy === `retry-${row.id}`"
                            class="size-4"
                        />
                        <RotateCw v-else class="size-4" aria-hidden="true" />
                        {{ t('pf.reminders.retry') }}
                    </Button>
                </span>
            </template>
            <template #empty>
                <EmptyState
                    :icon="active() && narrowed() ? SearchX : BellRing"
                    :tone="narrowed() ? 'info' : 'primary'"
                    :title="
                        narrowed()
                            ? t('pf.reminders.empty_filtered')
                            : t(`pf.reminders.empty.${currentTab}`)
                    "
                    :description="
                        narrowed()
                            ? t('ui.common.try_other_filters')
                            : t(`pf.reminders.empty_body.${currentTab}`)
                    "
                >
                    <Button
                        v-if="narrowed()"
                        variant="outline"
                        @click="reset"
                        >{{ t('ui.common.clear_filters') }}</Button
                    >
                    <Button
                        v-else-if="
                            currentTab === 'upcoming' &&
                            pf.can('reminders.configure')
                        "
                        as-child
                    >
                        <Link :href="collections().url">{{
                            t('pf.reminders.setup_policy')
                        }}</Link>
                    </Button>
                </EmptyState>
            </template>
            <template #footer>
                <LaravelPagination
                    :links="reminders.links"
                    :meta="reminders.meta"
                    :count="reminders.data.length"
                    :only="['reminders', 'counts', 'filters', 'tab']"
                />
            </template>
        </DataTable>
    </div>

    <Sheet v-model:open="viewOpen">
        <SheetContent
            side="right"
            class="flex w-full flex-col gap-0 overflow-y-auto sm:max-w-xl"
        >
            <SheetHeader>
                <SheetTitle>{{
                    detail?.subject ?? t('pf.reminders.view')
                }}</SheetTitle>
                <SheetDescription v-if="detail">
                    {{ t('pf.reminder.to') }}:
                    {{ detail.recipient_email ?? '—' }} ·
                    {{
                        fmt.dateTime(
                            detail.sent_at ?? detail.scheduled_for,
                            'medium',
                        )
                    }}
                </SheetDescription>
            </SheetHeader>
            <div class="px-4 pb-6">
                <div v-if="detailHttp.processing" class="space-y-2">
                    <Skeleton class="h-4 w-2/3" />
                    <Skeleton class="h-40 w-full" />
                </div>
                <p
                    v-else-if="detailError"
                    role="alert"
                    class="text-sm text-destructive"
                >
                    {{ detailError }}
                </p>
                <template v-else-if="detail">
                    <div class="mb-3 flex flex-wrap items-center gap-2">
                        <ToneChip :tone="detail.tone" />
                        <StatusBadge
                            :tone="REMINDER_STATUS_TONE[detail.status]"
                            :label="t(`pf.reminder_status.${detail.status}`)"
                        />
                        <span
                            v-if="detail.delivery_attempts > 1"
                            class="text-xs text-muted-foreground"
                            >{{
                                t('pf.reminders.attempts', {
                                    count: detail.delivery_attempts,
                                })
                            }}</span
                        >
                    </div>
                    <div
                        v-if="detail.body_html"
                        class="answer-prose rounded-xl border px-4 py-3 text-sm"
                        data-test="reminder-detail-body"
                        v-html="sanitizeAnswer(detail.body_html)"
                    />
                    <pre
                        v-else-if="detail.body_text"
                        class="rounded-xl border px-4 py-3 text-xs whitespace-pre-wrap"
                        >{{ detail.body_text }}</pre>
                    <p v-else class="text-sm text-muted-foreground">
                        {{ t('pf.reminders.no_body') }}
                    </p>
                </template>
            </div>
        </SheetContent>
    </Sheet>
</template>
