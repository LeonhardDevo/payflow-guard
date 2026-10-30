<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { usePf } from '@/composables/pf/usePf';
import { t } from '@/lib/i18n';
import { decimalToMinor, minorToDecimal } from '@/lib/pf/money';
import { store } from '@/routes/invoices/payments';
import type { InvoiceDetail } from '@/types/payflow';

const props = defineProps<{ invoice: InvoiceDetail; today: string }>();
const open = defineModel<boolean>('open', { default: false });
const pf = usePf();

const form = useForm({
    amount: '',
    paid_on: props.today,
    method: 'bank_transfer',
    reference: '',
    note: '',
    send_receipt: true,
    allow_overpayment: false,
});

watch(open, (value) => {
    if (value) {
        form.reset();
        form.clearErrors();
        form.amount = minorToDecimal(
            props.invoice.balance_due.minor,
            props.invoice.currency,
        );
        form.paid_on = props.today;
    }
});

const amountMinor = computed(() =>
    decimalToMinor(form.amount, props.invoice.currency),
);
const over = computed(
    () =>
        amountMinor.value !== null &&
        amountMinor.value > props.invoice.balance_due.minor,
);
const remaining = computed(() =>
    amountMinor.value === null
        ? null
        : Math.max(0, props.invoice.balance_due.minor - amountMinor.value),
);

function submit(): void {
    form.transform((data) => ({
        ...data,
        amount: data.amount.replace(',', '.').replace(/\s/g, ''),
        reference: data.reference || null,
        note: data.note || null,
    })).post(store(props.invoice.id).url, {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>{{ t('pf.payment.record_title') }}</DialogTitle>
                <DialogDescription>
                    {{
                        t('pf.payment.record_body', {
                            number: invoice.number ?? '',
                            balance: pf.money(invoice.balance_due),
                        })
                    }}
                </DialogDescription>
            </DialogHeader>
            <form
                class="grid gap-4"
                data-test="record-payment-form"
                @submit.prevent="submit"
            >
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="pay-amount">{{
                            t('pf.payment.amount', {
                                currency: invoice.currency,
                            })
                        }}</Label>
                        <Input
                            id="pay-amount"
                            v-model="form.amount"
                            inputmode="decimal"
                            class="text-right tabular-nums"
                            :aria-invalid="Boolean(form.errors.amount)"
                            data-test="payment-amount"
                        />
                        <p
                            v-if="remaining !== null && !over"
                            class="text-xs text-muted-foreground tabular-nums"
                        >
                            {{
                                remaining === 0
                                    ? t('pf.payment.settles')
                                    : t('pf.payment.remaining', {
                                          amount: pf.money({
                                              minor: remaining,
                                              currency: invoice.currency,
                                          }),
                                      })
                            }}
                        </p>
                        <InputError :message="form.errors.amount" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="pay-date">{{
                            t('pf.payment.paid_on')
                        }}</Label>
                        <Input
                            id="pay-date"
                            v-model="form.paid_on"
                            type="date"
                            :max="today"
                        />
                        <InputError :message="form.errors.paid_on" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="pay-method">{{
                            t('pf.payment.method')
                        }}</Label>
                        <Select v-model="form.method">
                            <SelectTrigger id="pay-method" class="w-full"
                                ><SelectValue
                            /></SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="method in [
                                        'bank_transfer',
                                        'card',
                                        'cash',
                                        'other',
                                    ]"
                                    :key="method"
                                    :value="method"
                                    >{{
                                        t(`pf.payment_method.${method}`)
                                    }}</SelectItem
                                >
                            </SelectContent>
                        </Select>
                    </div>
                    <div class="grid gap-2">
                        <Label for="pay-ref">{{
                            t('pf.payment.reference')
                        }}</Label>
                        <Input
                            id="pay-ref"
                            v-model="form.reference"
                            maxlength="255"
                        />
                        <InputError :message="form.errors.reference" />
                    </div>
                </div>
                <div class="grid gap-2">
                    <Label for="pay-note">{{ t('pf.payment.note') }}</Label>
                    <Textarea
                        id="pay-note"
                        v-model="form.note"
                        rows="2"
                        maxlength="1000"
                    />
                </div>
                <Label class="flex items-center gap-3 text-sm font-normal">
                    <Checkbox
                        v-model="form.send_receipt"
                        :disabled="!invoice.client.email"
                    />
                    {{
                        invoice.client.email
                            ? t('pf.payment.send_receipt', {
                                  email: invoice.client.email,
                              })
                            : t('pf.payment.no_receipt')
                    }}
                </Label>
                <Label
                    v-if="over"
                    class="flex items-start gap-3 rounded-xl border border-warning/40 bg-warning-soft p-3 text-sm font-normal text-warning-soft-foreground"
                >
                    <Checkbox v-model="form.allow_overpayment" class="mt-0.5" />
                    {{ t('pf.payment.allow_overpayment') }}
                </Label>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="ghost"
                        @click="open = false"
                        >{{ t('ui.common.cancel') }}</Button
                    >
                    <Button
                        type="submit"
                        :disabled="
                            form.processing ||
                            amountMinor === null ||
                            amountMinor <= 0 ||
                            (over && !form.allow_overpayment)
                        "
                        data-test="payment-submit"
                    >
                        <Spinner v-if="form.processing" class="size-4" />
                        {{ t('pf.actions.record_payment') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
