<script setup lang="ts">
import { Head, Link, router, usePoll } from '@inertiajs/vue3';
import {
    BookOpenText,
    Download,
    Eye,
    FilterX,
    MoreHorizontal,
    RefreshCw,
    SearchX,
    Trash2,
    Upload,
} from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import ConfirmDialog from '@/components/common/ConfirmDialog.vue';
import type { DataColumn } from '@/components/common/DataTable.vue';
import DataTable from '@/components/common/DataTable.vue';
import EmptyState from '@/components/common/EmptyState.vue';
import LaravelPagination from '@/components/common/LaravelPagination.vue';
import PageHeader from '@/components/common/PageHeader.vue';
import RelativeTime from '@/components/common/RelativeTime.vue';
import SearchInput from '@/components/common/SearchInput.vue';
import UsageMeter from '@/components/common/UsageMeter.vue';
import DocumentStatusBadge from '@/components/knowledge/DocumentStatusBadge.vue';
import FileTypeIcon from '@/components/knowledge/FileTypeIcon.vue';
import UploadDialog from '@/components/knowledge/UploadDialog.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useCan } from '@/composables/useCan';
import { useFilters } from '@/composables/useFilters';
import { useFormat } from '@/composables/useFormat';
import { usageState } from '@/lib/billing';
import { endpoints } from '@/lib/endpoints';
import { t, tc, tOr } from '@/lib/i18n';
import type {
    KbDocument,
    KbDocumentStatus,
    KbFilters,
    Paginated,
    SelectOption,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'ui.nav.knowledge', href: endpoints.knowledge.index() },
        ],
    },
});

const props = withDefaults(
    defineProps<{
        documents: Paginated<KbDocument>;
        filters: KbFilters;
        departments: string[];
        roles: SelectOption[];
        limits: { used: number; limit: number | null };
        accept: string[];
        maxSizeMb: number;
    }>(),
    { departments: () => [], roles: () => [], accept: () => [] },
);

const fmt = useFormat();
const { can } = useCan();
const canManage = computed(() => can('knowledge.manage', 'workspace.manage'));

const uploadOpen = ref(false);

onMounted(() => {
    if (
        canManage.value &&
        new URLSearchParams(window.location.search).get('upload') === '1'
    ) {
        uploadOpen.value = true;
    }
});

const { filters, reset, active } = useFilters(
    () => endpoints.knowledge.index().url,
    {
        q: props.filters?.q ?? null,
        status: props.filters?.status ?? null,
        department: props.filters?.department ?? null,
    },
    ['documents', 'filters'],
);

const statusModel = computed({
    get: () => filters.status ?? 'all',
    set: (value: string) => {
        filters.status = value === 'all' ? null : (value as KbDocumentStatus);
    },
});

const departmentModel = computed({
    get: () => filters.department ?? 'all',
    set: (value: string) => {
        filters.department = value === 'all' ? null : value;
    },
});

const inFlight = computed(
    () =>
        props.documents.data.filter(
            (doc) => doc.status === 'queued' || doc.status === 'processing',
        ).length,
);

const poll = usePoll(
    4000,
    { only: ['documents', 'limits'] },
    { autoStart: false },
);

watch(
    inFlight,
    (count) => {
        if (count > 0) {
            poll.start();
        } else {
            poll.stop();
        }
    },
    { immediate: true },
);

onBeforeUnmount(() => poll.stop());

const limitReached = computed(
    () => usageState(props.limits).level === 'critical',
);

const columns = computed<DataColumn<KbDocument>[]>(() => [
    { id: 'title', header: t('ui.knowledge.fields.title'), sortable: true },
    { id: 'status', header: t('ui.common.status'), sortable: true },
    {
        id: 'department',
        header: t('ui.knowledge.fields.department'),
        sortable: true,
        hideBelow: 'lg',
    },
    {
        id: 'chunks',
        header: t('ui.knowledge.indexed'),
        sortable: true,
        hideBelow: 'md',
        align: 'right',
    },
    {
        id: 'size_bytes',
        header: t('ui.knowledge.size'),
        sortable: true,
        hideBelow: 'lg',
        align: 'right',
    },
    {
        id: 'updated_at',
        header: t('ui.knowledge.updated'),
        sortable: true,
        hideBelow: 'sm',
    },
    {
        id: 'actions',
        header: t('ui.common.actions'),
        srOnlyHeader: true,
        align: 'right',
    },
]);

const deleteTarget = ref<KbDocument | null>(null);
const deleting = ref(false);
const deleteOpen = computed({
    get: () => deleteTarget.value !== null,
    set: (value) => {
        if (!value) {
            deleteTarget.value = null;
        }
    },
});

