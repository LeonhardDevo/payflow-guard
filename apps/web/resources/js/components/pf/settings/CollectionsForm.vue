<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Building2, Clock, Gauge, Mail, Save, Timer } from '@lucide/vue';
import { computed } from 'vue';
import SectionCard from '@/components/common/SectionCard.vue';
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
import { t } from '@/lib/i18n';
import { bpToPercent, minorToDecimal } from '@/lib/pf/money';
import { update } from '@/routes/settings/collections';
import type {
    CollectionsSettings,
    LateFeeType,
    ReminderPolicy,
} from '@/types/payflow';

const props = defineProps<{
    settings: CollectionsSettings;
    policies: ReminderPolicy[];
    timezone: string;
}>();

const s = props.settings;
const fee = s.late_fee;

const form = useForm({
    invoice_prefix: s.invoice_prefix,
    default_payment_terms_days: String(s.default_payment_terms_days),
    default_reminder_policy_id: s.default_reminder_policy_id
        ? String(s.default_reminder_policy_id)
        : 'none',
    send_window_start: s.send_window_start,
    send_window_end: s.send_window_end,
    business_days_only: s.business_days_only,
    first_contact_max_per_hour: String(s.first_contact_max_per_hour),
    sender_display_name: s.sender_display_name ?? '',
    reply_to_email: s.reply_to_email ?? '',
    privacy_contact_email: s.privacy_contact_email ?? '',
    track_invoice_views: s.track_invoice_views,
    late_fee: {
        type: fee.type as LateFeeType,
        rate: bpToPercent(fee.rate_bp),
        fixed: fee.fixed
            ? minorToDecimal(fee.fixed.minor, fee.fixed.currency)
            : '',
        grace_days: String(fee.grace_days ?? 0),
        cap: bpToPercent(fee.cap_bp),
    },
    company: {
        legal_name: s.company.legal_name ?? '',
        address: s.company.address ?? '',
        tax_id: s.company.tax_id ?? '',
        email: s.company.email ?? '',
        phone: s.company.phone ?? '',
    },
    digest_enabled: s.digest_enabled,
    digest_hour: String(s.digest_hour),
});

const feeTypes: LateFeeType[] = [
    'none',
    'percent_monthly',
    'percent_daily',
    'fixed',
];
const hours = Array.from({ length: 24 }, (_, i) => String(i));
const err = (key: string) =>
    (form.errors as Record<string, string | undefined>)[key];

const nextNumber = computed(
    () =>
        `${form.invoice_prefix}${new Date().getFullYear()}-${String(s.next_invoice_seq).padStart(4, '0')}`,
);

function submit(): void {
    const nullable = (value: string) =>
        value.trim() === '' ? null : value.trim();

    form.transform((data) => ({
        ...data,
        invoice_prefix: data.invoice_prefix.toUpperCase(),
        default_payment_terms_days: Number(data.default_payment_terms_days),
        default_reminder_policy_id:
            data.default_reminder_policy_id === 'none'
                ? null
                : Number(data.default_reminder_policy_id),
        first_contact_max_per_hour: Number(data.first_contact_max_per_hour),
        sender_display_name: nullable(data.sender_display_name),
        reply_to_email: nullable(data.reply_to_email),
        privacy_contact_email: nullable(data.privacy_contact_email),
        late_fee: {
            type: data.late_fee.type,
            rate: nullable(data.late_fee.rate),
            fixed: nullable(data.late_fee.fixed),
            grace_days: Number(data.late_fee.grace_days || 0),
            cap: nullable(data.late_fee.cap),
        },
        company: Object.fromEntries(
            Object.entries(data.company).map(([k, v]) => [k, nullable(v)]),
        ),
        digest_hour: Number(data.digest_hour),
    })).put(update().url, { preserveScroll: true });
}
</script>

