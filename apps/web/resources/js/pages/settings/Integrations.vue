<script setup lang="ts">
import { Head, Link, router, useHttp, usePoll } from '@inertiajs/vue3';
import {
    Bot,
    CircleCheck,
    ExternalLink,
    Link2Off,
    QrCode as QrIcon,
    RefreshCw,
    Smartphone,
    TriangleAlert,
} from '@lucide/vue';
import { useNow } from '@vueuse/core';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import ConfirmDialog from '@/components/common/ConfirmDialog.vue';
import CopyButton from '@/components/common/CopyButton.vue';
import QrCode from '@/components/common/QrCode.vue';
import RelativeTime from '@/components/common/RelativeTime.vue';
import SectionCard from '@/components/common/SectionCard.vue';
import StatusBadge from '@/components/common/StatusBadge.vue';
import TelegramIcon from '@/components/integrations/TelegramIcon.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { endpoints } from '@/lib/endpoints';
import { describeHttpError } from '@/lib/http-errors';
import { t } from '@/lib/i18n';
import {
    botHandle,
    formatCountdown,
    isTelegramDeepLink,
    secondsLeft,
} from '@/lib/telegram';
import type { TelegramLinkResponse, TelegramStatus } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'ui.settings.title',
                href: endpoints.settings.workspace.show(),
            },
            {
                title: 'ui.settings.nav.integrations',
                href: endpoints.settings.integrations.show(),
            },
        ],
    },
});

const props = defineProps<{
    telegram: TelegramStatus;
    botUsername: string | null;
}>();

const http = useHttp<Record<string, never>, TelegramLinkResponse>({});
const link = ref<TelegramLinkResponse | null>(null);
const error = ref<string | null>(null);
const now = useNow({ interval: 1000 });

const remaining = computed(() =>
    link.value ? secondsLeft(link.value.expires_at, now.value.getTime()) : 0,
);
const expired = computed(() => Boolean(link.value) && remaining.value === 0);
const safeUrl = computed(() =>
    link.value && isTelegramDeepLink(link.value.url) ? link.value.url : null,
);
const handle = computed(() => botHandle(props.botUsername));

const poll = usePoll(3000, { only: ['telegram'] }, { autoStart: false });

async function createLink(): Promise<void> {
    error.value = null;

    try {
        const result = await http.post(
            endpoints.settings.integrations.telegramLink().url,
        );

        if (!result?.url) {
            error.value = props.botUsername
                ? t('ui.integrations.telegram.link_error')
                : t('ui.integrations.telegram.no_bot');

            return;
        }

        link.value = result;
        poll.start();
    } catch (failure) {
        error.value =
            describeHttpError(failure).message ??
            t('ui.integrations.telegram.link_error');
    }
}

watch(expired, (value) => {
    if (value) {
        poll.stop();
    }
});

watch(
    () => props.telegram.linked,
    (linked, wasLinked) => {
        if (linked && !wasLinked) {
            poll.stop();
            link.value = null;
            toast.success(t('ui.integrations.telegram.linked_toast'));
        }
    },
);

onBeforeUnmount(() => poll.stop());

const unlinkOpen = ref(false);
const unlinking = ref(false);

function unlink(): void {
    router.delete(endpoints.settings.integrations.telegramUnlink().url, {
        preserveScroll: true,
        onStart: () => {
            unlinking.value = true;
        },
        onFinish: () => {
            unlinking.value = false;
            unlinkOpen.value = false;
        },
    });
}

const steps = ['open', 'start', 'done'] as const;
</script>

