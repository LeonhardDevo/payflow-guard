<script setup lang="ts">
import { Head, Link, router, useForm, useHttp } from '@inertiajs/vue3';
import {
    Archive,
    BellRing,
    CirclePause,
    CirclePlay,
    Eye,
    EyeOff,
    HandCoins,
    History,
    Landmark,
    Mail,
    Pencil,
    Phone,
    Plus,
    RefreshCcw,
    ShieldAlert,
    Store,
    UserX,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import ConfirmDialog from '@/components/common/ConfirmDialog.vue';
import EmptyState from '@/components/common/EmptyState.vue';
import PageHeader from '@/components/common/PageHeader.vue';
import SectionCard from '@/components/common/SectionCard.vue';
import StatusBadge from '@/components/common/StatusBadge.vue';
import InputError from '@/components/InputError.vue';
import AccountHistory from '@/components/pf/guard/AccountHistory.vue';
import BankAccountCard from '@/components/pf/guard/BankAccountCard.vue';
import GuardBand from '@/components/pf/guard/GuardBand.vue';
import GuardErrorDialog from '@/components/pf/guard/GuardErrorDialog.vue';
import PayoutRow from '@/components/pf/guard/PayoutRow.vue';
import StepUpDialog from '@/components/pf/guard/StepUpDialog.vue';
import ReasonDialog from '@/components/pf/invoice/ReasonDialog.vue';
import RiskBadge from '@/components/pf/RiskBadge.vue';
import { Button } from '@/components/ui/button';
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
import { Spinner } from '@/components/ui/spinner';
import { useFormat } from '@/composables/useFormat';
import { useCountdown } from '@/composables/pf/useCountdown';
import { useStepUp } from '@/composables/pf/useStepUp';
import { describeHttpError } from '@/lib/http-errors';
import { t } from '@/lib/i18n';
import { groupInFours } from '@/lib/pf/guard';
import { SEVERITY_TONE, VENDOR_STATUS_TONE } from '@/lib/pf/status';
import { show as reviewChange } from '@/routes/guard/bank-changes';
import { create as createPayout } from '@/routes/payouts';
import {
    anonymise,
    destroy,
    edit,
    hold,
    index,
    releaseHold,
} from '@/routes/vendors';
import { reveal } from '@/routes/vendors/bank-accounts';
import { create as requestChange } from '@/routes/vendors/bank-changes';
import type {
    RevealedBankDetails,
    VendorShowProps,
} from '@/types/payflow-guard';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'pf.nav.vendors', href: index() }],
    },
});

const props = defineProps<VendorShowProps>();
const fmt = useFormat();
const stepUp = useStepUp();

const v = computed(() => props.vendor);
const current = computed(
    () =>
        props.accounts.find((a) => a.id === v.value.current_account?.id) ??
        null,
);
const pending = computed(() =>
    props.accounts.filter((a) => a.status === 'pending_verification'),
);

const holdOpen = ref(false);
const archiveOpen = ref(false);
const anonymiseOpen = ref(false);
const busy = ref<string | null>(null);

function releaseVendorHold(): void {
    busy.value = 'release';
    router.post(
        releaseHold(v.value.id).url,
        {},
        { preserveScroll: true, onFinish: () => (busy.value = null) },
    );
}

function archiveVendor(): void {
    busy.value = 'archive';
    router.delete(destroy(v.value.id).url, {
        onFinish: () => {
            busy.value = null;
            archiveOpen.value = false;
        },
    });
}

const anonymiseForm = useForm({ confirm_name: '' });

function submitAnonymise(): void {
    stepUp.run(() => {
        anonymiseForm.post(anonymise(v.value.id).url, {
            preserveScroll: true,
            onSuccess: () => (anonymiseOpen.value = false),
        });
    }, true);
}

const revealHttp = useHttp<Record<string, never>, RevealedBankDetails>({});
const revealed = ref<RevealedBankDetails | null>(null);
const revealError = ref<string | null>(null);
const revealUntil = ref<string | null>(null);
const revealCountdown = useCountdown(revealUntil);
const remaining = computed(() => revealCountdown.remaining.value?.total ?? 0);

watch(
    () => revealCountdown.remaining.value,
    (value) => {
        if (value === null && revealed.value) {
            hideDetails();
        }
    },
);

function hideDetails(): void {
    revealed.value = null;
    revealUntil.value = null;
}

async function doReveal(accountId: number): Promise<void> {
    revealError.value = null;

    try {
        const data = await revealHttp.post(
            reveal({ vendor: v.value.id, account: accountId }).url,
        );

        if (data) {
            revealUntil.value = new Date(
                Date.now() + data.expires_in * 1000,
            ).toISOString();
            revealed.value = data;
        }
    } catch (error) {
        const info = describeHttpError(error);

        if (info.status === 423) {
            stepUp.open.value = true;

            return;
        }

        revealError.value = info.message ?? t('guard.vendor.reveal_failed');
    }
}

