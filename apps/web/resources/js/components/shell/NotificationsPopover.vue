<script setup lang="ts">
import { Link, useHttp, usePage } from '@inertiajs/vue3';
import {
    Bell,
    BellOff,
    CircleAlert,
    CircleCheck,
    Info,
    Sparkles,
    TriangleAlert,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import EmptyState from '@/components/common/EmptyState.vue';
import RelativeTime from '@/components/common/RelativeTime.vue';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { ScrollArea } from '@/components/ui/scroll-area';
import { endpoints } from '@/lib/endpoints';
import { t, tc } from '@/lib/i18n';
import type { AppNotification, NotificationKind } from '@/types/shared';

const page = usePage();

const items = computed<AppNotification[]>(
    () => page.props.notifications?.items ?? [],
);
const open = ref(false);
const acknowledged = ref(false);

watch(
    () => page.props.notifications,
    () => {
        acknowledged.value = false;
    },
);

const unread = computed(() =>
    acknowledged.value
        ? 0
        : (page.props.notifications?.unread ??
          items.value.filter((item) => !item.read).length),
);

const markRead = useHttp<Record<string, never>, { unread?: number }>({});

watch(open, async (isOpen) => {
    if (!isOpen || unread.value === 0 || markRead.processing) {
        return;
    }

    try {
        await markRead.post(endpoints.notifications.read().url);
        acknowledged.value = true;
    } catch {}
});

const icons: Record<NotificationKind, typeof Info> = {
    info: Info,
    success: CircleCheck,
    warning: TriangleAlert,
    danger: CircleAlert,
    ai: Sparkles,
};

const tones: Record<NotificationKind, string> = {
    info: 'bg-info-soft text-info-soft-foreground',
    success: 'bg-success-soft text-success-soft-foreground',
    warning: 'bg-warning-soft text-warning-soft-foreground',
    danger: 'bg-destructive-soft text-destructive-soft-foreground',
    ai: 'bg-ai-soft text-ai-soft-foreground',
};
</script>

<template>
    <Popover v-model:open="open">
        <PopoverTrigger as-child>
            <Button
                variant="ghost"
                size="icon"
                class="relative"
                :aria-label="
                    unread > 9
                        ? `9+ · ${tc('ui.notifications.unread', unread)}`
                        : unread > 0
                          ? tc('ui.notifications.unread', unread)
                          : t('ui.notifications.title')
                "
                data-test="notifications-trigger"
            >
                <Bell class="size-[1.15rem]" aria-hidden="true" />
                <span
                    v-if="unread > 0"
                    class="absolute top-1.5 right-1.5 inline-flex min-w-4 items-center justify-center rounded-full bg-destructive px-1 text-[0.625rem] leading-4 font-bold text-destructive-foreground ring-2 ring-background"
                    aria-hidden="true"
                >
                    {{ unread > 9 ? '9+' : unread }}
                </span>
            </Button>
        </PopoverTrigger>
        <PopoverContent align="end" class="w-[min(24rem,calc(100vw-2rem))] p-0">
            <div class="flex items-center justify-between border-b px-4 py-3">
                <p class="font-display text-sm font-semibold">
                    {{ t('ui.notifications.title') }}
                </p>
                <span
                    v-if="unread > 0"
                    class="rounded-full bg-primary-soft px-2 py-0.5 text-xs font-semibold text-primary-soft-foreground"
                >
                    {{ tc('ui.notifications.unread', unread) }}
                </span>
            </div>
            <ScrollArea v-if="items.length" class="max-h-96">
                <ul class="divide-y">
                    <li v-for="item in items" :key="item.id">
                        <component
                            :is="item.href ? Link : 'div'"
                            :href="item.href ?? undefined"
                            :class="[
                                'flex gap-3 px-4 py-3 transition-colors',
                                item.href ? 'hover:bg-muted/60' : '',
                                item.read ? '' : 'bg-primary-soft/30',
                            ]"
                        >
                            <span
                                :class="[
                                    'mt-0.5 inline-flex size-8 shrink-0 items-center justify-center rounded-lg',
                                    tones[item.kind ?? 'info'],
                                ]"
                                aria-hidden="true"
                            >
                                <component
                                    :is="icons[item.kind ?? 'info']"
                                    class="size-4"
                                />
                            </span>
                            <span class="min-w-0 flex-1 space-y-0.5">
                                <span class="block text-sm font-medium">{{
                                    item.title
                                }}</span>
                                <span
                                    v-if="item.body"
                                    class="line-clamp-2 block text-xs text-muted-foreground"
                                    >{{ item.body }}</span
                                >
                                <span
                                    class="block text-xs text-muted-foreground"
                                >
                                    <RelativeTime :value="item.created_at" />
                                </span>
                            </span>
                            <span
                                v-if="!item.read"
                                class="mt-2 size-2 shrink-0 rounded-full bg-primary"
                                :aria-label="t('ui.notifications.new')"
                            />
                        </component>
                    </li>
                </ul>
            </ScrollArea>
            <EmptyState
                v-else
                compact
                tone="muted"
                :icon="BellOff"
                :title="t('ui.notifications.empty_title')"
                :description="t('ui.notifications.empty_body')"
            />
        </PopoverContent>
    </Popover>
</template>
