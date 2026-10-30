<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { CirclePause, UserCheck } from '@lucide/vue';
import StatusBadge from '@/components/common/StatusBadge.vue';
import RiskBadge from '@/components/pf/RiskBadge.vue';
import { useFormat } from '@/composables/useFormat';
import { usePf } from '@/composables/pf/usePf';
import { t } from '@/lib/i18n';
import { PAYOUT_STATUS_TONE } from '@/lib/pf/status';
import { show } from '@/routes/payouts';
import type { PayoutListItem } from '@/types/payflow-guard';

withDefaults(defineProps<{ payout: PayoutListItem; hideVendor?: boolean }>(), {
    hideVendor: false,
});
const pf = usePf();
const fmt = useFormat();
</script>

<template>
    <li>
        <Link
            :href="show(payout.id).url"
            class="flex flex-wrap items-center justify-between gap-3 px-5 py-3 transition-colors hover:bg-muted/40 focus-visible:bg-muted/40 focus-visible:outline-none"
            data-test="payout-row"
            :data-number="payout.number"
            :data-status="payout.status"
        >
            <span class="min-w-0">
                <span class="flex flex-wrap items-center gap-2">
                    <span class="font-mono text-sm font-medium">{{
                        payout.number
                    }}</span>
                    <span v-if="!hideVendor" class="truncate text-sm">{{
                        payout.vendor.name
                    }}</span>
                    <StatusBadge
                        v-if="payout.awaiting_me"
                        tone="ai"
                        :icon="UserCheck"
                        :dot="false"
                        :label="t('guard.payouts.awaiting_me_badge')"
                    />
                </span>
                <span class="block truncate text-xs text-muted-foreground">
                    {{ payout.description }}
                    <template v-if="payout.due_date">
                        ·
                        {{
                            t('guard.fields.due_on', {
                                date: fmt.date(payout.due_date),
                            })
                        }}</template
                    >
                </span>
            </span>
            <span class="flex flex-wrap items-center gap-2">
                <RiskBadge
                    v-if="payout.risk && payout.status !== 'released'"
                    :band="payout.risk.band"
                />
                <StatusBadge
                    :tone="PAYOUT_STATUS_TONE[payout.status]"
                    :label="t(`guard.payout_status.${payout.status}`)"
                />
                <span
                    v-if="payout.status === 'pending_approval'"
                    class="text-xs text-muted-foreground tabular-nums"
                >
                    {{ payout.approvals_count }}/{{ payout.required_approvals }}
                </span>
                <span
                    v-if="payout.hold_reason"
                    class="inline-flex items-center gap-1 text-xs text-warning-soft-foreground"
                >
                    <CirclePause class="size-3.5" aria-hidden="true" />{{
                        t(`guard.hold_reason.${payout.hold_reason}`)
                    }}
                </span>
                <span class="min-w-24 text-right font-medium tabular-nums">{{
                    pf.money(payout.amount)
                }}</span>
            </span>
        </Link>
    </li>
</template>
