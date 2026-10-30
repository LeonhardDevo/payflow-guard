<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    ArrowRight,
    CircleAlert,
    CircleCheck,
    Columns3,
    FileSpreadsheet,
    Play,
    Settings2,
    TableProperties,
} from '@lucide/vue';
import { useIntervalFn } from '@vueuse/core';
import { computed, watch } from 'vue';
import EmptyState from '@/components/common/EmptyState.vue';
import KpiCard from '@/components/common/KpiCard.vue';
import PageHeader from '@/components/common/PageHeader.vue';
import SectionCard from '@/components/common/SectionCard.vue';
import StatusBadge from '@/components/common/StatusBadge.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Progress } from '@/components/ui/progress';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { usePf } from '@/composables/pf/usePf';
import { useFormat } from '@/composables/useFormat';
import { t } from '@/lib/i18n';
import { IMPORT_STATUS_TONE } from '@/lib/pf/status';
import { activation, index, start } from '@/routes/imports';
import { index as invoicesIndex } from '@/routes/invoices';
import { index as clientsIndex } from '@/routes/clients';
import type { ImportDetail, ImportShowProps } from '@/types/payflow';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'pf.nav.imports', href: index() }],
    },
});

const props = defineProps<ImportShowProps>();
const pf = usePf();
const fmt = useFormat();

const imp = computed(() => props.import);
const editable = computed(() =>
    ['uploaded', 'mapping'].includes(imp.value.status),
);
const running = computed(() =>
    ['queued', 'processing'].includes(imp.value.status),
);
const finished = computed(() =>
    ['completed', 'completed_with_errors', 'failed'].includes(imp.value.status),
);

const NONE = '__none__';

const form = useForm({
    mapping: Object.fromEntries(
        props.import.fields.map((field) => [
            field.key,
            props.import.mapping[field.key] ?? NONE,
        ]),
    ) as Record<string, string>,
    options: { ...props.import.options } as ImportDetail['options'],
});

const missingRequired = computed(() =>
    imp.value.fields
        .filter((field) => field.required && form.mapping[field.key] === NONE)
        .map((f) => f.key),
);

function applySuggested(): void {
    for (const field of imp.value.fields) {
        form.mapping[field.key] =
            imp.value.suggested_mapping[field.key] ?? NONE;
    }
}

function submit(): void {
    form.transform((data) => ({
        mapping: Object.fromEntries(
            Object.entries(data.mapping).map(([k, v]) => [
                k,
                v === NONE ? null : v,
            ]),
        ),
        options: data.options,
    })).post(start(imp.value.id).url, { preserveScroll: true });
}

const dateFormats: Array<ImportDetail['options']['date_format']> = [
    'Y-m-d',
    'd.m.Y',
    'd/m/Y',
    'm/d/Y',
];
const dateExample: Record<ImportDetail['options']['date_format'], string> = {
    'Y-m-d': '2026-10-23',
    'd.m.Y': '23.10.2026',
    'd/m/Y': '23/10/2026',
    'm/d/Y': '10/23/2026',
};

const headerIndex = (header: string) => imp.value.headers.indexOf(header);
const sampleFor = (key: string): string | null => {
    const header = form.mapping[key];

    if (!header || header === NONE) {
        return null;
    }

    const i = headerIndex(header);

    return (
        imp.value.sample_rows.find((row) => (row[i] ?? '').trim() !== '')?.[
            i
        ] ?? null
    );
};

const { pause: stopPoll, resume: startPoll } = useIntervalFn(
    () => router.reload({ only: ['import'] }),
    2000,
    { immediate: false },
);
watch(running, (value) => (value ? startPoll() : stopPoll()), {
    immediate: true,
});

const canActivate = computed(
    () =>
        imp.value.kind === 'invoices' &&
        imp.value.rows_imported > 0 &&
        imp.value.status !== 'failed' &&
        pf.can('reminders.configure'),
);

const errorRows = computed(() => imp.value.errors.slice(0, 200));
</script>

