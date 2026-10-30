<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { BellRing, Building2, Contact, NotebookPen, Save } from '@lucide/vue';
import { computed } from 'vue';
import SearchableSelect from '@/components/common/SearchableSelect.vue';
import SectionCard from '@/components/common/SectionCard.vue';
import TagsInput from '@/components/common/TagsInput.vue';
import UsageMeter from '@/components/common/UsageMeter.vue';
import InputError from '@/components/InputError.vue';
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
import { usePf } from '@/composables/pf/usePf';
import { t } from '@/lib/i18n';
import { bpToPercent, minorToDecimal } from '@/lib/pf/money';
import { currencyOptions, timezoneOptions } from '@/lib/regional';
import { index, show, store, update } from '@/routes/clients';
import type {
    ClientFormProps,
    LateFeeType,
    ToneCeiling,
} from '@/types/payflow';

const props = defineProps<ClientFormProps>();
const pf = usePf();
const client = props.client;
const ws = props.workspace_defaults;
const fee = client?.late_fee ?? { ...ws.late_fee, inherited: true };

const form = useForm({
    name: client?.name ?? '',
    legal_name: client?.legal_name ?? '',
    contact_name: client?.contact_name ?? '',
    email: client?.email ?? '',
    cc_emails: client?.cc_emails ?? ([] as string[]),
    phone: client?.phone ?? '',
    country: client?.country ?? null,
    timezone: client?.timezone ?? null,
    tax_id: client?.tax_id ?? '',
    address: client?.address ?? '',
    locale: client?.locale ?? pf.locale.value,
    currency: client?.currency ?? props.currencies[0] ?? 'USD',
    payment_terms_days:
        client?.payment_terms_days === null ||
        client?.payment_terms_days === undefined
            ? ''
            : String(client.payment_terms_days),
    reminder_policy_id:
        client && !client.reminder_policy.inherited
            ? String(client.reminder_policy.id)
            : 'default',
    tone_ceiling: (client?.tone_ceiling ?? 'none') as ToneCeiling,
    late_fee_override: client ? !client.late_fee.inherited : false,
    late_fee: {
        type: fee.type as LateFeeType,
        rate: bpToPercent(fee.rate_bp),
        fixed: fee.fixed
            ? minorToDecimal(fee.fixed.minor, fee.fixed.currency)
            : '',
        grace_days: String(fee.grace_days ?? 0),
        cap: bpToPercent(fee.cap_bp),
    },
    contract_document_id: client?.contract_document
        ? String(client.contract_document.id)
        : null,
    owner_user_id: client?.owner ? String(client.owner.id) : 'none',
    tags: client?.tags ?? ([] as string[]),
    notes: client?.notes ?? '',
});

const countryOptions = computed(() =>
    props.countries.map((c) => ({
        value: c.value,
        label: c.label,
        hint: c.value,
    })),
);
const currencyChoices = computed(() =>
    currencyOptions(props.currencies, pf.locale.value),
);
const zones = computed(() => {
    try {
        return timezoneOptions(
            (
                Intl as unknown as {
                    supportedValuesOf: (k: string) => string[];
                }
            ).supportedValuesOf('timeZone'),
        );
    } catch {
        return [];
    }
});
const documentOptions = computed(() =>
    props.documents.map((d) => ({ value: String(d.id), label: d.title })),
);

const feeTypes: LateFeeType[] = [
    'none',
    'percent_monthly',
    'percent_daily',
    'fixed',
];
const ceilings: ToneCeiling[] = ['none', 'neutral', 'firm'];

function submit(): void {
    const transform = (data: ReturnType<typeof form.data>) => ({
        ...data,
        payment_terms_days:
            data.payment_terms_days === ''
                ? null
                : Number(data.payment_terms_days),
        reminder_policy_id:
            data.reminder_policy_id === 'default'
                ? null
                : Number(data.reminder_policy_id),
        owner_user_id:
            data.owner_user_id === 'none' ? null : Number(data.owner_user_id),
        contract_document_id: data.contract_document_id
            ? Number(data.contract_document_id)
            : null,
        country: data.country || null,
        timezone: data.timezone || null,
        late_fee: data.late_fee_override
            ? {
                  type: data.late_fee.type,
                  rate: data.late_fee.rate || null,
                  fixed: data.late_fee.fixed || null,
                  grace_days: Number(data.late_fee.grace_days || 0),
                  cap: data.late_fee.cap || null,
              }
            : null,
    });

    if (client) {
        form.transform(transform).put(update(client.id).url, {
            preserveScroll: true,
        });
    } else {
        form.transform(transform).post(store().url, { preserveScroll: true });
    }
}