<template>
    <Head :title="t('ui.integrations.title')" />

    <SectionCard
        :title="t('ui.integrations.telegram.title')"
        :description="t('ui.integrations.telegram.description')"
    >
        <template #actions>
            <StatusBadge
                data-test="telegram-status"
                :data-linked="telegram.linked ? 'true' : 'false'"
                :tone="
                    telegram.linked
                        ? 'success'
                        : link && !expired
                          ? 'info'
                          : 'neutral'
                "
                :pulse="Boolean(link && !expired)"
                :label="
                    telegram.linked
                        ? t('ui.integrations.telegram.status_linked')
                        : link && !expired
                          ? t('ui.integrations.telegram.status_waiting')
                          : t('ui.integrations.telegram.status_not_linked')
                "
            />
        </template>

        <div class="flex flex-col gap-6 md:flex-row md:items-start">
            <div
                class="flex items-center gap-3 md:w-56 md:flex-col md:items-start"
            >
                <TelegramIcon class="size-12 shrink-0" />
                <div>
                    <p class="font-semibold">
                        {{
                            handle ?? t('ui.integrations.telegram.bot_generic')
                        }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{ t('ui.integrations.telegram.bot_caption') }}
                    </p>
                </div>
            </div>

            <div class="min-w-0 flex-1">
                <div v-if="telegram.linked" class="space-y-4">
                    <div
                        class="flex items-start gap-3 rounded-xl border border-success/30 bg-success-soft p-4 text-success-soft-foreground"
                    >
                        <CircleCheck
                            class="mt-0.5 size-5 shrink-0"
                            aria-hidden="true"
                        />
                        <div class="text-sm">
                            <p class="font-semibold">
                                {{
                                    t(
                                        'ui.integrations.telegram.linked_as_prefix',
                                    )
                                }}
                                <span data-test="telegram-username">{{
                                    telegram.username
                                        ? `@${telegram.username}`
                                        : t(
                                              'ui.integrations.telegram.your_account',
                                          )
                                }}</span>
                            </p>
                            <p v-if="telegram.linked_at" class="opacity-90">
                                {{ t('ui.integrations.telegram.linked_since') }}
                                <RelativeTime :value="telegram.linked_at" />
                            </p>
                        </div>
                    </div>
                    <ul
                        class="grid grid-cols-1 gap-2 text-sm text-muted-foreground sm:grid-cols-2"
                    >
                        <li
                            v-for="feature in [
                                'alerts',
                                'ask',
                                'approve',
                                'lang',
                            ]"
                            :key="feature"
                            class="flex gap-2"
                        >
                            <CircleCheck
                                class="mt-0.5 size-4 shrink-0 text-primary"
                                aria-hidden="true"
                            />
                            {{
                                t(
                                    `ui.integrations.telegram.features.${feature}`,
                                )
                            }}
                        </li>
                    </ul>
                    <Button
                        variant="outline"
                        class="text-destructive hover:text-destructive"
                        @click="unlinkOpen = true"
                    >
                        <Link2Off class="size-4" aria-hidden="true" />
                        {{ t('ui.integrations.telegram.unlink') }}
                    </Button>
                </div>

                <div
                    v-else-if="link && !expired"
                    class="grid grid-cols-1 gap-6 sm:grid-cols-[auto_1fr] sm:items-center"
                >
                    <QrCode
                        :value="safeUrl ?? link.url"
                        :label="t('ui.integrations.telegram.qr_label')"
                        class="mx-auto sm:mx-0"
                    />
                    <div class="space-y-4">
                        <ol class="space-y-2.5">
                            <li
                                v-for="(step, index) in steps"
                                :key="step"
                                class="flex gap-3 text-sm"
                            >
                                <span
                                    class="inline-flex size-6 shrink-0 items-center justify-center rounded-full bg-primary-soft text-xs font-bold text-primary-soft-foreground"
                                >
                                    {{ index + 1 }}
                                </span>
                                <span>{{
                                    t(`ui.integrations.telegram.steps.${step}`)
                                }}</span>
                            </li>
                        </ol>
                        <div class="flex flex-wrap items-center gap-2">
                            <Button v-if="safeUrl" as-child>
                                <a
                                    :href="safeUrl"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    data-test="telegram-deeplink"
                                >
                                    <Smartphone
                                        class="size-4"
                                        aria-hidden="true"
                                    />
                                    {{
                                        t(
                                            'ui.integrations.telegram.open_telegram',
                                        )
                                    }}
                                    <ExternalLink
                                        class="size-3.5"
                                        aria-hidden="true"
                                    />
                                </a>
                            </Button>
                            <CopyButton
                                :value="link.url"
                                :label="t('ui.integrations.telegram.copy_link')"
                                size="icon"
                            />
                        </div>
                        <p
                            class="flex items-center gap-2 text-sm text-muted-foreground"
                            aria-live="polite"
                        >
                            <Spinner class="size-4" />
                            {{
                                t('ui.integrations.telegram.waiting', {
                                    time: formatCountdown(remaining),
                                })
                            }}
                        </p>
                    </div>
                </div>

                <div v-else class="space-y-4">
                    <p class="text-sm text-muted-foreground">
                        {{ t('ui.integrations.telegram.intro') }}
                    </p>
                    <div
                        v-if="expired"
                        class="flex items-center gap-2 rounded-lg border border-warning/40 bg-warning-soft px-3 py-2 text-sm text-warning-soft-foreground"
                        role="status"
                    >
                        <TriangleAlert
                            class="size-4 shrink-0"
                            aria-hidden="true"
                        />
                        {{ t('ui.integrations.telegram.expired') }}
                    </div>
                    <p
                        v-if="!botUsername"
                        class="flex items-start gap-2 rounded-lg border border-dashed px-3 py-2 text-xs text-muted-foreground"
                    >
                        <Bot
                            class="mt-0.5 size-4 shrink-0"
                            aria-hidden="true"
                        />
                        {{ t('ui.integrations.telegram.no_bot') }}
                    </p>
                    <p
                        v-if="error"
                        class="text-sm font-medium text-destructive"
                        role="alert"
                    >
                        {{ error }}
                    </p>
                    <Button
                        :disabled="http.processing || !botUsername"
                        data-test="telegram-link-button"
                        @click="createLink"
                    >
                        <Spinner v-if="http.processing" />
                        <component
                            :is="expired ? RefreshCw : QrIcon"
                            v-else
                            class="size-4"
                            aria-hidden="true"
                        />
                        {{
                            expired
                                ? t('ui.integrations.telegram.regenerate')
                                : t('ui.integrations.telegram.connect')
                        }}
                    </Button>
                </div>
            </div>
        </div>
    </SectionCard>

    <SectionCard
        :icon="Bot"
        :title="t('ui.integrations.preview.title')"
        :description="t('ui.integrations.preview.description')"
    >
        <div
            class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
        >
            <p class="text-sm text-muted-foreground">
                {{ t('ui.integrations.preview.body') }}
            </p>
            <Button as-child variant="outline" class="shrink-0">
                <Link :href="endpoints.assistant.index()">{{
                    t('ui.integrations.preview.cta')
                }}</Link>
            </Button>
        </div>
    </SectionCard>

    <ConfirmDialog
        v-model:open="unlinkOpen"
        destructive
        :processing="unlinking"
        :title="t('ui.integrations.telegram.unlink_title')"
        :description="t('ui.integrations.telegram.unlink_body')"
        :confirm-label="t('ui.integrations.telegram.unlink')"
        @confirm="unlink"
    />
</template>
