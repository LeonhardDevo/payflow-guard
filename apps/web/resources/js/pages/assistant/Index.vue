<script setup lang="ts">
import { Head, Link, useHttp, usePage } from '@inertiajs/vue3';
import {
    Bot,
    History,
    MessageCircleQuestion,
    RefreshCw,
    RotateCcw,
    Send,
    Sparkles,
    TriangleAlert,
} from '@lucide/vue';
import { computed, nextTick, ref } from 'vue';
import { toast } from 'vue-sonner';
import AssistantAnswer from '@/components/assistant/AssistantAnswer.vue';
import ChatComposer from '@/components/assistant/ChatComposer.vue';
import TypingIndicator from '@/components/assistant/TypingIndicator.vue';
import AppLogoMark from '@/components/brand/AppLogoMark.vue';
import UserAvatar from '@/components/common/UserAvatar.vue';
import { Button } from '@/components/ui/button';
import { endpoints } from '@/lib/endpoints';
import { describeHttpError } from '@/lib/http-errors';
import { t } from '@/lib/i18n';
import type { ChatAction, ChatCitation, ChatResponse } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'ui.nav.assistant', href: endpoints.assistant.index() },
        ],
    },
});

const props = withDefaults(defineProps<{ suggestions?: string[] }>(), {
    suggestions: () => [],
});

const page = usePage();
const user = computed(() => page.props.auth.user);

type ChatMessage =
    | { id: string; role: 'user'; text: string }
    | {
          id: string;
          role: 'assistant';
          question: string;
          response: ChatResponse | null;
          error: string | null;
          upgrade: boolean;
          animate: boolean;
      };

const newId = (): string =>
    typeof crypto !== 'undefined' && 'randomUUID' in crypto
        ? crypto.randomUUID()
        : `${Date.now()}-${Math.random().toString(16).slice(2)}`;

const conversationId = ref(newId());
const messages = ref<ChatMessage[]>([]);
const draft = ref('');
const scroller = ref<HTMLElement | null>(null);
const composer = ref<InstanceType<typeof ChatComposer> | null>(null);

const http = useHttp<
    { message: string; conversation_id: string },
    ChatResponse
>({
    message: '',
    conversation_id: conversationId.value,
});

const busy = computed(() => http.processing);

async function scrollToBottom(): Promise<void> {
    await nextTick();
    scroller.value?.scrollTo({
        top: scroller.value.scrollHeight,
        behavior: 'smooth',
    });
}

async function ask(question: string, existing?: ChatMessage): Promise<void> {
    const text = question.trim();

    if (!text || busy.value) {
        return;
    }

    let pending: Extract<ChatMessage, { role: 'assistant' }>;

    if (existing && existing.role === 'assistant') {
        pending = existing;
        pending.error = null;
        pending.upgrade = false;
    } else {
        messages.value.push({ id: newId(), role: 'user', text });
        pending = {
            id: newId(),
            role: 'assistant',
            question: text,
            response: null,
            error: null,
            upgrade: false,
            animate: true,
        };
        messages.value.push(pending);
        draft.value = '';
    }

    const target = messages.value.find((message) => message.id === pending.id);
    void scrollToBottom();

    http.message = text;
    http.conversation_id = conversationId.value;

    try {
        const response = await http.post(endpoints.assistant.ask().url);

        if (target && target.role === 'assistant') {
            if (response) {
                target.response = response;
            } else {
                const validation = http.errors.message;
                target.error =
                    typeof validation === 'string' && validation
                        ? validation
                        : t('ui.assistant.error');
            }
        }
    } catch (error) {
        if (target && target.role === 'assistant') {
            const info = describeHttpError(error);

            if (info.kind === 'cancelled') {
                target.error = t('ui.assistant.stopped');
            } else if (info.status === 429) {
                target.error = info.message ?? t('ui.assistant.rate_limited');
            } else {
                target.error = info.message ?? t('ui.assistant.error');
                target.upgrade = info.status === 402;
            }
        }
    } finally {
        void scrollToBottom();
        composer.value?.focus();
    }
}

type ActionPayload = Record<string, string | number | boolean | null>;

function toPayload(payload: Record<string, unknown>): ActionPayload {
    const out: ActionPayload = {};

    for (const [key, value] of Object.entries(payload)) {
        out[key] =
            value === null ||
            typeof value === 'string' ||
            typeof value === 'number' ||
            typeof value === 'boolean'
                ? value
                : JSON.stringify(value);
    }

    return out;
}

const actionHttp = useHttp<
    { type: string; payload: ActionPayload },
    { ok?: boolean; message?: string }
>({
    type: '',
    payload: {},
});

async function runAction(action: ChatAction): Promise<void> {
    actionHttp.type = action.type;
    actionHttp.payload = toPayload({
        ...action.payload,
        conversation_id: conversationId.value,
    });

    try {
        const result = await actionHttp.post(endpoints.assistant.action().url);
        toast.success(result?.message ?? t('ui.assistant.action_done'));
    } catch (error) {
        toast.error(
            describeHttpError(error).message ?? t('ui.assistant.action_failed'),
        );
    }
}

