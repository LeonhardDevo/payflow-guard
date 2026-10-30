<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { Landmark, Save, Store } from '@lucide/vue';
import { computed, ref } from 'vue';
import SearchableSelect from '@/components/common/SearchableSelect.vue';
import SectionCard from '@/components/common/SectionCard.vue';
import UsageMeter from '@/components/common/UsageMeter.vue';
import InputError from '@/components/InputError.vue';
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
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { t } from '@/lib/i18n';
import { bankPayload, blankBank } from '@/lib/pf/bank';
import { index, show, store, update } from '@/routes/vendors';
import type { VendorCategory, VendorFormProps } from '@/types/payflow-guard';

const props = defineProps<VendorFormProps>();
const vendor = props.vendor;
const defaultCurrency =
    vendor?.default_currency ?? props.currencies[0] ?? 'USD';

const form = useForm({
    name: vendor?.name ?? '',
    legal_name: vendor?.legal_name ?? '',
    category: (vendor?.category ?? 'contractor') as VendorCategory,
    email: vendor?.email ?? '',
    phone: vendor?.phone ?? '',
    country: vendor?.country ?? null,
    default_currency: defaultCurrency,
    tax_id: '',
    notes: vendor?.notes ?? '',
    bank: blankBank(defaultCurrency, vendor?.country ?? null),
});

const withBank = ref(false);

const countryOptions = computed(() =>
    props.countries.map((c) => ({
        value: c.value,
        label: c.label,
        hint: c.value,
    })),
);

function submit(): void {
    const payload = (data: ReturnType<typeof form.data>) => ({
        name: data.name,
        legal_name: data.legal_name || null,
        category: data.category,
        email: data.email || null,
        phone: data.phone || null,
        country: data.country || null,
        default_currency: data.default_currency,
        ...(data.tax_id
            ? { tax_id: data.tax_id }
            : vendor
              ? {}
              : { tax_id: null }),
        notes: data.notes || null,
        ...(!vendor && withBank.value ? { bank: bankPayload(data.bank) } : {}),
    });

    if (vendor) {
        form.transform(payload).put(update(vendor.id).url, {
            preserveScroll: true,
        });
    } else {
        form.transform(payload).post(store().url, { preserveScroll: true });
    }
}

const errors = computed(
    () => form.errors as Record<string, string | undefined>,
);
</script>

