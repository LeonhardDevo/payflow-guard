<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { CircleCheck, CircleX, ShieldCheck, ShieldQuestion } from '@lucide/vue';
import type { Component } from 'vue';
import { computed, onMounted, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useFormat } from '@/composables/useFormat';
import { applyLocale, t } from '@/lib/i18n';
import type { TrustedVerifiedProps } from '@/types/payflow-guard';

const props = defineProps<TrustedVerifiedProps>();
const fmt = useFormat();

onMounted(() => {
    void applyLocale(props.locale);
});

const icons: Record<TrustedVerifiedProps['state'], Component> = {
    verified: CircleCheck,
    declined: CircleX,
    decline_prompt: ShieldQuestion,
    invalid: CircleX,
};

const tone = computed(() =>
    props.state === 'verified'
        ? 'bg-success-soft text-success-soft-foreground'
        : props.state === 'decline_prompt'
          ? 'bg-warning-soft text-warning-soft-foreground'
          : 'bg-muted text-muted-foreground',
);

const declining = ref(false);

function decline(): void {
    if (!props.decline_url) {
        return;
    }

    declining.value = true;
    router.post(
        props.decline_url,
        {},
        { onFinish: () => (declining.value = false) },
    );
}
</script>

<template>
    <Head :title="t('guard.trusted.verify_title')">
        <meta name="robots" content="noindex, nofollow" />
    </Head>

    <div
        class="relative z-10 mx-auto flex w-full max-w-lg flex-1 flex-col gap-6 px-5 py-10 sm:px-8"
    >
        <div
            class="rounded-3xl border bg-card p-6 text-center shadow-soft sm:p-8"
            data-test="trusted-verified"
            :data-state="state"
        >
            <span
                :class="[
                    'mx-auto inline-flex size-14 items-center justify-center rounded-2xl',
                    tone,
                ]"
            >
                <component
                    :is="icons[state]"
                    class="size-7"
                    aria-hidden="true"
                />
            </span>
            <h1 class="mt-4 text-xl font-semibold tracking-tight">
                {{ t(`guard.trusted.verify.${state}.title`) }}
            </h1>
            <p class="mt-2 text-sm text-muted-foreground">
                {{
                    t(`guard.trusted.verify.${state}.body`, {
                        name: contact_name ?? '',
                        workspace: workspace ?? '',
                        date: active_from
                            ? fmt.dateTime(active_from, 'medium')
                            : '',
                    })
                }}
            </p>

            <div v-if="state === 'decline_prompt' && decline_url" class="mt-6">
                <Button
                    variant="destructive"
                    :disabled="declining"
                    data-test="trusted-decline-role"
                    @click="decline"
                >
                    <Spinner v-if="declining" class="size-4" />
                    {{ t('guard.trusted.verify.decline_button') }}
                </Button>
            </div>
            <p
                v-else-if="state === 'verified' && decline_url"
                class="mt-6 text-xs text-muted-foreground"
            >
                {{ t('guard.trusted.verify.changed_mind') }}
                <a :href="decline_url" class="underline underline-offset-2">{{
                    t('guard.trusted.verify.decline_link')
                }}</a>
            </p>
        </div>
        <p
            class="flex items-center justify-center gap-1.5 text-center text-xs text-muted-foreground"
        >
            <ShieldCheck class="size-3.5" aria-hidden="true" />
            <a
                :href="disclaimer_url"
                target="_blank"
                rel="noopener"
                class="underline underline-offset-2"
                >{{ t('guard.overview.disclaimer') }}</a
            >
        </p>
    </div>
</template>
