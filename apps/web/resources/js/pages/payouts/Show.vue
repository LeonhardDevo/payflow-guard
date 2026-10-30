<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    Ban,
    CircleAlert,
    CircleCheck,
    CircleX,
    HandCoins,
    History,
    Landmark,
    ListChecks,
    Mail,
    MessageCircleOff,
    Pencil,
    Send,
    ShieldAlert,
    ThumbsDown,
    ThumbsUp,
    UsersRound,
    Wallet,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import PageHeader from '@/components/common/PageHeader.vue';
import SectionCard from '@/components/common/SectionCard.vue';
import StatusBadge from '@/components/common/StatusBadge.vue';
import InputError from '@/components/InputError.vue';
import ApprovalStepper from '@/components/pf/guard/ApprovalStepper.vue';
import BankAccountCard from '@/components/pf/guard/BankAccountCard.vue';
import GuardBand from '@/components/pf/guard/GuardBand.vue';
import PayoutTimeline from '@/components/pf/guard/PayoutTimeline.vue';
import GuardErrorDialog from '@/components/pf/guard/GuardErrorDialog.vue';
import SignalList from '@/components/pf/guard/SignalList.vue';
import StepUpDialog from '@/components/pf/guard/StepUpDialog.vue';
import ReasonDialog from '@/components/pf/invoice/ReasonDialog.vue';
import RiskBadge from '@/components/pf/RiskBadge.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { useFormat } from '@/composables/useFormat';
import { usePf } from '@/composables/pf/usePf';
import { useStepUp } from '@/composables/pf/useStepUp';
import { humanize, t, tOr } from '@/lib/i18n';
import { minorToDecimal } from '@/lib/pf/money';
import { PAYOUT_STATUS_TONE } from '@/lib/pf/status';
import {
    approve,
    cancel,
    index,
    reject,
    release,
    resubmit,
    submit as submitRoute,
    update,
} from '@/routes/payouts';
import { resend } from '@/routes/payouts/trusted-confirmation';
import { show as showVendor } from '@/routes/vendors';
import type { PayoutCheck, PayoutShowProps } from '@/types/payflow-guard';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'pf.nav.payouts', href: index() }],
    },
});

const props = defineProps<PayoutShowProps>();
const pf = usePf();
const fmt = useFormat();
const stepUp = useStepUp();
const page = usePage();

const p = computed(() => props.payout);
const riskyBand = computed(() =>
    Boolean(p.value.risk && p.value.risk.band !== 'low'),
);
const highRisk = computed(() => p.value.risk?.band === 'high');
const busy = ref<string | null>(null);

function post(key: string, url: string): void {
    busy.value = key;
    router.post(
        url,
        {},
        { preserveScroll: true, onFinish: () => (busy.value = null) },
    );
}

const approveOpen = ref(false);
const approveForm = useForm({ comment: '' });

function submitApprove(): void {
    stepUp.run(() => {
        approveForm
            .transform((data) => ({ comment: data.comment || null }))
            .post(approve(p.value.id).url, {
                preserveScroll: true,
                onSuccess: () => (approveOpen.value = false),
            });
    }, riskyBand.value);
}

const rejectOpen = ref(false);
const cancelOpen = ref(false);

const today = computed(() => {
    try {
        return new Intl.DateTimeFormat('en-CA', {
            timeZone: page.props.workspace?.timezone ?? undefined,
        }).format(new Date());
    } catch {
        return new Date().toISOString().slice(0, 10);
    }
});

const releaseOpen = ref(false);
const releaseForm = useForm({ paid_reference: '', paid_on: '' });
const typedNumber = ref('');

function openRelease(): void {
    releaseForm.reset();
    releaseForm.clearErrors();
    releaseForm.paid_on = today.value;
    typedNumber.value = '';
    releaseOpen.value = true;
}

function submitRelease(): void {
    stepUp.run(() => {
        releaseForm.post(release(p.value.id).url, {
            preserveScroll: true,
            onSuccess: () => (releaseOpen.value = false),
        });
    }, riskyBand.value);
}

const editing = ref(false);
const editForm = useForm({
    amount: minorToDecimal(
        props.payout.amount.minor,
        props.payout.amount.currency,
    ),
    description: props.payout.description,
    vendor_invoice_number: props.payout.vendor_invoice_number ?? '',
    due_date: props.payout.due_date ?? '',
});

