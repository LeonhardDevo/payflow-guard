<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { FileText, Landmark, RefreshCcw, ShieldAlert } from '@lucide/vue';
import { computed } from 'vue';
import PageHeader from '@/components/common/PageHeader.vue';
import SectionCard from '@/components/common/SectionCard.vue';
import InputError from '@/components/InputError.vue';
import GuardBand from '@/components/pf/guard/GuardBand.vue';
import BankAccountCard from '@/components/pf/guard/BankAccountCard.vue';
import BankDetailsFields from '@/components/pf/guard/BankDetailsFields.vue';
import { Button } from '@/components/ui/button';
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
import { t } from '@/lib/i18n';
import { bankPayload, blankBank } from '@/lib/pf/bank';
import { index, show } from '@/routes/vendors';
import { store } from '@/routes/vendors/bank-changes';
import type {
    BankChangeFormProps,
    BankChangeSource,
} from '@/types/payflow-guard';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'pf.nav.vendors', href: index() }],
    },
});

const props = defineProps<BankChangeFormProps>();

const form = useForm({
    bank: blankBank(
        props.current?.currency ?? props.vendor.default_currency,
        props.current?.bank_country ?? props.vendor.country,
    ),
    source: (props.sources[0]?.value ?? 'vendor_email') as BankChangeSource,
    requester_contact: '',
    change_request_text: '',
    received_at: '',
});

const contactKind = computed(() =>
    form.source === 'vendor_email'
        ? 'email'
        : form.source === 'vendor_phone'
          ? 'phone'
          : null,
);

const errors = computed(
    () => form.errors as Record<string, string | undefined>,
);

function submit(): void {
    form.transform((data) => ({
        ...bankPayload(data.bank),
        source: data.source,
        requester_contact: contactKind.value
            ? data.requester_contact || null
            : null,
        change_request_text: data.change_request_text || null,
        received_at: data.received_at
            ? new Date(data.received_at).toISOString()
            : null,
    })).post(store(props.vendor.id).url, { preserveScroll: true });
}
</script>

<template>
    <Head :title="t('guard.bank_change.title', { name: vendor.name })" />

    <div
        class="mx-auto flex w-full max-w-4xl flex-col gap-6 px-4 py-6 sm:px-6 lg:py-8"
    >
        <GuardBand />
        <PageHeader
            :icon="RefreshCcw"
            :eyebrow="vendor.name"
            :title="
                current
                    ? t('guard.bank_change.title_change')
                    : t('guard.bank_change.title_new')
            "
        />

        <div
            class="flex items-start gap-3 rounded-2xl border border-info/30 bg-info-soft p-4 text-sm text-info-soft-foreground"
        >
            <ShieldAlert class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
            <p>{{ t('guard.bank_change.safety') }}</p>
        </div>

        <SectionCard
            v-if="current"
            :icon="Landmark"
            :title="t('guard.bank_change.current')"
        >
            <BankAccountCard :account="current" compact />
        </SectionCard>

        <form
            class="space-y-6"
            novalidate
            data-test="bank-change-form"
            @submit.prevent="submit"
        >
            <SectionCard
                :icon="Landmark"
                :title="t('guard.bank_change.new_details')"
            >
                <BankDetailsFields
                    v-model="form.bank"
                    :countries="countries"
                    :currencies="currencies"
                    :errors="errors"
                    id-prefix="change"
                />
            </SectionCard>

            <SectionCard
                :icon="FileText"
                :title="t('guard.bank_change.request')"
                :description="t('guard.bank_change.request_hint')"
            >
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="change-source">{{
                            t('guard.bank_change.source')
                        }}</Label>
                        <Select v-model="form.source">
                            <SelectTrigger id="change-source" class="w-full"
                                ><SelectValue
                            /></SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="source in sources"
                                    :key="source.value"
                                    :value="source.value"
                                    >{{ source.label }}</SelectItem
                                >
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.source" />
                    </div>
                    <div v-if="contactKind" class="grid gap-2">
                        <Label for="change-contact">{{
                            contactKind === 'email'
                                ? t('guard.bank_change.from_email')
                                : t('guard.bank_change.from_phone')
                        }}</Label>
                        <Input
                            id="change-contact"
                            v-model="form.requester_contact"
                            :type="contactKind === 'email' ? 'email' : 'tel'"
                            maxlength="255"
                            :aria-invalid="
                                Boolean(form.errors.requester_contact)
                            "
                            data-test="change-contact"
                        />
                        <p class="text-xs text-muted-foreground">
                            {{ t('guard.bank_change.contact_hint') }}
                        </p>
                        <InputError :message="form.errors.requester_contact" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="change-received">{{
                            t('guard.bank_change.received_at')
                        }}</Label>
                        <Input
                            id="change-received"
                            v-model="form.received_at"
                            type="datetime-local"
                        />
                        <InputError :message="form.errors.received_at" />
                    </div>
                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="change-text">{{
                            t('guard.bank_change.text')
                        }}</Label>
                        <Textarea
                            id="change-text"
                            v-model="form.change_request_text"
                            rows="6"
                            maxlength="10000"
                            :placeholder="
                                t('guard.bank_change.text_placeholder')
                            "
                            data-test="change-text"
                        />
                        <p class="text-xs text-muted-foreground">
                            {{ t('guard.bank_change.text_hint') }}
                        </p>
                        <InputError
                            :message="form.errors.change_request_text"
                        />
                    </div>
                </div>
            </SectionCard>

            <div
                class="sticky bottom-0 z-10 -mx-4 flex flex-wrap justify-end gap-2 border-t bg-background/90 px-4 py-3 backdrop-blur sm:mx-0 sm:rounded-2xl sm:border"
            >
                <Button variant="ghost" as-child>
                    <Link :href="show(vendor.id).url">{{
                        t('ui.common.cancel')
                    }}</Link>
                </Button>
                <Button
                    type="submit"
                    :disabled="form.processing"
                    data-test="bank-change-submit"
                >
                    <Spinner v-if="form.processing" class="size-4" />
                    {{ t('guard.bank_change.submit') }}
                </Button>
            </div>
        </form>
    </div>
</template>
