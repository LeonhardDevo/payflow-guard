<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    Plus,
    Save,
    ShieldAlert,
    ShieldOff,
    Trash2,
    UserRound,
    UsersRound,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import ConfirmDialog from '@/components/common/ConfirmDialog.vue';
import SectionCard from '@/components/common/SectionCard.vue';
import StatusBadge from '@/components/common/StatusBadge.vue';
import InputError from '@/components/InputError.vue';
import GuardBand from '@/components/pf/guard/GuardBand.vue';
import GuardErrorDialog from '@/components/pf/guard/GuardErrorDialog.vue';
import GuardRulesCard from '@/components/pf/guard/GuardRulesCard.vue';
import StepUpDialog from '@/components/pf/guard/StepUpDialog.vue';
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
import { useStepUp } from '@/composables/pf/useStepUp';
import { useFormat } from '@/composables/useFormat';
import { t } from '@/lib/i18n';
import { minorToDecimal } from '@/lib/pf/money';
import { guard, workspace } from '@/routes/settings';
import { update } from '@/routes/settings/guard';
import {
    destroy as removeContact,
    store as storeContact,
} from '@/routes/settings/guard/trusted-contact';
import type {
    CurrencyCode,
    EligibleApprover,
    GuardSettings,
    Locale,
    TrustedContact,
} from '@/types/payflow-guard';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'ui.settings.title', href: workspace() },
            { title: 'pf.settings.nav.guard', href: guard() },
        ],
    },
});

const props = defineProps<{
    settings: GuardSettings;
    eligible_approvers: EligibleApprover[];
    trusted_contact: TrustedContact | null;
    currencies: CurrencyCode[];
    can_edit: boolean;
}>();

const fmt = useFormat();
const stepUp = useStepUp();

let seq = 0;
const s = props.settings;
const form = useForm({
    thresholds: s.thresholds.map((th) => ({
        key: ++seq,
        currency: th.currency,
        auto_approve_below: minorToDecimal(
            th.auto_approve_below.minor,
            th.currency,
        ),
        dual_approval_from: minorToDecimal(
            th.dual_approval_from.minor,
            th.currency,
        ),
        auto_approve_cumulative: th.auto_approve_cumulative
            ? minorToDecimal(th.auto_approve_cumulative.minor, th.currency)
            : '',
    })),
    cooling_off_hours_medium: String(s.cooling_off_hours_medium),
    cooling_off_hours_high: String(s.cooling_off_hours_high),
    allow_telegram_approvals: s.allow_telegram_approvals,
    escalate_pending_after_hours: String(s.escalate_pending_after_hours),
    guard_trust_delay_hours: String(s.guard_trust_delay_hours),
    require_second_factor: s.require_second_factor,
    solo_release_delay_hours: String(s.solo_release_delay_hours),
});

function addThreshold(): void {
    const used = new Set(form.thresholds.map((th) => th.currency));
    const currency = props.currencies.find((c) => !used.has(c));

    if (currency) {
        form.thresholds.push({
            key: ++seq,
            currency,
            auto_approve_below: '0',
            dual_approval_from: '5000',
            auto_approve_cumulative: '',
        });
    }
}

function save(): void {
    stepUp.run(() => {
        form.transform((data) => ({
            ...data,
            thresholds: data.thresholds.map(({ key: _key, ...th }) => ({
                ...th,
                auto_approve_cumulative: th.auto_approve_cumulative || null,
            })),
            cooling_off_hours_medium: Number(data.cooling_off_hours_medium),
            cooling_off_hours_high: Number(data.cooling_off_hours_high),
            escalate_pending_after_hours: Number(
                data.escalate_pending_after_hours,
            ),
            guard_trust_delay_hours: Number(data.guard_trust_delay_hours),
            solo_release_delay_hours: Number(data.solo_release_delay_hours),
        })).put(update().url, { preserveScroll: true });
    }, true);
}

const err = (key: string) =>
    (form.errors as Record<string, string | undefined>)[key];

const hourFields = [
    'cooling_off_hours_medium',
    'cooling_off_hours_high',
    'escalate_pending_after_hours',
    'guard_trust_delay_hours',
    'solo_release_delay_hours',
] as const;

const secondFactorOffOpen = ref(false);
const requireSecondFactor = computed({
    get: () => form.require_second_factor,
    set: (value: boolean) => {
        if (!value && form.require_second_factor) {
            secondFactorOffOpen.value = true;

            return;
        }

        form.require_second_factor = value;
    },
});