function stop(): void {
    http.cancel();
}

function reset(): void {
    if (busy.value) {
        http.cancel();
    }

    messages.value = [];
    conversationId.value = newId();
    composer.value?.focus();
}

const sources = computed(() => {
    const map = new Map<number, ChatCitation & { uses: number }>();

    for (const message of messages.value) {
        if (message.role !== 'assistant' || !message.response) {
            continue;
        }

        for (const citation of message.response.citations) {
            const entry = map.get(citation.document_id);
            map.set(citation.document_id, {
                ...citation,
                uses: (entry?.uses ?? 0) + 1,
            });
        }
    }

    return [...map.values()].sort((a, b) => b.uses - a.uses);
});

const visibleSuggestions = computed(() => props.suggestions.slice(0, 6));
</script>

<template>
    <Head :title="t('ui.nav.assistant')" />

    <div
        class="mx-auto flex h-[calc(100svh-3.5rem)] w-full max-w-7xl gap-6 px-4 pt-4 sm:px-6 md:h-[calc(100svh-4.5rem)]"
    >
        <section
            class="flex min-w-0 flex-1 flex-col"
            :aria-label="t('ui.assistant.conversation')"
        >
            <header
                class="flex flex-wrap items-center justify-between gap-3 pb-3"
            >
                <div class="flex min-w-0 items-center gap-3">
                    <span
                        class="inline-flex size-10 items-center justify-center rounded-xl bg-ai-soft text-ai-soft-foreground"
                        aria-hidden="true"
                    >
                        <Bot class="size-5" />
                    </span>
                    <div class="min-w-0">
                        <h1
                            class="truncate font-display text-xl font-bold tracking-tight"
                        >
                            {{ t('ui.assistant.title') }}
                        </h1>
                        <p class="truncate text-sm text-muted-foreground">
                            {{ t('ui.assistant.subtitle') }}
                        </p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <Button as-child variant="ghost" size="sm">
                        <Link :href="endpoints.assistant.history()">
                            <History class="size-4" aria-hidden="true" />
                            <span class="max-sm:sr-only">{{
                                t('ui.nav.history')
                            }}</span>
                        </Link>
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="!messages.length"
                        @click="reset"
                    >
                        <RotateCcw class="size-4" aria-hidden="true" />
                        {{ t('ui.assistant.new_chat') }}
                    </Button>
                </div>
            </header>

            <div
                ref="scroller"
                class="relative flex-1 scrollbar-thin overflow-y-auto rounded-2xl border bg-card/50"
                aria-live="polite"
                :aria-busy="busy"
            >
                <div
                    v-if="!messages.length"
                    class="flex min-h-full flex-col items-center justify-center gap-6 px-6 py-12 text-center"
                >
                    <div class="relative">
                        <div
                            class="bg-brand-gradient absolute -inset-6 rounded-full opacity-20 blur-2xl"
                            aria-hidden="true"
                        />
                        <AppLogoMark class="relative size-16 drop-shadow" />
                    </div>
                    <div class="max-w-md space-y-2">
                        <h2
                            class="font-display text-2xl font-bold tracking-tight"
                        >
                            {{ t('ui.assistant.empty_title') }}
                        </h2>
                        <p class="text-sm text-muted-foreground">
                            {{ t('ui.assistant.empty_body') }}
                        </p>
                    </div>
                    <ul
                        v-if="visibleSuggestions.length"
                        class="grid w-full max-w-2xl grid-cols-1 gap-2 sm:grid-cols-2"
                        :aria-label="t('ui.assistant.suggestions')"
                    >
                        <li
                            v-for="suggestion in visibleSuggestions"
                            :key="suggestion"
                        >
                            <button
                                type="button"
                                class="group flex h-full w-full items-start gap-2.5 rounded-xl border bg-card p-3.5 text-left text-sm transition-colors hover:border-ai/40 hover:bg-ai-soft/40"
                                @click="ask(suggestion)"
                            >
                                <MessageCircleQuestion
                                    class="mt-0.5 size-4 shrink-0 text-ai"
                                    aria-hidden="true"
                                />
                                <span>{{ suggestion }}</span>
                            </button>
                        </li>
                    </ul>
                </div>

                <ol v-else class="space-y-6 px-4 py-6 sm:px-6">
                    <li
                        v-for="message in messages"
                        :key="message.id"
                        :class="[
                            'flex gap-3',
                            message.role === 'user' ? 'flex-row-reverse' : '',
                        ]"
                    >
                        <template v-if="message.role === 'user'">
                            <UserAvatar
                                :name="user?.name ?? '?'"
                                :src="user?.avatar ?? null"
                                class="mt-0.5 shrink-0"
                            />
                            <div
                                class="max-w-[85%] rounded-2xl rounded-tr-md bg-primary px-4 py-2.5 text-[0.9375rem] whitespace-pre-wrap text-primary-foreground shadow-soft sm:max-w-[75%]"
                            >
                                <span class="sr-only">{{
                                    t('ui.assistant.you_said')
                                }}</span>
                                {{ message.text }}
                            </div>
                        </template>
                        <template v-else>
                            <span
                                class="mt-0.5 inline-flex size-8 shrink-0 items-center justify-center rounded-lg bg-ai-soft text-ai-soft-foreground"
                                aria-hidden="true"
                            >
                                <Sparkles class="size-4" />
                            </span>
                            <div
                                class="min-w-0 flex-1 rounded-2xl rounded-tl-md border bg-card px-4 py-3 shadow-soft sm:max-w-[85%]"
                            >
                                <span class="sr-only">{{
                                    t('ui.assistant.assistant_said')
                                }}</span>
                                <TypingIndicator
                                    v-if="!message.response && !message.error"
                                />
                                <div
                                    v-else-if="message.error"
                                    class="space-y-3"
                                    role="alert"
                                >
                                    <p
                                        class="flex items-center gap-2 text-sm font-medium text-destructive"
                                    >
                                        <TriangleAlert
                                            class="size-4 shrink-0"
                                            aria-hidden="true"
                                        />
                                        {{ message.error }}
                                    </p>
                                    <Button
                                        v-if="message.upgrade"
                                        as-child
                                        size="sm"
                                    >
                                        <Link
                                            :href="
                                                endpoints.settings.billing.show()
                                            "
                                            >{{ t('ui.upgrade.cta') }}</Link
                                        >
                                    </Button>
                                    <Button
                                        v-else
                                        size="sm"
                                        variant="outline"
                                        :disabled="busy"
                                        @click="ask(message.question, message)"
                                    >
                                        <RefreshCw
                                            class="size-4"
                                            aria-hidden="true"
                                        />
                                        {{ t('ui.common.retry') }}
                                    </Button>
                                </div>
                                <AssistantAnswer
                                    v-else-if="message.response"
                                    :response="message.response"
                                    :animate="message.animate"
                                    @revealed="message.animate = false"
                                    @ask="ask"
                                    @action="runAction"
                                />
                            </div>
                        </template>
                    </li>
                </ol>
            </div>

            <div
                class="sticky bottom-0 space-y-2 bg-gradient-to-t from-background via-background to-transparent pt-3 pb-4"
            >
                <div
                    v-if="messages.length && visibleSuggestions.length && !busy"
                    class="flex scrollbar-thin gap-2 overflow-x-auto pb-1"
                >
                    <button
                        v-for="suggestion in visibleSuggestions.slice(0, 3)"
                        :key="suggestion"
                        type="button"
                        class="inline-flex h-8 shrink-0 items-center gap-1.5 rounded-full border bg-card px-3 text-xs font-medium text-muted-foreground transition-colors hover:border-ai/40 hover:text-foreground pointer-coarse:h-11"
                        @click="ask(suggestion)"
                    >
                        <Send class="size-3" aria-hidden="true" />
                        {{ suggestion }}
                    </button>
                </div>
                <ChatComposer
                    ref="composer"
                    v-model="draft"
                    :busy="busy"
                    @send="ask"
                    @stop="stop"
                />
                <p class="text-center text-xs text-muted-foreground">
                    {{ t('ui.assistant.disclaimer') }}
                </p>
            </div>
        </section>

        <aside
            class="hidden w-72 shrink-0 flex-col gap-4 pb-4 xl:flex"
            :aria-label="t('ui.assistant.sources_panel')"
        >
            <div class="rounded-2xl border bg-card p-4 shadow-soft">
                <h2 class="font-display text-sm font-semibold">
                    {{ t('ui.assistant.sources_panel') }}
                </h2>
                <p
                    v-if="!sources.length"
                    class="mt-2 text-sm text-muted-foreground"
                >
                    {{ t('ui.assistant.sources_empty') }}
                </p>
                <ul v-else class="mt-3 space-y-2">
                    <li v-for="source in sources" :key="source.document_id">
                        <Link
                            :href="endpoints.knowledge.show(source.document_id)"
                            class="flex items-center gap-2 rounded-lg p-2 text-sm transition-colors hover:bg-muted"
                        >
                            <span class="min-w-0 flex-1 truncate font-medium">{{
                                source.title
                            }}</span>
                            <span
                                class="rounded-full bg-ai-soft px-1.5 py-0.5 text-[0.6875rem] font-bold text-ai-soft-foreground"
                                >×{{ source.uses }}</span
                            >
                        </Link>
                    </li>
                </ul>
            </div>
            <div
                class="rounded-2xl border border-dashed p-4 text-sm text-muted-foreground"
            >
                <p class="font-semibold text-foreground">
                    {{ t('ui.assistant.how_title') }}
                </p>
                <ul class="mt-2 list-disc space-y-1 pl-4">
                    <li>{{ t('ui.assistant.how_1') }}</li>
                    <li>{{ t('ui.assistant.how_2') }}</li>
                    <li>{{ t('ui.assistant.how_3') }}</li>
                </ul>
            </div>
        </aside>
    </div>
</template>
