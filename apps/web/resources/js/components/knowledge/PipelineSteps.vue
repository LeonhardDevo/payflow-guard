<script setup lang="ts">
import { Check, CircleX, LoaderCircle } from '@lucide/vue';
import { computed } from 'vue';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { KbDocumentStatus } from '@/types';

const props = defineProps<{ status: KbDocumentStatus; ocrUsed: boolean }>();

type StepState = 'done' | 'active' | 'pending' | 'failed';

const steps = computed<Array<{ key: string; state: StepState }>>(() => {
    const order: KbDocumentStatus[] = ['queued', 'processing', 'indexed'];
    const current = props.status === 'failed' ? 1 : order.indexOf(props.status);

    return [
        { key: 'uploaded', state: 'done' },
        { key: 'queued', state: current > 0 ? 'done' : 'active' },
        {
            key: 'processing',
            state:
                props.status === 'failed'
                    ? 'failed'
                    : current > 1
                      ? 'done'
                      : current === 1
                        ? 'active'
                        : 'pending',
        },
        {
            key: 'indexed',
            state: props.status === 'indexed' ? 'done' : 'pending',
        },
    ];
});
</script>

<template>
    <ol class="space-y-0">
        <li
            v-for="(step, index) in steps"
            :key="step.key"
            class="relative flex gap-3 pb-5 last:pb-0"
        >
            <span
                v-if="index < steps.length - 1"
                aria-hidden="true"
                :class="
                    cn(
                        'absolute top-6 left-3 h-[calc(100%-1rem)] w-px',
                        step.state === 'done' ? 'bg-primary' : 'bg-border',
                    )
                "
            />
            <span
                :class="
                    cn(
                        'relative z-10 inline-flex size-6 shrink-0 items-center justify-center rounded-full border-2',
                        step.state === 'done' &&
                            'border-primary bg-primary text-primary-foreground',
                        step.state === 'active' &&
                            'border-ai bg-ai-soft text-ai-soft-foreground',
                        step.state === 'pending' &&
                            'border-border bg-background',
                        step.state === 'failed' &&
                            'border-destructive bg-destructive-soft text-destructive-soft-foreground',
                    )
                "
                aria-hidden="true"
            >
                <Check v-if="step.state === 'done'" class="size-3.5" />
                <LoaderCircle
                    v-else-if="step.state === 'active'"
                    class="size-3.5 motion-safe:animate-spin"
                />
                <CircleX v-else-if="step.state === 'failed'" class="size-3.5" />
            </span>
            <div class="min-w-0 pt-0.5">
                <p class="text-sm font-medium">
                    {{ t(`ui.knowledge.pipeline.${step.key}`) }}
                </p>
                <p class="text-xs text-muted-foreground">
                    {{
                        step.key === 'processing' && ocrUsed
                            ? t('ui.knowledge.pipeline.processing_ocr')
                            : t(`ui.knowledge.pipeline.${step.key}_hint`)
                    }}
                </p>
                <span class="sr-only">{{
                    t(`ui.knowledge.pipeline.state_${step.state}`)
                }}</span>
            </div>
        </li>
    </ol>
</template>
