<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Download, FileSpreadsheet, FileUp, Lock, Upload } from '@lucide/vue';
import { computed, ref } from 'vue';
import type { DataColumn } from '@/components/common/DataTable.vue';
import DataTable from '@/components/common/DataTable.vue';
import EmptyState from '@/components/common/EmptyState.vue';
import LaravelPagination from '@/components/common/LaravelPagination.vue';
import PageHeader from '@/components/common/PageHeader.vue';
import SectionCard from '@/components/common/SectionCard.vue';
import SegmentedControl from '@/components/common/SegmentedControl.vue';
import StatusBadge from '@/components/common/StatusBadge.vue';
import UsageMeter from '@/components/common/UsageMeter.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { useFormat } from '@/composables/useFormat';
import { t } from '@/lib/i18n';
import { IMPORT_STATUS_TONE } from '@/lib/pf/status';
import { index, show, store, template } from '@/routes/imports';
import { billing } from '@/routes/settings';
import type {
    ImportKind,
    ImportListItem,
    ImportPreset,
    ImportsIndexProps,
} from '@/types/payflow';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'pf.nav.imports', href: index() }],
    },
});

const props = defineProps<ImportsIndexProps>();
const fmt = useFormat();

const form = useForm<{
    file: File | null;
    kind: ImportKind;
    preset: ImportPreset;
}>({
    file: null,
    kind: 'invoices',
    preset: 'generic',
});

const kindOptions = computed(() =>
    props.kinds.map((k) => ({ value: k.value, label: k.label })),
);

const dragging = ref(false);
const input = ref<HTMLInputElement | null>(null);

const overLimit = computed(() => {
    const entry = props.limits.imports_per_month;

    return entry.limit !== null && entry.used >= entry.limit;
});

function pick(files: FileList | null | undefined): void {
    const file = files?.[0] ?? null;
    form.clearErrors('file');

    if (file && file.size > 5 * 1024 * 1024) {
        form.setError('file', t('pf.imports.too_large'));
        form.file = null;

        return;
    }

    form.file = file;
}

function onDrop(event: DragEvent): void {
    dragging.value = false;
    pick(event.dataTransfer?.files);
}

function submit(): void {
    form.post(store().url, { forceFormData: true });
}

const columns = computed<DataColumn<ImportListItem>[]>(() => [
    { id: 'file', header: t('pf.imports.file') },
    { id: 'status', header: t('pf.invoice.status') },
    { id: 'rows', header: t('pf.imports.rows'), hideBelow: 'sm' },
    { id: 'created', header: t('pf.imports.uploaded'), hideBelow: 'md' },
]);
</script>