function destroyDocument(): void {
    if (!deleteTarget.value) {
        return;
    }

    router.delete(endpoints.knowledge.destroy(deleteTarget.value.id).url, {
        preserveScroll: true,
        onStart: () => {
            deleting.value = true;
        },
        onFinish: () => {
            deleting.value = false;
            deleteTarget.value = null;
        },
    });
}

function open(doc: KbDocument): void {
    router.visit(endpoints.knowledge.show(doc.id).url);
}

const statusOptions = ['queued', 'processing', 'indexed', 'failed'] as const;
</script>

<template>
    <Head :title="t('ui.knowledge.title')" />

    <div
        class="mx-auto flex w-full max-w-7xl flex-col gap-6 px-4 py-6 sm:px-6 lg:py-8"
    >
        <PageHeader
            :icon="BookOpenText"
            :title="t('ui.knowledge.title')"
            :description="t('ui.knowledge.description')"
        >
            <template #actions>
                <Button
                    v-if="canManage"
                    data-test="kb-upload-button"
                    @click="uploadOpen = true"
                >
                    <Upload class="size-4" aria-hidden="true" />
                    {{ t('ui.knowledge.upload.cta') }}
                </Button>
            </template>
        </PageHeader>

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-[minmax(0,1fr)_20rem]">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                <SearchInput
                    v-model="filters.q"
                    :placeholder="t('ui.knowledge.search')"
                />
                <div class="flex flex-wrap items-center gap-2">
                    <Select v-model="statusModel">
                        <SelectTrigger
                            class="w-40"
                            :aria-label="t('ui.common.status')"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">{{
                                t('ui.knowledge.all_statuses')
                            }}</SelectItem>
                            <SelectItem
                                v-for="status in statusOptions"
                                :key="status"
                                :value="status"
                            >
                                {{ t(`ui.knowledge.status.${status}`) }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <Select v-if="departments.length" v-model="departmentModel">
                        <SelectTrigger
                            class="w-44"
                            :aria-label="t('ui.knowledge.fields.department')"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">{{
                                t('ui.knowledge.all_departments')
                            }}</SelectItem>
                            <SelectItem
                                v-for="department in departments"
                                :key="department"
                                :value="department"
                            >
                                {{ department }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <Button
                        v-if="active()"
                        variant="ghost"
                        size="sm"
                        @click="reset"
                    >
                        <FilterX class="size-4" aria-hidden="true" />
                        {{ t('ui.common.clear_filters') }}
                    </Button>
                </div>
            </div>
            <div class="rounded-2xl border bg-card px-4 py-3 shadow-soft">
                <UsageMeter
                    :label="t('ui.knowledge.limit_label')"
                    :entry="limits"
                />
            </div>
        </div>

        <p
            v-if="inFlight > 0"
            class="flex items-center gap-2 rounded-xl border border-ai/25 bg-ai-soft px-4 py-2.5 text-sm text-ai-soft-foreground"
            role="status"
            aria-live="polite"
        >
            <RefreshCw
                class="size-4 motion-safe:animate-spin"
                aria-hidden="true"
            />
            {{ tc('ui.knowledge.processing_banner', inFlight) }}
        </p>

        <DataTable
            :columns="columns"
            :rows="documents.data"
            :row-key="(doc) => doc.id"
            :row-attrs="
                (doc) => ({
                    'data-test': 'kb-row',
                    'data-title': doc.title,
                    'data-status': doc.status,
                })
            "
            :caption="t('ui.knowledge.table_caption')"
            clickable
            @row-click="open"
        >
            <template #cell-title="{ row }">
                <div class="flex max-w-md min-w-56 items-center gap-3">
                    <FileTypeIcon :mime="row.mime" :name="row.original_name" />
                    <div class="min-w-0">
                        <Link
                            :href="endpoints.knowledge.show(row.id)"
                            class="block truncate font-medium hover:underline"
                        >
                            {{ row.title }}
                        </Link>
                        <p
                            class="flex items-center gap-1.5 truncate text-xs text-muted-foreground"
                        >
                            <span class="truncate">{{
                                row.original_name
                            }}</span>
                            <span
                                v-if="row.version"
                                class="shrink-0 rounded bg-muted px-1 font-mono"
                                >{{ row.version }}</span
                            >
                        </p>
                    </div>
                </div>
            </template>
            <template #cell-status="{ row }">
                <DocumentStatusBadge :status="row.status" />
                <p
                    v-if="row.status === 'failed' && row.error"
                    class="mt-1 max-w-48 truncate text-xs text-destructive"
                    :title="row.error"
                >
                    {{ row.error }}
                </p>
            </template>
            <template #cell-department="{ row }">
                <div class="flex flex-wrap gap-1">
                    <span
                        v-if="row.department"
                        class="rounded-md bg-muted px-2 py-0.5 text-xs font-medium"
                        >{{ row.department }}</span
                    >
                    <span
                        v-for="role in row.roles.slice(0, 2)"
                        :key="role"
                        class="rounded-md bg-info-soft px-2 py-0.5 text-xs font-medium text-info-soft-foreground"
                        >{{ tOr(`roles.${role}`, role) }}</span
                    >
                    <span
                        v-if="!row.department && !row.roles.length"
                        class="text-xs text-muted-foreground"
                        >{{ t('ui.knowledge.everyone') }}</span
                    >
                </div>
            </template>
            <template #cell-chunks="{ row }">
                <span
                    v-if="row.status === 'indexed'"
                    class="text-sm tabular-nums"
                >
                    {{ tc('ui.knowledge.chunks', row.chunks ?? 0) }}
                    <span
                        v-if="row.pages"
                        class="block text-xs text-muted-foreground"
                        >{{ tc('ui.knowledge.pages', row.pages) }}</span
                    >
                </span>
                <span v-else class="text-muted-foreground">—</span>
            </template>
            <template #cell-size_bytes="{ row }">
                <span class="text-muted-foreground tabular-nums">{{
                    fmt.bytes(row.size_bytes)
                }}</span>
            </template>
            <template #cell-updated_at="{ row }">
                <span class="text-muted-foreground"
                    ><RelativeTime :value="row.updated_at"
                /></span>
            </template>
            <template #cell-actions="{ row }">
                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <Button
                            variant="ghost"
                            size="icon-sm"
                            :aria-label="
                                t('ui.knowledge.actions_for', {
                                    title: row.title,
                                })
                            "
                        >
                            <MoreHorizontal />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="w-44">
                        <DropdownMenuItem as-child>
                            <Link :href="endpoints.knowledge.show(row.id)">
                                <Eye class="size-4" aria-hidden="true" />
                                {{ t('ui.common.view') }}
                            </Link>
                        </DropdownMenuItem>
                        <DropdownMenuItem v-if="row.download_url" as-child>
                            <a :href="row.download_url" download>
                                <Download class="size-4" aria-hidden="true" />
                                {{ t('ui.common.download') }}
                            </a>
                        </DropdownMenuItem>
                        <template v-if="canManage">
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                                class="text-destructive focus:text-destructive"
                                @select="deleteTarget = row"
                            >
                                <Trash2 class="size-4" aria-hidden="true" />
                                {{ t('ui.common.delete') }}
                            </DropdownMenuItem>
                        </template>
                    </DropdownMenuContent>
                </DropdownMenu>
            </template>
            <template #empty>
                <EmptyState
                    :icon="active() ? SearchX : BookOpenText"
                    :title="
                        active()
                            ? t('ui.knowledge.empty_filtered')
                            : t('ui.knowledge.empty_title')
                    "
                    :description="
                        active()
                            ? t('ui.common.try_other_filters')
                            : t('ui.knowledge.empty_body')
                    "
                >
                    <Button v-if="active()" variant="outline" @click="reset">{{
                        t('ui.common.clear_filters')
                    }}</Button>
                    <Button v-else-if="canManage" @click="uploadOpen = true">
                        <Upload class="size-4" aria-hidden="true" />
                        {{ t('ui.knowledge.upload.cta') }}
                    </Button>
                </EmptyState>
            </template>
            <template #footer>
                <LaravelPagination
                    :links="documents.links"
                    :meta="documents.meta"
                    :count="documents.data.length"
                    :only="['documents', 'filters']"
                />
            </template>
        </DataTable>
    </div>

    <UploadDialog
        v-if="canManage"
        v-model:open="uploadOpen"
        :accept="accept"
        :max-size-mb="maxSizeMb"
        :departments="departments"
        :roles="roles"
        :limit-reached="limitReached"
    />

    <ConfirmDialog
        v-model:open="deleteOpen"
        destructive
        :processing="deleting"
        :title="t('ui.knowledge.delete_title')"
        :description="
            t('ui.knowledge.delete_body', { title: deleteTarget?.title ?? '' })
        "
        :confirm-label="t('ui.common.delete')"
        @confirm="destroyDocument"
    />
</template>
