<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    CircleAlert,
    CircleCheck,
    CreditCard,
    FlaskConical,
    Info,
    PlugZap,
    RefreshCw,
    Unplug,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import ConfirmDialog from '@/components/common/ConfirmDialog.vue';
import CopyButton from '@/components/common/CopyButton.vue';
import SectionCard from '@/components/common/SectionCard.vue';
import StatusBadge from '@/components/common/StatusBadge.vue';
import InputError from '@/components/InputError.vue';
import GuardErrorDialog from '@/components/pf/guard/GuardErrorDialog.vue';
import StepUpDialog from '@/components/pf/guard/StepUpDialog.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useStepUp } from '@/composables/pf/useStepUp';
import { useFormat } from '@/composables/useFormat';
import { t } from '@/lib/i18n';
import { payments, workspace } from '@/routes/settings';
import { demo, disconnect, stripe, yookassa } from '@/routes/settings/payments';
import { verify } from '@/routes/settings/payments/stripe';
import type { PaymentAccount, PaymentDriver } from '@/types/payflow';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'ui.settings.title', href: workspace() },
            { title: 'pf.settings.nav.payments', href: payments() },
        ],
    },
});

const props = defineProps<{
    account: PaymentAccount | null;
    market: 'ru' | 'intl';
    available_drivers: PaymentDriver[];
    demo_allowed: boolean;
    stripe_required_permissions: string[];
    yookassa_webhook_url: string;
    money_flow_notice: boolean;
    terms_url: string;
}>();

const fmt = useFormat();
const stepUp = useStepUp();

const statusTone = computed(() => {
    switch (props.account?.status) {
        case 'active':
            return 'success' as const;
        case 'restricted':
            return 'destructive' as const;
        case 'onboarding':
            return 'warning' as const;
        default:
            return 'neutral' as const;
    }
});

const stripeForm = useForm({ restricted_key: '', display_name: '' });
const yooForm = useForm({ shop_id: '', secret_key: '' });

function connectStripe(): void {
    stepUp.run(() => {
        stripeForm
            .transform((data) => ({
                restricted_key: data.restricted_key.trim(),
                display_name: data.display_name || null,
            }))
            .post(stripe().url, {
                preserveScroll: true,
                onSuccess: () => stripeForm.reset(),
            });
    }, true);
}

function connectYookassa(): void {
    stepUp.run(() => {
        yooForm.post(yookassa().url, {
            preserveScroll: true,
            onSuccess: () => yooForm.reset(),
        });
    }, true);
}

const busy = ref<string | null>(null);

function post(key: string, url: string): void {
    busy.value = key;
    router.post(
        url,
        {},
        { preserveScroll: true, onFinish: () => (busy.value = null) },
    );
}

const disconnectOpen = ref(false);

function confirmDisconnect(): void {
    stepUp.run(() => {
        busy.value = 'disconnect';
        router.delete(disconnect().url, {
            preserveScroll: true,
            onFinish: () => {
                busy.value = null;
                disconnectOpen.value = false;
            },
        });
    }, true);
}

const replacing = ref(false);
const showConnect = computed(
    () =>
        !props.account ||
        props.account.status === 'disconnected' ||
        replacing.value,
);
</script>

