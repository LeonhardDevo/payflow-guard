<script setup lang="ts">
import {
    File,
    FileImage,
    FileSpreadsheet,
    FileText,
    FileType2,
    Globe,
    ScrollText,
} from '@lucide/vue';
import { computed } from 'vue';
import { fileKind } from '@/lib/files';
import { cn } from '@/lib/utils';

const props = withDefaults(
    defineProps<{ mime: string; name: string; class?: string }>(),
    {
        class: '',
    },
);

const kind = computed(() => fileKind(props.mime, props.name));

const map = {
    pdf: {
        icon: FileType2,
        tone: 'bg-destructive-soft text-destructive-soft-foreground',
    },
    doc: { icon: FileText, tone: 'bg-info-soft text-info-soft-foreground' },
    sheet: {
        icon: FileSpreadsheet,
        tone: 'bg-success-soft text-success-soft-foreground',
    },
    image: { icon: FileImage, tone: 'bg-ai-soft text-ai-soft-foreground' },
    text: { icon: ScrollText, tone: 'bg-muted text-muted-foreground' },
    web: { icon: Globe, tone: 'bg-warning-soft text-warning-soft-foreground' },
    other: { icon: File, tone: 'bg-muted text-muted-foreground' },
} as const;
</script>

<template>
    <span
        :class="
            cn(
                'inline-flex size-9 shrink-0 items-center justify-center rounded-lg',
                map[kind].tone,
                props.class,
            )
        "
        aria-hidden="true"
    >
        <component :is="map[kind].icon" class="size-4" />
    </span>
</template>