function confirmSecondFactorOff(): void {
    form.require_second_factor = false;
    secondFactorOffOpen.value = false;
}

const rules = computed(() => {
    const now = Date.now();

    return {
        ...props.settings,
        eligible_approvers: props.eligible_approvers.filter(
            (a) => a.eligible && !a.missing_second_factor,
        ).length,
        pending_eligibility: props.eligible_approvers.flatMap((a) =>
            a.eligible_from && Date.parse(a.eligible_from) > now
                ? [{ id: a.id, name: a.name, eligible_from: a.eligible_from }]
                : [],
        ),
        trusted_contact: props.trusted_contact,
        solo_mode: false,
    };
});

const contactForm = useForm({ name: '', email: '', locale: 'en' as Locale });
const contactEditing = ref(false);

function saveContact(): void {
    stepUp.run(() => {
        contactForm.post(storeContact().url, {
            preserveScroll: true,
            onSuccess: () => {
                contactEditing.value = false;
                contactForm.reset();
            },
        });
    }, true);
}

const removeOpen = ref(false);
const removing = ref(false);

function confirmRemove(): void {
    stepUp.run(() => {
        removing.value = true;
        router.delete(removeContact().url, {
            preserveScroll: true,
            onFinish: () => {
                removing.value = false;
                removeOpen.value = false;
            },
        });
    }, true);
}
</script>

