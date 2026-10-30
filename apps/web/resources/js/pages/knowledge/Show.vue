<script setup lang="ts">
import {
    Head,
    router,
    setLayoutProps,
    useForm,
    usePoll,
} from '@inertiajs/vue3';
import {
    Download,
    FileSearch,
    Layers,
    Pencil,
    ScanText,
    Trash2,
} from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch, watchEffect } from 'vue';
import ConfirmDialog from '@/components/common/ConfirmDialog.vue';
import EmptyState from '@/components/common/EmptyState.vue';
import ErrorState from '@/components/common/ErrorState.vue';
import RelativeTime from '@/components/common/RelativeTime.vue';
import SectionCard from '@/components/common/SectionCard.vue';
import type { DocumentMeta } from '@/components/knowledge/DocumentMetaFields.vue';
import DocumentMetaFields from '@/components/knowledge/DocumentMetaFields.vue';
import DocumentStatusBadge from '@/components/knowledge/DocumentStatusBadge.vue';
import FileTypeIcon from '@/components/knowledge/FileTypeIcon.vue';
import PipelineSteps from '@/components/knowledge/PipelineSteps.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { useCan } from '@/composables/useCan';
import { useFormat } from '@/composables/useFormat';
import { endpoints } from '@/lib/endpoints';
import { t, tc, tOr } from '@/lib/i18n';
import type { ChunkPreview, KbDocument, SelectOption } from '@/types';

const props = withDefaults(
    defineProps<{
        document: KbDocument;
        chunksPreview?: ChunkPreview[];
        departments?: string[];
        roles?: SelectOption[];
    }>(),
    { chunksPreview: () => [], departments: () => [], roles: () => [] },
);

watchEffect(() => {
    setLayoutProps({
        breadcrumbs: [
            { title: 'ui.nav.knowledge', href: endpoints.knowledge.index() },
            {
                title: props.document.title,
                href: endpoints.knowledge.show(props.document.id),
            },
        ],
    });
});

const fmt = useFormat();
const { can } = useCan();
const canManage = computed(() => can('knowledge.manage', 'workspace.manage'));

const inFlight = computed(
    () =>
        props.document.status === 'queued' ||
        props.document.status === 'processing',
);
const poll = usePoll(
    3000,
    { only: ['document', 'chunksPreview'] },
    { autoStart: false },
);

watch(
    inFlight,
    (value) => {
        if (value) {
            poll.start();
        } else {
            poll.stop();
        }
    },
    { immediate: true },
);

onBeforeUnmount(() => poll.stop());

const roleOptions = computed<SelectOption[]>(() => {
    if (props.roles.length) {
        return props.roles;
    }

    return ['owner', 'admin', 'member'].map((value) => ({
        value,
        label: tOr(`roles.${value}`, value),
    }));
});

const editOpen = ref(false);
const meta = ref<DocumentMeta>(toMeta(props.document));

function toMeta(doc: KbDocument): DocumentMeta {
    return {
        title: doc.title,
        department: doc.department,
        roles: [...doc.roles],
        tags: [...doc.tags],
        version: doc.version ?? '',
        effective_date: doc.effective_date
            ? doc.effective_date.slice(0, 10)
            : '',
    };
}

const form = useForm({
    title: '',
    department: null as string | null,
    roles: [] as string[],
    tags: [] as string[],
    version: null as string | null,
    effective_date: null as string | null,
});

watch(editOpen, (value) => {
    if (value) {
        meta.value = toMeta(props.document);
        form.clearErrors();
    }
});

function saveMeta(): void {
    form.title = meta.value.title.trim();
    form.department = meta.value.department || null;
    form.roles = meta.value.roles;
    form.tags = meta.value.tags;
    form.version = meta.value.version.trim() || null;
    form.effective_date = meta.value.effective_date || null;

    form.patch(endpoints.knowledge.update(props.document.id).url, {
        preserveScroll: true,
        onSuccess: () => {
            editOpen.value = false;
        },
    });
}

const deleteOpen = ref(false);
const deleting = ref(false);

function destroyDocument(): void {
    router.delete(endpoints.knowledge.destroy(props.document.id).url, {
        onStart: () => {
            deleting.value = true;
        },
        onFinish: () => {
            deleting.value = false;
            deleteOpen.value = false;
        },
    });
}

const expanded = ref<Set<number>>(new Set());

function toggleChunk(index: number): void {
    const next = new Set(expanded.value);

    if (next.has(index)) {
        next.delete(index);
    } else {
        next.add(index);
    }

    expanded.value = next;
}

