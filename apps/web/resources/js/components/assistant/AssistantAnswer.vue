<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    ArrowUpRight,
    Clock,
    Copy,
    Check,
    Cpu,
    DatabaseZap,
    SearchX,
    ShieldBan,
} from '@lucide/vue';
import { useClipboard } from '@vueuse/core';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import ConfidenceMeter from '@/components/assistant/ConfidenceMeter.vue';
import CitationChip from '@/components/assistant/CitationChip.vue';
import { Button } from '@/components/ui/button';
import { useFormat } from '@/composables/useFormat';
import { useReducedMotion } from '@/composables/useReducedMotion';
import {
    linkCitationMarkers,
    markdownToPlain,
    plainToHtml,
} from '@/lib/citations';
import { t } from '@/lib/i18n';
import { sanitizeAnswer } from '@/lib/sanitize';
import type { ChatAction, ChatResponse } from '@/types';

const props = withDefaults(
    defineProps<{ response: ChatResponse; animate?: boolean }>(),
    {
        animate: true,
    },
);

const emit = defineEmits<{
    ask: [question: string];
    action: [action: ChatAction];
    revealed: [];
}>();

const fmt = useFormat();
const reduced = useReducedMotion();
const { copy, copied } = useClipboard({ legacy: true, copiedDuring: 1500 });

const html = computed(() => {
    const base = props.response.answer_html?.trim()
        ? sanitizeAnswer(props.response.answer_html)
        : plainToHtml(props.response.answer_markdown ?? '');

    return linkCitationMarkers(base, props.response.citations.length, (n) =>
        t('ui.assistant.source_n', { n }),
    );
});

const plain = computed(() =>
    markdownToPlain(props.response.answer_markdown ?? ''),
);
const words = computed(() => plain.value.split(/(\s+)/));
const shown = ref(0);
const revealing = ref(false);
let frame = 0;

function finish(): void {
    revealing.value = false;
    shown.value = words.value.length;
    emit('revealed');
}

onMounted(() => {
    if (!props.animate || reduced.value || words.value.length < 4) {
        finish();

        return;
    }

    revealing.value = true;
    const total = words.value.length;
    const duration = Math.min(1600, Math.max(500, total * 18));
    const start = performance.now();

    const step = (now: number) => {
        const progress = Math.min(1, (now - start) / duration);
        shown.value = Math.ceil(total * progress);

        if (progress < 1) {
            frame = requestAnimationFrame(step);
        } else {
            finish();
        }
    };

    frame = requestAnimationFrame(step);
});

onBeforeUnmount(() => cancelAnimationFrame(frame));

const preview = computed(() => words.value.slice(0, shown.value).join(''));

const openCitation = ref<number | null>(null);

function onAnswerClick(event: MouseEvent): void {
    const target = (event.target as HTMLElement).closest<HTMLElement>(
        '[data-cite]',
    );

    if (target?.dataset.cite) {
        openCitation.value = Number(target.dataset.cite);
    }
}

type ResolvedAction = {
    action: ChatAction;
    run: () => void;
    external: boolean;
};

const SERVER_ACTIONS = new Set(['escalate']);

const refusedByPolicy = computed(
    () => props.response.refusal_reason === 'policy',
);

const liveData = computed(() =>
    props.response.tools_used.some((tool) => tool !== 'search_documents'),
);

const actions = computed<ResolvedAction[]>(() =>
    (props.response.actions ?? []).flatMap<ResolvedAction>((action) => {
        if (SERVER_ACTIONS.has(action.type)) {
            return [
                { action, external: false, run: () => emit('action', action) },
            ];
        }

        const url =
            typeof action.payload?.url === 'string' ? action.payload.url : null;
        const prompt =
            typeof action.payload?.prompt === 'string'
                ? action.payload.prompt
                : typeof action.payload?.question === 'string'
                  ? action.payload.question
                  : null;

        if (url && url.startsWith('/')) {
            return [{ action, external: false, run: () => router.visit(url) }];
        }

        if (url && /^https:\/\//i.test(url)) {
            return [
                {
                    action,
                    external: true,
                    run: () =>
                        window.open(url, '_blank', 'noopener,noreferrer'),
                },
            ];
        }

        if (prompt) {
            return [
                { action, external: false, run: () => emit('ask', prompt) },
            ];
        }

        return [];
    }),
);
</script>