<template>
    <form
        class="space-y-6"
        novalidate
        data-test="vendor-form"
        @submit.prevent="submit"
    >
        <UsageMeter
            v-if="!vendor && limits.vendors.limit !== null"
            :label="t('pf.limits.vendors')"
            :entry="limits.vendors"
        />

        <SectionCard :icon="Store" :title="t('guard.vendor_form.details')">
            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="vendor-name">{{
                        t('guard.vendor.name')
                    }}</Label>
                    <Input
                        id="vendor-name"
                        v-model="form.name"
                        required
                        maxlength="200"
                        :aria-invalid="Boolean(form.errors.name)"
                    />
                    <InputError :message="form.errors.name" />
                </div>
                <div class="grid gap-2">
                    <Label for="vendor-legal">{{
                        t('guard.vendor.legal_name')
                    }}</Label>
                    <Input
                        id="vendor-legal"
                        v-model="form.legal_name"
                        maxlength="200"
                    />
                    <InputError :message="form.errors.legal_name" />
                </div>
                <div class="grid gap-2">
                    <Label for="vendor-category">{{
                        t('guard.vendor.category')
                    }}</Label>
                    <Select v-model="form.category">
                        <SelectTrigger id="vendor-category" class="w-full"
                            ><SelectValue
                        /></SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="category in categories"
                                :key="category.value"
                                :value="category.value"
                                >{{ category.label }}</SelectItem
                            >
                        </SelectContent>
                    </Select>
                </div>
                <div class="grid gap-2">
                    <Label for="vendor-country">{{
                        t('guard.fields.country')
                    }}</Label>
                    <SearchableSelect
                        id="vendor-country"
                        v-model="form.country"
                        :options="countryOptions"
                        clearable
                        :placeholder="t('guard.fields.choose')"
                    />
                    <InputError :message="form.errors.country" />
                </div>
                <div class="grid gap-2">
                    <Label for="vendor-email">{{
                        t('guard.vendor.email')
                    }}</Label>
                    <Input
                        id="vendor-email"
                        v-model="form.email"
                        type="email"
                        maxlength="255"
                        :aria-invalid="Boolean(form.errors.email)"
                    />
                    <p class="text-xs text-muted-foreground">
                        {{ t('guard.vendor_form.email_hint') }}
                    </p>
                    <InputError :message="form.errors.email" />
                </div>
                <div class="grid gap-2">
                    <Label for="vendor-phone">{{
                        t('guard.vendor.phone')
                    }}</Label>
                    <Input
                        id="vendor-phone"
                        v-model="form.phone"
                        type="tel"
                        maxlength="20"
                        :aria-invalid="Boolean(form.errors.phone)"
                    />
                    <p class="text-xs text-muted-foreground">
                        {{ t('guard.vendor_form.phone_hint') }}
                    </p>
                    <InputError :message="form.errors.phone" />
                </div>
                <div class="grid gap-2">
                    <Label for="vendor-currency">{{
                        t('guard.vendor.default_currency')
                    }}</Label>
                    <Select v-model="form.default_currency">
                        <SelectTrigger id="vendor-currency" class="w-full"
                            ><SelectValue
                        /></SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="code in currencies"
                                :key="code"
                                :value="code"
                                >{{ code }}</SelectItem
                            >
                        </SelectContent>
                    </Select>
                </div>
                <div class="grid gap-2">
                    <Label for="vendor-tax">{{
                        t('guard.fields.tax_id')
                    }}</Label>
                    <Input
                        id="vendor-tax"
                        v-model="form.tax_id"
                        maxlength="64"
                        :placeholder="vendor?.tax_id_masked ?? ''"
                    />
                    <p
                        v-if="vendor?.tax_id_masked"
                        class="text-xs text-muted-foreground"
                    >
                        {{ t('guard.vendor_form.tax_keep') }}
                    </p>
                    <InputError :message="form.errors.tax_id" />
                </div>
                <div class="grid gap-2 sm:col-span-2">
                    <Label for="vendor-notes">{{
                        t('guard.fields.notes')
                    }}</Label>
                    <Textarea
                        id="vendor-notes"
                        v-model="form.notes"
                        rows="3"
                        maxlength="5000"
                    />
                    <InputError :message="form.errors.notes" />
                </div>
            </div>
        </SectionCard>

        <SectionCard
            v-if="!vendor"
            :icon="Landmark"
            :title="t('guard.vendor_form.bank')"
            :description="t('guard.vendor_form.bank_hint')"
        >
            <template #actions>
                <Switch
                    v-model="withBank"
                    :aria-label="t('guard.vendor_form.add_bank')"
                    data-test="vendor-with-bank"
                />
            </template>
            <p v-if="!withBank" class="text-sm text-muted-foreground">
                {{ t('guard.vendor_form.bank_later') }}
            </p>
            <BankDetailsFields
                v-else
                v-model="form.bank"
                :countries="countries"
                :currencies="currencies"
                :errors="errors"
                prefix="bank."
                id-prefix="vendor-bank"
            />
        </SectionCard>

        <div
            class="sticky bottom-0 z-10 -mx-4 flex flex-wrap justify-end gap-2 border-t bg-background/90 px-4 py-3 backdrop-blur sm:mx-0 sm:rounded-2xl sm:border"
        >
            <Button variant="ghost" as-child>
                <Link :href="vendor ? show(vendor.id).url : index().url">{{
                    t('ui.common.cancel')
                }}</Link>
            </Button>
            <Button
                type="submit"
                :disabled="form.processing"
                data-test="vendor-save"
            >
                <Spinner v-if="form.processing" class="size-4" />
                <Save v-else class="size-4" aria-hidden="true" />
                {{
                    vendor
                        ? t('pf.actions.save_changes')
                        : t('guard.vendors.create')
                }}
            </Button>
        </div>
    </form>
</template>