const details = computed(() => [
    {
        key: 'original',
        label: t('ui.knowledge.details.original'),
        value: props.document.original_name,
        mono: true,
    },
    {
        key: 'type',
        label: t('ui.knowledge.details.type'),
        value: props.document.mime,
        mono: true,
    },
    {
        key: 'size',
        label: t('ui.knowledge.size'),
        value: fmt.value.bytes(props.document.size_bytes),
    },
    {
        key: 'pages',
        label: t('ui.knowledge.details.pages'),
        value:
            props.document.pages !== null
                ? fmt.value.number(props.document.pages)
                : '—',
    },
    {
        key: 'chunks',
        label: t('ui.knowledge.details.chunks'),
        value:
            props.document.chunks !== null
                ? fmt.value.number(props.document.chunks)
                : '—',
    },
    {
        key: 'ocr',
        label: t('ui.knowledge.details.ocr'),
        value: props.document.ocr_used ? t('ui.common.yes') : t('ui.common.no'),
    },
    {
        key: 'version',
        label: t('ui.knowledge.fields.version'),
        value: props.document.version ?? '—',
        mono: true,
    },
    {
        key: 'effective',
        label: t('ui.knowledge.fields.effective_date'),
        value: props.document.effective_date
            ? fmt.value.date(props.document.effective_date)
            : '—',
    },
    {
        key: 'uploaded_by',
        label: t('ui.knowledge.details.uploaded_by'),
        value: props.document.uploaded_by?.name ?? '—',
    },
]);
</script>

