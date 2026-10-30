<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import {
    CircleCheck,
    CircleX,
    Clock,
    ShieldCheck,
    TriangleAlert,
} from '@lucide/vue';
import { computed, onMounted } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { useFormat } from '@/composables/useFormat';
import { applyLocale, t } from '@/lib/i18n';
import { formatMoney } from '@/lib/pf/money';
import type { TrustedConfirmProps } from '@/types/payflow-guard';

const props = defineProps<TrustedConfirmProps>();
const page = usePage();
const fmt = useFormat();

onMounted(() => {
    void applyLocale(props.locale);
});

const c = computed(() => props.confirmation);
const s = computed(() => c.value.snapshot);
const pending = computed(() => c.value.status === 'pending');

const amount = computed(() =>
    typeof s.value.amount_minor === 'number' &&
    typeof s.value.currency === 'string'
        ? formatMoney(
              { minor: s.value.amount_minor, currency: s.value.currency },
              props.locale,
          )
        : null,
);

const form = useForm({
    decision: 'confirm' as 'confirm' | 'decline',
    comment: '',
});

function decide(decision: 'confirm' | 'decline'): void {
    form.decision = decision;
    form.transform((data) => ({
        decision: data.decision,
        comment: data.comment || null,
    })).post(page.url, {
        preserveScroll: true,
    });
}

const statusIcon = computed(() =>
    c.value.status === 'confirmed'
        ? CircleCheck
        : c.value.status === 'declined'
          ? CircleX
          : Clock,
);
</script>

<template>
    <Head :title="t('guard.trusted.title')">
        <meta name="robots" content="noindex, nofollow" />
    </Head>

    <div
        class="relative z-10 mx-auto flex w-full max-w-xl flex-1 flex-col gap-6 px-5 py-8 sm:px-8"
    >
        <div class="rounded-3xl border bg-card p-6 shadow-soft sm:p-8">
            <div class="flex items-center gap-3">
                <span
                    class="inline-flex size-11 items-center justify-center rounded-2xl bg-primary/10 text-primary"
                >
                    <ShieldCheck class="size-6" aria-hidden="true" />
                </span>
                <div>
                    <p class="text-xs text-muted-foreground">
                        {{ c.workspace }}
                    </p>
                    <h1 class="text-xl font-semibold tracking-tight">
                        {{ t(`guard.trusted.subject.${c.subject}`) }}
                    </h1>
                </div>
            </div>

            <p class="mt-4 text-sm text-muted-foreground">
                {{
                    t('guard.trusted.intro', {
                        requester: c.requester,
                        workspace: c.workspace,
                    })
                }}
            </p>

            <dl
                class="mt-5 grid grid-cols-2 gap-3 rounded-2xl border bg-muted/30 p-4 text-sm"
                data-test="trusted-snapshot"
            >
                <div v-if="amount" class="col-span-2">
                    <dt class="text-xs text-muted-foreground">
                        {{ t('guard.payout.amount') }}
                    </dt>
                    <dd class="text-2xl font-semibold tabular-nums">
                        {{ amount }}
                    </dd>
                </div>
                <div v-if="s.vendor">
                    <dt class="text-xs text-muted-foreground">
                        {{ t('guard.payout.vendor') }}
                    </dt>
                    <dd>{{ s.vendor }}</dd>
                </div>
                <div v-if="s.number">
                    <dt class="text-xs text-muted-foreground">
                        {{ t('guard.fields.number') }}
                    </dt>
                    <dd class="font-mono text-xs">{{ s.number }}</dd>
                </div>
                <div v-if="s.last4">
                    <dt class="text-xs text-muted-foreground">
                        {{ t('guard.trusted.account') }}
                    </dt>
                    <dd class="font-mono">
                        •••• {{ s.last4
                        }}<span v-if="s.bank_country">
                            · {{ s.bank_country }}</span
                        >
                    </dd>
                </div>
                <div v-if="s.risk_band">
                    <dt class="text-xs text-muted-foreground">
                        {{ t('guard.fields.risk') }}
                    </dt>
                    <dd>{{ t(`pf.risk.${s.risk_band}`) }}</dd>
                </div>
                <div v-if="s.description" class="col-span-2">
                    <dt class="text-xs text-muted-foreground">
                        {{ t('guard.payout.description') }}
                    </dt>
                    <dd>{{ s.description }}</dd>
                </div>
            </dl>

            <div
                v-if="c.signals.length"
                class="mt-4 rounded-2xl border border-warning/40 bg-warning-soft p-4 text-sm text-warning-soft-foreground"
            >
                <p class="flex items-center gap-2 font-medium">
                    <TriangleAlert class="size-4" aria-hidden="true" />{{
                        t('guard.trusted.signals')
                    }}
                </p>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    <li v-for="signal in c.signals" :key="signal">
                        {{ signal }}
                    </li>
                </ul>
            </div>

            <template v-if="pending">
                <p class="mt-5 text-xs text-muted-foreground">
                    {{
                        t('guard.trusted.expires', {
                            date: fmt.dateTime(c.expires_at, 'medium'),
                        })
                    }}
                </p>
                <div class="mt-4 grid gap-2">
                    <Label for="tc-comment">{{
                        t('guard.trusted.comment')
                    }}</Label>
                    <Textarea
                        id="tc-comment"
                        v-model="form.comment"
                        rows="2"
                        maxlength="500"
                    />
                    <InputError
                        :message="form.errors.comment ?? form.errors.decision"
                    />
                </div>
                <div class="mt-5 grid gap-3 sm:grid-cols-2">
                    <div class="flex flex-col gap-1.5">
                        <Button
                            variant="outline"
                            class="text-destructive"
                            :disabled="form.processing"
                            data-test="trusted-decline"
                            @click="decide('decline')"
                        >
                            <Spinner
                                v-if="
                                    form.processing &&
                                    form.decision === 'decline'
                                "
                                class="size-4"
                            />
                            <CircleX v-else class="size-4" aria-hidden="true" />
                            {{ t('guard.trusted.decline') }}
                        </Button>
                        <p class="text-xs text-muted-foreground">
                            {{ t('guard.trusted.decline_effect') }}
                        </p>
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Button
                            :disabled="form.processing"
                            data-test="trusted-confirm"
                            @click="decide('confirm')"
                        >
                            <Spinner
                                v-if="
                                    form.processing &&
                                    form.decision === 'confirm'
                                "
                                class="size-4"
                            />
                            <CircleCheck
                                v-else
                                class="size-4"
                                aria-hidden="true"
                            />
                            {{ t('guard.trusted.confirm') }}
                        </Button>
                        <p class="text-xs text-muted-foreground">
                            {{ t('guard.trusted.confirm_effect') }}
                        </p>
                    </div>
                </div>
            </template>
            <div
                v-else
                class="mt-6 flex items-start gap-3 rounded-2xl border p-4 text-sm"
                data-test="trusted-status"
                :data-status="c.status"
            >
                <component
                    :is="statusIcon"
                    class="mt-0.5 size-5 shrink-0"
                    aria-hidden="true"
                />
                <p>{{ t(`guard.trusted.done.${c.status}`) }}</p>
            </div>
        </div>

        <p class="text-center text-xs text-muted-foreground">
            {{ t('guard.trusted.not_you') }}
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
