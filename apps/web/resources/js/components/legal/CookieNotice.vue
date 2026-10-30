<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Cookie, X } from '@lucide/vue';
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { endpoints } from '@/lib/endpoints';
import { t } from '@/lib/i18n';
import { cookieNoticeCookie, cookieNoticeDismissed } from '@/lib/legal';

const props = withDefaults(defineProps<{ inline?: boolean }>(), {
    inline: false,
});

const OFFSET_VAR = '--cookie-notice-offset';

const visible = ref(false);
const root = ref<HTMLElement | null>(null);
let observer: ResizeObserver | null = null;

function setOffset(px: number): void {
    const html = document.documentElement;

    if (px > 0) {
        html.style.setProperty(OFFSET_VAR, `${px}px`);
        document.body.style.paddingBottom = `var(${OFFSET_VAR})`;
    } else {
        html.style.removeProperty(OFFSET_VAR);
        document.body.style.removeProperty('padding-bottom');
    }
}

function measure(): void {
    const el = root.value;

    if (!el || props.inline) {
        setOffset(0);

        return;
    }

    const rect = el.getBoundingClientRect();
    setOffset(Math.ceil(window.innerHeight - rect.top) + 8);
}

function stopObserving(): void {
    observer?.disconnect();
    observer = null;
    window.removeEventListener('resize', measure);
}

watch(visible, async (shown) => {
    if (!shown) {
        stopObserving();
        setOffset(0);

        return;
    }

    await nextTick();
    measure();

    if (typeof ResizeObserver !== 'undefined' && root.value) {
        observer = new ResizeObserver(measure);
        observer.observe(root.value);
    }

    window.addEventListener('resize', measure);
});

onMounted(() => {
    try {
        visible.value = !cookieNoticeDismissed(document.cookie);
    } catch {
        visible.value = false;
    }
});

onBeforeUnmount(() => {
    stopObserving();
    setOffset(0);
});

function dismiss(): void {
    visible.value = false;

    try {
        document.cookie = cookieNoticeCookie(
            window.location.protocol === 'https:',
        );
    } catch {
        return;
    }
}
</script>

<template>
    <Transition
        enter-active-class="transition duration-300 ease-out"
        enter-from-class="translate-y-4 opacity-0"
        leave-active-class="transition duration-200 ease-in"
        leave-to-class="translate-y-4 opacity-0"
    >
        <section
            v-if="visible"
            ref="root"
            role="region"
            :aria-label="t('ui.cookie_notice.label')"
            :class="
                inline
                    ? 'flex items-center gap-2 border-b bg-card px-4 py-2 text-card-foreground sm:gap-3 sm:px-6 print:hidden'
                    : 'fixed inset-x-0 bottom-0 z-30 flex items-center gap-2 border-t bg-card/95 px-4 py-2 text-card-foreground shadow-lifted backdrop-blur sm:inset-x-auto sm:right-4 sm:bottom-4 sm:max-w-xl sm:items-start sm:gap-3 sm:rounded-2xl sm:border sm:p-4 print:hidden'
            "
            data-test="cookie-notice"
            data-print-hide
        >
            <span
                class="hidden size-9 shrink-0 items-center justify-center rounded-xl bg-primary-soft text-primary-soft-foreground sm:inline-flex"
                aria-hidden="true"
            >
                <Cookie class="size-4.5" />
            </span>
            <p
                class="min-w-0 flex-1 truncate text-xs text-muted-foreground sm:text-sm sm:whitespace-normal"
            >
                {{ t('ui.cookie_notice.text') }}
                <Link
                    :href="endpoints.legal('cookies')"
                    class="font-medium text-primary underline underline-offset-2"
                    >{{ t('ui.cookie_notice.link') }}</Link
                >
            </p>
            <div class="flex shrink-0 items-center gap-1">
                <Button
                    size="sm"
                    class="hidden sm:inline-flex"
                    data-test="cookie-notice-dismiss"
                    @click="dismiss"
                >
                    {{ t('ui.cookie_notice.ok') }}
                </Button>
                <Button
                    size="icon"
                    variant="ghost"
                    class="size-8 sm:hidden"
                    :aria-label="t('ui.cookie_notice.ok')"
                    data-test="cookie-notice-close"
                    @click="dismiss"
                >
                    <X class="size-4" aria-hidden="true" />
                </Button>
            </div>
        </section>
    </Transition>
</template>
