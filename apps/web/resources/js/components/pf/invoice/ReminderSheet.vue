<script setup lang="ts">
import { useForm, useHttp } from '@inertiajs/vue3';
import { CircleAlert, Eye, PenLine, Sparkles } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import ToneChip from '@/components/pf/ToneChip.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { usePf } from '@/composables/pf/usePf';
import { describeHttpError } from '@/lib/http-errors';
import { t } from '@/lib/i18n';
import { TONES } from '@/lib/pf/status';
import { sanitizeAnswer } from '@/lib/sanitize';
import {
    aiDraft,
    preview as previewRoute,
    store,
} from '@/routes/invoices/reminders';
import type {
    AiDraftResponse,
    InvoiceDetail,
    ReminderPreview,
    Tone,
    ToneSuggestion,
} from '@/types/payflow';

const props = defineProps<{
    invoice: InvoiceDetail;
    suggestion: ToneSuggestion | null;
}>();
const open = defineModel<boolean>('open', { default: false });
const pf = usePf();

const tone = ref<Tone>(props.suggestion?.tone ?? 'friendly');
const preview = ref<ReminderPreview | null>(null);
const previewError = ref<string | null>(null);
const editing = ref(false);
const aiNote = ref<string | null>(null);

const previewHttp = useHttp<{ tone: Tone | null }, ReminderPreview>({
    tone: null,
});
const draftHttp = useHttp<{ tone: Tone }, AiDraftResponse>({
    tone: 'friendly',
});

const form = useForm({
    tone: tone.value,
    subject: '',
    body_markdown: '',
    force: false,
});

async function loadPreview(): Promise<void> {
    previewError.value = null;
    previewHttp.tone = tone.value;

    try {
        preview.value =
            (await previewHttp.post(previewRoute(props.invoice.id).url)) ??
            null;

        if (preview.value && !editing.value) {
            form.subject = preview.value.subject;
            form.body_markdown = preview.value.body_markdown;
        }
    } catch (error) {
        previewError.value =
            describeHttpError(error).message ?? t('pf.reminder.preview_failed');
    }
}

watch(open, (value) => {
    if (value) {
        tone.value = props.suggestion?.tone ?? 'friendly';
        editing.value = false;
        aiNote.value = null;
        form.reset();
        form.clearErrors();
        void loadPreview();
    }
});

watch(tone, () => {
    if (open.value) {
        editing.value = false;
        void loadPreview();
    }
});

async function draftWithAi(): Promise<void> {
    draftHttp.tone = tone.value;
    aiNote.value = null;

    try {
        const draft = await draftHttp.post(aiDraft(props.invoice.id).url);

        if (draft) {
            form.subject = draft.subject;
            form.body_markdown = draft.body_markdown;
            editing.value = true;
            aiNote.value = draft.fallback
                ? t('pf.reminder.ai_fallback')
                : t('pf.reminder.ai_done');
        }
    } catch (error) {
        aiNote.value =
            describeHttpError(error).message ?? t('pf.reminder.ai_failed');
    }
}

const blocking = computed(() =>
    (preview.value?.warnings ?? []).some((w) =>
        [
            'recent_reminder',
            'paused',
            'client_reported_paid',
            'first_contact_capped',
        ].includes(w),
    ),
);
const noRecipient = computed(() =>
    (preview.value?.warnings ?? []).some(
        (w) => w === 'no_recipient' || w === 'recipient_opted_out',
    ),
);

function submit(): void {
    form.transform((data) => ({
        tone: tone.value,
        subject: editing.value ? data.subject || null : null,
        body_markdown: editing.value ? data.body_markdown || null : null,
        force: data.force,
    })).post(store(props.invoice.id).url, {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
    });
}
</script>