function saveDraft(send: boolean): void {
    editForm
        .transform((data) => ({
            vendor_id: p.value.vendor.id,
            currency: p.value.amount.currency,
            amount: data.amount.replace(',', '.').replace(/\s/g, ''),
            description: data.description,
            vendor_invoice_number: data.vendor_invoice_number || null,
            due_date: data.due_date || null,
            submit: send,
        }))
        .put(update(p.value.id).url, {
            preserveScroll: true,
            onSuccess: () => (editing.value = false),
        });
}

function checkText(check: PayoutCheck): string {
    const params = { ...check.params };

    if (typeof params.not_before === 'string') {
        params.not_before = fmt.value.dateTime(params.not_before, 'medium');
    }

    return tOr(`guard.checks.${check.code}`, humanize(check.code), params);
}

const progress = computed(() =>
    p.value.required_approvals
        ? Math.min(
              100,
              (p.value.approvals_count / p.value.required_approvals) * 100,
          )
        : 0,
);
const releaseByOther = computed(
    () =>
        p.value.release_requires_other_user &&
        ['approved', 'pending_approval'].includes(p.value.status),
);
</script>

<template>
    <Head :title="p.number" />

    <div
        class="mx-auto flex w-full max-w-7xl flex-col gap-6 px-4 py-6 sm:px-6 lg:py-8"
    >
        <GuardBand />
        <PageHeader
            :icon="HandCoins"
            :title="p.number"
            :description="p.description"
        >
            <template #meta>
                <div class="flex flex-wrap items-center gap-2">
                    <StatusBadge
                        :tone="PAYOUT_STATUS_TONE[p.status]"
                        :label="t(`guard.payout_status.${p.status}`)"
                        data-test="payout-status"
                        :data-status="p.status"
                    />
                    <RiskBadge
                        v-if="p.risk"
                        :band="p.risk.band"
                        :score="p.risk.score"
                    />
                    <Link
                        :href="showVendor(p.vendor.id).url"
                        class="text-sm hover:underline"
                        >{{ p.vendor.name }}</Link
                    >
                    <span
                        v-if="p.bank_account"
                        class="font-mono text-sm tracking-wide text-muted-foreground"
                        data-test="payout-account"
                        >{{ p.bank_account.display }}</span
                    >
                    <StatusBadge
                        v-if="p.auto_approved"
                        tone="info"
                        :dot="false"
                        :label="t('guard.payout.auto_approved')"
                    />
                </div>
            </template>
            <template #actions>
                <Button
                    v-if="can.edit && p.status === 'draft' && !editing"
                    variant="outline"
                    @click="editing = true"
                >
                    <Pencil class="size-4" aria-hidden="true" />{{
                        t('pf.actions.edit')
                    }}
                </Button>
                <Button
                    v-if="can.submit"
                    :disabled="busy === 'submit'"
                    data-test="payout-submit-now"
                    @click="post('submit', submitRoute(p.id).url)"
                >
                    <Spinner v-if="busy === 'submit'" class="size-4" />
                    <Send v-else class="size-4" aria-hidden="true" />{{
                        t('guard.payout.submit')
                    }}
                </Button>
                <Button
                    v-if="can.resubmit"
                    :disabled="busy === 'resubmit'"
                    @click="post('resubmit', resubmit(p.id).url)"
                >
                    <Spinner v-if="busy === 'resubmit'" class="size-4" />
                    <Send v-else class="size-4" aria-hidden="true" />{{
                        t('guard.payout.resubmit')
                    }}
                </Button>
                <Button
                    v-if="can.reject"
                    variant="outline"
                    data-test="payout-reject"
                    @click="rejectOpen = true"
                >
                    <ThumbsDown class="size-4" aria-hidden="true" />{{
                        t('guard.payout.reject')
                    }}
                </Button>
                <Button
                    v-if="can.approve"
                    data-test="payout-approve"
                    @click="approveOpen = true"
                >
                    <ThumbsUp class="size-4" aria-hidden="true" />{{
                        t('guard.payout.approve')
                    }}
                </Button>
                <Button
                    v-if="can.release"
                    data-test="payout-release"
                    @click="openRelease"
                >
                    <Wallet class="size-4" aria-hidden="true" />{{
                        t('guard.payout.release')
                    }}
                </Button>
            </template>
        </PageHeader>

        <ApprovalStepper :payout="p" />

        <div
            v-if="
                p.status === 'pending_approval' &&
                cannot_approve_reason &&
                pf.can('payouts.approve')
            "
            class="flex items-start gap-3 rounded-2xl border border-info/30 bg-info-soft p-4 text-sm text-info-soft-foreground"
            data-test="cannot-approve"
            :data-reason="cannot_approve_reason"
        >
            <CircleAlert class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
            <p>
                {{
                    t(`guard.payout.cannot_approve.${cannot_approve_reason}`, {
                        date: page.props.pf?.guard?.eligible_from
                            ? fmt.dateTime(
                                  page.props.pf.guard.eligible_from,
                                  'medium',
                              )
                            : '—',
                    })
                }}
            </p>
        </div>
        <div
            v-if="
                p.status === 'approved' &&
                cannot_release_reason &&
                pf.can('payouts.release')
            "
            class="flex items-start gap-3 rounded-2xl border border-info/30 bg-info-soft p-4 text-sm text-info-soft-foreground"
            data-test="cannot-release"
            :data-reason="cannot_release_reason"
        >
            <CircleAlert class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
            <p>
                {{
                    t(`guard.payout.cannot_release.${cannot_release_reason}`, {
                        date: p.release_not_before
                            ? fmt.dateTime(p.release_not_before, 'medium')
                            : '—',
                    })
                }}
            </p>
        </div>
        <div
            v-if="p.hold_reason"
            class="flex items-start gap-3 rounded-2xl border border-warning/40 bg-warning-soft p-4 text-sm text-warning-soft-foreground"
        >
            <ShieldAlert class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
            <p>{{ t(`guard.hold_reason_long.${p.hold_reason}`) }}</p>
        </div>
        <div
            v-if="p.status === 'released'"
            class="flex items-start gap-3 rounded-2xl border border-success/40 bg-success-soft p-4 text-sm text-success-soft-foreground"
        >
            <CircleCheck class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
            <p>
                {{
                    t('guard.payout.released_info', {
                        name: p.released_by?.name ?? '—',
                        date: fmt.date(p.paid_on),
                        reference: p.paid_reference ?? '',
                    })
                }}
            </p>
        </div>
        <div
            v-if="p.status === 'canceled' || p.status === 'rejected'"
            class="flex items-start gap-3 rounded-2xl border bg-muted/40 p-4 text-sm"
        >
            <Ban
                class="mt-0.5 size-5 shrink-0 text-muted-foreground"
                aria-hidden="true"
            />
            <p>
                {{
                    p.status === 'canceled'
                        ? t('guard.payout.canceled_info', {
                              name: p.canceled_by?.name ?? '—',
                              reason: p.cancel_reason ?? '',
                          })
                        : t('guard.payout.rejected_info', {
                              date: fmt.date(p.rejected_at),
                          })
                }}
            </p>
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <div class="flex min-w-0 flex-col gap-6 lg:col-span-2">
                <SectionCard
                    v-if="editing"
                    :icon="Pencil"
                    :title="t('guard.payout.edit_draft')"
                >
                    <form
                        class="grid grid-cols-1 gap-4 sm:grid-cols-2"
                        @submit.prevent="saveDraft(false)"
                    >
                        <div class="grid gap-2">
                            <Label for="edit-amount">{{
                                t('guard.fields.amount', {
                                    currency: p.amount.currency,
                                })
                            }}</Label>
                            <Input
                                id="edit-amount"
                                v-model="editForm.amount"
                                inputmode="decimal"
                                class="text-right tabular-nums"
                            />
                            <InputError :message="editForm.errors.amount" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="edit-due">{{
                                t('guard.fields.due_date')
                            }}</Label>
                            <Input
                                id="edit-due"
                                v-model="editForm.due_date"
                                type="date"
                            />
                        </div>
                        <div class="grid gap-2 sm:col-span-2">
                            <Label for="edit-desc">{{
                                t('guard.payout.description')
                            }}</Label>
                            <Textarea
                                id="edit-desc"
                                v-model="editForm.description"
                                rows="2"
                                maxlength="500"
                            />
                            <InputError
                                :message="editForm.errors.description"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="edit-inv">{{
                                t('guard.payout.vendor_invoice')
                            }}</Label>
                            <Input
                                id="edit-inv"
                                v-model="editForm.vendor_invoice_number"
                                maxlength="100"
                            />
                        </div>
                        <div class="flex flex-wrap items-end justify-end gap-2">
                            <Button
                                type="button"
                                variant="ghost"
                                @click="editing = false"
                                >{{ t('ui.common.cancel') }}</Button
                            >
                            <Button
                                type="submit"
                                variant="outline"
                                :disabled="editForm.processing"
                                >{{ t('pf.actions.save_draft') }}</Button
                            >
                            <Button
                                type="button"
                                :disabled="editForm.processing"
                                @click="saveDraft(true)"
                                >{{ t('guard.payout_form.submit') }}</Button
                            >
                        </div>
                    </form>
                </SectionCard>

                <SectionCard
                    :icon="ListChecks"
                    :title="t('guard.payout.checks')"
                >
                    <ul
                        class="grid gap-2 sm:grid-cols-2"
                        data-test="payout-checks"
                    >
                        <li
                            v-for="check in p.checks"
                            :key="check.code"
                            class="flex items-start gap-2 text-sm"
                            :data-code="check.code"
                            :data-ok="check.ok"
                        >
                            <CircleCheck
                                v-if="check.ok"
                                class="mt-0.5 size-4 shrink-0 text-success"
                                :aria-label="t('guard.payout.check_ok')"
                            />
                            <CircleX
                                v-else
                                class="mt-0.5 size-4 shrink-0 text-destructive"
                                :aria-label="t('guard.payout.check_failed')"
                            />
                            <span :class="check.ok ? '' : 'font-medium'">{{
                                checkText(check)
                            }}</span>
                        </li>
                    </ul>
                </SectionCard>

                <SectionCard
                    :icon="Landmark"
                    :title="t('guard.payout.bank_account')"
                >
                    <BankAccountCard
                        v-if="p.bank_account"
                        :account="p.bank_account"
                        compact
                    />
                    <p v-else class="text-sm text-muted-foreground">
                        {{ t('guard.vendor.no_account') }}
                    </p>
                    <p
                        v-if="p.bank_account && !p.current_account_matches"
                        class="mt-3 flex items-start gap-2 rounded-xl border border-destructive/40 bg-destructive-soft p-3 text-sm text-destructive-soft-foreground"
                    >
                        <CircleAlert
                            class="mt-0.5 size-4 shrink-0"
                            aria-hidden="true"
                        />{{ t('guard.payout.account_mismatch') }}
                    </p>
                </SectionCard>

                <SectionCard
                    v-if="p.risk_signals.length"
                    :icon="ShieldAlert"
                    :title="t('guard.payout.signals')"
                >
                    <SignalList
                        :signals="p.risk_signals"
                        :currency="p.amount.currency"
                    />
                    <p
                        v-if="p.split_group_total"
                        class="mt-3 text-sm text-muted-foreground"
                    >
                        {{
                            t('guard.payout.split_total', {
                                amount: pf.money(p.split_group_total),
                            })
                        }}
                    </p>
                </SectionCard>

                <SectionCard
                    :icon="History"
                    :title="t('guard.fields.timeline')"
                >
                    <p
                        v-if="!p.timeline.length"
                        class="text-sm text-muted-foreground"
                    >
                        {{ t('pf.common.no_activity') }}
                    </p>
                    <div v-else>
                        <PayoutTimeline
                            :events="p.timeline"
                            :currency="p.amount.currency"
                        />
                    </div>
                </SectionCard>
            </div>

            <div class="flex flex-col gap-6">
                <section
                    class="rounded-2xl border bg-card p-5 shadow-soft"
                    :aria-label="t('guard.fields.summary')"
                >
                    <p class="text-xs text-muted-foreground">
                        {{ t('guard.payout.amount') }}
                    </p>
                    <p
                        class="text-3xl font-semibold tracking-tight tabular-nums"
                        data-test="payout-amount"
                    >
                        {{ pf.money(p.amount) }}
                    </p>
                    <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                {{ t('guard.payout.requested_by') }}
                            </dt>
                            <dd>{{ p.requested_by.name }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                {{ t('guard.fields.due_date') }}
                            </dt>
                            <dd>
                                {{ p.due_date ? fmt.date(p.due_date) : '—' }}
                            </dd>
                        </div>
                        <div v-if="p.vendor_invoice_number">
                            <dt class="text-xs text-muted-foreground">
                                {{ t('guard.payout.vendor_invoice') }}
                            </dt>
                            <dd class="font-mono text-xs">
                                {{ p.vendor_invoice_number }}
                            </dd>
                        </div>
                        <div v-if="p.submitted_at">
                            <dt class="text-xs text-muted-foreground">
                                {{ t('guard.payout.submitted') }}
                            </dt>
                            <dd>{{ fmt.dateTime(p.submitted_at, 'short') }}</dd>
                        </div>
                    </dl>
                    <p
                        v-if="releaseByOther"
                        class="mt-4 flex items-start gap-2 rounded-xl border border-info/30 bg-info-soft p-3 text-xs text-info-soft-foreground"
                        data-test="release-by-other"
                    >
                        <UsersRound
                            class="mt-0.5 size-3.5 shrink-0"
                            aria-hidden="true"
                        />{{
                            t('guard.payout.release_other', {
                                name: p.requested_by.name,
                            })
                        }}
                    </p>
                </section>

                <SectionCard
                    :icon="UsersRound"
                    :title="t('guard.payout.approvals')"
                >
                    <div v-if="p.status !== 'draft'" class="mb-4">
                        <div class="flex items-center justify-between text-sm">
                            <span>{{
                                t('guard.payout.approvals_progress', {
                                    count: p.approvals_count,
                                    required: p.required_approvals,
                                })
                            }}</span>
                            <span
                                v-if="p.approval_round > 1"
                                class="text-xs text-muted-foreground"
                                >{{
                                    t('guard.payout.round', {
                                        round: p.approval_round,
                                    })
                                }}</span
                            >
                        </div>
                        <div
                            class="mt-2 h-2 overflow-hidden rounded-full bg-muted"
                            role="progressbar"
                            :aria-valuenow="p.approvals_count"
                            :aria-valuemax="p.required_approvals"
                            :aria-label="t('guard.payout.approvals')"
                        >
                            <div
                                class="h-full rounded-full bg-primary transition-all motion-reduce:transition-none"
                                :style="{ width: `${progress}%` }"
                            />
                        </div>
                    </div>
                    <ul
                        v-if="p.approvals.length"
                        class="space-y-2"
                        data-test="approvals"
                    >
                        <li
                            v-for="approval in p.approvals"
                            :key="approval.id"
                            :class="[
                                'rounded-xl border p-3 text-sm',
                                approval.invalidated_at ? 'opacity-60' : '',
                            ]"
                        >
                            <div
                                class="flex items-center justify-between gap-2"
                            >
                                <span class="font-medium">{{
                                    approval.user?.name ??
                                    approval.trusted_contact?.name ??
                                    '—'
                                }}</span>
                                <StatusBadge
                                    :tone="
                                        approval.decision === 'approved'
                                            ? 'success'
                                            : 'destructive'
                                    "
                                    :label="
                                        t(
                                            `guard.payout.decision.${approval.decision}`,
                                        )
                                    "
                                />
                            </div>
                            <p class="text-xs text-muted-foreground">
                                {{ fmt.dateTime(approval.created_at, 'short') }}
                                ·
                                {{
                                    t(
                                        `guard.payout.channel.${approval.channel}`,
                                    )
                                }}
                                <template
                                    v-if="
                                        approval.step_up_method &&
                                        approval.step_up_method !== 'none'
                                    "
                                >
                                    ·
                                    {{
                                        t(
                                            `guard.stepup.methods.${approval.step_up_method}`,
                                        )
                                    }}</template
                                >
                                <template v-if="approval.invalidated_at">
                                    ·
                                    {{
                                        t('guard.payout.invalidated')
                                    }}</template
                                >
                            </p>
                            <p v-if="approval.comment" class="mt-1 text-sm">
                                {{ approval.comment }}
                            </p>
                        </li>
                    </ul>
                    <p
                        v-else-if="p.status !== 'draft'"
                        class="text-sm text-muted-foreground"
                    >
                        {{ t('guard.payout.no_approvals') }}
                    </p>
                    <p
                        v-if="p.status === 'pending_approval'"
                        class="mt-3 flex items-start gap-2 text-xs text-muted-foreground"
                        data-test="telegram-allowed"
                        :data-allowed="p.telegram_allowed"
                    >
                        <Send
                            v-if="p.telegram_allowed"
                            class="mt-0.5 size-3.5 shrink-0"
                            aria-hidden="true"
                        />
                        <MessageCircleOff
                            v-else
                            class="mt-0.5 size-3.5 shrink-0"
                            aria-hidden="true"
                        />{{
                            p.telegram_allowed
                                ? t('guard.payout.telegram_allowed')
                                : t('guard.payout.telegram_blocked')
                        }}
                    </p>
                    <div
                        v-if="
                            p.eligible_approvers.length &&
                            p.status === 'pending_approval'
                        "
                        class="mt-4 border-t pt-3"
                    >
                        <p class="mb-2 text-xs text-muted-foreground">
                            {{ t('guard.payout.who_can_approve') }}
                        </p>
                        <ul class="space-y-1.5 text-sm">
                            <li
                                v-for="approver in p.eligible_approvers"
                                :key="approver.id"
                                class="flex items-center justify-between gap-2"
                            >
                                <span
                                    >{{ approver.name }}
                                    <span class="text-xs text-muted-foreground"
                                        >·
                                        {{ t(`roles.${approver.role}`) }}</span
                                    ></span
                                >
                                <span class="text-xs text-muted-foreground">
                                    <template v-if="approver.decided">{{
                                        t('guard.payout.decided')
                                    }}</template>
                                    <template
                                        v-else-if="
                                            approver.missing_second_factor
                                        "
                                        >{{
                                            t('guard.payout.needs_2fa')
                                        }}</template
                                    >
                                    <template
                                        v-else-if="
                                            !approver.eligible &&
                                            approver.eligible_from
                                        "
                                        >{{
                                            t('guard.payout.eligible_from', {
                                                date: fmt.date(
                                                    approver.eligible_from,
                                                ),
                                            })
                                        }}</template
                                    >
                                    <template v-else-if="approver.eligible">{{
                                        t('guard.payout.can_approve')
                                    }}</template>
                                </span>
                            </li>
                        </ul>
                    </div>
                    <div
                        v-if="p.trusted_confirmation"
                        class="mt-4 rounded-xl border bg-muted/30 p-3 text-sm"
                        data-test="trusted-confirmation"
                    >
                        <p class="flex items-center gap-2">
                            <Mail
                                class="size-4 text-muted-foreground"
                                aria-hidden="true"
                            />{{
                                t('guard.payout.waiting_trusted', {
                                    name: p.trusted_confirmation.contact_name,
                                })
                            }}
                        </p>
                        <p class="mt-1 text-xs text-muted-foreground">
                            {{
                                t(
                                    `guard.trusted_status.${p.trusted_confirmation.status}`,
                                )
                            }}
                            ·
                            {{
                                t('guard.review.expires', {
                                    date: fmt.dateTime(
                                        p.trusted_confirmation.expires_at,
                                        'short',
                                    ),
                                })
                            }}
                        </p>
                        <Button
                            v-if="
                                can.cancel &&
                                ['expired', 'pending'].includes(
                                    p.trusted_confirmation.status,
                                )
                            "
                            size="sm"
                            variant="outline"
                            class="mt-2"
                            :disabled="busy === 'resend'"
                            @click="post('resend', resend(p.id).url)"
                        >
                            <Spinner v-if="busy === 'resend'" class="size-4" />
                            {{ t('guard.payout.resend_trusted') }}
                        </Button>
                    </div>
                </SectionCard>

                <Button
                    v-if="can.cancel"
                    variant="outline"
                    class="text-destructive"
                    data-test="payout-cancel"
                    @click="cancelOpen = true"
                >
                    <Ban class="size-4" aria-hidden="true" />{{
                        t('guard.payout.cancel')
                    }}
                </Button>
                <a
                    :href="disclaimer_url"
                    target="_blank"
                    rel="noopener"
                    class="text-xs text-muted-foreground underline-offset-2 hover:underline"
                    >{{ t('guard.overview.disclaimer') }}</a
                >
            </div>
        </div>
    </div>

    <Dialog v-model:open="approveOpen">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>{{
                    t('guard.payout.approve_title', { number: p.number })
                }}</DialogTitle>
                <DialogDescription>{{
                    t('guard.payout.approve_body')
                }}</DialogDescription>
            </DialogHeader>
            <form class="grid gap-4" @submit.prevent="submitApprove">
                <p
                    class="rounded-xl border bg-muted/40 px-3 py-2 text-sm font-medium tabular-nums"
                >
                    {{ pf.money(p.amount) }} · {{ p.vendor.name }} ·
                    {{ p.bank_account?.display ?? '' }}
                </p>
                <div class="grid gap-2">
                    <Label for="approve-comment">{{
                        t('guard.payout.comment')
                    }}</Label>
                    <Textarea
                        id="approve-comment"
                        v-model="approveForm.comment"
                        rows="2"
                        maxlength="500"
                    />
                    <InputError :message="approveForm.errors.comment" />
                </div>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="ghost"
                        @click="approveOpen = false"
                        >{{ t('ui.common.cancel') }}</Button
                    >
                    <Button
                        type="submit"
                        :disabled="approveForm.processing"
                        data-test="approve-confirm"
                    >
                        <Spinner v-if="approveForm.processing" class="size-4" />
                        {{ t('guard.payout.approve') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="releaseOpen">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>{{
                    t('guard.payout.release_title', { number: p.number })
                }}</DialogTitle>
                <DialogDescription>{{
                    t('guard.payout.release_body')
                }}</DialogDescription>
            </DialogHeader>
            <form class="grid gap-4" @submit.prevent="submitRelease">
                <p
                    class="rounded-xl border bg-muted/40 px-3 py-2 text-sm font-medium tabular-nums"
                >
                    {{ pf.money(p.amount) }} · {{ p.vendor.name }} ·
                    {{ p.bank_account?.display ?? '' }}
                </p>
                <p
                    v-if="p.bank_account"
                    class="flex items-start gap-2 rounded-xl border border-warning/40 bg-warning-soft px-3 py-2 text-sm text-warning-soft-foreground"
                    data-test="release-last4"
                >
                    <Landmark
                        class="mt-0.5 size-4 shrink-0"
                        aria-hidden="true"
                    />
                    {{
                        t('guard.payout.compare_last4', {
                            last4: p.bank_account.last4,
                        })
                    }}
                </p>
                <div class="grid gap-2">
                    <Label for="release-ref">{{
                        t('guard.payout.paid_reference')
                    }}</Label>
                    <Input
                        id="release-ref"
                        v-model="releaseForm.paid_reference"
                        maxlength="140"
                        data-test="release-reference"
                    />
                    <InputError :message="releaseForm.errors.paid_reference" />
                </div>
                <div class="grid gap-2">
                    <Label for="release-date">{{
                        t('guard.payout.paid_on')
                    }}</Label>
                    <Input
                        id="release-date"
                        v-model="releaseForm.paid_on"
                        type="date"
                        :max="today"
                    />
                    <InputError :message="releaseForm.errors.paid_on" />
                </div>
                <div v-if="highRisk" class="grid gap-2">
                    <Label for="release-confirm">{{
                        t('guard.payout.type_number', { number: p.number })
                    }}</Label>
                    <Input
                        id="release-confirm"
                        v-model="typedNumber"
                        autocomplete="off"
                        class="font-mono"
                        data-test="release-type-number"
                    />
                </div>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="ghost"
                        @click="releaseOpen = false"
                        >{{ t('ui.common.cancel') }}</Button
                    >
                    <Button
                        type="submit"
                        :disabled="
                            releaseForm.processing ||
                            !releaseForm.paid_reference.trim() ||
                            !releaseForm.paid_on ||
                            (highRisk && typedNumber.trim() !== p.number)
                        "
                        data-test="release-confirm"
                    >
                        <Spinner v-if="releaseForm.processing" class="size-4" />
                        {{ t('guard.payout.release') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <ReasonDialog
        v-model:open="rejectOpen"
        :url="reject(p.id).url"
        field="comment"
        :title="t('guard.payout.reject_title', { number: p.number })"
        :description="t('guard.payout.reject_body')"
        :label="t('guard.payout.comment')"
        :confirm-label="t('guard.payout.reject')"
        destructive
        :max="500"
        :summary="`${p.number} · ${pf.money(p.amount)} · ${p.vendor.name}${p.bank_account ? ` · ${p.bank_account.display}` : ''}`"
    />
    <ReasonDialog
        v-model:open="cancelOpen"
        :url="cancel(p.id).url"
        :title="t('guard.payout.cancel_title', { number: p.number })"
        :description="t('guard.payout.cancel_body')"
        :label="t('pf.common.reason')"
        :confirm-label="t('guard.payout.cancel')"
        destructive
        :max="500"
    />
    <StepUpDialog />
    <GuardErrorDialog />
</template>
