<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    ArrowLeft,
    CreditCard,
    FlaskConical,
    Lock,
    ShieldCheck,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import AppLogoMark from '@/components/brand/AppLogoMark.vue';
import AppToaster from '@/components/shell/AppToaster.vue';
import LocaleSwitcher from '@/components/shell/LocaleSwitcher.vue';
import ThemeToggle from '@/components/shell/ThemeToggle.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useFormat } from '@/composables/useFormat';
import { endpoints } from '@/lib/endpoints';
import { t } from '@/lib/i18n';
import { refName } from '@/lib/utils';
import type { FakeCheckoutSession } from '@/types';

const props = defineProps<{ session: FakeCheckoutSession }>();

const fmt = useFormat();
const paying = ref(false);

const amount = computed(() =>
    fmt.value.money(props.session.amount, props.session.currency, {
        fractionDigits: 2,
    }),
);

const testCard = [
    { key: 'number', value: '4242 4242 4242 4242', mono: true },
    { key: 'expiry', value: '12 / 34', mono: true },
    { key: 'cvc', value: '123', mono: true },
] as const;

function pay(): void {
    router.post(
        endpoints.dev.fakeCheckoutPay(props.session.id).url,
        {},
        {
            onStart: () => {
                paying.value = true;
            },
            onFinish: () => {
                paying.value = false;
            },
        },
    );
}

function cancel(): void {
    window.location.assign(props.session.cancel_url);
}
</script>

<template>
    <Head :title="t('ui.checkout.title')" />

    <div class="flex min-h-svh flex-col bg-background">
        <div
            class="flex items-center justify-center gap-2 bg-warning px-4 py-2 text-center text-sm font-semibold text-warning-foreground"
            role="status"
            data-test="test-mode-banner"
        >
            <FlaskConical class="size-4 shrink-0" aria-hidden="true" />
            {{ t('ui.checkout.test_banner') }}
        </div>

        <main id="main-content" class="grid flex-1 grid-cols-1 lg:grid-cols-2">
            <section
                class="relative overflow-hidden bg-muted/50 px-6 py-10 sm:px-12 lg:py-16"
                :aria-label="t('ui.checkout.summary')"
            >
                <div class="mx-auto max-w-md space-y-10 lg:mr-0 lg:ml-auto">
                    <div class="flex items-center justify-between gap-3">
                        <button
                            type="button"
                            class="group inline-flex items-center gap-2 rounded-lg text-sm font-medium text-muted-foreground hover:text-foreground"
                            @click="cancel"
                        >
                            <ArrowLeft
                                class="size-4 transition-transform group-hover:-translate-x-0.5"
                                aria-hidden="true"
                            />
                            <AppLogoMark class="size-6" />
                            <span class="truncate">{{
                                refName(session.workspace)
                            }}</span>
                        </button>
                        <span
                            class="rounded-full bg-warning-soft px-2.5 py-1 text-xs font-bold tracking-wide text-warning-soft-foreground uppercase"
                        >
                            {{ t('ui.checkout.test_pill') }}
                        </span>
                    </div>

                    <div class="space-y-2">
                        <p class="text-sm text-muted-foreground">
                            {{
                                session.kind === 'subscription'
                                    ? t('ui.checkout.subscribe_to')
                                    : t('ui.checkout.pay_for')
                            }}
                        </p>
                        <h1 class="font-display text-2xl font-bold">
                            {{ session.title }}
                        </h1>
                        <p
                            class="font-display text-5xl font-extrabold tracking-tight tabular-nums"
                            data-test="checkout-amount"
                        >
                            {{ amount }}
                        </p>
                    </div>

                    <dl class="space-y-3 border-t pt-6 text-sm">
                        <div class="flex justify-between gap-4">
                            <dt class="text-muted-foreground">
                                {{ session.title }}
                            </dt>
                            <dd class="tabular-nums">{{ amount }}</dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-muted-foreground">
                                {{ t('ui.checkout.tax') }}
                            </dt>
                            <dd class="tabular-nums">
                                {{
                                    fmt.money(0, session.currency, {
                                        fractionDigits: 2,
                                    })
                                }}
                            </dd>
                        </div>
                        <div
                            class="flex justify-between gap-4 border-t pt-3 font-semibold"
                        >
                            <dt>{{ t('ui.checkout.total') }}</dt>
                            <dd class="tabular-nums">{{ amount }}</dd>
                        </div>
                    </dl>
                </div>
            </section>

            <section
                class="px-6 py-10 sm:px-12 lg:py-16"
                :aria-label="t('ui.checkout.payment')"
            >
                <div class="mx-auto max-w-md space-y-6 lg:mr-auto lg:ml-0">
                    <div class="flex items-center justify-between gap-2">
                        <h2 class="font-display text-lg font-semibold">
                            {{ t('ui.checkout.payment') }}
                        </h2>
                        <div class="flex items-center gap-1">
                            <LocaleSwitcher />
                            <ThemeToggle />
                        </div>
                    </div>

                    <div
                        class="space-y-3 rounded-2xl border bg-card p-5 shadow-soft"
                    >
                        <p class="flex items-center gap-2 text-sm font-medium">
                            <CreditCard
                                class="size-4 text-muted-foreground"
                                aria-hidden="true"
                            />
                            {{ t('ui.checkout.test_card') }}
                        </p>
                        <dl class="grid grid-cols-[1fr_auto_auto] gap-3">
                            <div
                                v-for="field in testCard"
                                :key="field.key"
                                :class="
                                    field.key === 'number' ? 'col-span-3' : ''
                                "
                            >
                                <dt class="text-xs text-muted-foreground">
                                    {{ t(`ui.checkout.card.${field.key}`) }}
                                </dt>
                                <dd
                                    class="mt-1 rounded-lg border bg-muted/50 px-3 py-2 font-mono text-sm tracking-wider"
                                >
                                    {{ field.value }}
                                </dd>
                            </div>
                        </dl>
                        <p class="text-xs text-muted-foreground">
                            {{ t('ui.checkout.no_real_payment') }}
                        </p>
                    </div>

                    <Button
                        class="h-12 w-full text-base"
                        :disabled="paying"
                        data-test="fake-checkout-pay"
                        @click="pay"
                    >
                        <Spinner v-if="paying" />
                        <Lock v-else class="size-4" aria-hidden="true" />
                        {{ t('ui.checkout.pay', { amount }) }}
                    </Button>
                    <Button
                        variant="ghost"
                        class="w-full"
                        :disabled="paying"
                        data-test="fake-checkout-cancel"
                        @click="cancel"
                    >
                        {{ t('ui.checkout.cancel') }}
                    </Button>

                    <p
                        class="flex items-start gap-2 text-xs text-muted-foreground"
                    >
                        <ShieldCheck
                            class="mt-0.5 size-4 shrink-0"
                            aria-hidden="true"
                        />
                        {{ t('ui.checkout.webhook_note') }}
                    </p>
                </div>
            </section>
        </main>
    </div>
    <AppToaster />
</template>
