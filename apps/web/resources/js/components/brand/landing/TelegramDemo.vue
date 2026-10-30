<script setup lang="ts">
import { CheckCheck, FileText, Send } from '@lucide/vue';
import { computed } from 'vue';
import Reveal from '@/components/fx/Reveal.vue';
import TelegramIcon from '@/components/integrations/TelegramIcon.vue';
import { t } from '@/lib/i18n';

type Bubble = {
    from: 'bot' | 'user';
    key: string;
    buttons?: string[];
    cite?: boolean;
};

const bubbles = computed<Bubble[]>(() => [
    { from: 'bot', key: 'overdue', buttons: ['send_firm', 'snooze'] },
    { from: 'user', key: 'ask' },
    { from: 'bot', key: 'answer', cite: true },
    { from: 'bot', key: 'approval', buttons: ['approve', 'reject'] },
]);
</script>

<template>
    <div
        class="relative mx-auto w-full max-w-sm"
        role="img"
        :aria-label="t('marketing.telegram.demo_label')"
    >
        <div
            class="absolute -inset-10 rounded-full bg-[#2AABEE]/15 blur-3xl"
            aria-hidden="true"
        />
        <div
            class="relative overflow-hidden rounded-[2.5rem] border-8 border-foreground/90 bg-[#E7EBF0] shadow-lifted dark:border-foreground/20 dark:bg-[#0E1621]"
            aria-hidden="true"
        >
            <div
                class="flex items-center gap-3 bg-[#3F6A8F] px-4 py-3 text-white dark:bg-[#17212B]"
            >
                <TelegramIcon class="size-8" />
                <div class="min-w-0 leading-tight">
                    <p class="truncate text-sm font-semibold">
                        {{ t('marketing.telegram.bot_name') }}
                    </p>
                    <p class="text-xs opacity-90">
                        {{ t('marketing.telegram.bot_status') }}
                    </p>
                </div>
            </div>
            <div class="space-y-3 px-3 py-4">
                <Reveal
                    v-for="(bubble, index) in bubbles"
                    :key="bubble.key"
                    :delay="index * 0.35"
                    :blur="false"
                >
                    <div
                        :class="[
                            'flex',
                            bubble.from === 'user'
                                ? 'justify-end'
                                : 'justify-start',
                        ]"
                    >
                        <div
                            :class="[
                                'max-w-[85%] rounded-2xl px-3 py-2 text-[0.8125rem] leading-snug shadow-sm',
                                bubble.from === 'user'
                                    ? 'rounded-br-md bg-[#EFFDDE] text-[#0B1324] dark:bg-[#2B5278] dark:text-white'
                                    : 'rounded-bl-md bg-white text-[#0B1324] dark:bg-[#182533] dark:text-white',
                            ]"
                        >
                            <p>
                                {{
                                    t(
                                        `marketing.telegram.messages.${bubble.key}`,
                                    )
                                }}
                            </p>
                            <p
                                v-if="bubble.cite"
                                class="mt-1.5 flex items-center gap-1 rounded-md bg-[#2AABEE]/10 px-1.5 py-1 text-[0.6875rem] text-[#0F6AA3] dark:text-[#6AB7F0]"
                            >
                                <FileText class="size-3" />
                                {{ t('marketing.telegram.messages.source') }}
                            </p>
                            <p
                                class="mt-1 flex items-center justify-end gap-1 text-[0.625rem] opacity-75"
                            >
                                09:4{{ index }}
                                <CheckCheck
                                    v-if="bubble.from === 'user'"
                                    class="size-3"
                                />
                            </p>
                            <div
                                v-if="bubble.buttons"
                                class="mt-2 grid grid-cols-2 gap-1.5"
                            >
                                <span
                                    v-for="button in bubble.buttons"
                                    :key="button"
                                    class="rounded-lg bg-[#2AABEE]/12 px-2 py-1.5 text-center text-xs font-semibold text-[#0F6AA3] dark:bg-white/10 dark:text-[#6AB7F0]"
                                >
                                    {{
                                        t(
                                            `marketing.telegram.buttons.${button}`,
                                        )
                                    }}
                                </span>
                            </div>
                        </div>
                    </div>
                </Reveal>
            </div>
            <div
                class="flex items-center gap-2 border-t border-black/5 bg-white px-3 py-2.5 dark:border-white/5 dark:bg-[#17212B]"
            >
                <span
                    class="flex-1 rounded-full bg-[#F1F3F5] px-3 py-2 text-xs text-[#5F6468] dark:bg-[#242F3D] dark:text-[#AAB2BA]"
                    >{{ t('marketing.telegram.input') }}</span
                >
                <Send class="size-5 text-[#2AABEE]" />
            </div>
        </div>
    </div>
</template>