const err = (key: string): string | undefined =>
    (form.errors as Record<string, string | undefined>)[key];
</script>

<template>
    <form
        class="space-y-6"
        novalidate
        data-test="client-form"
        @submit.prevent="submit"
    >
        <UsageMeter
            v-if="!client && limits.clients.limit !== null"
            :label="t('pf.limits.clients')"
            :entry="{ used: limits.clients.used, limit: limits.clients.limit }"
        />

        <SectionCard :icon="Building2" :title="t('pf.client_form.basics')">
            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="client-name">{{ t('pf.client.name') }}</Label>
                    <Input
                        id="client-name"
                        v-model="form.name"
                        required
                        maxlength="200"
                        autocomplete="organization"
                        :aria-invalid="Boolean(form.errors.name)"
                        aria-describedby="client-name-error"
                    />
                    <InputError
                        id="client-name-error"
                        :message="form.errors.name"
                    />
                </div>
                <div class="grid gap-2">
                    <Label for="client-legal">{{
                        t('pf.client.legal_name')
                    }}</Label>
                    <Input
                        id="client-legal"
                        v-model="form.legal_name"
                        maxlength="200"
                    />
                    <InputError :message="form.errors.legal_name" />
                </div>
                <div class="grid gap-2">
                    <Label for="client-contact">{{
                        t('pf.client.contact_name')
                    }}</Label>
                    <Input
                        id="client-contact"
                        v-model="form.contact_name"
                        maxlength="120"
                        autocomplete="name"
                    />
                    <InputError :message="form.errors.contact_name" />
                </div>
                <div class="grid gap-2">
                    <Label for="client-email">{{ t('pf.client.email') }}</Label>
                    <Input
                        id="client-email"
                        v-model="form.email"
                        type="email"
                        maxlength="255"
                        autocomplete="email"
                        :aria-invalid="Boolean(form.errors.email)"
                    />
                    <p class="text-xs text-muted-foreground">
                        {{ t('pf.client_form.email_hint') }}
                    </p>
                    <InputError :message="form.errors.email" />
                </div>
                <div class="grid gap-2">
                    <Label for="client-cc">{{
                        t('pf.client.cc_emails')
                    }}</Label>
                    <TagsInput
                        id="client-cc"
                        v-model="form.cc_emails"
                        :max="5"
                        :max-length="255"
                        :placeholder="t('pf.client_form.cc_placeholder')"
                    />
                    <InputError
                        :message="form.errors.cc_emails ?? err('cc_emails.0')"
                    />
                </div>
                <div class="grid gap-2">
                    <Label for="client-phone">{{ t('pf.client.phone') }}</Label>
                    <Input
                        id="client-phone"
                        v-model="form.phone"
                        type="tel"
                        maxlength="40"
                        autocomplete="tel"
                    />
                    <InputError :message="form.errors.phone" />
                </div>
            </div>
        </SectionCard>

        <SectionCard :icon="Contact" :title="t('pf.client_form.billing')">
            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="client-country">{{
                        t('pf.client.country')
                    }}</Label>
                    <SearchableSelect
                        id="client-country"
                        v-model="form.country"
                        :options="countryOptions"
                        clearable
                        :placeholder="t('pf.client_form.choose')"
                        :invalid="Boolean(form.errors.country)"
                    />
                    <InputError :message="form.errors.country" />
                </div>
                <div class="grid gap-2">
                    <Label for="client-currency">{{
                        t('pf.client.currency')
                    }}</Label>
                    <SearchableSelect
                        id="client-currency"
                        v-model="form.currency"
                        :options="currencyChoices"
                        :invalid="Boolean(form.errors.currency)"
                    />
                    <InputError :message="form.errors.currency" />
                </div>
                <div class="grid gap-2">
                    <Label for="client-locale">{{
                        t('pf.client.locale')
                    }}</Label>
                    <Select v-model="form.locale">
                        <SelectTrigger id="client-locale" class="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="en">English</SelectItem>
                            <SelectItem value="ru">Русский</SelectItem>
                        </SelectContent>
                    </Select>
                    <p class="text-xs text-muted-foreground">
                        {{ t('pf.client_form.locale_hint') }}
                    </p>
                </div>
                <div class="grid gap-2">
                    <Label for="client-tz">{{ t('pf.client.timezone') }}</Label>
                    <SearchableSelect
                        id="client-tz"
                        v-model="form.timezone"
                        :options="zones"
                        clearable
                        :placeholder="t('pf.client_form.workspace_timezone')"
                        :invalid="Boolean(form.errors.timezone)"
                    />
                    <InputError :message="form.errors.timezone" />
                </div>
                <div class="grid gap-2">
                    <Label for="client-terms">{{
                        t('pf.client.payment_terms')
                    }}</Label>
                    <Input
                        id="client-terms"
                        v-model="form.payment_terms_days"
                        type="number"
                        min="0"
                        max="180"
                        inputmode="numeric"
                        :placeholder="
                            t('pf.client_form.default_days', {
                                days: ws.payment_terms_days,
                            })
                        "
                    />
                    <InputError :message="form.errors.payment_terms_days" />
                </div>
                <div class="grid gap-2">
                    <Label for="client-tax">{{ t('pf.client.tax_id') }}</Label>
                    <Input
                        id="client-tax"
                        v-model="form.tax_id"
                        maxlength="64"
                    />
                    <InputError :message="form.errors.tax_id" />
                </div>
                <div class="grid gap-2 sm:col-span-2">
                    <Label for="client-address">{{
                        t('pf.client.address')
                    }}</Label>
                    <Textarea
                        id="client-address"
                        v-model="form.address"
                        rows="2"
                        maxlength="1000"
                        autocomplete="street-address"
                    />
                    <InputError :message="form.errors.address" />
                </div>
            </div>
        </SectionCard>

        <SectionCard
            :icon="BellRing"
            :title="t('pf.client_form.collections')"
            :description="t('pf.client_form.collections_hint')"
        >
            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="client-policy">{{
                        t('pf.client.reminder_policy')
                    }}</Label>
                    <Select v-model="form.reminder_policy_id">
                        <SelectTrigger id="client-policy" class="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="default">{{
                                ws.policy
                                    ? t('pf.client_form.workspace_policy', {
                                          name: ws.policy.label,
                                      })
                                    : t('pf.client_form.workspace_default')
                            }}</SelectItem>
                            <SelectItem
                                v-for="policy in policies"
                                :key="policy.value"
                                :value="String(policy.value)"
                                >{{ policy.label }}</SelectItem
                            >
                        </SelectContent>
                    </Select>
                    <InputError :message="form.errors.reminder_policy_id" />
                </div>
                <div class="grid gap-2">
                    <Label for="client-ceiling">{{
                        t('pf.client.tone_ceiling')
                    }}</Label>
                    <Select v-model="form.tone_ceiling">
                        <SelectTrigger id="client-ceiling" class="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="ceiling in ceilings"
                                :key="ceiling"
                                :value="ceiling"
                                >{{
                                    t(`pf.tone_ceiling.${ceiling}`)
                                }}</SelectItem
                            >
                        </SelectContent>
                    </Select>
                    <p class="text-xs text-muted-foreground">
                        {{ t('pf.client_form.ceiling_hint') }}
                    </p>
                </div>
            </div>

            <div class="mt-6 rounded-xl border bg-muted/30 p-4">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <Label for="client-fee-override" class="font-medium">{{
                            t('pf.client_form.late_fee_override')
                        }}</Label>
                        <p
                            id="client-fee-hint"
                            class="text-xs text-muted-foreground"
                        >
                            {{ t('pf.client_form.late_fee_hint') }}
                        </p>
                    </div>
                    <Switch
                        id="client-fee-override"
                        v-model="form.late_fee_override"
                        aria-describedby="client-fee-hint"
                    />
                </div>
                <div
                    v-if="form.late_fee_override"
                    class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-4"
                >
                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="fee-type">{{
                            t('pf.late_fee.type')
                        }}</Label>
                        <Select v-model="form.late_fee.type">
                            <SelectTrigger id="fee-type" class="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="type in feeTypes"
                                    :key="type"
                                    :value="type"
                                    >{{
                                        t(`pf.late_fee.types.${type}`)
                                    }}</SelectItem
                                >
                            </SelectContent>
                        </Select>
                        <InputError :message="err('late_fee.type')" />
                    </div>
                    <div
                        v-if="form.late_fee.type.startsWith('percent')"
                        class="grid gap-2"
                    >
                        <Label for="fee-rate">{{
                            t('pf.late_fee.rate')
                        }}</Label>
                        <Input
                            id="fee-rate"
                            v-model="form.late_fee.rate"
                            inputmode="decimal"
                            placeholder="1.5"
                        />
                        <InputError :message="err('late_fee.rate')" />
                    </div>
                    <div
                        v-if="form.late_fee.type === 'fixed'"
                        class="grid gap-2"
                    >
                        <Label for="fee-fixed">{{
                            t('pf.late_fee.fixed', { currency: form.currency })
                        }}</Label>
                        <Input
                            id="fee-fixed"
                            v-model="form.late_fee.fixed"
                            inputmode="decimal"
                            placeholder="40.00"
                        />
                        <InputError :message="err('late_fee.fixed')" />
                    </div>
                    <div
                        v-if="form.late_fee.type !== 'none'"
                        class="grid gap-2"
                    >
                        <Label for="fee-grace">{{
                            t('pf.late_fee.grace_days')
                        }}</Label>
                        <Input
                            id="fee-grace"
                            v-model="form.late_fee.grace_days"
                            type="number"
                            min="0"
                            max="90"
                        />
                        <InputError :message="err('late_fee.grace_days')" />
                    </div>
                    <div
                        v-if="form.late_fee.type.startsWith('percent')"
                        class="grid gap-2"
                    >
                        <Label for="fee-cap">{{ t('pf.late_fee.cap') }}</Label>
                        <Input
                            id="fee-cap"
                            v-model="form.late_fee.cap"
                            inputmode="decimal"
                            placeholder="10"
                        />
                        <InputError :message="err('late_fee.cap')" />
                    </div>
                </div>
            </div>
        </SectionCard>

        <SectionCard :icon="NotebookPen" :title="t('pf.client_form.internal')">
            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="client-owner">{{ t('pf.client.owner') }}</Label>
                    <Select v-model="form.owner_user_id">
                        <SelectTrigger id="client-owner" class="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="none">{{
                                t('pf.client_form.no_owner')
                            }}</SelectItem>
                            <SelectItem
                                v-for="member in members"
                                :key="member.value"
                                :value="String(member.value)"
                                >{{ member.label }}</SelectItem
                            >
                        </SelectContent>
                    </Select>
                    <InputError :message="form.errors.owner_user_id" />
                </div>
                <div class="grid gap-2">
                    <Label for="client-contract">{{
                        t('pf.client.contract')
                    }}</Label>
                    <SearchableSelect
                        id="client-contract"
                        v-model="form.contract_document_id"
                        :options="documentOptions"
                        clearable
                        :placeholder="t('pf.client_form.no_contract')"
                    />
                    <p class="text-xs text-muted-foreground">
                        {{ t('pf.client_form.contract_hint') }}
                    </p>
                    <InputError :message="form.errors.contract_document_id" />
                </div>
                <div class="grid gap-2 sm:col-span-2">
                    <Label for="client-tags">{{ t('pf.client.tags') }}</Label>
                    <TagsInput
                        id="client-tags"
                        v-model="form.tags"
                        :max="10"
                        :max-length="30"
                    />
                    <InputError :message="form.errors.tags ?? err('tags.0')" />
                </div>
                <div class="grid gap-2 sm:col-span-2">
                    <Label for="client-notes">{{ t('pf.client.notes') }}</Label>
                    <Textarea
                        id="client-notes"
                        v-model="form.notes"
                        rows="3"
                        maxlength="5000"
                    />
                    <p class="text-xs text-muted-foreground">
                        {{ t('pf.client_form.notes_hint') }}
                    </p>
                    <InputError :message="form.errors.notes" />
                </div>
            </div>
        </SectionCard>

        <div
            class="sticky bottom-0 z-10 -mx-4 flex flex-wrap justify-end gap-2 border-t bg-background/90 px-4 py-3 backdrop-blur sm:mx-0 sm:rounded-2xl sm:border"
        >
            <Button variant="ghost" as-child>
                <Link :href="client ? show(client.id).url : index().url">{{
                    t('ui.common.cancel')
                }}</Link>
            </Button>
            <Button
                type="submit"
                :disabled="form.processing"
                data-test="client-save"
            >
                <Spinner v-if="form.processing" class="size-4" />
                <Save v-else class="size-4" aria-hidden="true" />
                {{
                    client
                        ? t('pf.actions.save_changes')
                        : t('pf.clients.create')
                }}
            </Button>
        </div>
    </form>
</template>
