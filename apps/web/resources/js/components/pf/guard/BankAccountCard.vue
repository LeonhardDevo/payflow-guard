<script setup lang="ts">
import { Hourglass, Landmark, UserRound } from '@lucide/vue';
import StatusBadge from '@/components/common/StatusBadge.vue';
import RiskBadge from '@/components/pf/RiskBadge.vue';
import { useCountdown } from '@/composables/pf/useCountdown';
import { useFormat } from '@/composables/useFormat';
import { t } from '@/lib/i18n';
import { BANK_STATUS_TONE } from '@/lib/pf/status';
import type { BankAccountView } from '@/types/payflow-guard';

const props = withDefaults(
    defineProps<{ account: BankAccountView; compact?: boolean }>(),
    { compact: false },
);
const fmt = useFormat();
const cooling = useCountdown(() =>
    props.account.status === 'verified'
        ? props.account.cooling_off_until
        : null,
);
</script>

<template>
    <div
        class="rounded-xl border bg-card p-4"
        data-test="bank-account"
        :data-status="account.status"
    >
        <div class="flex flex-wrap items-start justify-between gap-2">
            <div class="flex items-start gap-3">
                <span
                    class="inline-flex size-9 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground"
                >
                    <Landmark class="size-4" aria-hidden="true" />
                </span>
                <div class="min-w-0">
                    <p class="font-mono text-sm font-medium tracking-wide">
                        {{ account.display }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{ account.holder_name }} ·
                        {{
                            account.bank_name ??
                            t(`guard.bank.scheme.${account.scheme}`)
                        }}
                        · {{ account.bank_country }} · {{ account.currency }}
                    </p>
                    <p
                        v-if="account.swift_bic_masked"
                        class="font-mono text-xs text-muted-foreground"
                    >
                        SWIFT {{ account.swift_bic_masked }}
                    </p>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-1.5">
                <StatusBadge
                    :tone="BANK_STATUS_TONE[account.status]"
                    :label="t(`guard.bank.status.${account.status}`)"
                />
                <StatusBadge
                    v-if="account.verification_mode === 'solo'"
                    tone="ai"
                    :icon="UserRound"
                    :dot="false"
                    :label="t('guard.bank.solo')"
                />
                <RiskBadge
                    v-if="account.status === 'pending_verification'"
                    :band="account.risk_band"
                    :score="account.risk_score"
                />
            </div>
        </div>
        <dl
            v-if="!compact"
            class="mt-3 grid grid-cols-2 gap-x-4 gap-y-2 border-t pt-3 text-xs sm:grid-cols-3"
        >
            <div>
                <dt class="text-muted-foreground">
                    {{ t('guard.bank.version') }}
                </dt>
                <dd>
                    v{{ account.version }} ·
                    {{ t(`guard.bank.source.${account.source}`) }}
                </dd>
            </div>
            <div>
                <dt class="text-muted-foreground">
                    {{ t('guard.bank.requested') }}
                </dt>
                <dd>
                    {{ account.requested_by.name }} ·
                    {{ fmt.date(account.requested_at) }}
                </dd>
            </div>
            <div v-if="account.verified_at">
                <dt class="text-muted-foreground">
                    {{ t('guard.bank.verified') }}
                </dt>
                <dd>
                    {{ account.verified_by?.name ?? '—' }} ·
                    {{ fmt.date(account.verified_at) }}
                    <span
                        v-if="account.verification_method"
                        class="block text-muted-foreground"
                        >{{
                            t(
                                `guard.bank.methods.${account.verification_method}`,
                            )
                        }}</span
                    >
                </dd>
            </div>
            <div v-if="account.rejected_at" class="col-span-2">
                <dt class="text-muted-foreground">
                    {{ t('guard.bank.rejected') }}
                </dt>
                <dd>
                    {{ account.rejected_by?.name ?? '—' }} ·
                    {{ fmt.date(account.rejected_at) }} ·
                    {{ account.rejection_reason }}
                </dd>
            </div>
        </dl>
        <div
            v-if="cooling.label.value && account.cooling_off_until"
            class="mt-3 flex flex-col gap-0.5 rounded-lg border border-warning/40 bg-warning-soft px-2.5 py-1.5 text-xs text-warning-soft-foreground"
            data-test="cooling-off"
        >
            <p class="flex items-center gap-1.5 font-medium">
                <Hourglass class="size-3.5" aria-hidden="true" />
                <span aria-live="off">{{
                    t('guard.bank.cooling_off_left', {
                        time: cooling.label.value,
                    })
                }}</span>
            </p>
            <p>
                {{
                    t('guard.bank.cooling_off_until', {
                        date: fmt.dateTime(account.cooling_off_until, 'medium'),
                    })
                }}
            </p>
        </div>
    </div>
</template>