<template>
    <Head :title="t('pf.settings.nav.guard')" />

    <div class="flex flex-col gap-6">
        <GuardBand />
        <div
            v-if="!settings.require_second_factor"
            class="flex items-start gap-3 rounded-2xl border border-destructive/40 bg-destructive-soft p-4 text-sm text-destructive-soft-foreground"
            role="status"
            data-test="second-factor-off-banner"
        >
            <ShieldOff class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
            <p>{{ t('guard.settings.second_factor_off_banner') }}</p>
        </div>
        <SectionCard
            :icon="ShieldAlert"
            :title="t('guard.settings.title')"
            :description="t('guard.settings.description')"
        >
            <form
                class="flex flex-col gap-6"
                novalidate
                data-test="guard-settings-form"
                @submit.prevent="save"
            >
                <fieldset
                    :disabled="!can_edit || form.processing"
                    class="flex flex-col gap-6"
                >
                    <div>
                        <div
                            class="mb-2 flex items-center justify-between gap-2"
                        >
                            <p class="text-sm font-medium">
                                {{ t('guard.settings.thresholds') }}
                            </p>
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                :disabled="
                                    form.thresholds.length >= currencies.length
                                "
                                @click="addThreshold"
                            >
                                <Plus class="size-4" aria-hidden="true" />{{
                                    t('guard.settings.add_currency')
                                }}
                            </Button>
                        </div>
                        <div class="flex flex-col gap-3">
                            <div
                                v-for="(th, i) in form.thresholds"
                                :key="th.key"
                                class="grid grid-cols-2 gap-3 rounded-xl border p-3 sm:grid-cols-[6rem_1fr_1fr_1fr_auto] sm:items-end"
                            >
                                <div class="grid gap-1">
                                    <Label
                                        :for="`th-cur-${th.key}`"
                                        class="text-xs text-muted-foreground"
                                        >{{ t('guard.fields.currency') }}</Label
                                    >
                                    <Select v-model="th.currency">
                                        <SelectTrigger
                                            :id="`th-cur-${th.key}`"
                                            class="w-full"
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
                                <div class="grid gap-1">
                                    <Label
                                        :for="`th-auto-${th.key}`"
                                        class="text-xs text-muted-foreground"
                                        >{{
                                            t('guard.settings.auto_below')
                                        }}</Label
                                    >
                                    <Input
                                        :id="`th-auto-${th.key}`"
                                        v-model="th.auto_approve_below"
                                        inputmode="decimal"
                                        class="text-right tabular-nums"
                                    />
                                    <InputError
                                        :message="
                                            err(
                                                `thresholds.${i}.auto_approve_below`,
                                            )
                                        "
                                    />
                                </div>
                                <div class="grid gap-1">
                                    <Label
                                        :for="`th-dual-${th.key}`"
                                        class="text-xs text-muted-foreground"
                                        >{{
                                            t('guard.settings.dual_from')
                                        }}</Label
                                    >
                                    <Input
                                        :id="`th-dual-${th.key}`"
                                        v-model="th.dual_approval_from"
                                        inputmode="decimal"
                                        class="text-right tabular-nums"
                                    />
                                    <InputError
                                        :message="
                                            err(
                                                `thresholds.${i}.dual_approval_from`,
                                            )
                                        "
                                    />
                                </div>
                                <div class="grid gap-1">
                                    <Label
                                        :for="`th-cum-${th.key}`"
                                        class="text-xs text-muted-foreground"
                                        >{{
                                            t('guard.settings.cumulative')
                                        }}</Label
                                    >
                                    <Input
                                        :id="`th-cum-${th.key}`"
                                        v-model="th.auto_approve_cumulative"
                                        inputmode="decimal"
                                        class="text-right tabular-nums"
                                        :placeholder="
                                            t('guard.settings.no_limit')
                                        "
                                    />
                                    <InputError
                                        :message="
                                            err(
                                                `thresholds.${i}.auto_approve_cumulative`,
                                            )
                                        "
                                    />
                                </div>
                                <Button
                                    type="button"
                                    size="icon"
                                    variant="ghost"
                                    class="size-9 text-destructive"
                                    :aria-label="t('ui.common.delete')"
                                    @click="form.thresholds.splice(i, 1)"
                                >
                                    <Trash2 class="size-4" aria-hidden="true" />
                                </Button>
                            </div>
                        </div>
                        <InputError :message="form.errors.thresholds" />
                    </div>

                    <div
                        class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3"
                    >
                        <div
                            v-for="field in hourFields"
                            :key="field"
                            class="grid gap-2"
                        >
                            <Label :for="`gs-${field}`">{{
                                t(`guard.settings.fields.${field}`)
                            }}</Label>
                            <Input
                                :id="`gs-${field}`"
                                v-model="form[field]"
                                type="number"
                                min="0"
                                max="336"
                            />
                            <InputError :message="err(field)" />
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div
                            class="flex items-start justify-between gap-3 rounded-xl border p-3"
                        >
                            <div>
                                <Label for="gs-2fa" class="font-medium">{{
                                    t('guard.settings.require_2fa')
                                }}</Label>
                                <p class="text-xs text-muted-foreground">
                                    {{ t('guard.settings.require_2fa_hint') }}
                                </p>
                            </div>
                            <Switch
                                id="gs-2fa"
                                v-model="requireSecondFactor"
                                data-test="require-second-factor"
                            />
                        </div>
                        <div
                            class="flex items-start justify-between gap-3 rounded-xl border p-3"
                        >
                            <div>
                                <Label for="gs-tg" class="font-medium">{{
                                    t('guard.settings.telegram')
                                }}</Label>
                                <p class="text-xs text-muted-foreground">
                                    {{ t('guard.settings.telegram_hint') }}
                                </p>
                            </div>
                            <Switch
                                id="gs-tg"
                                v-model="form.allow_telegram_approvals"
                            />
                        </div>
                    </div>
                    <p class="text-xs text-muted-foreground">
                        {{ t('guard.settings.weaken_notice') }}
                    </p>
                </fieldset>
                <div v-if="can_edit" class="flex justify-end">
                    <Button
                        type="submit"
                        :disabled="form.processing || !form.isDirty"
                        data-test="guard-settings-save"
                    >
                        <Spinner v-if="form.processing" class="size-4" />
                        <Save v-else class="size-4" aria-hidden="true" />{{
                            t('pf.actions.save_changes')
                        }}
                    </Button>
                </div>
            </form>
        </SectionCard>

        <GuardRulesCard
            :rules="rules"
            :description="t('guard.settings.summary_hint')"
        />
        <SectionCard
            :icon="UsersRound"
            :title="t('guard.settings.approvers')"
            :description="t('guard.settings.approvers_hint')"
        >
            <p
                v-if="!eligible_approvers.length"
                class="text-sm text-muted-foreground"
            >
                {{ t('guard.settings.no_approvers') }}
            </p>
            <ul v-else class="divide-y text-sm">
                <li
                    v-for="approver in eligible_approvers"
                    :key="approver.id"
                    class="flex flex-wrap items-center justify-between gap-2 py-2"
                >
                    <span
                        >{{ approver.name }}
                        <span class="text-xs text-muted-foreground"
                            >· {{ t(`roles.${approver.role}`) }}</span
                        ></span
                    >
                    <span class="text-xs text-muted-foreground">
                        <template v-if="approver.missing_second_factor">{{
                            t('guard.payout.needs_2fa')
                        }}</template>
                        <template
                            v-else-if="
                                !approver.eligible && approver.eligible_from
                            "
                            >{{
                                t('guard.payout.eligible_from', {
                                    date: fmt.date(approver.eligible_from),
                                })
                            }}</template
                        >
                        <template v-else>{{
                            t('guard.payout.can_approve')
                        }}</template>
                    </span>
                </li>
            </ul>
        </SectionCard>

        <SectionCard
            :icon="UserRound"
            :title="t('guard.settings.trusted')"
            :description="t('guard.settings.trusted_hint')"
        >
            <div
                v-if="trusted_contact && !contactEditing"
                class="flex flex-wrap items-center justify-between gap-3"
                data-test="trusted-contact"
            >
                <div class="text-sm">
                    <p class="font-medium">{{ trusted_contact.name }}</p>
                    <p class="font-mono text-xs text-muted-foreground">
                        {{ trusted_contact.email_masked }} ·
                        {{ trusted_contact.locale.toUpperCase() }}
                    </p>
                    <p
                        v-if="trusted_contact.active_from"
                        class="text-xs text-muted-foreground"
                    >
                        {{
                            t('guard.settings.active_from', {
                                date: fmt.dateTime(
                                    trusted_contact.active_from,
                                    'medium',
                                ),
                            })
                        }}
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <StatusBadge
                        :tone="
                            trusted_contact.status === 'active'
                                ? 'success'
                                : 'warning'
                        "
                        :label="
                            t(
                                `guard.settings.contact_status.${trusted_contact.status}`,
                            )
                        "
                    />
                    <template v-if="can_edit">
                        <Button
                            size="sm"
                            variant="outline"
                            @click="contactEditing = true"
                            >{{ t('guard.settings.replace') }}</Button
                        >
                        <Button
                            size="sm"
                            variant="ghost"
                            class="text-destructive"
                            @click="removeOpen = true"
                            >{{ t('ui.common.delete') }}</Button
                        >
                    </template>
                </div>
            </div>
            <form
                v-else-if="can_edit"
                class="grid grid-cols-1 gap-4 sm:grid-cols-[1fr_1fr_8rem]"
                @submit.prevent="saveContact"
            >
                <div class="grid gap-2">
                    <Label for="tc-name">{{ t('guard.fields.name') }}</Label>
                    <Input
                        id="tc-name"
                        v-model="contactForm.name"
                        maxlength="120"
                    />
                    <InputError :message="contactForm.errors.name" />
                </div>
                <div class="grid gap-2">
                    <Label for="tc-email">{{ t('guard.fields.email') }}</Label>
                    <Input
                        id="tc-email"
                        v-model="contactForm.email"
                        type="email"
                    />
                    <InputError :message="contactForm.errors.email" />
                </div>
                <div class="grid gap-2">
                    <Label for="tc-locale">{{
                        t('guard.fields.locale')
                    }}</Label>
                    <Select v-model="contactForm.locale">
                        <SelectTrigger id="tc-locale" class="w-full"
                            ><SelectValue
                        /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="en">English</SelectItem>
                            <SelectItem value="ru">Русский</SelectItem>
                        </SelectContent>
                    </Select>
                </div>
                <p class="text-xs text-muted-foreground sm:col-span-3">
                    {{ t('guard.settings.trusted_rules') }}
                </p>
                <div class="flex gap-2 sm:col-span-3">
                    <Button
                        v-if="trusted_contact"
                        type="button"
                        variant="ghost"
                        @click="contactEditing = false"
                        >{{ t('ui.common.cancel') }}</Button
                    >
                    <Button
                        type="submit"
                        :disabled="contactForm.processing"
                        data-test="trusted-contact-save"
                    >
                        <Spinner
                            v-if="contactForm.processing"
                            class="size-4"
                        />{{ t('guard.settings.send_invite') }}
                    </Button>
                </div>
            </form>
            <p v-else class="text-sm text-muted-foreground">
                {{ t('guard.settings.no_contact') }}
            </p>
        </SectionCard>
    </div>

    <ConfirmDialog
        v-model:open="secondFactorOffOpen"
        destructive
        :title="t('guard.settings.second_factor_off_title')"
        :description="t('guard.settings.second_factor_off_body')"
        :confirm-label="t('guard.settings.second_factor_off_confirm')"
        @confirm="confirmSecondFactorOff"
    />
    <ConfirmDialog
        v-model:open="removeOpen"
        destructive
        :title="t('guard.settings.remove_title')"
        :description="t('guard.settings.remove_body')"
        :confirm-label="t('ui.common.delete')"
        :processing="removing"
        @confirm="confirmRemove"
    />
    <StepUpDialog />
    <GuardErrorDialog />
</template>
