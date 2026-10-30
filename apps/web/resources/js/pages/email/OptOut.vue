<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { MailCheck, MailX, TriangleAlert } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { endpoints } from '@/lib/endpoints';
import { t } from '@/lib/i18n';
import type { OptOutStatus } from '@/types/legal';

const props = withDefaults(
    defineProps<{
        status?: OptOutStatus;
        token?: string | null;
        email?: string | null;
        workspace?: string | null;
    }>(),
    { status: 'confirm', token: null, email: null, workspace: null },
);

const page = usePage();
const processing = ref(false);

const sender = computed(() => props.workspace || page.props.name);

const view = computed(() => {
    switch (props.status) {
        case 'done':
            return {
                icon: MailCheck,
                tone: 'bg-success-soft text-success-soft-foreground',
                title: t('ui.email_opt_out.done_title'),
                body: t('ui.email_opt_out.done_body', { sender: sender.value }),
            };
        case 'invalid':
            return {
                icon: TriangleAlert,
                tone: 'bg-warning-soft text-warning-soft-foreground',
                title: t('ui.email_opt_out.invalid_title'),
                body: t('ui.email_opt_out.invalid_body'),
            };
        default:
            return {
                icon: MailX,
                tone: 'bg-primary-soft text-primary-soft-foreground',
                title: t('ui.email_opt_out.confirm_title'),
                body: t('ui.email_opt_out.confirm_body', {
                    sender: sender.value,
                }),
            };
    }
});

function confirm(): void {
    router.post(
        page.url,
        {},
        {
            preserveScroll: true,
            onStart: () => (processing.value = true),
            onFinish: () => (processing.value = false),
        },
    );
}
</script>

<template>
    <Head :title="view.title" />

    <div class="mx-auto flex w-full max-w-lg flex-1 items-center py-10">
        <div
            class="w-full rounded-3xl border bg-card/95 p-6 text-center shadow-lifted backdrop-blur sm:p-8"
            data-test="email-opt-out"
            :data-status="status"
        >
            <span
                :class="[
                    'mx-auto inline-flex size-14 items-center justify-center rounded-2xl',
                    view.tone,
                ]"
            >
                <component :is="view.icon" class="size-7" aria-hidden="true" />
            </span>
            <h1 class="mt-5 font-display text-2xl font-bold tracking-tight">
                {{ view.title }}
            </h1>
            <p class="mt-2 text-sm text-muted-foreground">{{ view.body }}</p>
            <p
                v-if="email && status !== 'invalid'"
                class="mt-4 inline-flex rounded-full bg-muted px-3 py-1 font-mono text-xs"
            >
                {{ email }}
            </p>

            <div v-if="status === 'confirm'" class="mt-6 grid gap-2">
                <Button
                    class="h-10 w-full"
                    :disabled="processing"
                    data-test="email-opt-out-confirm"
                    @click="confirm"
                >
                    <Spinner v-if="processing" />
                    {{ t('ui.email_opt_out.confirm') }}
                </Button>
                <p class="text-xs text-muted-foreground">
                    {{ t('ui.email_opt_out.confirm_hint') }}
                </p>
            </div>

            <p class="mt-6 text-xs text-muted-foreground">
                {{ t('ui.email_opt_out.notice') }}
                <Link
                    :href="endpoints.legal('privacy')"
                    class="font-medium text-primary underline underline-offset-2"
                    >{{ t('ui.legal.privacy') }}</Link
                >
            </p>
        </div>
    </div>
</template>
