<script setup lang="ts">
import { EyeOff, Timer } from '@lucide/vue';
import { computed } from 'vue';
import { t } from '@/lib/i18n';
import { markRequestText } from '@/lib/pf/guard';

const props = defineProps<{ text: string }>();

const segments = computed(() => markRequestText(props.text));
const marks = computed(
    () => new Set(segments.value.flatMap((s) => (s.mark ? [s.mark] : []))),
);
</script>

<template>
    <div>
        <pre
            class="max-h-72 overflow-auto rounded-xl border bg-muted/30 p-3 font-mono text-xs leading-relaxed whitespace-pre-wrap"
            data-test="change-text"
            :data-marks="segments.filter((s) => s.mark).length"
        ><template v-for="(segment, index) in segments" :key="index"><mark
                    v-if="segment.mark"
                    :class="[
                        'rounded px-0.5 font-semibold',
                        segment.mark === 'urgency'
                            ? 'bg-warning-soft text-warning-soft-foreground'
                            : 'bg-destructive-soft text-destructive-soft-foreground',
                    ]"
                    :data-mark="segment.mark"
                    :title="t(`guard.request_text.${segment.mark}`)"
                >{{ segment.text }}</mark><template v-else>{{ segment.text }}</template></template></pre>
        <div
            v-if="marks.size"
            class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground"
        >
            <span
                v-if="marks.has('urgency')"
                class="inline-flex items-center gap-1.5"
            >
                <Timer
                    class="size-3.5 text-warning-soft-foreground"
                    aria-hidden="true"
                />{{ t('guard.request_text.urgency') }}
            </span>
            <span
                v-if="marks.has('secrecy')"
                class="inline-flex items-center gap-1.5"
            >
                <EyeOff
                    class="size-3.5 text-destructive-soft-foreground"
                    aria-hidden="true"
                />{{ t('guard.request_text.secrecy') }}
            </span>
            <span class="basis-full">{{ t('guard.request_text.legend') }}</span>
        </div>
    </div>
</template>
