<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowLeftRight, ChevronDown, UserRound } from '@lucide/vue';
import { computed, ref } from 'vue';
import StatusBadge from '@/components/common/StatusBadge.vue';
import RiskBadge from '@/components/pf/RiskBadge.vue';
import { useFormat } from '@/composables/useFormat';
import { t } from '@/lib/i18n';
import { accountHistory } from '@/lib/pf/guard';
import { BANK_STATUS_TONE } from '@/lib/pf/status';
import { show as reviewChange } from '@/routes/guard/bank-changes';
import type { BankAccountView } from '@/types/payflow-guard';

const props = defineProps<{
    accounts: BankAccountView[];
    currentId: number | null;
}>();
const fmt = useFormat();

const entries = computed(() => accountHistory(props.accounts));
const open = ref<Set<number>>(new Set());

function toggle(id: number): void {
    const next = new Set(open.value);

    if (next.has(id)) {
        next.delete(id);
    } else {
        next.add(id);
    }

    open.value = next;
}

const dot: Record<BankAccountView['status'], string> = {
    pending_verification: 'bg-warning',
    verified: 'bg-primary',
    rejected: 'bg-destructive',
    superseded: 'bg-muted-foreground/50',
};
</script>

<template>
    <ol class="relative space-y-4 pl-6" data-test="account-history">
        <span
            class="absolute top-2 bottom-2 left-[0.4375rem] w-0.5 rounded bg-border"
            aria-hidden="true"
        />
        <li
            v-for="{ account, previous, changes } in entries"
            :key="account.id"
            class="relative"
            :data-version="account.version"
            :data-status="account.status"
        >
            <span
                :class="[
                    'absolute top-1.5 -left-6 size-4 rounded-full border-2 border-card',
                    dot[account.status],
                ]"
                aria-hidden="true"
            />
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-sm font-medium">{{
                    t('guard.vendor.version', { n: account.version })
                }}</span>
                <StatusBadge
                    :tone="BANK_STATUS_TONE[account.status]"
                    :label="t(`guard.bank.status.${account.status}`)"
                />
                <StatusBadge
                    v-if="account.id === currentId"
                    tone="success"
                    :dot="false"
                    :label="t('guard.vendor.current_account')"
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
            <p class="mt-1 font-mono text-sm tracking-wide">
                {{ account.display }}
            </p>
            <p class="text-xs text-muted-foreground">
                {{ account.holder_name }} · {{ account.bank_country }} ·
                {{ account.currency }} ·
                {{ t(`guard.bank.source.${account.source}`) }}
            </p>
            <p class="mt-1 text-xs text-muted-foreground">
                {{ t('guard.bank.requested') }}:
                {{ account.requested_by.name }},
                {{ fmt.dateTime(account.requested_at, 'short') }}
                <template v-if="account.verified_at">
                    · {{ t('guard.bank.verified') }}:
                    {{ account.verified_by?.name ?? '—' }},
                    {{ fmt.dateTime(account.verified_at, 'short') }}
                    <template v-if="account.verification_method">
                        ({{
                            t(
                                `guard.bank.methods.${account.verification_method}`,
                            )
                        }})</template
                    >
                </template>
                <template v-if="account.rejected_at">
                    · {{ t('guard.bank.rejected') }}:
                    {{ account.rejected_by?.name ?? '—' }},
                    {{ fmt.dateTime(account.rejected_at, 'short') }}
                </template>
            </p>
            <p
                v-if="account.verification_mode === 'solo'"
                class="mt-1 text-xs text-ai-soft-foreground"
            >
                {{ t('guard.vendor.solo_verified') }}
            </p>
            <p
                v-if="account.rejection_reason"
                class="mt-1 text-xs whitespace-pre-line text-muted-foreground"
            >
                {{ account.rejection_reason }}
            </p>
            <div class="mt-2 flex flex-wrap items-center gap-2">
                <button
                    type="button"
                    class="inline-flex min-h-8 items-center gap-1 rounded-md px-1.5 text-xs font-medium text-primary hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    :aria-expanded="open.has(account.id)"
                    :aria-controls="`account-diff-${account.id}`"
                    @click="toggle(account.id)"
                >
                    <ChevronDown
                        :class="[
                            'size-3.5 transition-transform motion-reduce:transition-none',
                            open.has(account.id) ? 'rotate-180' : '',
                        ]"
                        aria-hidden="true"
                    />
                    {{
                        open.has(account.id)
                            ? t('guard.vendor.hide_changes')
                            : t('guard.vendor.show_changes')
                    }}
                </button>
                <Link
                    :href="reviewChange(account.id).url"
                    class="inline-flex min-h-8 items-center px-1.5 text-xs text-muted-foreground hover:text-foreground hover:underline"
                    >{{ t('guard.vendor.review_change') }}</Link
                >
            </div>
            <div
                v-if="open.has(account.id)"
                :id="`account-diff-${account.id}`"
                class="mt-2 overflow-x-auto rounded-xl border"
            >
                <p
                    v-if="!previous || !changes.length"
                    class="border-b px-3 py-2 text-xs text-muted-foreground last:border-0"
                >
                    {{
                        previous
                            ? t('guard.vendor.no_changes')
                            : t('guard.vendor.first_version')
                    }}
                </p>
                <table v-if="changes.length" class="w-full text-xs">
                    <caption class="sr-only">
                        {{
                            t('guard.review.diff')
                        }}
                    </caption>
                    <thead class="border-b text-left text-muted-foreground">
                        <tr>
                            <th class="px-3 py-1.5 font-medium">
                                {{ t('guard.fields.field') }}
                            </th>
                            <th class="px-3 py-1.5 font-medium">
                                {{ t('guard.review.before') }}
                            </th>
                            <th class="px-3 py-1.5 font-medium">
                                {{ t('guard.review.after') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="change in changes"
                            :key="change.field"
                            class="border-b bg-warning-soft/40 last:border-0"
                        >
                            <td class="px-3 py-1.5 text-muted-foreground">
                                <span class="inline-flex items-center gap-1">
                                    <ArrowLeftRight
                                        class="size-3"
                                        aria-hidden="true"
                                    />{{
                                        t(`guard.review.fields.${change.field}`)
                                    }}
                                </span>
                            </td>
                            <td class="px-3 py-1.5 font-mono">
                                {{ change.before ?? '—' }}
                            </td>
                            <td class="px-3 py-1.5 font-mono font-semibold">
                                {{ change.after ?? '—' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </li>
    </ol>
</template>
