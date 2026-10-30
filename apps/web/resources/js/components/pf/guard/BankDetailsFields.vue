<script setup lang="ts">
import { computed } from 'vue';
import SearchableSelect from '@/components/common/SearchableSelect.vue';
import SegmentedControl from '@/components/common/SegmentedControl.vue';
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { t } from '@/lib/i18n';
import type { BankScheme, Option } from '@/types/payflow-guard';

export type BankFields = {
    scheme: BankScheme;
    account_holder_name: string;
    iban: string;
    account_number: string;
    routing_number: string;
    swift_bic: string;
    bank_name: string;
    bank_country: string | null;
    currency: string;
};

const props = withDefaults(
    defineProps<{
        countries: Option<string>[];
        currencies: string[];
        errors: Record<string, string | undefined>;
        prefix?: string;
        idPrefix?: string;
    }>(),
    { prefix: '', idPrefix: 'bank' },
);

const bank = defineModel<BankFields>({ required: true });

const schemes = computed(() =>
    (['iban', 'us_ach', 'local'] as BankScheme[]).map((value) => ({
        value,
        label: t(`guard.bank.scheme.${value}`),
    })),
);
const countryOptions = computed(() =>
    props.countries.map((c) => ({
        value: c.value,
        label: c.label,
        hint: c.value,
    })),
);
const err = (key: string) => props.errors[`${props.prefix}${key}`];
const id = (key: string) => `${props.idPrefix}-${key}`;
</script>

<template>
    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
        <div class="grid gap-2 sm:col-span-2">
            <span class="text-sm font-medium">{{
                t('guard.bank.scheme_label')
            }}</span>
            <SegmentedControl
                v-model="bank.scheme"
                :options="schemes"
                :label="t('guard.bank.scheme_label')"
            />
        </div>
        <div class="grid gap-2 sm:col-span-2">
            <Label :for="id('holder')">{{ t('guard.bank.holder') }}</Label>
            <Input
                :id="id('holder')"
                v-model="bank.account_holder_name"
                maxlength="200"
                autocomplete="off"
                :aria-invalid="Boolean(err('account_holder_name'))"
            />
            <InputError :message="err('account_holder_name')" />
        </div>
        <div v-if="bank.scheme === 'iban'" class="grid gap-2 sm:col-span-2">
            <Label :for="id('iban')">IBAN</Label>
            <Input
                :id="id('iban')"
                v-model="bank.iban"
                maxlength="42"
                autocomplete="off"
                spellcheck="false"
                class="font-mono uppercase"
                :aria-invalid="Boolean(err('iban'))"
                data-test="bank-iban"
            />
            <InputError :message="err('iban')" />
        </div>
        <template v-else>
            <div class="grid gap-2">
                <Label :for="id('account')">{{
                    t('guard.bank.account_number')
                }}</Label>
                <Input
                    :id="id('account')"
                    v-model="bank.account_number"
                    maxlength="40"
                    autocomplete="off"
                    class="font-mono"
                    :aria-invalid="Boolean(err('account_number'))"
                />
                <InputError :message="err('account_number')" />
            </div>
            <div v-if="bank.scheme === 'us_ach'" class="grid gap-2">
                <Label :for="id('routing')">{{
                    t('guard.bank.routing_number')
                }}</Label>
                <Input
                    :id="id('routing')"
                    v-model="bank.routing_number"
                    maxlength="20"
                    inputmode="numeric"
                    autocomplete="off"
                    class="font-mono"
                    :aria-invalid="Boolean(err('routing_number'))"
                />
                <InputError :message="err('routing_number')" />
            </div>
        </template>
        <div class="grid gap-2">
            <Label :for="id('swift')">SWIFT / BIC</Label>
            <Input
                :id="id('swift')"
                v-model="bank.swift_bic"
                maxlength="11"
                autocomplete="off"
                class="font-mono uppercase"
                :aria-invalid="Boolean(err('swift_bic'))"
            />
            <InputError :message="err('swift_bic')" />
        </div>
        <div class="grid gap-2">
            <Label :for="id('bank-name')">{{
                t('guard.bank.bank_name')
            }}</Label>
            <Input
                :id="id('bank-name')"
                v-model="bank.bank_name"
                maxlength="200"
            />
            <InputError :message="err('bank_name')" />
        </div>
        <div class="grid gap-2">
            <Label :for="id('country')">{{
                t('guard.bank.bank_country')
            }}</Label>
            <SearchableSelect
                :id="id('country')"
                v-model="bank.bank_country"
                :options="countryOptions"
                :invalid="Boolean(err('bank_country'))"
            />
            <InputError :message="err('bank_country')" />
        </div>
        <div class="grid gap-2">
            <Label :for="id('currency')">{{
                t('guard.fields.currency')
            }}</Label>
            <Select v-model="bank.currency">
                <SelectTrigger :id="id('currency')" class="w-full"
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
            <InputError :message="err('currency')" />
        </div>
    </div>
</template>