<template>
    <Head :title="document.title" />

    <div
        class="mx-auto flex w-full max-w-7xl flex-col gap-6 px-4 py-6 sm:px-6 lg:py-8"
    >
        <header
            class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
        >
            <div class="flex min-w-0 items-start gap-3">
                <FileTypeIcon
                    :mime="document.mime"
                    :name="document.original_name"
                    class="size-11 rounded-xl"
                />
                <div class="min-w-0 space-y-1.5">
                    <h1
                        class="font-display text-2xl font-bold tracking-tight break-words"
                    >
                        {{ document.title }}
                    </h1>
                    <div
                        class="flex flex-wrap items-center gap-2 text-sm text-muted-foreground"
                    >
                        <DocumentStatusBadge :status="document.status" />
                        <span
                            >{{ t('ui.knowledge.updated') }}
                            <RelativeTime :value="document.updated_at"
                        /></span>
                    </div>
                </div>
            </div>
            <div class="flex shrink-0 flex-wrap gap-2">
                <Button v-if="document.download_url" as-child variant="outline">
                    <a
                        :href="document.download_url"
                        download
                        data-test="kb-download"
                    >
                        <Download class="size-4" aria-hidden="true" />
                        {{ t('ui.common.download') }}
                    </a>
                </Button>
                <Button
                    v-if="canManage"
                    variant="outline"
                    @click="editOpen = true"
                >
                    <Pencil class="size-4" aria-hidden="true" />
                    {{ t('ui.knowledge.edit_metadata') }}
                </Button>
                <Button
                    v-if="canManage"
                    variant="ghost"
                    size="icon"
                    class="text-destructive hover:bg-destructive/10 hover:text-destructive"
                    :aria-label="t('ui.common.delete')"
                    @click="deleteOpen = true"
                >
                    <Trash2 class="size-4" aria-hidden="true" />
                </Button>
            </div>
        </header>

        <div
            class="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_22rem] lg:items-start"
        >
            <SectionCard
                :icon="Layers"
                :title="t('ui.knowledge.chunks_title')"
                :description="t('ui.knowledge.chunks_description')"
            >
                <ErrorState
                    v-if="document.status === 'failed'"
                    :title="t('ui.knowledge.failed_title')"
                    :message="document.error ?? t('ui.knowledge.failed_body')"
                />
                <EmptyState
                    v-else-if="inFlight"
                    compact
                    tone="ai"
                    :icon="ScanText"
                    :title="t('ui.knowledge.processing_title')"
                    :description="t('ui.knowledge.processing_body')"
                />
                <EmptyState
                    v-else-if="!chunksPreview.length"
                    compact
                    tone="muted"
                    :icon="FileSearch"
                    :title="t('ui.knowledge.no_chunks')"
                />
                <ol v-else class="space-y-3">
                    <li
                        v-for="(chunk, index) in chunksPreview"
                        :key="index"
                        class="rounded-xl border bg-background/60 p-4"
                    >
                        <div
                            class="mb-2 flex flex-wrap items-center gap-2 text-xs"
                        >
                            <span
                                class="rounded-md bg-ai-soft px-2 py-0.5 font-semibold text-ai-soft-foreground"
                                >#{{ index + 1 }}</span
                            >
                            <span
                                v-if="chunk.page"
                                class="rounded-md bg-muted px-2 py-0.5 font-medium"
                            >
                                {{
                                    t('ui.assistant.page', { page: chunk.page })
                                }}
                            </span>
                            <span
                                v-if="chunk.section"
                                class="truncate text-muted-foreground"
                                >{{ chunk.section }}</span
                            >
                        </div>
                        <p
                            :id="`chunk-${index}`"
                            :class="[
                                'text-sm leading-relaxed whitespace-pre-line',
                                expanded.has(index) ? '' : 'line-clamp-4',
                            ]"
                        >
                            {{ chunk.content }}
                        </p>
                        <button
                            v-if="chunk.content.length > 320"
                            type="button"
                            class="mt-2 text-xs font-semibold text-primary hover:underline"
                            :aria-expanded="expanded.has(index)"
                            :aria-controls="`chunk-${index}`"
                            @click="toggleChunk(index)"
                        >
                            {{
                                expanded.has(index)
                                    ? t('ui.common.show_less')
                                    : t('ui.common.show_more')
                            }}
                        </button>
                    </li>
                </ol>
                <p
                    v-if="
                        document.chunks &&
                        chunksPreview.length &&
                        document.chunks > chunksPreview.length
                    "
                    class="mt-4 text-xs text-muted-foreground"
                >
                    {{
                        t('ui.knowledge.chunks_more', {
                            shown: chunksPreview.length,
                            total: document.chunks,
                        })
                    }}
                </p>
            </SectionCard>

            <div class="space-y-6">
                <SectionCard :title="t('ui.knowledge.pipeline.title')">
                    <PipelineSteps
                        :status="document.status"
                        :ocr-used="document.ocr_used"
                    />
                </SectionCard>

                <SectionCard :title="t('ui.knowledge.details.title')">
                    <dl class="space-y-3 text-sm">
                        <div
                            v-for="item in details"
                            :key="item.key"
                            class="flex items-start justify-between gap-4"
                        >
                            <dt class="text-muted-foreground">
                                {{ item.label }}
                            </dt>
                            <dd
                                :class="[
                                    'min-w-0 text-right font-medium break-all',
                                    item.mono ? 'font-mono text-xs' : '',
                                ]"
                            >
                                {{ item.value }}
                            </dd>
                        </div>
                        <div class="flex items-start justify-between gap-4">
                            <dt class="text-muted-foreground">
                                {{ t('ui.knowledge.fields.department') }}
                            </dt>
                            <dd class="text-right font-medium">
                                {{
                                    document.department ??
                                    t('ui.knowledge.everyone')
                                }}
                            </dd>
                        </div>
                        <div class="space-y-1.5">
                            <dt class="text-muted-foreground">
                                {{ t('ui.knowledge.fields.roles') }}
                            </dt>
                            <dd class="flex flex-wrap gap-1">
                                <span
                                    v-for="role in document.roles"
                                    :key="role"
                                    class="rounded-md bg-info-soft px-2 py-0.5 text-xs font-medium text-info-soft-foreground"
                                    >{{ tOr(`roles.${role}`, role) }}</span
                                >
                                <span
                                    v-if="!document.roles.length"
                                    class="text-xs text-muted-foreground"
                                    >{{ t('ui.knowledge.everyone') }}</span
                                >
                            </dd>
                        </div>
                        <div class="space-y-1.5">
                            <dt class="text-muted-foreground">
                                {{ t('ui.knowledge.fields.tags') }}
                            </dt>
                            <dd class="flex flex-wrap gap-1">
                                <span
                                    v-for="tag in document.tags"
                                    :key="tag"
                                    class="rounded-md bg-muted px-2 py-0.5 text-xs font-medium"
                                    >#{{ tag }}</span
                                >
                                <span
                                    v-if="!document.tags.length"
                                    class="text-xs text-muted-foreground"
                                    >—</span
                                >
                            </dd>
                        </div>
                        <div
                            class="flex items-start justify-between gap-4 border-t pt-3"
                        >
                            <dt class="text-muted-foreground">
                                {{ t('ui.knowledge.details.created') }}
                            </dt>
                            <dd class="text-right">
                                {{ fmt.dateTime(document.created_at) }}
                            </dd>
                        </div>
                    </dl>
                    <p
                        v-if="document.chunks"
                        class="mt-4 text-xs text-muted-foreground"
                    >
                        {{ tc('ui.knowledge.chunks', document.chunks) }}
                    </p>
                </SectionCard>
            </div>
        </div>
    </div>

    <Dialog v-model:open="editOpen">
        <DialogContent class="max-h-[92svh] overflow-y-auto sm:max-w-2xl">
            <form class="space-y-6" @submit.prevent="saveMeta">
                <DialogHeader>
                    <DialogTitle>{{
                        t('ui.knowledge.edit_metadata')
                    }}</DialogTitle>
                    <DialogDescription>{{
                        t('ui.knowledge.edit_description')
                    }}</DialogDescription>
                </DialogHeader>
                <DocumentMetaFields
                    v-model="meta"
                    id-prefix="edit"
                    :departments="departments"
                    :roles="roleOptions"
                    :errors="form.errors"
                />
                <DialogFooter class="gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        @click="editOpen = false"
                        >{{ t('ui.common.cancel') }}</Button
                    >
                    <Button type="submit" :disabled="form.processing">
                        <Spinner v-if="form.processing" />
                        {{ t('ui.common.save_changes') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <ConfirmDialog
        v-model:open="deleteOpen"
        destructive
        :processing="deleting"
        :title="t('ui.knowledge.delete_title')"
        :description="t('ui.knowledge.delete_body', { title: document.title })"
        :confirm-label="t('ui.common.delete')"
        @confirm="destroyDocument"
    />
</template>