function revealAccount(accountId: number): void {
    stepUp.run(() => void doReveal(accountId), true);
}
</script>

<template>
    <Head :title="v.name" />

    <div
        class="mx-auto flex w-full max-w-7xl flex-col gap-6 px-4 py-6 sm:px-6 lg:py-8"
    >
        <GuardBand />
        <PageHeader :icon="Store" :title="v.name" :description="v.legal_name">
            <template #meta>
                <div class="flex flex-wrap items-center gap-2">
                    <StatusBadge
                        :tone="VENDOR_STATUS_TONE[v.status]"
                        :label="t(`guard.vendor_status.${v.status}`)"
                        data-test="vendor-status"
                    />
                    <span class="text-sm text-muted-foreground">{{
                        t(`guard.vendor_category.${v.category}`)
                    }}</span>
                    <StatusBadge
                        v-if="v.anonymised_at"
                        tone="neutral"
                        :icon="UserX"
                        :dot="false"
                        :label="t('guard.fields.anonymised')"
                    />
                </div>
            </template>
            <template #actions>
                <Button v-if="can.request_change" variant="outline" as-child>
                    <Link
                        :href="requestChange(v.id).url"
                        data-test="vendor-request-change"
                    >
                        <RefreshCcw class="size-4" aria-hidden="true" />
                        {{ t('guard.vendor.request_change') }}
                    </Link>
                </Button>
                <Button v-if="can.edit" variant="outline" as-child>
                    <Link :href="edit(v.id).url"
                        ><Pencil class="size-4" aria-hidden="true" />{{
                            t('pf.actions.edit')
                        }}</Link
                    >
                </Button>
                <Button
                    v-if="can.create_payout && v.status === 'active'"
                    as-child
                >
                    <Link
                        :href="createPayout({ query: { vendor_id: v.id } }).url"
                        data-test="vendor-new-payout"
                    >
                        <Plus class="size-4" aria-hidden="true" />
                        {{ t('guard.payouts.create') }}
                    </Link>
                </Button>
            </template>
        </PageHeader>

        <div
            v-if="v.status === 'on_hold'"
            class="flex flex-col gap-3 rounded-2xl border border-warning/40 bg-warning-soft p-4 text-sm text-warning-soft-foreground sm:flex-row sm:items-center sm:justify-between"
        >
            <div class="flex items-start gap-3">
                <CirclePause
                    class="mt-0.5 size-5 shrink-0"
                    aria-hidden="true"
                />
                <div>
                    <p class="font-medium">
                        {{ t('guard.vendor.on_hold_title') }}
                    </p>
                    <p v-if="v.hold_reason">{{ v.hold_reason }}</p>
                </div>
            </div>
            <Button
                v-if="can.hold"
                size="sm"
                variant="outline"
                :disabled="busy === 'release'"
                @click="releaseVendorHold"
            >
                <Spinner v-if="busy === 'release'" class="size-4" />
                <CirclePlay v-else class="size-4" aria-hidden="true" />
                {{ t('guard.vendor.release_hold') }}
            </Button>
        </div>

        <div
            v-for="change in pending"
            :key="change.id"
            class="flex flex-col gap-3 rounded-2xl border border-warning/40 bg-warning-soft p-4 text-sm text-warning-soft-foreground sm:flex-row sm:items-center sm:justify-between"
            data-test="vendor-pending-change"
        >
            <div class="flex items-start gap-3">
                <ShieldAlert
                    class="mt-0.5 size-5 shrink-0"
                    aria-hidden="true"
                />
                <div>
                    <p class="font-medium">
                        {{
                            t('guard.vendor.pending_change_title', {
                                date: fmt.dateTime(
                                    change.requested_at,
                                    'medium',
                                ),
                            })
                        }}
                    </p>
                    <p>{{ t('guard.vendor.pending_change_body') }}</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <RiskBadge
                    :band="change.risk_band"
                    :score="change.risk_score"
                />
                <Button size="sm" as-child>
                    <Link :href="reviewChange(change.id).url">{{
                        t('guard.vendor.review_change')
                    }}</Link>
                </Button>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <div class="flex min-w-0 flex-col gap-6 lg:col-span-2">
                <SectionCard
                    :icon="Landmark"
                    :title="t('guard.vendor.current_account')"
                >
                    <template v-if="current">
                        <BankAccountCard :account="current" />
                        <div v-if="can.reveal" class="mt-3">
                            <div
                                v-if="revealed"
                                class="rounded-xl border border-ai/30 bg-ai-soft p-3 text-sm text-ai-soft-foreground"
                                data-test="revealed-details"
                                aria-live="polite"
                            >
                                <div
                                    class="mb-2 flex items-center justify-between gap-2"
                                >
                                    <span class="text-xs">{{
                                        t('guard.vendor.revealed_for', {
                                            seconds: remaining,
                                        })
                                    }}</span>
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        @click="hideDetails"
                                    >
                                        <EyeOff
                                            class="size-4"
                                            aria-hidden="true"
                                        />
                                        {{ t('guard.vendor.hide') }}
                                    </Button>
                                </div>
                                <dl class="grid gap-1 font-mono text-sm">
                                    <div v-if="revealed.iban">
                                        <dt class="sr-only">IBAN</dt>
                                        <dd>
                                            {{ groupInFours(revealed.iban) }}
                                        </dd>
                                    </div>
                                    <div v-if="revealed.account_number">
                                        <dt class="inline text-xs">
                                            {{
                                                t('guard.bank.account_number')
                                            }}:
                                        </dt>
                                        <dd class="inline">
                                            {{
                                                groupInFours(
                                                    revealed.account_number,
                                                )
                                            }}
                                        </dd>
                                    </div>
                                    <div v-if="revealed.routing_number">
                                        <dt class="inline text-xs">
                                            {{
                                                t('guard.bank.routing_number')
                                            }}:
                                        </dt>
                                        <dd class="inline">
                                            {{ revealed.routing_number }}
                                        </dd>
                                    </div>
                                    <div v-if="revealed.swift_bic">
                                        <dt class="inline text-xs">SWIFT:</dt>
                                        <dd class="inline">
                                            {{ revealed.swift_bic }}
                                        </dd>
                                    </div>
                                </dl>
                            </div>
                            <Button
                                v-else
                                size="sm"
                                variant="outline"
                                :disabled="revealHttp.processing"
                                data-test="vendor-reveal"
                                @click="revealAccount(current.id)"
                            >
                                <Spinner
                                    v-if="revealHttp.processing"
                                    class="size-4"
                                />
                                <Eye v-else class="size-4" aria-hidden="true" />
                                {{ t('guard.vendor.reveal') }}
                            </Button>
                            <p class="mt-1 text-xs text-muted-foreground">
                                {{ t('guard.vendor.reveal_hint') }}
                            </p>
                            <InputError :message="revealError" />
                        </div>
                    </template>
                    <EmptyState
                        v-else
                        compact
                        :icon="Landmark"
                        :title="t('guard.vendor.no_account')"
                        :description="t('guard.vendor.no_account_hint')"
                    >
                        <Button v-if="can.request_change" size="sm" as-child>
                            <Link :href="requestChange(v.id).url">{{
                                t('guard.vendor.add_account')
                            }}</Link>
                        </Button>
                    </EmptyState>
                </SectionCard>

                <SectionCard
                    :icon="HandCoins"
                    :title="t('guard.vendor.payouts')"
                    flush
                >
                    <EmptyState
                        v-if="!payouts.length"
                        compact
                        :icon="HandCoins"
                        tone="muted"
                        :title="t('guard.vendor.no_payouts')"
                    />
                    <ul v-else class="divide-y">
                        <PayoutRow
                            v-for="payout in payouts"
                            :key="payout.id"
                            :payout="payout"
                            hide-vendor
                        />
                    </ul>
                </SectionCard>

                <SectionCard
                    v-if="accounts.length"
                    :icon="History"
                    :title="t('guard.vendor.account_history')"
                >
                    <AccountHistory
                        :accounts="accounts"
                        :current-id="current?.id ?? null"
                    />
                </SectionCard>
            </div>

            <div class="flex flex-col gap-6">
                <SectionCard :icon="Mail" :title="t('guard.vendor.contact')">
                    <dl class="space-y-3 text-sm">
                        <div class="flex items-start gap-2">
                            <Mail
                                class="mt-0.5 size-4 text-muted-foreground"
                                aria-hidden="true"
                            />
                            <dd class="break-all">{{ v.email ?? '—' }}</dd>
                        </div>
                        <div class="flex items-start gap-2">
                            <Phone
                                class="mt-0.5 size-4 text-muted-foreground"
                                aria-hidden="true"
                            />
                            <dd>
                                {{ v.phone ?? '—' }}
                                <span
                                    v-if="v.phone"
                                    class="block text-xs text-muted-foreground"
                                >
                                    {{
                                        v.phone_verified_at
                                            ? t('guard.vendor.phone_verified', {
                                                  date: fmt.date(
                                                      v.phone_verified_at,
                                                  ),
                                              })
                                            : t('guard.vendor.phone_unverified')
                                    }}
                                </span>
                            </dd>
                        </div>
                    </dl>
                    <dl
                        class="mt-4 grid grid-cols-2 gap-3 border-t pt-4 text-sm"
                    >
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                {{ t('guard.fields.country') }}
                            </dt>
                            <dd>{{ v.country ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                {{ t('guard.vendor.default_currency') }}
                            </dt>
                            <dd>{{ v.default_currency }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                {{ t('guard.fields.tax_id') }}
                            </dt>
                            <dd class="font-mono text-xs">
                                {{ v.tax_id_masked ?? '—' }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                {{ t('guard.vendor.added') }}
                            </dt>
                            <dd>{{ fmt.date(v.created_at) }}</dd>
                        </div>
                    </dl>
                    <p
                        v-if="v.notes"
                        class="mt-4 border-t pt-4 text-sm whitespace-pre-line text-muted-foreground"
                    >
                        {{ v.notes }}
                    </p>
                </SectionCard>

                <SectionCard :icon="BellRing" :title="t('guard.vendor.alerts')">
                    <p
                        v-if="!alerts.length"
                        class="text-sm text-muted-foreground"
                    >
                        {{ t('guard.vendor.no_alerts') }}
                    </p>
                    <ul v-else class="space-y-2">
                        <li
                            v-for="alert in alerts"
                            :key="alert.id"
                            class="flex items-start justify-between gap-2 text-sm"
                        >
                            <span
                                >{{ t(`guard.alerts.${alert.type}`)
                                }}<span
                                    class="block text-xs text-muted-foreground"
                                    >{{
                                        fmt.dateTime(alert.created_at, 'short')
                                    }}</span
                                ></span
                            >
                            <StatusBadge
                                :tone="SEVERITY_TONE[alert.severity]"
                                :label="t(`guard.severity.${alert.severity}`)"
                            />
                        </li>
                    </ul>
                </SectionCard>

                <SectionCard
                    v-if="can.hold || can.edit || can.anonymise"
                    tone="danger"
                    :title="t('pf.common.danger_zone')"
                >
                    <div class="flex flex-col gap-2">
                        <Button
                            v-if="can.hold && v.status === 'active'"
                            variant="outline"
                            data-test="vendor-hold"
                            @click="holdOpen = true"
                        >
                            <CirclePause class="size-4" aria-hidden="true" />{{
                                t('guard.vendor.hold')
                            }}
                        </Button>
                        <Button
                            v-if="can.anonymise && !v.anonymised_at"
                            variant="outline"
                            @click="anonymiseOpen = true"
                        >
                            <UserX class="size-4" aria-hidden="true" />{{
                                t('guard.fields.anonymise')
                            }}
                        </Button>
                        <Button
                            v-if="can.edit && v.status !== 'archived'"
                            variant="destructive"
                            @click="archiveOpen = true"
                        >
                            <Archive class="size-4" aria-hidden="true" />{{
                                t('guard.vendor.archive')
                            }}
                        </Button>
                    </div>
                </SectionCard>
            </div>
        </div>
    </div>

    <ReasonDialog
        v-model:open="holdOpen"
        :url="hold(v.id).url"
        :title="t('guard.vendor.hold_title')"
        :description="t('guard.vendor.hold_body')"
        :label="t('pf.common.reason')"
        :confirm-label="t('guard.vendor.hold')"
        :max="255"
    />
    <ConfirmDialog
        v-model:open="archiveOpen"
        destructive
        :title="t('guard.vendor.archive_title', { name: v.name })"
        :description="t('guard.vendor.archive_body')"
        :confirm-label="t('guard.vendor.archive')"
        :processing="busy === 'archive'"
        @confirm="archiveVendor"
    />
    <Dialog v-model:open="anonymiseOpen">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>{{
                    t('guard.vendor.anonymise_title')
                }}</DialogTitle>
                <DialogDescription>{{
                    t('guard.vendor.anonymise_body')
                }}</DialogDescription>
            </DialogHeader>
            <form class="grid gap-4" @submit.prevent="submitAnonymise">
                <div class="grid gap-2">
                    <Label for="v-anon">{{
                        t('guard.fields.anonymise_confirm', { name: v.name })
                    }}</Label>
                    <Input
                        id="v-anon"
                        v-model="anonymiseForm.confirm_name"
                        autocomplete="off"
                    />
                    <InputError :message="anonymiseForm.errors.confirm_name" />
                </div>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="ghost"
                        @click="anonymiseOpen = false"
                        >{{ t('ui.common.cancel') }}</Button
                    >
                    <Button
                        type="submit"
                        variant="destructive"
                        :disabled="
                            anonymiseForm.processing ||
                            anonymiseForm.confirm_name !== v.name
                        "
                    >
                        <Spinner
                            v-if="anonymiseForm.processing"
                            class="size-4"
                        />
                        {{ t('guard.fields.anonymise') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <StepUpDialog />
    <GuardErrorDialog />
</template>
