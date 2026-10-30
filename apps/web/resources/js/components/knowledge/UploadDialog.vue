<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { CloudUpload, FileCheck2, X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import type { DocumentMeta } from '@/components/knowledge/DocumentMetaFields.vue';
import DocumentMetaFields from '@/components/knowledge/DocumentMetaFields.vue';
import FileTypeIcon from '@/components/knowledge/FileTypeIcon.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Progress } from '@/components/ui/progress';
import { Spinner } from '@/components/ui/spinner';
import { useFormat } from '@/composables/useFormat';
import { endpoints } from '@/lib/endpoints';
import {
    acceptAttribute,
    acceptLabel,
    titleFromFilename,
    validateUpload,
} from '@/lib/files';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { SelectOption } from '@/types';

const props = defineProps<{
    accept: string[];
    maxSizeMb: number;
    departments: string[];
    roles: SelectOption[];
    limitReached?: boolean;
}>();

const open = defineModel<boolean>('open', { default: false });

const fmt = useFormat();
const dragging = ref(false);
const fileError = ref<string | null>(null);
const input = ref<HTMLInputElement | null>(null);

const emptyMeta = (): DocumentMeta => ({
    title: '',
    department: null,
    roles: [],
    tags: [],
    version: '',
    effective_date: '',
});

const meta = ref<DocumentMeta>(emptyMeta());

const form = useForm<{
    file: File | null;
    title: string;
    department: string | null;
    roles: string[];
    tags: string[];
    version: string | null;
    effective_date: string | null;
}>({
    file: null,
    title: '',
    department: null,
    roles: [],
    tags: [],
    version: null,
    effective_date: null,
});

watch(open, (value) => {
    if (!value) {
        form.reset();
        form.clearErrors();
        meta.value = emptyMeta();
        fileError.value = null;
        dragging.value = false;
    }
});

function pick(file: File | undefined | null): void {
    if (!file) {
        return;
    }

    const error = validateUpload(file, props.accept, props.maxSizeMb);

    if (error) {
        fileError.value = t(
            `ui.knowledge.upload.errors.${error.key}`,
            error.params,
        );

        return;
    }

    fileError.value = null;
    form.file = file;

    if (!meta.value.title) {
        meta.value.title = titleFromFilename(file.name);
    }
}

function onDrop(event: DragEvent): void {
    dragging.value = false;
    pick(event.dataTransfer?.files?.[0]);
}

function onChange(event: Event): void {
    pick((event.target as HTMLInputElement).files?.[0]);
    (event.target as HTMLInputElement).value = '';
}

function clearFile(): void {
    form.file = null;
}

const percent = computed(() => form.progress?.percentage ?? 0);

function submit(): void {
    if (!form.file) {
        fileError.value = t('ui.knowledge.upload.errors.required');

        return;
    }

    form.title = meta.value.title.trim();
    form.department = meta.value.department || null;
    form.roles = meta.value.roles;
    form.tags = meta.value.tags;
    form.version = meta.value.version.trim() || null;
    form.effective_date = meta.value.effective_date || null;

    form.post(endpoints.knowledge.store().url, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-h-[92svh] overflow-y-auto sm:max-w-2xl">
            <form class="space-y-6" @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>{{
                        t('ui.knowledge.upload.title')
                    }}</DialogTitle>
                    <DialogDescription>{{
                        t('ui.knowledge.upload.description')
                    }}</DialogDescription>
                </DialogHeader>

                <div
                    v-if="!form.file"
                    :class="
                        cn(
                            'relative flex flex-col items-center justify-center gap-3 rounded-2xl border-2 border-dashed px-6 py-10 text-center transition-colors',
                            dragging
                                ? 'border-primary bg-primary-soft/50'
                                : 'border-border bg-muted/30 hover:border-primary/50',
                        )
                    "
                    @dragenter.prevent="dragging = true"
                    @dragover.prevent="dragging = true"
                    @dragleave.prevent="dragging = false"
                    @drop.prevent="onDrop"
                >
                    <span
                        class="inline-flex size-12 items-center justify-center rounded-2xl bg-primary-soft text-primary-soft-foreground"
                        aria-hidden="true"
                    >
                        <CloudUpload class="size-6" />
                    </span>
                    <div class="space-y-1">
                        <p class="font-medium">
                            {{ t('ui.knowledge.upload.drop') }}
                        </p>
                        <p class="text-sm text-muted-foreground">
                            {{
                                t('ui.knowledge.upload.constraints', {
                                    types: acceptLabel(accept),
                                    max: maxSizeMb,
                                })
                            }}
                        </p>
                    </div>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        @click="input?.click()"
                    >
                        {{ t('ui.knowledge.upload.browse') }}
                    </Button>
                    <input
                        ref="input"
                        type="file"
                        class="sr-only"
                        :accept="acceptAttribute(accept)"
                        name="file"
                        :aria-label="t('ui.knowledge.upload.browse')"
                        data-test="kb-file-input"
                        @change="onChange"
                    />
                </div>

                <div
                    v-else
                    class="flex items-center gap-3 rounded-2xl border bg-muted/30 p-3"
                >
                    <FileTypeIcon
                        :mime="form.file.type"
                        :name="form.file.name"
                    />
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium">
                            {{ form.file.name }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ fmt.bytes(form.file.size) }}
                        </p>
                    </div>
                    <FileCheck2
                        class="size-5 text-success"
                        aria-hidden="true"
                    />
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon-sm"
                        :disabled="form.processing"
                        :aria-label="t('ui.knowledge.upload.remove_file')"
                        @click="clearFile"
                    >
                        <X />
                    </Button>
                </div>
                <InputError :message="fileError ?? form.errors.file" />

                <DocumentMetaFields
                    v-model="meta"
                    id-prefix="upload"
                    :departments="departments"
                    :roles="roles"
                    :errors="form.errors"
                />

                <div
                    v-if="form.processing && form.progress"
                    class="space-y-1.5"
                    aria-live="polite"
                >
                    <Progress
                        :model-value="percent"
                        :aria-label="t('ui.knowledge.upload.progress')"
                    />
                    <p class="text-xs text-muted-foreground tabular-nums">
                        {{ t('ui.knowledge.upload.uploading', { percent }) }}
                    </p>
                </div>

                <p
                    v-if="limitReached"
                    class="rounded-lg border border-warning/40 bg-warning-soft px-3 py-2 text-sm text-warning-soft-foreground"
                    role="alert"
                >
                    {{ t('ui.knowledge.limit_reached') }}
                </p>

                <DialogFooter class="gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="form.processing"
                        @click="open = false"
                    >
                        {{ t('ui.common.cancel') }}
                    </Button>
                    <Button
                        type="submit"
                        :disabled="form.processing || limitReached"
                        data-test="kb-upload-submit"
                    >
                        <Spinner v-if="form.processing" />
                        <CloudUpload v-else class="size-4" aria-hidden="true" />
                        {{ t('ui.knowledge.upload.submit') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