<template>
    <Head :title="t('pf.imports.title')" />

    <div
        class="mx-auto flex w-full max-w-7xl flex-col gap-6 px-4 py-6 sm:px-6 lg:py-8"
    >
        <PageHeader
            :icon="Upload"
            :title="t('pf.imports.title')"
            :description="t('pf.imports.description')"
        />

        <SectionCard :icon="FileUp" :title="t('pf.imports.new')">
            <div
                v-if="!entitled"
                class="flex flex-col items-center gap-3 py-6 text-center"
                data-test="imports-locked"
            >
                <span
                    class="inline-flex size-10 items-center justify-center rounded-full bg-ai-soft text-ai-soft-foreground"
                >
                    <Lock class="size-5" aria-hidden="true" />
                </span>
                <p class="max-w-md text-sm text-muted-foreground">
                    {{ t('pf.imports.not_entitled') }}
                </p>
                <Button size="sm" as-child>
                    <Link :href="billing().url">{{
                        t('pf.analytics.upgrade')
                    }}</Link>
                </Button>
            </div>
            <form
                v-else
                class="grid grid-cols-1 gap-6 lg:grid-cols-[1fr_18rem]"
                data-test="import-upload"
                @submit.prevent="submit"
            >
                <div class="flex flex-col gap-4">
                    <label
                        for="import-file"
                        :class="[
                            'flex cursor-pointer flex-col items-center justify-center gap-2 rounded-2xl border-2 border-dashed px-6 py-10 text-center transition-colors focus-within:ring-2 focus-within:ring-ring',
                            dragging
                                ? 'border-primary bg-primary/5'
                                : 'hover:bg-muted/40',
                            form.errors.file ? 'border-destructive' : '',
                        ]"
                        @dragover.prevent="dragging = true"
                        @dragleave.prevent="dragging = false"
                        @drop.prevent="onDrop"
                    >
                        <FileSpreadsheet
                            class="size-8 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <span v-if="form.file" class="text-sm font-medium"
                            >{{ form.file.name }} ·
                            {{ fmt.bytes(form.file.size) }}</span
                        >
                        <span v-else class="text-sm font-medium">{{
                            t('pf.imports.drop')
                        }}</span>
                        <span class="text-xs text-muted-foreground">{{
                            t('pf.imports.drop_hint')
                        }}</span>
                        <input
                            id="import-file"
                            ref="input"
                            type="file"
                            accept=".csv,.txt,text/csv,text/plain"
                            class="sr-only"
                            data-test="import-file"
                            @change="
                                pick(($event.target as HTMLInputElement).files)
                            "
                        />
                    </label>
                    <InputError :message="form.errors.file" />
                    <p class="text-xs text-muted-foreground">
                        {{ t('pf.imports.safety') }}
                    </p>
                </div>
                <div class="flex flex-col gap-4">
                    <div class="grid gap-2">
                        <span class="text-sm font-medium">{{
                            t('pf.imports.kind')
                        }}</span>
                        <SegmentedControl
                            v-model="form.kind"
                            :options="kindOptions"
                            :label="t('pf.imports.kind')"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="import-preset">{{
                            t('pf.imports.source')
                        }}</Label>
                        <Select v-model="form.preset">
                            <SelectTrigger id="import-preset" class="w-full"
                                ><SelectValue
                            /></SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="preset in presets"
                                    :key="preset.value"
                                    :value="preset.value"
                                    >{{ preset.label }}</SelectItem
                                >
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.preset" />
                    </div>
                    <a
                        :href="template(form.kind).url"
                        class="inline-flex items-center gap-1.5 text-sm text-primary hover:underline"
                    >
                        <Download class="size-4" aria-hidden="true" />
                        {{ t('pf.imports.template') }}
                    </a>
                    <UsageMeter
                        v-if="limits.imports_per_month.limit !== null"
                        :label="t('pf.limits.imports_per_month')"
                        :entry="limits.imports_per_month"
                    />
                    <Button
                        type="submit"
                        :disabled="!form.file || form.processing || overLimit"
                        data-test="import-submit"
                    >
                        <Spinner v-if="form.processing" class="size-4" />
                        <Upload v-else class="size-4" aria-hidden="true" />
                        {{ t('pf.imports.upload') }}
                    </Button>
                    <p
                        v-if="form.progress"
                        class="text-xs text-muted-foreground tabular-nums"
                    >
                        {{ form.progress.percentage }}%
                    </p>
                </div>
            </form>
        </SectionCard>

        <DataTable
            :columns="columns"
            :rows="imports.data"
            :row-key="(row) => row.id"
            :row-attrs="
                (row) => ({
                    'data-test': 'import-row',
                    'data-status': row.status,
                })
            "
            :caption="t('pf.imports.history')"
            clickable
            @row-click="(row) => router.visit(show(row.id).url)"
        >
            <template #cell-file="{ row }">
                <Link
                    :href="show(row.id).url"
                    class="font-medium hover:underline"
                    @click.stop
                    >{{ row.original_name }}</Link
                >
                <p class="text-xs text-muted-foreground">
                    {{ t(`pf.imports.kinds.${row.kind}`) }} ·
                    {{ t(`pf.imports.presets.${row.preset}`) }}
                </p>
            </template>
            <template #cell-status="{ row }">
                <StatusBadge
                    :tone="IMPORT_STATUS_TONE[row.status]"
                    :label="t(`pf.imports.status.${row.status}`)"
                    :pulse="row.status === 'processing'"
                />
                <p
                    v-if="
                        row.kind === 'invoices' &&
                        row.rows_imported > 0 &&
                        ['completed', 'completed_with_errors'].includes(
                            row.status,
                        )
                    "
                    class="mt-1 text-xs text-muted-foreground"
                >
                    {{ t(`pf.imports.activation.${row.activation_status}`) }}
                </p>
            </template>
            <template #cell-rows="{ row }">
                <span class="text-sm tabular-nums">{{
                    t('pf.imports.rows_summary', {
                        imported: row.rows_imported,
                        total: row.rows_total,
                    })
                }}</span>
                <p
                    v-if="row.rows_skipped || row.rows_failed"
                    class="text-xs text-muted-foreground tabular-nums"
                >
                    {{
                        t('pf.imports.skipped_failed', {
                            skipped: row.rows_skipped,
                            failed: row.rows_failed,
                        })
                    }}
                </p>
            </template>
            <template #cell-created="{ row }">
                <span class="text-sm">{{
                    fmt.dateTime(row.created_at, 'short')
                }}</span>
                <p class="text-xs text-muted-foreground">
                    {{ row.created_by.name }}
                </p>
            </template>
            <template #empty>
                <EmptyState
                    :icon="FileSpreadsheet"
                    tone="muted"
                    :title="t('pf.imports.empty_title')"
                    :description="t('pf.imports.empty_body')"
                />
            </template>
            <template #footer>
                <LaravelPagination
                    :links="imports.links"
                    :meta="imports.meta"
                    :count="imports.data.length"
                    :only="['imports']"
                />
            </template>
        </DataTable>
    </div>
</template>
