<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import {
    Ban,
    CircleCheck,
    CreditCard,
    Download,
    FlaskConical,
    Landmark,
} from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import StatusBadge from '@/components/common/StatusBadge.vue';
import PublicFooter from '@/components/pf/public/PublicFooter.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useFormat } from '@/composables/useFormat';
import { applyLocale, t } from '@/lib/i18n';
import { formatMoney } from '@/lib/pf/money';
import { INVOICE_STATUS_TONE } from '@/lib/pf/status';
import type { Money, PublicInvoiceProps } from '@/types/payflow';

const props = defineProps<PublicInvoiceProps>();
const fmt = useFormat();
const page = usePage();

onMounted(() => {
    void applyLocale(props.locale);
});

const inv = computed(() => props.invoice);
const m = (value: Money) => formatMoney(value, props.locale);
const closed = computed(() =>
    ['paid', 'void', 'uncollectible'].includes(inv.value.status),
);

const paying = ref(false);

function pay(): void {
    paying.value = true;
    router.post(
        `${page.url.split('?')[0]}/pay`,
        {},
        { onFinish: () => (paying.value = false) },
    );
}
</script>

<template>
    <Head
        :title="
            t('pf.public.invoice_title', {
                number: inv.number,
                company: company.name,
            })
        "
    >
        <meta name="robots" content="noindex, nofollow" />
    </Head>

    <div
        class="relative z-10 mx-auto flex w-full max-w-3xl flex-1 flex-col gap-6 px-4 py-8 sm:px-8"
    >
        <section
            class="overflow-hidden rounded-3xl border bg-card shadow-soft"
            data-test="public-invoice"
            :data-status="inv.display_status"
        >
            <div
                class="flex flex-col gap-4 border-b p-6 sm:flex-row sm:items-start sm:justify-between sm:p-8"
            >
                <div>
                    <p class="text-xs text-muted-foreground">
                        {{ t('pf.public.invoice_from') }}
                    </p>
                    <h1 class="text-2xl font-semibold tracking-tight">
                        {{ company.name }}
                    </h1>
                    <p
                        v-if="company.legal_name"
                        class="text-sm text-muted-foreground"
                    >
                        {{ company.legal_name }}
                    </p>
                    <p
                        v-if="company.address"
                        class="text-sm whitespace-pre-line text-muted-foreground"
                    >
                        {{ company.address }}
                    </p>
                    <p
                        v-if="company.tax_id"
                        class="text-xs text-muted-foreground"
                    >
                        {{ t('pf.client.tax_id') }}: {{ company.tax_id }}
                    </p>
                </div>
                <div class="sm:text-right">
                    <p class="font-mono text-sm">{{ inv.number }}</p>
                    <StatusBadge
                        class="mt-1"
                        :tone="INVOICE_STATUS_TONE[inv.display_status]"
                        :label="t(`pf.status.${inv.display_status}`)"
                    />
                    <p class="mt-2 text-xs text-muted-foreground">
                        {{ t('pf.invoice.issue_date') }}:
                        {{ fmt.date(inv.issue_date) }}
                    </p>
                    <p
                        class="text-xs"
                        :class="
                            inv.days_overdue > 0 && !closed
                                ? 'font-medium text-destructive'
                                : 'text-muted-foreground'
                        "
                    >
                        {{ t('pf.invoice.due_date') }}:
                        {{ fmt.date(inv.due_date) }}
                    </p>
                </div>
            </div>

            <div
                v-if="inv.status === 'void'"
                class="flex items-center gap-3 border-b bg-muted/40 px-6 py-4 text-sm sm:px-8"
            >
                <Ban
                    class="size-5 text-muted-foreground"
                    aria-hidden="true"
                />{{ t('pf.public.cancelled') }}
            </div>
            <div
                v-else-if="inv.status === 'paid'"
                class="flex items-center gap-3 border-b bg-success-soft px-6 py-4 text-sm text-success-soft-foreground sm:px-8"
            >
                <CircleCheck class="size-5" aria-hidden="true" />{{
                    t('pf.public.paid', { date: fmt.date(inv.paid_at) })
                }}
            </div>

            <div class="grid gap-6 p-6 sm:grid-cols-2 sm:p-8">
                <div>
                    <p class="text-xs text-muted-foreground">
                        {{ t('pf.public.bill_to') }}
                    </p>
                    <p class="font-medium">
                        {{ client.legal_name ?? client.name }}
                    </p>
                    <p
                        v-if="client.address"
                        class="text-sm whitespace-pre-line text-muted-foreground"
                    >
                        {{ client.address }}
                    </p>
                    <p
                        v-if="client.tax_id"
                        class="text-xs text-muted-foreground"
                    >
                        {{ t('pf.client.tax_id') }}: {{ client.tax_id }}
                    </p>
                    <p
                        v-if="inv.po_number"
                        class="mt-1 text-xs text-muted-foreground"
                    >
                        {{ t('pf.invoice.po_number') }}: {{ inv.po_number }}
                    </p>
                </div>
                <div class="rounded-2xl bg-muted/40 p-4 sm:text-right">
                    <p class="text-xs text-muted-foreground">
                        {{ t('pf.invoice.balance_due') }}
                    </p>
                    <p
                        class="text-3xl font-semibold tracking-tight tabular-nums"
                        data-test="public-balance"
                    >
                        {{ m(inv.balance_due) }}
                    </p>
                    <p
                        v-if="inv.amount_paid.minor"
                        class="text-xs text-muted-foreground"
                    >
                        {{ t('pf.invoice.of_total', { total: m(inv.total) }) }}
                    </p>
                </div>
            </div>

            <div class="overflow-x-auto border-t">
                <table class="w-full text-sm">
                    <caption class="sr-only">
                        {{
                            t('pf.invoice.items')
                        }}
                    </caption>
                    <thead class="text-left text-xs text-muted-foreground">
                        <tr>
                            <th class="px-6 py-2 font-medium sm:px-8">
                                {{ t('pf.invoice_form.description') }}
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                {{ t('pf.invoice_form.quantity') }}
                            </th>
                            <th
                                class="hidden px-3 py-2 text-right font-medium sm:table-cell"
                            >
                                {{ t('pf.public.price') }}
                            </th>
                            <th
                                class="px-6 py-2 text-right font-medium sm:px-8"
                            >
                                {{ t('pf.invoice.amount') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="(item, i) in inv.items"
                            :key="i"
                            class="border-t"
                        >
                            <td class="px-6 py-2.5 sm:px-8">
                                {{ item.description }}
                            </td>
                            <td class="px-3 py-2.5 text-right tabular-nums">
                                {{ item.quantity }}
                            </td>
                            <td
                                class="hidden px-3 py-2.5 text-right tabular-nums sm:table-cell"
                            >
                                {{ m(item.unit_price) }}
                            </td>
                            <td
                                class="px-6 py-2.5 text-right tabular-nums sm:px-8"
                            >
                                {{ m(item.amount) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <dl
                class="ml-auto w-full max-w-xs space-y-1.5 border-t px-6 py-4 text-sm sm:px-8"
            >
                <div class="flex justify-between">
                    <dt class="text-muted-foreground">
                        {{ t('pf.invoice.subtotal') }}
                    </dt>
                    <dd class="tabular-nums">{{ m(inv.subtotal) }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-muted-foreground">
                        {{ t('pf.invoice.tax') }}
                    </dt>
                    <dd class="tabular-nums">{{ m(inv.tax) }}</dd>
                </div>
                <div class="flex justify-between border-t pt-1.5 font-semibold">
                    <dt>{{ t('pf.invoice.total') }}</dt>
                    <dd class="tabular-nums">{{ m(inv.total) }}</dd>
                </div>
                <div v-if="inv.amount_paid.minor" class="flex justify-between">
                    <dt class="text-muted-foreground">
                        {{ t('pf.invoice.paid') }}
                    </dt>
                    <dd class="tabular-nums">− {{ m(inv.amount_paid) }}</dd>
                </div>
            </dl>

            <div
                v-if="inv.notes || inv.terms"
                class="grid gap-4 border-t px-6 py-4 text-sm sm:grid-cols-2 sm:px-8"
            >
                <p v-if="inv.notes" class="whitespace-pre-line">
                    {{ inv.notes }}
                </p>
                <p
                    v-if="inv.terms"
                    class="whitespace-pre-line text-muted-foreground"
                >
                    {{ inv.terms }}
                </p>
            </div>

            <div
                v-if="!closed"
                class="flex flex-col gap-4 border-t bg-muted/20 p-6 sm:p-8"
            >
                <div v-if="payable" class="flex flex-col items-start gap-2">
                    <Button
                        size="lg"
                        :disabled="paying"
                        data-test="public-pay"
                        @click="pay"
                    >
                        <Spinner v-if="paying" class="size-4" />
                        <FlaskConical
                            v-else-if="pay_label === 'demo'"
                            class="size-4"
                            aria-hidden="true"
                        />
                        <CreditCard v-else class="size-4" aria-hidden="true" />
                        {{
                            t(`pf.public.pay.${pay_label ?? 'card'}`, {
                                amount: m(inv.balance_due),
                            })
                        }}
                    </Button>
                    <p
                        v-if="provider_mode === 'test' || pay_label === 'demo'"
                        class="text-xs text-warning-soft-foreground"
                    >
                        {{ t('pf.public.test_mode') }}
                    </p>
                </div>
                <div
                    v-if="company.receiving_bank_details"
                    class="rounded-2xl border bg-card p-4"
                >
                    <p class="mb-2 flex items-center gap-2 text-sm font-medium">
                        <Landmark class="size-4" aria-hidden="true" />{{
                            t('pf.public.bank_transfer')
                        }}
                    </p>
                    <pre class="text-sm whitespace-pre-wrap">{{
                        company.receiving_bank_details
                    }}</pre>
                    <p class="mt-2 text-xs text-muted-foreground">
                        {{
                            t('pf.public.reference_hint', {
                                number: inv.number,
                            })
                        }}
                    </p>
                </div>
            </div>

            <div
                class="flex flex-wrap items-center justify-between gap-3 border-t px-6 py-4 sm:px-8"
            >
                <p
                    v-if="company.email || company.phone"
                    class="text-xs text-muted-foreground"
                >
                    {{ t('pf.public.questions') }}
                    {{
                        [company.email, company.phone]
                            .filter(Boolean)
                            .join(' · ')
                    }}
                </p>
                <Button variant="outline" size="sm" as-child>
                    <a :href="pdf_url"
                        ><Download class="size-4" aria-hidden="true" />{{
                            t('pf.actions.download_pdf')
                        }}</a
                    >
                </Button>
            </div>
        </section>

        <PublicFooter
            :legal="legal"
            :company="company.name"
            :hide-choices="closed"
        />
    </div>
</template>
