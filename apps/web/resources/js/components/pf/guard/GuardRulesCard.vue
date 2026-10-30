<script setup lang="ts">
import { ShieldCheck } from '@lucide/vue';
import SectionCard from '@/components/common/SectionCard.vue';
import { useFormat } from '@/composables/useFormat';
import { usePf } from '@/composables/pf/usePf';
import { t } from '@/lib/i18n';
import type { GuardIndexProps } from '@/types/payflow-guard';

withDefaults(
    defineProps<{ rules: GuardIndexProps['rules']; description?: string }>(),
    { description: undefined },
);
const pf = usePf();
const fmt = useFormat();
</script>

<template>
    <SectionCard
        :icon="ShieldCheck"
        :title="t('guard.overview.rules_title')"
        :description="description ?? t('guard.overview.rules_hint')"
    >
        <ul class="space-y-3 text-sm" data-test="guard-rules">
            <li
                v-for="th in rules.thresholds"
                :key="th.currency"
                class="rounded-xl border p-3"
                :data-currency="th.currency"
            >
                <p class="font-mono text-xs font-medium text-muted-foreground">
                    {{ th.currency }}
                </p>
                <p>
                    {{
                        th.auto_approve_below.minor > 0
                            ? t('guard.overview.rule_auto', {
                                  amount: pf.money(th.auto_approve_below),
                              })
                            : t('guard.overview.rule_auto_off')
                    }}
                </p>
                <p
                    v-if="
                        th.auto_approve_below.minor > 0 &&
                        th.auto_approve_cumulative
                    "
                    class="text-xs text-muted-foreground"
                >
                    {{
                        t('guard.overview.rule_cumulative', {
                            amount: pf.money(th.auto_approve_cumulative),
                        })
                    }}
                </p>
                <p>
                    {{
                        t('guard.overview.rule_dual', {
                            amount: pf.money(th.dual_approval_from),
                        })
                    }}
                </p>
            </li>
            <li>
                {{
                    t('guard.overview.rule_cooling', {
                        medium: rules.cooling_off_hours_medium,
                        high: rules.cooling_off_hours_high,
                    })
                }}
            </li>
            <li>
                {{
                    t('guard.overview.rule_trust_delay', {
                        hours: rules.guard_trust_delay_hours,
                    })
                }}
            </li>
            <li
                :class="
                    rules.require_second_factor
                        ? ''
                        : 'font-medium text-warning-soft-foreground'
                "
            >
                {{
                    rules.require_second_factor
                        ? t('guard.overview.rule_2fa_on')
                        : t('guard.overview.rule_2fa_off')
                }}
            </li>
            <li>
                {{
                    rules.allow_telegram_approvals
                        ? t('guard.overview.rule_telegram_on')
                        : t('guard.overview.rule_telegram_off')
                }}
            </li>
            <li>
                {{
                    t('guard.overview.rule_approvers', {
                        count: rules.eligible_approvers,
                    })
                }}
            </li>
            <li
                v-for="person in rules.pending_eligibility"
                :key="person.id"
                class="text-xs text-muted-foreground"
            >
                {{
                    t('guard.overview.rule_pending_person', {
                        name: person.name,
                        date: fmt.date(person.eligible_from),
                    })
                }}
            </li>
            <li
                v-if="rules.solo_mode"
                class="rounded-xl border border-ai/30 bg-ai-soft p-3 text-ai-soft-foreground"
            >
                {{
                    rules.trusted_contact
                        ? t('guard.overview.rule_solo', {
                              name: rules.trusted_contact.name,
                              hours: rules.solo_release_delay_hours,
                          })
                        : t('guard.overview.rule_solo_no_contact')
                }}
            </li>
        </ul>
    </SectionCard>
</template>