<template>
    <Head :title="imp.original_name" />

    <div
        class="mx-auto flex w-full max-w-6xl flex-col gap-6 px-4 py-6 sm:px-6 lg:py-8"
    >
        <PageHeader
            :icon="FileSpreadsheet"
            :title="imp.original_name"
            :description="`${t(`pf.imports.kinds.${imp.kind}`)} · ${t(`pf.imports.presets.${imp.preset}`)}`"
        >
            <template #meta>
                <StatusBadge
                    :tone="IMPORT_STATUS_TONE[imp.status]"
                    :label="t(`pf.imports.status.${imp.status}`)"
                    :pulse="running"
                    data-test="import-status"
                    :data-status="imp.status"
                />
            </template>
        </PageHeader>

        <template v-if="editable">
            <SectionCard
                :icon="Columns3"
                :title="t('pf.imports.mapping')"
                :description="t('pf.imports.mapping_hint')"
            >
                <template #actions>
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        @click="applySuggested"
                        >{{ t('pf.imports.use_suggested') }}</Button
                    >
                </template>
                <ul class="divide-y" data-test="import-mapping">
                    <li
                        v-for="field in imp.fields"
                        :key="field.key"
                        class="grid grid-cols-1 items-center gap-2 py-3 sm:grid-cols-[1fr_auto_1fr_1fr]"
                    >
                        <Label :for="`map-${field.key}`" class="font-medium">
                            {{ t(`pf.imports.fields.${field.key}`) }}
                            <span
                                v-if="field.required"
                                class="text-destructive"
                                :aria-label="t('pf.imports.required')"
                                >*</span
                            >
                        </Label>
                        <ArrowRight
                            class="hidden size-4 text-muted-foreground sm:block"
                            aria-hidden="true"
                        />
                        <Select v-model="form.mapping[field.key]">
                            <SelectTrigger
                                :id="`map-${field.key}`"
                                class="w-full"
                                :aria-invalid="
                                    field.required &&
                                    form.mapping[field.key] === NONE
                                "
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem :value="NONE">{{
                                    t('pf.imports.not_mapped')
                                }}</SelectItem>
                                <SelectItem
                                    v-for="header in imp.headers"
                                    :key="header"
                                    :value="header"
                                    >{{ header }}</SelectItem
                                >
                            </SelectContent>
                        </Select>
                        <span
                            class="truncate font-mono text-xs text-muted-foreground"
                            :title="sampleFor(field.key) ?? ''"
                            >{{ sampleFor(field.key) ?? '—' }}</span
                        >
                    </li>
                </ul>
                <InputError :message="form.errors.mapping" />
                <p
                    v-if="missingRequired.length"
                    role="alert"
                    class="mt-3 flex items-center gap-2 text-sm text-destructive"
                >
                    <CircleAlert class="size-4" aria-hidden="true" />
                    {{
                        t('pf.imports.missing_required', {
                            fields: missingRequired
                                .map((k) => t(`pf.imports.fields.${k}`))
                                .join(', '),
                        })
                    }}
                </p>
            </SectionCard>

            <SectionCard :icon="Settings2" :title="t('pf.imports.options')">
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="opt-currency">{{
                            t('pf.imports.default_currency')
                        }}</Label>
                        <Select v-model="form.options.default_currency">
                            <SelectTrigger id="opt-currency" class="w-full"
                                ><SelectValue
                            /></SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="code in currencies"
                                    :key="code"
                                    :value="code"
                                    >{{ code }}</SelectItem
                                >
                            </SelectContent>
                        </Select>
                        <p class="text-xs text-muted-foreground">
                            {{ t('pf.imports.default_currency_hint') }}
                        </p>
                    </div>
                    <div class="grid gap-2">
                        <Label for="opt-date">{{
                            t('pf.imports.date_format')
                        }}</Label>
                        <Select v-model="form.options.date_format">
                            <SelectTrigger id="opt-date" class="w-full"
                                ><SelectValue
                            /></SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="format in dateFormats"
                                    :key="format"
                                    :value="format"
                                    >{{ dateExample[format] }}</SelectItem
                                >
                            </SelectContent>
                        </Select>
                        <InputError
                            :message="
                                (form.errors as Record<string, string>)[
                                    'options.date_format'
                                ]
                            "
                        />
                    </div>
                    <template v-if="imp.kind === 'invoices'">
                        <Label
                            class="flex items-start gap-3 text-sm font-normal"
                        >
                            <Checkbox
                                v-model="form.options.create_missing_clients"
                                class="mt-0.5"
                            />
                            <span>
                                {{ t('pf.imports.create_missing_clients') }}
                                <span
                                    class="block text-xs text-muted-foreground"
                                    >{{
                                        t(
                                            'pf.imports.create_missing_clients_hint',
                                        )
                                    }}</span
                                >
                            </span>
                        </Label>
                        <Label
                            class="flex items-start gap-3 text-sm font-normal"
                        >
                            <Checkbox
                                v-model="form.options.mark_as_sent"
                                class="mt-0.5"
                            />
                            <span>
                                {{ t('pf.imports.mark_as_sent') }}
                                <span
                                    class="block text-xs text-muted-foreground"
                                    >{{
                                        t('pf.imports.mark_as_sent_hint')
                                    }}</span
                                >
                            </span>
                        </Label>
                    </template>
                </div>
            </SectionCard>

            <SectionCard
                :icon="TableProperties"
                :title="t('pf.imports.sample')"
                flush
            >
                <EmptyState
                    v-if="!imp.sample_rows.length"
                    compact
                    :icon="TableProperties"
                    tone="muted"
                    :title="t('pf.imports.no_sample')"
                />
                <div v-else class="overflow-x-auto">
                    <table class="w-full text-xs">
                        <caption class="sr-only">
                            {{
                                t('pf.imports.sample')
                            }}
                        </caption>
                        <thead class="border-b bg-muted/40 text-left">
                            <tr>
                                <th
                                    v-for="header in imp.headers"
                                    :key="header"
                                    class="px-3 py-2 font-medium whitespace-nowrap"
                                >
                                    {{ header }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="(row, i) in imp.sample_rows"
                                :key="i"
                                class="border-b last:border-0"
                            >
                                <td
                                    v-for="(cell, j) in row"
                                    :key="j"
                                    class="max-w-48 truncate px-3 py-2 font-mono"
                                >
                                    {{ cell }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </SectionCard>

            <div
                class="sticky bottom-0 z-10 -mx-4 flex flex-wrap items-center justify-between gap-3 border-t bg-background/90 px-4 py-3 backdrop-blur sm:mx-0 sm:rounded-2xl sm:border"
            >
                <p class="text-xs text-muted-foreground">
                    {{
                        t('pf.imports.rows_detected', { count: imp.rows_total })
                    }}
                </p>
                <Button
                    :disabled="form.processing || missingRequired.length > 0"
                    data-test="import-start"
                    @click="submit"
                >
                    <Spinner v-if="form.processing" class="size-4" />
                    <Play v-else class="size-4" aria-hidden="true" />
                    {{ t('pf.imports.start') }}
                </Button>
            </div>
        </template>

        <SectionCard v-else-if="running" :title="t('pf.imports.processing')">
            <div class="space-y-3" role="status" aria-live="polite">
                <Progress
                    :model-value="Math.round(imp.progress * 100)"
                    :aria-label="t('pf.imports.processing')"
                />
                <p class="text-sm text-muted-foreground tabular-nums">
                    {{ fmt.percent(imp.progress) }} ·
                    {{
                        t('pf.imports.rows_summary', {
                            imported: imp.rows_imported,
                            total: imp.rows_total,
                        })
                    }}
                </p>
            </div>
        </SectionCard>

        <template v-if="finished">
            <div
                class="grid grid-cols-2 gap-3 lg:grid-cols-4"
                data-test="import-result"
            >
                <KpiCard
                    :label="t('pf.imports.total_rows')"
                    :value="imp.rows_total"
                    :icon="FileSpreadsheet"
                />
                <KpiCard
                    :label="t('pf.imports.imported')"
                    :value="imp.rows_imported"
                    :icon="CircleCheck"
                />
                <KpiCard
                    :label="t('pf.imports.skipped')"
                    :value="imp.rows_skipped"
                    :icon="ArrowRight"
                    tone="info"
                />
                <KpiCard
                    :label="t('pf.imports.failed')"
                    :value="imp.rows_failed"
                    :icon="CircleAlert"
                    tone="warning"
                />
            </div>

            <div
                v-if="imp.kind === 'invoices' && imp.rows_imported > 0"
                class="flex flex-col gap-3 rounded-2xl border border-info/30 bg-info-soft p-4 text-sm text-info-soft-foreground sm:flex-row sm:items-center sm:justify-between"
            >
                <div class="flex items-start gap-3">
                    <CircleAlert
                        class="mt-0.5 size-5 shrink-0"
                        aria-hidden="true"
                    />
                    <div>
                        <p class="font-medium">
                            {{
                                t(
                                    `pf.imports.activation_title.${imp.activation_status}`,
                                )
                            }}
                        </p>
                        <p>
                            {{
                                t(
                                    `pf.imports.activation_body.${imp.activation_status}`,
                                )
                            }}
                        </p>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button variant="outline" size="sm" as-child>
                        <Link :href="invoicesIndex().url">{{
                            t('pf.imports.view_invoices')
                        }}</Link>
                    </Button>
                    <Button
                        v-if="
                            canActivate && imp.activation_status !== 'activated'
                        "
                        size="sm"
                        as-child
                    >
                        <Link
                            :href="activation(imp.id).url"
                            data-test="import-activation-link"
                            >{{ t('pf.imports.review_activation') }}</Link
                        >
                    </Button>
                </div>
            </div>
            <div
                v-else-if="imp.kind === 'clients' && imp.rows_imported > 0"
                class="flex justify-end"
            >
                <Button variant="outline" size="sm" as-child>
                    <Link :href="clientsIndex().url">{{
                        t('pf.imports.view_clients')
                    }}</Link>
                </Button>
            </div>

            <SectionCard
                :icon="CircleAlert"
                :title="t('pf.imports.problems')"
                flush
            >
                <EmptyState
                    v-if="!imp.errors.length"
                    compact
                    :icon="CircleCheck"
                    :title="t('pf.imports.no_problems')"
                />
                <div v-else class="overflow-x-auto">
                    <table class="w-full text-sm" data-test="import-errors">
                        <caption class="sr-only">
                            {{
                                t('pf.imports.problems')
                            }}
                        </caption>
                        <thead
                            class="border-b text-left text-xs text-muted-foreground"
                        >
                            <tr>
                                <th class="px-5 py-2 font-medium">
                                    {{ t('pf.imports.row') }}
                                </th>
                                <th class="px-3 py-2 font-medium">
                                    {{ t('pf.imports.field') }}
                                </th>
                                <th class="px-5 py-2 font-medium">
                                    {{ t('pf.imports.problem') }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="(error, i) in errorRows"
                                :key="i"
                                class="border-b last:border-0"
                                :data-code="error.code"
                            >
                                <td class="px-5 py-2 tabular-nums">
                                    {{ error.row }}
                                </td>
                                <td class="px-3 py-2">
                                    {{
                                        error.field
                                            ? t(
                                                  `pf.imports.fields.${error.field}`,
                                              )
                                            : '—'
                                    }}
                                </td>
                                <td class="px-5 py-2">
                                    {{ t(`pf.imports.errors.${error.code}`) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p
                        v-if="imp.errors.length > errorRows.length"
                        class="px-5 py-3 text-xs text-muted-foreground"
                    >
                        {{
                            t('pf.imports.more_errors', {
                                count: imp.errors.length - errorRows.length,
                            })
                        }}
                    </p>
                </div>
            </SectionCard>
        </template>
    </div>
</template>