<template>
    <Sheet v-model:open="open">
        <SheetContent
            side="right"
            class="flex w-full flex-col gap-0 overflow-y-auto sm:max-w-xl"
        >
            <SheetHeader>
                <SheetTitle>{{
                    t('pf.reminder.title', { number: invoice.number ?? '' })
                }}</SheetTitle>
                <SheetDescription>{{
                    t('pf.reminder.description')
                }}</SheetDescription>
            </SheetHeader>

            <div class="flex flex-1 flex-col gap-5 px-4 pb-4">
                <fieldset class="grid gap-2">
                    <legend class="mb-2 text-sm font-medium">
                        {{ t('pf.reminder.tone') }}
                    </legend>
                    <div
                        class="grid grid-cols-2 gap-2 sm:grid-cols-4"
                        role="radiogroup"
                    >
                        <button
                            v-for="option in TONES"
                            :key="option"
                            type="button"
                            role="radio"
                            :aria-checked="tone === option"
                            :class="[
                                'flex flex-col items-start gap-1 rounded-xl border p-2.5 text-left text-xs transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                                tone === option
                                    ? 'border-primary bg-primary/5'
                                    : 'hover:bg-muted/50',
                            ]"
                            :data-test="`tone-${option}`"
                            @click="tone = option"
                        >
                            <ToneChip :tone="option" />
                            <span
                                v-if="suggestion?.tone === option"
                                class="text-muted-foreground"
                                >{{ t('pf.reminder.suggested') }}</span
                            >
                        </button>
                    </div>
                    <ul
                        v-if="suggestion?.reasons.length"
                        class="mt-1 space-y-1 text-xs text-muted-foreground"
                    >
                        <li
                            v-for="reason in suggestion.reasons"
                            :key="reason.code"
                        >
                            • {{ pf.reason(reason) }}
                        </li>
                    </ul>
                </fieldset>

                <div
                    v-if="preview?.warnings.length"
                    class="flex flex-col gap-2"
                    data-test="reminder-warnings"
                >
                    <p
                        v-for="warning in preview.warnings"
                        :key="warning"
                        class="flex items-start gap-2 rounded-xl border border-warning/40 bg-warning-soft px-3 py-2 text-xs text-warning-soft-foreground"
                    >
                        <CircleAlert
                            class="mt-0.5 size-3.5 shrink-0"
                            aria-hidden="true"
                        />
                        {{ t(`pf.reminder.warnings.${warning}`) }}
                    </p>
                </div>

                <div class="flex items-center justify-between gap-2">
                    <p class="text-sm font-medium">
                        {{
                            editing
                                ? t('pf.reminder.edit_message')
                                : t('pf.reminder.preview')
                        }}
                    </p>
                    <div class="flex gap-1">
                        <Button
                            v-if="pf.entitled('ai_drafts')"
                            type="button"
                            size="sm"
                            variant="ghost"
                            :disabled="draftHttp.processing"
                            data-test="reminder-ai-draft"
                            @click="draftWithAi"
                        >
                            <Spinner
                                v-if="draftHttp.processing"
                                class="size-4"
                            />
                            <Sparkles
                                v-else
                                class="size-4 text-ai"
                                aria-hidden="true"
                            />
                            {{ t('pf.reminder.ai_draft') }}
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            @click="editing = !editing"
                        >
                            <component
                                :is="editing ? Eye : PenLine"
                                class="size-4"
                                aria-hidden="true"
                            />
                            {{
                                editing
                                    ? t('pf.reminder.preview')
                                    : t('pf.reminder.customize')
                            }}
                        </Button>
                    </div>
                </div>
                <p
                    v-if="aiNote"
                    class="text-xs text-muted-foreground"
                    role="status"
                >
                    {{ aiNote }}
                </p>

                <template v-if="editing">
                    <div class="grid gap-2">
                        <Label for="rem-subject">{{
                            t('pf.reminder.subject')
                        }}</Label>
                        <Input
                            id="rem-subject"
                            v-model="form.subject"
                            maxlength="255"
                        />
                        <InputError :message="form.errors.subject" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="rem-body">{{
                            t('pf.reminder.body')
                        }}</Label>
                        <Textarea
                            id="rem-body"
                            v-model="form.body_markdown"
                            rows="10"
                            maxlength="5000"
                            class="font-mono text-xs"
                        />
                        <p class="text-xs text-muted-foreground">
                            {{ t('pf.reminder.body_hint') }}
                        </p>
                        <InputError :message="form.errors.body_markdown" />
                    </div>
                </template>
                <div v-else class="overflow-hidden rounded-xl border">
                    <template v-if="previewHttp.processing && !preview">
                        <div class="space-y-2 p-4">
                            <Skeleton class="h-4 w-2/3" />
                            <Skeleton class="h-24 w-full" />
                        </div>
                    </template>
                    <p
                        v-else-if="previewError"
                        role="alert"
                        class="p-4 text-sm text-destructive"
                    >
                        {{ previewError }}
                    </p>
                    <template v-else-if="preview">
                        <div
                            class="space-y-1 border-b bg-muted/30 px-4 py-3 text-xs"
                        >
                            <p>
                                <span class="text-muted-foreground"
                                    >{{ t('pf.reminder.to') }}:</span
                                >
                                {{ preview.recipient ?? '—'
                                }}<template v-if="preview.cc.length"
                                    >, cc {{ preview.cc.join(', ') }}</template
                                >
                            </p>
                            <p
                                class="text-sm font-medium"
                                data-test="reminder-subject"
                            >
                                {{ preview.subject }}
                            </p>
                        </div>
                        <div
                            class="answer-prose px-4 py-3 text-sm"
                            data-test="reminder-body"
                            v-html="sanitizeAnswer(preview.body_html)"
                        />
                        <p
                            v-if="preview.late_fee"
                            class="border-t px-4 py-2 text-xs text-muted-foreground"
                        >
                            {{
                                t('pf.reminder.includes_late_fee', {
                                    amount: pf.money(preview.late_fee),
                                })
                            }}
                        </p>
                    </template>
                </div>

                <Label
                    v-if="blocking"
                    class="flex items-start gap-3 text-sm font-normal"
                >
                    <Checkbox
                        v-model="form.force"
                        class="mt-0.5"
                        data-test="reminder-force"
                    />
                    {{ t('pf.reminder.force') }}
                </Label>
                <InputError :message="form.errors.tone" />
            </div>

            <SheetFooter class="border-t">
                <Button type="button" variant="ghost" @click="open = false">{{
                    t('ui.common.cancel')
                }}</Button>
                <Button
                    type="button"
                    :disabled="
                        form.processing ||
                        noRecipient ||
                        (blocking && !form.force)
                    "
                    data-test="reminder-send"
                    @click="submit"
                >
                    <Spinner v-if="form.processing" class="size-4" />
                    {{ t('pf.actions.send_reminder') }}
                </Button>
            </SheetFooter>
        </SheetContent>
    </Sheet>
</template>
