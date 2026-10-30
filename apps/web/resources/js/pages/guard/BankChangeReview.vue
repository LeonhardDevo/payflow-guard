<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    ArrowLeftRight,
    Check,
    CircleAlert,
    CircleCheck,
    Copy,
    FileText,
    GitCompareArrows,
    HandCoins,
    Phone,
    PhoneOff,
    ShieldAlert,
    ShieldCheck,
    UserRound,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import PageHeader from '@/components/common/PageHeader.vue';
import SectionCard from '@/components/common/SectionCard.vue';
import StatusBadge from '@/components/common/StatusBadge.vue';
import InputError from '@/components/InputError.vue';
import GuardBand from '@/components/pf/guard/GuardBand.vue';
import GuardErrorDialog from '@/components/pf/guard/GuardErrorDialog.vue';
import PayoutRow from '@/components/pf/guard/PayoutRow.vue';
import RequestText from '@/components/pf/guard/RequestText.vue';
import SignalList from '@/components/pf/guard/SignalList.vue';
import StepUpDialog from '@/components/pf/guard/StepUpDialog.vue';
import ReasonDialog from '@/components/pf/invoice/ReasonDialog.vue';
import RiskBadge from '@/components/pf/RiskBadge.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { useFormat } from '@/composables/useFormat';
import { useStepUp } from '@/composables/pf/useStepUp';
import { t } from '@/lib/i18n';
import { BANK_STATUS_TONE } from '@/lib/pf/status';
import { index as guardIndex } from '@/routes/guard';
import { reject, soloConfirmation, verify } from '@/routes/guard/bank-changes';
import { edit as securityEdit } from '@/routes/security';
import { show as showVendor } from '@/routes/vendors';
import type {
    BankChangeReviewProps,
    VerificationMethod,
} from '@/types/payflow-guard';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'pf.nav.guard', href: guardIndex() }],
    },
});

const props = defineProps<BankChangeReviewProps>();
const fmt = useFormat();
const stepUp = useStepUp();
const page = usePage();

const c = computed(() => props.change);
const req = computed(() => props.requirements);
const isPending = computed(() => c.value.status === 'pending_verification');

const CHECKLIST = [
    'called_known_number',
    'confirmed_holder_name',
    'confirmed_last4',
    'confirmed_request_origin',
] as const;

const form = useForm({
    method: (props.requirements.allowed_methods[0] ??
        'callback_known_phone') as VerificationMethod,
    checklist: Object.fromEntries(
        CHECKLIST.map((key) => [key, false]),
    ) as Record<(typeof CHECKLIST)[number], boolean>,
    note: '',
    solo: false,
});

const soloReady = computed(
    () => req.value.solo.confirmation?.status === 'confirmed',
);
const noteMin = computed(() =>
    form.solo ? Math.max(30, req.value.note_min) : req.value.note_min,
);
const noteNeeded = computed(() => req.value.note_required || form.solo);
const checklistDone = computed(() =>
    CHECKLIST.every((key) => form.checklist[key]),
);
const canSubmit = computed(
    () =>
        checklistDone.value &&
        (!noteNeeded.value || form.note.trim().length >= noteMin.value) &&
        (!form.solo || soloReady.value),
);

function submitVerify(): void {
    stepUp.run(() => {
        form.transform((data) => ({ ...data, note: data.note || null })).post(
            verify(c.value.id).url,
            {
                preserveScroll: true,
            },
        );
    }, req.value.step_up_required || form.solo);
}

const rejectOpen = ref(false);
const sending = ref(false);

function sendSoloConfirmation(): void {
    sending.value = true;
    router.post(
        soloConfirmation(c.value.id).url,
        {},
        { preserveScroll: true, onFinish: () => (sending.value = false) },
    );
}

const eligibleFrom = computed(
    () => page.props.pf?.guard?.eligible_from ?? null,
);

const reasonText = computed(() => {
    switch (props.cannot_verify_reason) {
        case 'not_eligible_yet':
            return t('guard.review.cannot.not_eligible_yet', {
                date: eligibleFrom.value
                    ? fmt.value.dateTime(eligibleFrom.value, 'medium')
                    : '—',
            });
        case null:
            return null;
        default:
            return t(`guard.review.cannot.${props.cannot_verify_reason}`);
    }
});

