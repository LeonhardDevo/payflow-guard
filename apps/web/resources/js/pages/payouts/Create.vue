<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { CircleAlert, HandCoins, Send, ShieldCheck, Timer } from '@lucide/vue';
import { computed, watch } from 'vue';
import PageHeader from '@/components/common/PageHeader.vue';
import SearchableSelect from '@/components/common/SearchableSelect.vue';
import SectionCard from '@/components/common/SectionCard.vue';
import StatusBadge from '@/components/common/StatusBadge.vue';
import UsageMeter from '@/components/common/UsageMeter.vue';
import InputError from '@/components/InputError.vue';
import GuardBand from '@/components/pf/guard/GuardBand.vue';
import GuardErrorDialog from '@/components/pf/guard/GuardErrorDialog.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { useFormat } from '@/composables/useFormat';
import { usePf } from '@/composables/pf/usePf';
import { t } from '@/lib/i18n';
import { decimalToMinor } from '@/lib/pf/money';
import { BANK_STATUS_TONE } from '@/lib/pf/status';
import { create, index, store } from '@/routes/payouts';
import type { PayoutFormProps } from '@/types/payflow-guard';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'pf.nav.payouts', href: index() },
            { title: 'guard.payouts.create', href: create() },
        ],
    },
});

const props = defineProps<PayoutFormProps>();
const pf = usePf();
const fmt = useFormat();

const initialVendor = (() => {
    const id = new URLSearchParams(usePage().url.split('?')[1] ?? '').get(
        'vendor_id',
    );

    return id && props.vendors.some((v) => String(v.id) === id) ? id : null;
})();

const form = useForm({
    vendor_id: initialVendor as string | null,
    amount: '',
    currency: '',
    description: '',
    vendor_invoice_number: '',
    due_date: '',
    send: true,
});

const vendor = computed(
    () => props.vendors.find((v) => String(v.id) === form.vendor_id) ?? null,
);

watch(
    vendor,
    (value) => {
        form.currency = value?.currency ?? '';
    },
    { immediate: true },
);

const vendorOptions = computed(() =>
    props.vendors.map((v) => ({
        value: String(v.id),
        label: v.name,
        hint: v.account_display ?? t('guard.vendor.no_account'),
    })),
);

const threshold = computed(
    () => props.thresholds.find((th) => th.currency === form.currency) ?? null,
);
const amountMinor = computed(() =>
    form.currency ? decimalToMinor(form.amount, form.currency) : null,
);

const approvalHint = computed(() => {
    const th = threshold.value;
    const amount = amountMinor.value;

    if (!th || amount === null) {
        return null;
    }

    if (amount < th.auto_approve_below.minor) {
        return t('guard.payout_form.auto_approve', {
            amount: pf.money(th.auto_approve_below),
        });
    }

    return amount >= th.dual_approval_from.minor
        ? t('guard.payout_form.dual_approval', {
              amount: pf.money(th.dual_approval_from),
          })
        : t('guard.payout_form.single_approval');
});

const coolingOff = computed(() => {
    const until = vendor.value?.cooling_off_until;

    return until && new Date(until).getTime() > Date.now() ? until : null;
});

const noApprovers = computed(
    () =>
        props.approvers_available.eligible === 0 &&
        !props.approvers_available.trusted_contact,
);
const overLimit = computed(() => {
    const entry = props.limits.payouts_per_month;

    return entry.limit !== null && entry.used >= entry.limit;
});

function submit(send: boolean): void {
    form.send = send;
    form.transform((data) => ({
        vendor_id: data.vendor_id ? Number(data.vendor_id) : null,
        amount: data.amount.replace(',', '.').replace(/\s/g, ''),
        currency: data.currency,
        description: data.description,
        vendor_invoice_number: data.vendor_invoice_number || null,
        due_date: data.due_date || null,
        submit: data.send,
    })).post(store().url, { preserveScroll: true });
}
</script>