<template>
    <div
        class="space-y-3"
        data-test="assistant-answer"
        :data-refused="response.refused ? 'true' : 'false'"
    >
        <div
            v-if="response.refused"
            class="flex items-start gap-3 rounded-xl border border-warning/40 bg-warning-soft p-3 text-sm text-warning-soft-foreground"
            role="note"
        >
            <ShieldBan
                v-if="refusedByPolicy"
                class="mt-0.5 size-4 shrink-0"
                aria-hidden="true"
            />
            <SearchX v-else class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
            <p class="font-medium">
                {{
                    refusedByPolicy
                        ? t('ui.assistant.refused_policy')
                        : t('ui.assistant.refused_banner')
                }}
            </p>
        </div>
        <p
            v-if="response.degraded"
            class="text-xs text-muted-foreground"
            role="note"
        >
            {{ t('ui.assistant.degraded') }}
        </p>

        <p
            v-if="revealing"
            class="text-[0.9375rem] leading-relaxed whitespace-pre-wrap"
            aria-hidden="true"
        >
            {{ preview
            }}<span
                class="ml-0.5 inline-block h-4 w-1.5 translate-y-0.5 animate-pulse rounded-sm bg-ai"
            />
        </p>
        <div
            v-show="!revealing"
            class="answer-prose"
            @click="onAnswerClick"
            v-html="html"
        />

        <div v-if="!revealing && response.citations.length" class="space-y-2">
            <p
                class="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
            >
                {{ t('ui.assistant.sources') }}
            </p>
            <div class="flex flex-wrap gap-1.5">
                <CitationChip
                    v-for="(citation, index) in response.citations"
                    :key="`${citation.document_id}-${index}`"
                    :citation="citation"
                    :index="index + 1"
                    :open="openCitation === index + 1"
                    @update:open="
                        (value: boolean) =>
                            (openCitation = value ? index + 1 : null)
                    "
                />
            </div>
        </div>

        <div v-if="!revealing && actions.length" class="flex flex-wrap gap-2">
            <Button
                v-for="item in actions"
                :key="`${item.action.type}-${item.action.label}`"
                size="sm"
                :variant="
                    item.action.type === 'escalate' ? 'default' : 'outline'
                "
                @click="item.run"
            >
                {{ item.action.label }}
                <ArrowUpRight
                    v-if="item.external"
                    class="size-3.5"
                    aria-hidden="true"
                />
            </Button>
        </div>

        <div
            v-if="!revealing"
            class="flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-border/60 pt-2.5"
        >
            <ConfidenceMeter :value="response.confidence" />
            <span
                class="inline-flex items-center gap-1 text-xs text-muted-foreground"
            >
                <Cpu class="size-3.5" aria-hidden="true" />
                <span class="font-mono">{{ response.model }}</span>
            </span>
            <span
                class="inline-flex items-center gap-1 text-xs text-muted-foreground"
            >
                <Clock class="size-3.5" aria-hidden="true" />
                {{ fmt.duration(response.latency_ms) }}
            </span>
            <span
                v-if="liveData"
                class="inline-flex items-center gap-1 rounded-full bg-ai-soft px-2 py-0.5 text-xs font-medium text-ai-soft-foreground"
                data-test="assistant-live-data"
            >
                <DatabaseZap class="size-3.5" aria-hidden="true" />
                {{ t('ui.assistant.live_data') }}
            </span>
            <Button
                variant="ghost"
                size="icon-sm"
                class="ml-auto"
                :aria-label="
                    copied
                        ? t('ui.common.copied')
                        : t('ui.assistant.copy_answer')
                "
                @click="copy(response.answer_markdown)"
            >
                <Check v-if="copied" class="text-success" />
                <Copy v-else />
            </Button>
        </div>
    </div>
</template>
