<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { CalendarClock, Pencil, Plus, Star, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import ConfirmDialog from '@/components/common/ConfirmDialog.vue';
import SectionCard from '@/components/common/SectionCard.vue';
import StatusBadge from '@/components/common/StatusBadge.vue';
import InputError from '@/components/InputError.vue';
import ToneChip from '@/components/pf/ToneChip.vue';
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
import { Switch } from '@/components/ui/switch';
import { t } from '@/lib/i18n';
import { destroy, store, update } from '@/routes/settings/policies';
import type { ReminderPolicy, StepTone } from '@/types/payflow';

defineProps<{ policies: ReminderPolicy[] }>();

type StepForm = {
    key: number;
    offset_days: string;
    tone: StepTone;
    attach_pdf: boolean;
    include_payment_link: boolean;
    include_late_fee_notice: boolean;
    notify_owner: boolean;
};

let seq = 0;
const blankStep = (offset: number): StepForm => ({
    key: ++seq,
    offset_days: String(offset),
    tone: 'auto',
    attach_pdf: false,
    include_payment_link: true,
    include_late_fee_notice: false,
    notify_owner: false,
});

const editing = ref<ReminderPolicy | null>(null);
const open = ref(false);
const form = useForm({
    name: '',
    description: '',
    is_default: false,
    max_reminders: '6',
    min_interval_days: '3',
    escalate_after_days: '45',
    steps: [] as StepForm[],
});

const tones: StepTone[] = ['auto', 'friendly', 'neutral', 'firm', 'final'];
const flags = [
    'attach_pdf',
    'include_payment_link',
    'include_late_fee_notice',
    'notify_owner',
] as const;

function edit(policy: ReminderPolicy | null): void {
    editing.value = policy;
    form.clearErrors();
    form.name = policy?.name ?? '';
    form.description = policy?.description ?? '';
    form.is_default = policy?.is_default ?? false;
    form.max_reminders = String(policy?.max_reminders ?? 6);
    form.min_interval_days = String(policy?.min_interval_days ?? 3);
    form.escalate_after_days = String(policy?.escalate_after_days ?? 45);
    form.steps = policy
        ? policy.steps.map((step) => ({
              ...step,
              key: ++seq,
              offset_days: String(step.offset_days),
          }))
        : [blankStep(-3), blankStep(1), blankStep(7), blankStep(14)];
    open.value = true;
}

function addStep(): void {
    const last = form.steps.at(-1);
    form.steps.push(blankStep(last ? Number(last.offset_days) + 7 : 0));
}

function save(): void {
    const options = {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
    };

    form.transform((data) => ({
        ...data,
        description: data.description || null,
        max_reminders: Number(data.max_reminders),
        min_interval_days: Number(data.min_interval_days),
        escalate_after_days: Number(data.escalate_after_days),
        steps: data.steps
            .map(({ key: _key, ...step }) => ({
                ...step,
                offset_days: Number(step.offset_days),
            }))
            .sort((a, b) => a.offset_days - b.offset_days),
    }));

    if (editing.value) {
        form.put(update(editing.value.id).url, options);
    } else {
        form.post(store().url, options);
    }
}

const deleting = ref<ReminderPolicy | null>(null);
const deleteBusy = ref(false);

function confirmDelete(): void {
    if (!deleting.value) {
        return;
    }

    deleteBusy.value = true;
    router.delete(destroy(deleting.value.id).url, {
        preserveScroll: true,
        onFinish: () => {
            deleteBusy.value = false;
            deleting.value = null;
        },
    });
}

const err = (key: string) =>
    (form.errors as Record<string, string | undefined>)[key];
const offsetLabel = (offset: number) =>
    offset === 0
        ? t('pf.settings.policies.on_due')
        : offset < 0
          ? t('pf.settings.policies.before', { days: -offset })
          : t('pf.settings.policies.after', { days: offset });
</script>

<template>
    <SectionCard
        :icon="CalendarClock"
        :title="t('pf.settings.policies.title')"
        :description="t('pf.settings.policies.description')"
    >
        <template #actions>
            <Button size="sm" data-test="policy-create" @click="edit(null)">
                <Plus class="size-4" aria-hidden="true" />{{
                    t('pf.settings.policies.create')
                }}
            </Button>
        </template>
        <ul class="flex flex-col gap-3" data-test="policies">
            <li
                v-for="policy in policies"
                :key="policy.id"
                class="rounded-xl border p-4"
            >
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <p class="flex items-center gap-2 font-medium">
                            {{ policy.name }}
                            <StatusBadge
                                v-if="policy.is_default"
                                tone="success"
                                :icon="Star"
                                :dot="false"
                                :label="t('pf.settings.policies.default')"
                            />
                        </p>
                        <p
                            v-if="policy.description"
                            class="text-sm text-muted-foreground"
                        >
                            {{ policy.description }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{
                                t('pf.settings.policies.summary', {
                                    max: policy.max_reminders,
                                    interval: policy.min_interval_days,
                                    escalate: policy.escalate_after_days,
                                    clients: policy.clients_count,
                                })
                            }}
                        </p>
                    </div>
                    <div class="flex gap-1">
                        <Button
                            size="icon"
                            variant="ghost"
                            class="size-8"
                            :aria-label="t('pf.actions.edit')"
                            @click="edit(policy)"
                        >
                            <Pencil class="size-4" aria-hidden="true" />
                        </Button>
                        <Button
                            v-if="!policy.is_default"
                            size="icon"
                            variant="ghost"
                            class="size-8 text-destructive"
                            :aria-label="t('ui.common.delete')"
                            @click="deleting = policy"
                        >
                            <Trash2 class="size-4" aria-hidden="true" />
                        </Button>
                    </div>
                </div>
                <ol class="mt-3 flex flex-wrap gap-2">
                    <li
                        v-for="step in policy.steps"
                        :key="step.id ?? step.position"
                        class="flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs"
                    >
                        <span class="text-muted-foreground">{{
                            offsetLabel(step.offset_days)
                        }}</span>
                        <ToneChip
                            v-if="step.tone !== 'auto'"
                            :tone="step.tone"
                        />
                        <span v-else class="text-muted-foreground">{{
                            t('pf.settings.policies.tone_auto')
                        }}</span>
                    </li>
                </ol>
            </li>
        </ul>
    </SectionCard>

    <Dialog v-model:open="open">
        <DialogContent class="max-h-[90svh] overflow-y-auto sm:max-w-2xl">
            <DialogHeader>
                <DialogTitle>{{
                    editing
                        ? t('pf.settings.policies.edit')
                        : t('pf.settings.policies.create')
                }}</DialogTitle>
                <DialogDescription>{{
                    t('pf.settings.policies.editor_hint')
                }}</DialogDescription>
            </DialogHeader>
            <form
                class="grid gap-4"
                data-test="policy-form"
                @submit.prevent="save"
            >
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="pol-name">{{ t('pf.client.name') }}</Label>
                        <Input
                            id="pol-name"
                            v-model="form.name"
                            maxlength="120"
                        />
                        <InputError :message="form.errors.name" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="pol-desc">{{
                            t('pf.settings.policies.description_label')
                        }}</Label>
                        <Input
                            id="pol-desc"
                            v-model="form.description"
                            maxlength="500"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="pol-max">{{
                            t('pf.settings.policies.max_reminders')
                        }}</Label>
                        <Input
                            id="pol-max"
                            v-model="form.max_reminders"
                            type="number"
                            min="1"
                            max="20"
                        />
                        <InputError :message="form.errors.max_reminders" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="pol-interval">{{
                            t('pf.settings.policies.min_interval')
                        }}</Label>
                        <Input
                            id="pol-interval"
                            v-model="form.min_interval_days"
                            type="number"
                            min="1"
                            max="30"
                        />
                        <InputError :message="form.errors.min_interval_days" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="pol-escalate">{{
                            t('pf.settings.policies.escalate_after')
                        }}</Label>
                        <Input
                            id="pol-escalate"
                            v-model="form.escalate_after_days"
                            type="number"
                            min="1"
                            max="365"
                        />
                        <InputError
                            :message="form.errors.escalate_after_days"
                        />
                    </div>
                    <div class="flex items-center gap-3 self-end pb-2">
                        <Switch id="pol-default" v-model="form.is_default" />
                        <Label for="pol-default">{{
                            t('pf.settings.policies.make_default')
                        }}</Label>
                    </div>
                </div>

                <fieldset class="grid gap-2">
                    <legend class="mb-1 text-sm font-medium">
                        {{ t('pf.settings.policies.steps') }}
                    </legend>
                    <div
                        v-for="(step, i) in form.steps"
                        :key="step.key"
                        class="grid grid-cols-2 gap-3 rounded-xl border p-3 sm:grid-cols-[6rem_9rem_1fr_auto] sm:items-center"
                    >
                        <div class="grid gap-1">
                            <Label
                                :for="`step-offset-${step.key}`"
                                class="text-xs text-muted-foreground"
                                >{{ t('pf.settings.policies.offset') }}</Label
                            >
                            <Input
                                :id="`step-offset-${step.key}`"
                                v-model="step.offset_days"
                                type="number"
                                min="-30"
                                max="180"
                            />
                        </div>
                        <div class="grid gap-1">
                            <Label
                                :for="`step-tone-${step.key}`"
                                class="text-xs text-muted-foreground"
                                >{{ t('pf.reminder.tone') }}</Label
                            >
                            <Select v-model="step.tone">
                                <SelectTrigger
                                    :id="`step-tone-${step.key}`"
                                    class="w-full"
                                    ><SelectValue
                                /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="tone in tones"
                                        :key="tone"
                                        :value="tone"
                                        >{{
                                            tone === 'auto'
                                                ? t(
                                                      'pf.settings.policies.tone_auto',
                                                  )
                                                : t(`pf.tone.${tone}`)
                                        }}</SelectItem
                                    >
                                </SelectContent>
                            </Select>
                        </div>
                        <div
                            class="col-span-2 flex flex-wrap gap-x-4 gap-y-1 sm:col-span-1"
                        >
                            <Label
                                v-for="flag in flags"
                                :key="flag"
                                class="flex items-center gap-1.5 text-xs font-normal"
                            >
                                <Checkbox v-model="step[flag]" />
                                {{ t(`pf.settings.policies.flags.${flag}`) }}
                            </Label>
                        </div>
                        <Button
                            type="button"
                            size="icon"
                            variant="ghost"
                            class="size-8 text-destructive"
                            :disabled="form.steps.length === 1"
                            :aria-label="t('pf.invoice_form.remove_line')"
                            @click="form.steps.splice(i, 1)"
                        >
                            <Trash2 class="size-4" aria-hidden="true" />
                        </Button>
                        <InputError
                            class="col-span-full"
                            :message="
                                err(`steps.${i}.offset_days`) ??
                                err(`steps.${i}.tone`)
                            "
                        />
                    </div>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        class="justify-self-start"
                        :disabled="form.steps.length >= 20"
                        @click="addStep"
                    >
                        <Plus class="size-4" aria-hidden="true" />{{
                            t('pf.settings.policies.add_step')
                        }}
                    </Button>
                    <InputError :message="form.errors.steps" />
                </fieldset>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="ghost"
                        @click="open = false"
                        >{{ t('ui.common.cancel') }}</Button
                    >
                    <Button
                        type="submit"
                        :disabled="form.processing"
                        data-test="policy-save"
                    >
                        <Spinner v-if="form.processing" class="size-4" />
                        {{ t('pf.actions.save_changes') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <ConfirmDialog
        :open="deleting !== null"
        destructive
        :title="
            t('pf.settings.policies.delete_title', {
                name: deleting?.name ?? '',
            })
        "
        :description="t('pf.settings.policies.delete_body')"
        :confirm-label="t('ui.common.delete')"
        :processing="deleteBusy"
        @update:open="(value) => (value ? null : (deleting = null))"
        @confirm="confirmDelete"
    />
</template>