<template>
    <form
        class="flex flex-col gap-6"
        novalidate
        data-test="collections-form"
        @submit.prevent="submit"
    >
        <SectionCard
            :icon="Timer"
            :title="t('pf.settings.collections.invoicing')"
        >
            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="cs-prefix">{{
                        t('pf.settings.collections.prefix')
                    }}</Label>
                    <Input
                        id="cs-prefix"
                        v-model="form.invoice_prefix"
                        maxlength="12"
                        class="font-mono uppercase"
                        :aria-invalid="Boolean(form.errors.invoice_prefix)"
                    />
                    <p class="text-xs text-muted-foreground">
                        {{
                            t('pf.settings.collections.next_number', {
                                number: nextNumber,
                            })
                        }}
                    </p>
                    <InputError :message="form.errors.invoice_prefix" />
                </div>
                <div class="grid gap-2">
                    <Label for="cs-terms">{{
                        t('pf.client.payment_terms')
                    }}</Label>
                    <Input
                        id="cs-terms"
                        v-model="form.default_payment_terms_days"
                        type="number"
                        min="0"
                        max="180"
                    />
                    <InputError
                        :message="form.errors.default_payment_terms_days"
                    />
                </div>
                <div class="grid gap-2">
                    <Label for="cs-policy">{{
                        t('pf.settings.collections.default_policy')
                    }}</Label>
                    <Select v-model="form.default_reminder_policy_id">
                        <SelectTrigger id="cs-policy" class="w-full"
                            ><SelectValue
                        /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="none">{{
                                t('pf.settings.collections.no_policy')
                            }}</SelectItem>
                            <SelectItem
                                v-for="policy in policies"
                                :key="policy.id"
                                :value="String(policy.id)"
                                >{{ policy.name }}</SelectItem
                            >
                        </SelectContent>
                    </Select>
                </div>
                <div
                    class="flex items-start justify-between gap-3 rounded-xl border p-3"
                >
                    <div>
                        <Label for="cs-track" class="font-medium">{{
                            t('pf.settings.collections.track_views')
                        }}</Label>
                        <p class="text-xs text-muted-foreground">
                            {{ t('pf.settings.collections.track_views_hint') }}
                        </p>
                    </div>
                    <Switch id="cs-track" v-model="form.track_invoice_views" />
                </div>
            </div>
        </SectionCard>

        <SectionCard
            :icon="Clock"
            :title="t('pf.settings.collections.sending')"
            :description="
                t('pf.settings.collections.sending_hint', { timezone })
            "
        >
            <div class="grid grid-cols-1 gap-5 sm:grid-cols-3">
                <div class="grid gap-2">
                    <Label for="cs-start">{{
                        t('pf.settings.collections.window_start')
                    }}</Label>
                    <Input
                        id="cs-start"
                        v-model="form.send_window_start"
                        type="time"
                    />
                    <InputError :message="form.errors.send_window_start" />
                </div>
                <div class="grid gap-2">
                    <Label for="cs-end">{{
                        t('pf.settings.collections.window_end')
                    }}</Label>
                    <Input
                        id="cs-end"
                        v-model="form.send_window_end"
                        type="time"
                    />
                    <InputError :message="form.errors.send_window_end" />
                </div>
                <div class="grid gap-2">
                    <Label for="cs-rate">{{
                        t('pf.settings.collections.first_contact_rate')
                    }}</Label>
                    <Input
                        id="cs-rate"
                        v-model="form.first_contact_max_per_hour"
                        type="number"
                        min="1"
                        max="500"
                    />
                    <InputError
                        :message="form.errors.first_contact_max_per_hour"
                    />
                </div>
                <div
                    class="flex items-start justify-between gap-3 rounded-xl border p-3 sm:col-span-3"
                >
                    <div>
                        <Label for="cs-business" class="font-medium">{{
                            t('pf.settings.collections.business_days')
                        }}</Label>
                        <p class="text-xs text-muted-foreground">
                            {{
                                t('pf.settings.collections.business_days_hint')
                            }}
                        </p>
                    </div>
                    <Switch
                        id="cs-business"
                        v-model="form.business_days_only"
                    />
                </div>
            </div>
        </SectionCard>

        <SectionCard :icon="Mail" :title="t('pf.settings.collections.sender')">
            <div class="grid grid-cols-1 gap-5 sm:grid-cols-3">
                <div class="grid gap-2">
                    <Label for="cs-name">{{
                        t('pf.settings.collections.sender_name')
                    }}</Label>
                    <Input
                        id="cs-name"
                        v-model="form.sender_display_name"
                        maxlength="120"
                    />
                    <InputError :message="form.errors.sender_display_name" />
                </div>
                <div class="grid gap-2">
                    <Label for="cs-reply">{{
                        t('pf.settings.collections.reply_to')
                    }}</Label>
                    <Input
                        id="cs-reply"
                        v-model="form.reply_to_email"
                        type="email"
                        maxlength="255"
                    />
                    <InputError :message="form.errors.reply_to_email" />
                </div>
                <div class="grid gap-2">
                    <Label for="cs-privacy">{{
                        t('pf.settings.collections.privacy_contact')
                    }}</Label>
                    <Input
                        id="cs-privacy"
                        v-model="form.privacy_contact_email"
                        type="email"
                        maxlength="255"
                    />
                    <p class="text-xs text-muted-foreground">
                        {{ t('pf.settings.collections.privacy_hint') }}
                    </p>
                    <InputError :message="form.errors.privacy_contact_email" />
                </div>
            </div>
        </SectionCard>

        <SectionCard
            :icon="Gauge"
            :title="t('pf.late_fee.title')"
            :description="t('pf.client_form.late_fee_hint')"
        >
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-4">
                <div class="grid gap-2 sm:col-span-2">
                    <Label for="cs-fee-type">{{ t('pf.late_fee.type') }}</Label>
                    <Select v-model="form.late_fee.type">
                        <SelectTrigger id="cs-fee-type" class="w-full"
                            ><SelectValue
                        /></SelectTrigger>
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
                </div>
                <div
                    v-if="form.late_fee.type.startsWith('percent')"
                    class="grid gap-2"
                >
                    <Label for="cs-fee-rate">{{ t('pf.late_fee.rate') }}</Label>
                    <Input
                        id="cs-fee-rate"
                        v-model="form.late_fee.rate"
                        inputmode="decimal"
                    />
                    <InputError :message="err('late_fee.rate')" />
                </div>
                <div v-if="form.late_fee.type === 'fixed'" class="grid gap-2">
                    <Label for="cs-fee-fixed">{{
                        t('pf.late_fee.fixed', {
                            currency:
                                fee.fixed?.currency ??
                                $page.props.workspace?.currency ??
                                '',
                        })
                    }}</Label>
                    <Input
                        id="cs-fee-fixed"
                        v-model="form.late_fee.fixed"
                        inputmode="decimal"
                    />
                    <InputError :message="err('late_fee.fixed')" />
                </div>
                <div v-if="form.late_fee.type !== 'none'" class="grid gap-2">
                    <Label for="cs-fee-grace">{{
                        t('pf.late_fee.grace_days')
                    }}</Label>
                    <Input
                        id="cs-fee-grace"
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
                    <Label for="cs-fee-cap">{{ t('pf.late_fee.cap') }}</Label>
                    <Input
                        id="cs-fee-cap"
                        v-model="form.late_fee.cap"
                        inputmode="decimal"
                    />
                    <InputError :message="err('late_fee.cap')" />
                </div>
            </div>
        </SectionCard>

        <SectionCard
            :icon="Building2"
            :title="t('pf.settings.collections.company')"
            :description="t('pf.settings.collections.company_hint')"
        >
            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="cs-legal">{{
                        t('pf.client.legal_name')
                    }}</Label>
                    <Input
                        id="cs-legal"
                        v-model="form.company.legal_name"
                        maxlength="200"
                    />
                    <InputError :message="err('company.legal_name')" />
                </div>
                <div class="grid gap-2">
                    <Label for="cs-tax">{{ t('pf.client.tax_id') }}</Label>
                    <Input
                        id="cs-tax"
                        v-model="form.company.tax_id"
                        maxlength="64"
                    />
                </div>
                <div class="grid gap-2">
                    <Label for="cs-cemail">{{ t('pf.client.email') }}</Label>
                    <Input
                        id="cs-cemail"
                        v-model="form.company.email"
                        type="email"
                        maxlength="255"
                    />
                    <InputError :message="err('company.email')" />
                </div>
                <div class="grid gap-2">
                    <Label for="cs-phone">{{ t('pf.client.phone') }}</Label>
                    <Input
                        id="cs-phone"
                        v-model="form.company.phone"
                        type="tel"
                        maxlength="40"
                    />
                </div>
                <div class="grid gap-2 sm:col-span-2">
                    <Label for="cs-address">{{ t('pf.client.address') }}</Label>
                    <Textarea
                        id="cs-address"
                        v-model="form.company.address"
                        rows="2"
                        maxlength="1000"
                    />
                </div>
            </div>
        </SectionCard>

        <SectionCard :icon="Mail" :title="t('pf.settings.collections.digest')">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div class="flex items-center gap-3">
                    <Switch id="cs-digest" v-model="form.digest_enabled" />
                    <Label for="cs-digest">{{
                        t('pf.settings.collections.digest_enabled')
                    }}</Label>
                </div>
                <div v-if="form.digest_enabled" class="flex items-center gap-2">
                    <Label
                        for="cs-digest-hour"
                        class="text-sm text-muted-foreground"
                        >{{ t('pf.settings.collections.digest_hour') }}</Label
                    >
                    <Select v-model="form.digest_hour">
                        <SelectTrigger id="cs-digest-hour" class="w-24"
                            ><SelectValue
                        /></SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="hour in hours"
                                :key="hour"
                                :value="hour"
                                >{{ hour.padStart(2, '0') }}:00</SelectItem
                            >
                        </SelectContent>
                    </Select>
                </div>
            </div>
        </SectionCard>

        <div class="flex justify-end">
            <Button
                type="submit"
                :disabled="form.processing"
                data-test="collections-save"
            >
                <Spinner v-if="form.processing" class="size-4" />
                <Save v-else class="size-4" aria-hidden="true" />
                {{ t('pf.actions.save_changes') }}
            </Button>
        </div>
    </form>
</template>
