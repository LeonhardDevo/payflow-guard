<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { BellOff, CircleCheck, Wallet } from '@lucide/vue';
import { computed, onMounted } from 'vue';
import InputError from '@/components/InputError.vue';
import PublicFooter from '@/components/pf/public/PublicFooter.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { useFormat } from '@/composables/useFormat';
import { applyLocale, t } from '@/lib/i18n';
import { formatMoney } from '@/lib/pf/money';
import type { ReminderChoicesProps } from '@/types/payflow';

const props = defineProps<ReminderChoicesProps>();
const fmt = useFormat();
const page = usePage();

onMounted(() => {
    void applyLocale(props.invoice.locale);
});

const base = computed(() => page.url.split('?')[0]);

const paidForm = useForm({ paid_on: '', reference: '' });
const stopForm = useForm({ note: '' });

function reportPaid(): void {
    paidForm
        .transform((data) => ({
            paid_on: data.paid_on || null,
            reference: data.reference || null,
        }))
        .post(`${base.value}/paid`, {
            preserveScroll: true,
        });
}

function stop(): void {
    stopForm
        .transform((data) => ({ note: data.note || null }))
        .post(`${base.value}/stop`, { preserveScroll: true });
}
</script>

<template>
    <Head :title="t('pf.public.choices_title')">
        <meta name="robots" content="noindex, nofollow" />
    </Head>

    <div
        class="relative z-10 mx-auto flex w-full max-w-xl flex-1 flex-col gap-6 px-5 py-8 sm:px-8"
    >
        <section class="rounded-3xl border bg-card p-6 shadow-soft sm:p-8">
            <h1 class="text-xl font-semibold tracking-tight">
                {{ t('pf.public.choices_title') }}
            </h1>
            <p class="mt-2 text-sm text-muted-foreground">
                {{
                    t('pf.public.choices_intro', {
                        client: client_name,
                        company: company_name,
                        number: invoice.number,
                        amount: formatMoney(
                            invoice.balance_due,
                            invoice.locale,
                        ),
                        date: fmt.date(invoice.due_date),
                    })
                }}
            </p>

            <div
                v-if="reported_paid_at"
                class="mt-5 flex items-start gap-3 rounded-2xl border border-success/40 bg-success-soft p-4 text-sm text-success-soft-foreground"
                data-test="reported-paid"
            >
                <CircleCheck
                    class="mt-0.5 size-5 shrink-0"
                    aria-hidden="true"
                />
                <p>
                    {{
                        t('pf.public.reported_paid', {
                            date: fmt.dateTime(reported_paid_at, 'medium'),
                            company: company_name,
                        })
                    }}
                </p>
            </div>
            <form
                v-else
                class="mt-5 grid gap-3 rounded-2xl border p-4"
                data-test="report-paid-form"
                @submit.prevent="reportPaid"
            >
                <p class="flex items-center gap-2 font-medium">
                    <Wallet class="size-4" aria-hidden="true" />{{
                        t('pf.public.already_paid')
                    }}
                </p>
                <p class="text-xs text-muted-foreground">
                    {{ t('pf.public.already_paid_hint') }}
                </p>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div class="grid gap-1.5">
                        <Label for="rc-date">{{
                            t('pf.payment.paid_on')
                        }}</Label>
                        <Input
                            id="rc-date"
                            v-model="paidForm.paid_on"
                            type="date"
                        />
                        <InputError :message="paidForm.errors.paid_on" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="rc-ref">{{
                            t('pf.payment.reference')
                        }}</Label>
                        <Input
                            id="rc-ref"
                            v-model="paidForm.reference"
                            maxlength="140"
                        />
                        <InputError :message="paidForm.errors.reference" />
                    </div>
                </div>
                <Button
                    type="submit"
                    class="justify-self-start"
                    :disabled="paidForm.processing"
                    data-test="report-paid"
                >
                    <Spinner v-if="paidForm.processing" class="size-4" />{{
                        t('pf.public.already_paid_button')
                    }}
                </Button>
            </form>

            <div
                v-if="opted_out"
                class="mt-5 flex items-start gap-3 rounded-2xl border bg-muted/40 p-4 text-sm"
                data-test="opted-out"
            >
                <BellOff
                    class="mt-0.5 size-5 shrink-0 text-muted-foreground"
                    aria-hidden="true"
                />
                <p>{{ t('pf.public.opted_out', { company: company_name }) }}</p>
            </div>
            <form
                v-else
                class="mt-5 grid gap-3 rounded-2xl border p-4"
                data-test="stop-form"
                @submit.prevent="stop"
            >
                <p class="flex items-center gap-2 font-medium">
                    <BellOff class="size-4" aria-hidden="true" />{{
                        t('pf.public.stop')
                    }}
                </p>
                <p class="text-xs text-muted-foreground">
                    {{ t('pf.public.stop_hint', { company: company_name }) }}
                </p>
                <div class="grid gap-1.5">
                    <Label for="rc-note">{{ t('pf.public.note') }}</Label>
                    <Textarea
                        id="rc-note"
                        v-model="stopForm.note"
                        rows="2"
                        maxlength="500"
                    />
                    <InputError :message="stopForm.errors.note" />
                </div>
                <Button
                    type="submit"
                    variant="outline"
                    class="justify-self-start"
                    :disabled="stopForm.processing"
                    data-test="stop-reminders"
                >
                    <Spinner v-if="stopForm.processing" class="size-4" />{{
                        t('pf.public.stop_button')
                    }}
                </Button>
            </form>
        </section>

        <PublicFooter :legal="legal" :company="company_name" hide-choices />
    </div>
</template>