<template>
    <Head :title="t('guard.payouts.create')" />

    <div
        class="mx-auto grid w-full max-w-6xl grid-cols-1 gap-6 px-4 py-6 sm:px-6 lg:grid-cols-[1fr_20rem] lg:py-8"
    >
        <GuardBand class="lg:col-span-2" />
        <div class="flex min-w-0 flex-col gap-6">
            <PageHeader
                :icon="HandCoins"
                :title="t('guard.payouts.create')"
                :description="t('guard.payout_form.description')"
            />

            <UsageMeter
                v-if="limits.payouts_per_month.limit !== null"
                :label="t('pf.limits.payouts_per_month')"
                :entry="limits.payouts_per_month"
            />

            <SectionCard :title="t('guard.payout_form.details')">
                <form
                    class="grid grid-cols-1 gap-5 sm:grid-cols-2"
                    novalidate
                    data-test="payout-form"
                    @submit.prevent="submit(true)"
                >
                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="po-vendor">{{
                            t('guard.payout.vendor')
                        }}</Label>
                        <SearchableSelect
                            id="po-vendor"
                            v-model="form.vendor_id"
                            :options="vendorOptions"
                            :placeholder="t('guard.payout_form.choose_vendor')"
                            :invalid="Boolean(form.errors.vendor_id)"
                        />
                        <InputError :message="form.errors.vendor_id" />
                        <div
                            v-if="vendor"
                            class="flex flex-wrap items-center gap-2 text-xs"
                        >
                            <span class="font-mono">{{
                                vendor.account_display ??
                                t('guard.vendor.no_account')
                            }}</span>
                            <StatusBadge
                                v-if="vendor.account_status"
                                :tone="BANK_STATUS_TONE[vendor.account_status]"
                                :label="
                                    t(
                                        `guard.bank.status.${vendor.account_status}`,
                                    )
                                "
                            />
                        </div>
                    </div>
                    <div class="grid gap-2">
                        <Label for="po-amount">{{
                            t('guard.fields.amount', {
                                currency: form.currency || '—',
                            })
                        }}</Label>
                        <Input
                            id="po-amount"
                            v-model="form.amount"
                            inputmode="decimal"
                            class="text-right tabular-nums"
                            :aria-invalid="Boolean(form.errors.amount)"
                            data-test="payout-amount"
                        />
                        <InputError
                            :message="
                                form.errors.amount ?? form.errors.currency
                            "
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="po-due">{{
                            t('guard.fields.due_date')
                        }}</Label>
                        <Input
                            id="po-due"
                            v-model="form.due_date"
                            type="date"
                        />
                        <InputError :message="form.errors.due_date" />
                    </div>
                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="po-desc">{{
                            t('guard.payout.description')
                        }}</Label>
                        <Textarea
                            id="po-desc"
                            v-model="form.description"
                            rows="2"
                            maxlength="500"
                            :aria-invalid="Boolean(form.errors.description)"
                        />
                        <InputError :message="form.errors.description" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="po-invoice">{{
                            t('guard.payout.vendor_invoice')
                        }}</Label>
                        <Input
                            id="po-invoice"
                            v-model="form.vendor_invoice_number"
                            maxlength="100"
                        />
                        <InputError
                            :message="form.errors.vendor_invoice_number"
                        />
                    </div>
                </form>
            </SectionCard>
        </div>

        <aside class="flex flex-col gap-4 lg:sticky lg:top-20 lg:self-start">
            <div class="rounded-2xl border bg-card p-5 shadow-soft">
                <h2 class="flex items-center gap-2 text-sm font-semibold">
                    <ShieldCheck
                        class="size-4 text-primary"
                        aria-hidden="true"
                    />{{ t('guard.payout_form.guard') }}
                </h2>
                <ul class="mt-3 space-y-2 text-sm">
                    <li v-if="approvalHint" class="text-muted-foreground">
                        {{ approvalHint }}
                    </li>
                    <li
                        v-if="vendor?.pending_change"
                        class="flex items-start gap-2 text-warning-soft-foreground"
                    >
                        <CircleAlert
                            class="mt-0.5 size-4 shrink-0"
                            aria-hidden="true"
                        />{{ t('guard.payout_form.pending_change') }}
                    </li>
                    <li
                        v-if="vendor && vendor.account_status !== 'verified'"
                        class="flex items-start gap-2 text-warning-soft-foreground"
                    >
                        <CircleAlert
                            class="mt-0.5 size-4 shrink-0"
                            aria-hidden="true"
                        />{{ t('guard.payout_form.unverified') }}
                    </li>
                    <li
                        v-if="coolingOff"
                        class="flex items-start gap-2 text-info-soft-foreground"
                    >
                        <Timer
                            class="mt-0.5 size-4 shrink-0"
                            aria-hidden="true"
                        />{{
                            t('guard.bank.cooling_off_until', {
                                date: fmt.dateTime(coolingOff, 'medium'),
                            })
                        }}
                    </li>
                    <li class="text-muted-foreground">
                        {{
                            approvers_available.trusted_contact
                                ? t('guard.payout_form.approvers_trusted', {
                                      count: approvers_available.eligible,
                                  })
                                : t('guard.payout_form.approvers', {
                                      count: approvers_available.eligible,
                                  })
                        }}
                    </li>
                    <li
                        v-if="noApprovers"
                        class="flex items-start gap-2 text-destructive"
                    >
                        <CircleAlert
                            class="mt-0.5 size-4 shrink-0"
                            aria-hidden="true"
                        />{{ t('guard.payout_form.no_approvers') }}
                    </li>
                </ul>
                <div class="mt-4 flex flex-col gap-2">
                    <Button
                        :disabled="
                            form.processing || overLimit || !form.vendor_id
                        "
                        data-test="payout-submit"
                        @click="submit(true)"
                    >
                        <Spinner
                            v-if="form.processing && form.send"
                            class="size-4"
                        />
                        <Send v-else class="size-4" aria-hidden="true" />
                        {{ t('guard.payout_form.submit') }}
                    </Button>
                    <Button
                        variant="outline"
                        :disabled="form.processing || !form.vendor_id"
                        data-test="payout-save-draft"
                        @click="submit(false)"
                    >
                        <Spinner
                            v-if="form.processing && !form.send"
                            class="size-4"
                        />
                        {{ t('pf.actions.save_draft') }}
                    </Button>
                    <Button variant="link" as-child>
                        <Link :href="index().url">{{
                            t('ui.common.cancel')
                        }}</Link>
                    </Button>
                </div>
            </div>
        </aside>
    </div>

    <GuardErrorDialog />
</template>
