<script setup lang="ts">
import { Check } from '@lucide/vue';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';

defineProps<{
    steps: Array<{ key: string; title: string; description?: string }>;
    current: number;
}>();

const emit = defineEmits<{ go: [index: number] }>();
</script>

<template>
    <ol
        class="flex gap-2 md:flex-col md:gap-0"
        :aria-label="t('ui.common.progress')"
    >
        <li
            v-for="(step, index) in steps"
            :key="step.key"
            class="relative flex flex-1 md:flex-none md:pb-8 md:last:pb-0"
            :aria-current="index === current ? 'step' : undefined"
        >
            <span
                v-if="index < steps.length - 1"
                aria-hidden="true"
                :class="
                    cn(
                        'absolute top-4 left-4 hidden h-full w-px -translate-x-1/2 md:block',
                        index < current ? 'bg-primary' : 'bg-border',
                    )
                "
            />
            <button
                type="button"
                class="group relative flex w-full items-start gap-3 rounded-lg text-left disabled:cursor-default"
                :disabled="index > current"
                @click="emit('go', index)"
            >
                <span
                    :class="
                        cn(
                            'relative z-10 inline-flex size-8 shrink-0 items-center justify-center rounded-full border-2 text-sm font-bold transition-colors',
                            index < current
                                ? 'border-primary bg-primary text-primary-foreground'
                                : index === current
                                  ? 'border-primary bg-background text-primary'
                                  : 'border-border bg-background text-muted-foreground',
                        )
                    "
                >
                    <Check
                        v-if="index < current"
                        class="size-4"
                        aria-hidden="true"
                    />
                    <span v-else>{{ index + 1 }}</span>
                </span>
                <span class="hidden min-w-0 pt-1 md:block">
                    <span
                        :class="[
                            'block text-sm font-semibold',
                            index === current
                                ? 'text-foreground'
                                : 'text-muted-foreground',
                        ]"
                        >{{ step.title }}</span
                    >
                    <span
                        v-if="step.description"
                        class="block text-xs text-muted-foreground"
                        >{{ step.description }}</span
                    >
                </span>
                <span class="sr-only md:hidden">{{ step.title }}</span>
            </button>
        </li>
    </ol>
</template>