const errors = computed(
    () => form.errors as Record<string, string | undefined>,
);

const holderMismatch = computed(() =>
    c.value.risk_signals.some((s) => s.code === 'holder_name_mismatch'),
);

const copied = ref(false);

async function copyPhone(): Promise<void> {
    if (!props.callback.known_phone) {
        return;
    }

    try {
        await navigator.clipboard.writeText(props.callback.known_phone);
        copied.value = true;
        window.setTimeout(() => (copied.value = false), 2000);
    } catch {
        copied.value = false;
    }
}
</script>

<template>
    <Head :title="t('guard.review.title', { name: vendor.name })" />

    <div
        class="mx-auto flex w-full max-w-7xl flex-col gap-6 px-4 py-6 sm:px-6 lg:py-8"
    >
        <GuardBand />
        <PageHeader
            :icon="ShieldAlert"
            :eyebrow="t('guard.review.eyebrow')"
            :title="vendor.name"
        >
            <template #meta>
                <div class="flex flex-wrap items-center gap-2">
                    <StatusBadge
                        :tone="BANK_STATUS_TONE[c.status]"
                        :label="t(`guard.bank.status.${c.status}`)"
                        data-test="change-status"
                        :data-status="c.status"
                    />
                    <RiskBadge :band="c.risk_band" :score="c.risk_score" />
                    <span class="text-sm text-muted-foreground">{{
                        t('guard.review.requested_by', {
                            name: c.requested_by.name,
                            date: fmt.dateTime(c.requested_at, 'medium'),
                        })
                    }}</span>
                </div>
            </template>
            <template #actions>
                <Button variant="outline" as-child>
                    <Link :href="showVendor(vendor.id).url">{{
                        t('guard.review.open_vendor')
                    }}</Link>
                </Button>
            </template>
        </PageHeader>

        <div
            v-if="isPending && reasonText"
            class="flex items-start gap-3 rounded-2xl border border-info/30 bg-info-soft p-4 text-sm text-info-soft-foreground"
            data-test="cannot-verify"
            :data-reason="cannot_verify_reason"
        >
            <CircleAlert class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
            <p class="flex-1">{{ reasonText }}</p>
            <Link
                v-if="cannot_verify_reason === 'second_factor_missing'"
                :href="securityEdit().url"
                class="shrink-0 font-medium underline underline-offset-2"
                >{{ t('guard.stepup.setup_second_factor') }}</Link
            >
        </div>
        <div
            v-if="c.status === 'verified'"
            class="flex items-start gap-3 rounded-2xl border border-success/40 bg-success-soft p-4 text-sm text-success-soft-foreground"
        >
            <CircleCheck class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
            <div>
                <p class="font-medium">
                    {{
                        t('guard.review.verified_by', {
                            name: c.verified_by?.name ?? '—',
                            date: fmt.dateTime(c.verified_at, 'medium'),
                        })
                    }}
                </p>
                <p v-if="c.cooling_off_until">
                    {{
                        t('guard.bank.cooling_off_until', {
                            date: fmt.dateTime(c.cooling_off_until, 'medium'),
                        })
                    }}
                </p>
            </div>
        </div>
        <div
            v-if="c.status === 'rejected'"
            class="flex items-start gap-3 rounded-2xl border border-destructive/40 bg-destructive-soft p-4 text-sm text-destructive-soft-foreground"
        >
            <CircleAlert class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
            <p>
                {{
                    t('guard.review.rejected_by', {
                        name: c.rejected_by?.name ?? '—',
                        reason: c.rejection_reason ?? '',
                    })
                }}
            </p>
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <div class="flex min-w-0 flex-col gap-6 lg:col-span-2">
                <SectionCard
                    :icon="GitCompareArrows"
                    :title="t('guard.review.diff')"
                    flush
                >
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm" data-test="bank-diff">
                            <caption class="sr-only">
                                {{
                                    t('guard.review.diff')
                                }}
                            </caption>
                            <thead
                                class="border-b text-left text-xs text-muted-foreground"
                            >
                                <tr>
                                    <th class="px-5 py-2 font-medium">
                                        {{ t('guard.fields.field') }}
                                    </th>
                                    <th class="px-3 py-2 font-medium">
                                        {{ t('guard.review.before') }}
                                    </th>
                                    <th class="px-5 py-2 font-medium">
                                        {{ t('guard.review.after') }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="row in diff"
                                    :key="row.field"
                                    :class="[
                                        'border-b last:border-0',
                                        row.changed ? 'bg-warning-soft/40' : '',
                                    ]"
                                    :data-field="row.field"
                                    :data-changed="row.changed"
                                >
                                    <td
                                        class="px-5 py-2.5 text-muted-foreground"
                                    >
                                        <span
                                            class="inline-flex items-center gap-1.5"
                                        >
                                            <ArrowLeftRight
                                                v-if="row.changed"
                                                class="size-3.5 text-warning-soft-foreground"
                                                aria-hidden="true"
                                            />{{
                                                t(
                                                    `guard.review.fields.${row.field}`,
                                                )
                                            }}
                                        </span>
                                    </td>
                                    <td class="px-3 py-2.5 font-mono text-xs">
                                        {{ row.before ?? '—' }}
                                    </td>
                                    <td
                                        :class="[
                                            'px-5 py-2.5 font-mono text-xs',
                                            row.changed
                                                ? 'font-semibold text-warning-soft-foreground'
                                                : '',
                                        ]"
                                    >
                                        {{ row.after ?? '—' }}
                                        <span
                                            v-if="row.changed"
                                            class="ml-1 rounded bg-warning px-1 py-0.5 font-sans text-[10px] text-warning-foreground uppercase"
                                            >{{
                                                t('guard.review.changed')
                                            }}</span
                                        >
                                        <span
                                            v-if="
                                                row.field === 'holder_name' &&
                                                row.changed &&
                                                holderMismatch
                                            "
                                            class="mt-1 block font-sans text-xs font-normal text-destructive-soft-foreground"
                                            data-test="holder-mismatch"
                                            >{{
                                                t(
                                                    'guard.review.holder_mismatch',
                                                    {
                                                        name:
                                                            vendor.legal_name ??
                                                            vendor.name,
                                                    },
                                                )
                                            }}</span
                                        >
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </SectionCard>

                <SectionCard
                    :icon="ShieldAlert"
                    :title="t('guard.review.signals')"
                    :description="
                        t('guard.review.signals_hint', { score: c.risk_score })
                    "
                >
                    <p
                        v-if="!c.risk_signals.length"
                        class="text-sm text-muted-foreground"
                    >
                        {{ t('guard.review.no_signals') }}
                    </p>
                    <SignalList
                        v-else
                        :signals="c.risk_signals"
                        :score="c.risk_score"
                        :band="c.risk_band"
                    />
                </SectionCard>

                <SectionCard
                    :icon="FileText"
                    :title="t('guard.review.request')"
                >
                    <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                {{ t('guard.bank_change.source') }}
                            </dt>
                            <dd>{{ t(`guard.bank.source.${c.source}`) }}</dd>
                        </div>
                        <div
                            v-if="
                                c.requester_email_domain ||
                                c.requester_contact_hint
                            "
                        >
                            <dt class="text-xs text-muted-foreground">
                                {{ t('guard.review.requester') }}
                            </dt>
                            <dd class="font-mono text-xs">
                                {{
                                    c.requester_email_domain ??
                                    c.requester_contact_hint
                                }}
                            </dd>
                        </div>
                    </dl>
                    <div v-if="c.change_request_text" class="mt-4">
                        <p class="mb-1 text-xs text-muted-foreground">
                            {{ t('guard.bank_change.text') }}
                        </p>
                        <RequestText :text="c.change_request_text" />
                    </div>
                </SectionCard>

                <SectionCard
                    v-if="affected_payouts.length"
                    :icon="HandCoins"
                    :title="t('guard.review.affected_payouts')"
                    :description="t('guard.review.affected_hint')"
                    flush
                >
                    <ul class="divide-y">
                        <PayoutRow
                            v-for="payout in affected_payouts"
                            :key="payout.id"
                            :payout="payout"
                            hide-vendor
                        />
                    </ul>
                </SectionCard>
            </div>

            <div class="flex flex-col gap-6 lg:sticky lg:top-24 lg:self-start">
                <SectionCard
                    :icon="callback.phone_recently_changed ? PhoneOff : Phone"
                    :title="t('guard.review.callback')"
                >
                    <p class="text-sm text-muted-foreground">
                        {{ t('guard.review.callback_hint') }}
                    </p>
                    <div class="mt-3 flex items-center justify-between gap-2">
                        <p
                            class="font-mono text-lg font-semibold tracking-wide"
                            data-test="known-phone"
                        >
                            {{
                                callback.known_phone ??
                                t('guard.review.no_known_phone')
                            }}
                        </p>
                        <Button
                            v-if="callback.known_phone"
                            size="icon"
                            variant="ghost"
                            class="size-9 shrink-0"
                            :aria-label="
                                copied
                                    ? t('guard.review.copied')
                                    : t('guard.review.copy_phone')
                            "
                            :title="
                                copied
                                    ? t('guard.review.copied')
                                    : t('guard.review.copy_phone')
                            "
                            @click="copyPhone"
                        >
                            <Check
                                v-if="copied"
                                class="size-4 text-success"
                                aria-hidden="true"
                            />
                            <Copy v-else class="size-4" aria-hidden="true" />
                        </Button>
                    </div>
                    <p
                        v-if="callback.phone_verified_at"
                        class="text-xs text-muted-foreground"
                    >
                        {{
                            t('guard.vendor.phone_verified', {
                                date: fmt.date(callback.phone_verified_at),
                            })
                        }}
                    </p>
                    <p
                        v-if="callback.phone_recently_changed"
                        class="mt-3 flex items-start gap-2 rounded-xl border border-destructive/40 bg-destructive-soft p-3 text-xs text-destructive-soft-foreground"
                    >
                        <PhoneOff
                            class="mt-0.5 size-4 shrink-0"
                            aria-hidden="true"
                        />
                        {{ t('guard.review.phone_recently_changed') }}
                    </p>
                    <p class="mt-3 text-xs text-muted-foreground">
                        {{ t('guard.review.never_use_request_contact') }}
                    </p>
                </SectionCard>

                <SectionCard
                    v-if="isPending && can.verify"
                    :icon="ShieldCheck"
                    :title="t('guard.review.verify')"
                >
                    <form
                        class="grid gap-4"
                        data-test="verify-form"
                        @submit.prevent="submitVerify"
                    >
                        <fieldset class="grid gap-2">
                            <legend class="mb-1 text-sm font-medium">
                                {{ t('guard.review.method') }}
                            </legend>
                            <Label
                                v-for="method in req.allowed_methods"
                                :key="method"
                                class="flex items-center gap-2 rounded-lg border px-3 py-2 text-sm font-normal has-[:checked]:border-primary has-[:checked]:bg-primary/5"
                            >
                                <input
                                    v-model="form.method"
                                    type="radio"
                                    name="method"
                                    :value="method"
                                    class="accent-primary"
                                />
                                {{ t(`guard.bank.methods.${method}`) }}
                            </Label>
                            <InputError :message="form.errors.method" />
                        </fieldset>
                        <fieldset class="grid gap-2">
                            <legend class="mb-1 text-sm font-medium">
                                {{ t('guard.review.checklist') }}
                            </legend>
                            <Label
                                v-for="key in CHECKLIST"
                                :key="key"
                                class="flex items-start gap-2 text-sm font-normal"
                            >
                                <Checkbox
                                    v-model="form.checklist[key]"
                                    class="mt-0.5"
                                    :data-test="`check-${key}`"
                                />
                                <span>{{
                                    t(`guard.review.checks.${key}`, {
                                        last4: c.last4,
                                        holder: c.holder_name,
                                    })
                                }}</span>
                            </Label>
                            <InputError
                                :message="
                                    errors['checklist'] ??
                                    errors[`checklist.${CHECKLIST[0]}`]
                                "
                            />
                        </fieldset>
                        <div
                            v-if="can.verify_solo && req.solo.available"
                            class="flex items-start justify-between gap-3 rounded-xl border p-3"
                        >
                            <div>
                                <Label for="solo-mode" class="font-medium">{{
                                    t('guard.review.solo')
                                }}</Label>
                                <p class="text-xs text-muted-foreground">
                                    {{
                                        soloReady
                                            ? t('guard.review.solo_ready', {
                                                  name:
                                                      req.solo.confirmation
                                                          ?.contact_name ?? '',
                                              })
                                            : t('guard.review.solo_waiting')
                                    }}
                                </p>
                            </div>
                            <Switch
                                id="solo-mode"
                                v-model="form.solo"
                                :disabled="!soloReady"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="verify-note"
                                >{{ t('guard.review.note')
                                }}<span
                                    v-if="noteNeeded"
                                    class="text-destructive"
                                >
                                    *</span
                                ></Label
                            >
                            <Textarea
                                id="verify-note"
                                v-model="form.note"
                                rows="3"
                                maxlength="2000"
                                :placeholder="
                                    t('guard.review.note_placeholder')
                                "
                            />
                            <p
                                v-if="noteNeeded"
                                class="text-xs text-muted-foreground"
                            >
                                {{ t('pf.common.min_chars', { min: noteMin }) }}
                            </p>
                            <InputError :message="form.errors.note" />
                        </div>
                        <p class="text-xs text-muted-foreground">
                            {{
                                t('guard.review.cooling_off', {
                                    hours: req.cooling_off_hours,
                                })
                            }}
                            <template v-if="req.step_up_required">
                                {{ t('guard.review.step_up_note') }}</template
                            >
                        </p>
                        <p
                            class="rounded-lg border bg-muted/40 px-3 py-2 text-xs"
                            data-test="verify-consequence"
                        >
                            {{ t('guard.review.consequence') }}
                        </p>
                        <Button
                            type="submit"
                            :disabled="form.processing || !canSubmit"
                            data-test="verify-submit"
                        >
                            <Spinner v-if="form.processing" class="size-4" />
                            <ShieldCheck
                                v-else
                                class="size-4"
                                aria-hidden="true"
                            />
                            {{ t('guard.review.verify_button') }}
                        </Button>
                    </form>
                </SectionCard>

                <SectionCard
                    v-if="
                        isPending &&
                        req.solo.available &&
                        !can.verify &&
                        req.solo.trusted_contact
                    "
                    :icon="UserRound"
                    :title="t('guard.review.solo_title')"
                >
                    <p class="text-sm text-muted-foreground">
                        {{
                            t('guard.review.solo_body', {
                                name: req.solo.trusted_contact.name,
                            })
                        }}
                    </p>
                    <p v-if="req.solo.confirmation" class="mt-2 text-sm">
                        {{
                            t(
                                `guard.trusted_status.${req.solo.confirmation.status}`,
                            )
                        }}
                        <span class="text-xs text-muted-foreground"
                            >·
                            {{
                                t('guard.review.expires', {
                                    date: fmt.dateTime(
                                        req.solo.confirmation.expires_at,
                                        'short',
                                    ),
                                })
                            }}</span
                        >
                    </p>
                    <Button
                        v-if="
                            !req.solo.confirmation ||
                            ['expired', 'declined', 'canceled'].includes(
                                req.solo.confirmation.status,
                            )
                        "
                        class="mt-3 w-full"
                        variant="outline"
                        :disabled="sending"
                        @click="sendSoloConfirmation"
                    >
                        <Spinner v-if="sending" class="size-4" />
                        {{ t('guard.review.send_solo') }}
                    </Button>
                </SectionCard>

                <div
                    v-if="isPending && (can.reject || can.withdraw)"
                    class="flex flex-col gap-2"
                >
                    <Button
                        variant="outline"
                        class="text-destructive"
                        data-test="reject-change"
                        @click="rejectOpen = true"
                    >
                        {{
                            can.withdraw && !can.reject
                                ? t('guard.review.withdraw')
                                : t('guard.review.reject')
                        }}
                    </Button>
                </div>

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

    <ReasonDialog
        v-model:open="rejectOpen"
        :url="reject(c.id).url"
        :title="
            can.withdraw && !can.reject
                ? t('guard.review.withdraw_title')
                : t('guard.review.reject_title')
        "
        :description="t('guard.review.reject_body')"
        :label="t('pf.common.reason')"
        :confirm-label="
            can.withdraw && !can.reject
                ? t('guard.review.withdraw')
                : t('guard.review.reject')
        "
        destructive
        :max="500"
        :summary="`${c.display} · ${c.holder_name}`"
    />
    <StepUpDialog />
    <GuardErrorDialog />
</template>