<template>
    <Head :title="t('pf.settings.nav.payments')" />

    <div class="flex flex-col gap-6">
        <div
            v-if="money_flow_notice"
            class="flex items-start gap-3 rounded-2xl border border-info/30 bg-info-soft p-4 text-sm text-info-soft-foreground"
        >
            <Info class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
            <p>
                {{ t('pf.settings.payments.money_flow') }}
                <a
                    :href="terms_url"
                    target="_blank"
                    rel="noopener"
                    class="underline underline-offset-2"
                    >{{ t('pf.settings.payments.terms') }}</a
                >
            </p>
        </div>

        <SectionCard
            v-if="account && account.status !== 'disconnected'"
            :icon="CreditCard"
            :title="t('pf.settings.payments.account')"
        >
            <div
                class="flex flex-col gap-4"
                data-test="payment-account"
                :data-status="account.status"
            >
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p class="font-medium">
                            {{
                                account.display_name ??
                                t(`pf.provider.${account.driver}`)
                            }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ t(`pf.provider.${account.driver}`) }}
                            <template v-if="account.key_hint">
                                ·
                                <span class="font-mono">{{
                                    account.key_hint
                                }}</span></template
                            >
                            <template v-if="account.country">
                                · {{ account.country }}</template
                            >
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <StatusBadge
                            :tone="
                                account.mode === 'live' ? 'success' : 'warning'
                            "
                            :dot="false"
                            :label="
                                t(`pf.settings.payments.mode.${account.mode}`)
                            "
                        />
                        <StatusBadge
                            :tone="statusTone"
                            :label="
                                t(
                                    `pf.settings.payments.status.${account.status}`,
                                )
                            "
                        />
                    </div>
                </div>
                <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-xs text-muted-foreground">
                            {{ t('pf.settings.payments.charges') }}
                        </dt>
                        <dd class="flex items-center gap-1.5">
                            <CircleCheck
                                v-if="account.charges_enabled"
                                class="size-4 text-success"
                                aria-hidden="true"
                            />
                            <CircleAlert
                                v-else
                                class="size-4 text-destructive"
                                aria-hidden="true"
                            />
                            {{
                                account.charges_enabled
                                    ? t('pf.settings.payments.charges_on')
                                    : t('pf.settings.payments.charges_off')
                            }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">
                            {{ t('pf.settings.payments.currencies') }}
                        </dt>
                        <dd>
                            {{ account.supported_currencies.join(', ') || '—' }}
                        </dd>
                    </div>
                    <div v-if="account.webhook_url_masked">
                        <dt class="text-xs text-muted-foreground">
                            {{ t('pf.settings.payments.webhook') }}
                        </dt>
                        <dd class="font-mono text-xs">
                            {{ account.webhook_url_masked }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">
                            {{ t('pf.settings.payments.last_event') }}
                        </dt>
                        <dd>
                            {{
                                account.last_event_at
                                    ? fmt.relative(account.last_event_at)
                                    : '—'
                            }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">
                            {{ t('pf.settings.payments.connected_by') }}
                        </dt>
                        <dd>
                            {{ account.connected_by.name }} ·
                            {{ fmt.date(account.created_at) }}
                        </dd>
                    </div>
                </dl>
                <div
                    v-if="account.missing_permissions.length"
                    class="rounded-xl border border-destructive/40 bg-destructive-soft p-3 text-sm text-destructive-soft-foreground"
                >
                    <p class="font-medium">
                        {{ t('pf.settings.payments.missing_permissions') }}
                    </p>
                    <p class="font-mono text-xs">
                        {{ account.missing_permissions.join(', ') }}
                    </p>
                </div>
                <p
                    v-if="account.last_error"
                    class="rounded-xl border border-destructive/40 bg-destructive-soft p-3 text-sm text-destructive-soft-foreground"
                >
                    {{ account.last_error }}
                </p>
                <div class="flex flex-wrap gap-2">
                    <Button
                        v-if="account.driver === 'stripe'"
                        variant="outline"
                        size="sm"
                        :disabled="Boolean(busy)"
                        @click="post('verify', verify().url)"
                    >
                        <Spinner v-if="busy === 'verify'" class="size-4" />
                        <RefreshCw v-else class="size-4" aria-hidden="true" />{{
                            t('pf.settings.payments.verify')
                        }}
                    </Button>
                    <Button
                        v-if="account.driver !== 'fake'"
                        variant="outline"
                        size="sm"
                        @click="replacing = !replacing"
                        >{{ t('pf.settings.payments.replace_key') }}</Button
                    >
                    <Button
                        variant="ghost"
                        size="sm"
                        class="text-destructive"
                        data-test="payment-disconnect"
                        @click="disconnectOpen = true"
                    >
                        <Unplug class="size-4" aria-hidden="true" />{{
                            t('pf.settings.payments.disconnect')
                        }}
                    </Button>
                </div>
            </div>
        </SectionCard>

        <template v-if="showConnect">
            <SectionCard
                v-if="available_drivers.includes('stripe')"
                :icon="PlugZap"
                title="Stripe"
                :description="t('pf.settings.payments.stripe_hint')"
            >
                <form
                    class="grid gap-4"
                    data-test="stripe-connect"
                    @submit.prevent="connectStripe"
                >
                    <div class="grid gap-2">
                        <Label for="stripe-key">{{
                            t('pf.settings.payments.restricted_key')
                        }}</Label>
                        <Input
                            id="stripe-key"
                            v-model="stripeForm.restricted_key"
                            type="password"
                            autocomplete="off"
                            spellcheck="false"
                            placeholder="rk_live_…"
                            class="font-mono"
                        />
                        <InputError
                            :message="stripeForm.errors.restricted_key"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="stripe-name">{{
                            t('pf.settings.payments.display_name')
                        }}</Label>
                        <Input
                            id="stripe-name"
                            v-model="stripeForm.display_name"
                            maxlength="200"
                        />
                    </div>
                    <div class="rounded-xl border bg-muted/30 p-3 text-xs">
                        <p class="mb-1 font-medium">
                            {{ t('pf.settings.payments.required_permissions') }}
                        </p>
                        <ul
                            class="grid grid-cols-1 gap-0.5 font-mono sm:grid-cols-2"
                        >
                            <li
                                v-for="permission in stripe_required_permissions"
                                :key="permission"
                            >
                                {{ permission }}
                            </li>
                        </ul>
                    </div>
                    <Button
                        type="submit"
                        class="justify-self-start"
                        :disabled="
                            stripeForm.processing || !stripeForm.restricted_key
                        "
                    >
                        <Spinner
                            v-if="stripeForm.processing"
                            class="size-4"
                        />{{ t('pf.settings.payments.connect') }}
                    </Button>
                </form>
            </SectionCard>

            <SectionCard
                v-if="available_drivers.includes('yookassa')"
                :icon="PlugZap"
                :title="t('pf.provider.yookassa')"
                :description="t('pf.settings.payments.yookassa_hint')"
            >
                <form
                    class="grid gap-4"
                    data-test="yookassa-connect"
                    @submit.prevent="connectYookassa"
                >
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="yoo-shop">shopId</Label>
                            <Input
                                id="yoo-shop"
                                v-model="yooForm.shop_id"
                                inputmode="numeric"
                                autocomplete="off"
                                class="font-mono"
                            />
                            <InputError :message="yooForm.errors.shop_id" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="yoo-secret">{{
                                t('pf.settings.payments.secret_key')
                            }}</Label>
                            <Input
                                id="yoo-secret"
                                v-model="yooForm.secret_key"
                                type="password"
                                autocomplete="off"
                                class="font-mono"
                            />
                            <InputError :message="yooForm.errors.secret_key" />
                        </div>
                    </div>
                    <div class="grid gap-1">
                        <p class="text-xs text-muted-foreground">
                            {{ t('pf.settings.payments.yookassa_webhook') }}
                        </p>
                        <div
                            class="flex items-center gap-2 rounded-xl border bg-muted/30 px-3 py-2 text-xs"
                        >
                            <span class="min-w-0 flex-1 truncate font-mono">{{
                                yookassa_webhook_url
                            }}</span>
                            <CopyButton :value="yookassa_webhook_url" />
                        </div>
                    </div>
                    <Button
                        type="submit"
                        class="justify-self-start"
                        :disabled="
                            yooForm.processing ||
                            !yooForm.shop_id ||
                            !yooForm.secret_key
                        "
                    >
                        <Spinner v-if="yooForm.processing" class="size-4" />{{
                            t('pf.settings.payments.connect')
                        }}
                    </Button>
                </form>
            </SectionCard>

            <SectionCard
                v-if="demo_allowed"
                :icon="FlaskConical"
                :title="t('pf.settings.payments.demo')"
                :description="t('pf.settings.payments.demo_hint')"
            >
                <Button
                    variant="outline"
                    :disabled="Boolean(busy)"
                    data-test="payment-demo"
                    @click="post('demo', demo().url)"
                >
                    <Spinner v-if="busy === 'demo'" class="size-4" />{{
                        t('pf.settings.payments.use_demo')
                    }}
                </Button>
            </SectionCard>
        </template>
    </div>

    <ConfirmDialog
        v-model:open="disconnectOpen"
        destructive
        :title="t('pf.settings.payments.disconnect_title')"
        :description="t('pf.settings.payments.disconnect_body')"
        :confirm-label="t('pf.settings.payments.disconnect')"
        :processing="busy === 'disconnect'"
        @confirm="confirmDisconnect"
    />
    <StepUpDialog />
    <GuardErrorDialog />
</template>
