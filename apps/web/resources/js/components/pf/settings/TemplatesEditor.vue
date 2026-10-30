<script setup lang="ts">
import { router, useForm, useHttp } from '@inertiajs/vue3';
import { Eye, Lock, Mail, RotateCcw, Save } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import SectionCard from '@/components/common/SectionCard.vue';
import SegmentedControl from '@/components/common/SegmentedControl.vue';
import StatusBadge from '@/components/common/StatusBadge.vue';
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
import { Textarea } from '@/components/ui/textarea';
import { useFormat } from '@/composables/useFormat';
import { describeHttpError } from '@/lib/http-errors';
import { t } from '@/lib/i18n';
import { sanitizeAnswer } from '@/lib/sanitize';
import { preview, reset, update } from '@/routes/settings/templates';
import type { Locale, MessageTemplate, TemplateKey } from '@/types/payflow';

const props = defineProps<{
    templates: MessageTemplate[];
    variables: string[];
    canEdit: boolean;
}>();
const fmt = useFormat();

const keys = computed(
    () => [...new Set(props.templates.map((tpl) => tpl.key))] as TemplateKey[],
);
const key = ref<TemplateKey>(keys.value[0] ?? 'invoice_issued');
const locale = ref<Locale>('en');
const localeOptions = [
    { value: 'en' as const, label: 'English' },
    { value: 'ru' as const, label: 'Русский' },
];

const current = computed(
    () =>
        props.templates.find(
            (tpl) => tpl.key === key.value && tpl.locale === locale.value,
        ) ?? null,
);

const form = useForm({ subject: '', body_markdown: '' });

watch(
    current,
    (tpl) => {
        form.defaults({
            subject: tpl?.subject ?? '',
            body_markdown: tpl?.body_markdown ?? '',
        });
        form.reset();
        form.clearErrors();
        previewData.value = null;
    },
    { immediate: true },
);

const body = ref<HTMLTextAreaElement | null>(null);

const tokenOf = (variable: string) => `{{${variable}}}`;

function insert(variable: string): void {
    const token = tokenOf(variable);
    const el = body.value;

    if (!el) {
        form.body_markdown += token;

        return;
    }

    const start = el.selectionStart ?? form.body_markdown.length;
    const end = el.selectionEnd ?? start;
    form.body_markdown =
        form.body_markdown.slice(0, start) +
        token +
        form.body_markdown.slice(end);
}

type PreviewResult = {
    subject: string;
    html: string;
    text: string;
    unknown_variables: string[];
};
const previewHttp = useHttp<
    { key: string; locale: string; subject: string; body_markdown: string },
    PreviewResult
>({
    key: '',
    locale: '',
    subject: '',
    body_markdown: '',
});
const previewData = ref<PreviewResult | null>(null);
const previewError = ref<string | null>(null);

async function runPreview(): Promise<void> {
    previewError.value = null;
    previewHttp.key = key.value;
    previewHttp.locale = locale.value;
    previewHttp.subject = form.subject;
    previewHttp.body_markdown = form.body_markdown;

    try {
        previewData.value = (await previewHttp.post(preview().url)) ?? null;
    } catch (error) {
        previewError.value =
            describeHttpError(error).message ?? t('pf.reminder.preview_failed');
    }
}

function save(): void {
    if (current.value) {
        form.put(update(current.value.id).url, { preserveScroll: true });
    }
}

const resetting = ref(false);

function resetTemplate(): void {
    if (!current.value) {
        return;
    }

    resetting.value = true;
    router.post(
        reset(current.value.id).url,
        {},
        { preserveScroll: true, onFinish: () => (resetting.value = false) },
    );
}
</script>

<template>
    <SectionCard
        :icon="Mail"
        :title="t('pf.settings.templates.title')"
        :description="t('pf.settings.templates.description')"
    >
        <div class="flex flex-col gap-4">
            <div class="flex flex-wrap items-end gap-3">
                <div class="grid min-w-56 flex-1 gap-1.5">
                    <Label
                        for="tpl-key"
                        class="text-xs text-muted-foreground"
                        >{{ t('pf.settings.templates.template') }}</Label
                    >
                    <Select v-model="key">
                        <SelectTrigger id="tpl-key" class="w-full"
                            ><SelectValue
                        /></SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="item in keys"
                                :key="item"
                                :value="item"
                                >{{
                                    t(`pf.settings.templates.keys.${item}`)
                                }}</SelectItem
                            >
                        </SelectContent>
                    </Select>
                </div>
                <SegmentedControl
                    v-model="locale"
                    :options="localeOptions"
                    :label="t('pf.client.locale')"
                />
            </div>

            <p
                v-if="!canEdit"
                class="flex items-center gap-2 rounded-xl border bg-muted/40 p-3 text-sm text-muted-foreground"
            >
                <Lock class="size-4" aria-hidden="true" />{{
                    t('pf.settings.templates.locked')
                }}
            </p>

            <div v-if="current" class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                <form class="grid gap-3" @submit.prevent="save">
                    <p
                        class="flex flex-wrap items-center gap-2 text-xs text-muted-foreground"
                    >
                        <StatusBadge
                            v-if="current.is_customized"
                            tone="ai"
                            :label="t('pf.settings.templates.customized')"
                        />
                        <template v-if="current.updated_by">{{
                            t('pf.settings.templates.updated', {
                                name: current.updated_by.name,
                                date: fmt.date(current.updated_at),
                            })
                        }}</template>
                    </p>
                    <div class="grid gap-1.5">
                        <Label for="tpl-subject">{{
                            t('pf.reminder.subject')
                        }}</Label>
                        <Input
                            id="tpl-subject"
                            v-model="form.subject"
                            maxlength="200"
                            :disabled="!canEdit"
                        />
                        <InputError :message="form.errors.subject" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="tpl-body">{{
                            t('pf.reminder.body')
                        }}</Label>
                        <Textarea
                            id="tpl-body"
                            ref="body"
                            v-model="form.body_markdown"
                            rows="12"
                            maxlength="5000"
                            class="font-mono text-xs"
                            :disabled="!canEdit"
                        />
                        <InputError :message="form.errors.body_markdown" />
                    </div>
                    <div
                        v-if="canEdit"
                        class="flex flex-wrap gap-1.5"
                        :aria-label="t('pf.settings.templates.variables')"
                    >
                        <button
                            v-for="variable in variables"
                            :key="variable"
                            type="button"
                            class="rounded-full border px-2 py-0.5 font-mono text-[11px] text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            @click="insert(variable)"
                        >
                            {{ tokenOf(variable) }}
                        </button>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            :disabled="previewHttp.processing"
                            @click="runPreview"
                        >
                            <Spinner
                                v-if="previewHttp.processing"
                                class="size-4"
                            />
                            <Eye v-else class="size-4" aria-hidden="true" />{{
                                t('pf.reminder.preview')
                            }}
                        </Button>
                        <Button
                            v-if="canEdit"
                            type="submit"
                            size="sm"
                            :disabled="form.processing || !form.isDirty"
                            data-test="template-save"
                        >
                            <Spinner v-if="form.processing" class="size-4" />
                            <Save v-else class="size-4" aria-hidden="true" />{{
                                t('pf.actions.save_changes')
                            }}
                        </Button>
                        <Button
                            v-if="current.is_customized"
                            type="button"
                            variant="ghost"
                            size="sm"
                            :disabled="resetting"
                            @click="resetTemplate"
                        >
                            <RotateCcw class="size-4" aria-hidden="true" />{{
                                t('pf.settings.templates.reset')
                            }}
                        </Button>
                    </div>
                </form>
                <div
                    class="overflow-hidden rounded-xl border"
                    aria-live="polite"
                >
                    <p
                        v-if="!previewData && !previewError"
                        class="p-4 text-sm text-muted-foreground"
                    >
                        {{ t('pf.settings.templates.preview_hint') }}
                    </p>
                    <p
                        v-else-if="previewError"
                        role="alert"
                        class="p-4 text-sm text-destructive"
                    >
                        {{ previewError }}
                    </p>
                    <template v-else-if="previewData">
                        <p
                            class="border-b bg-muted/30 px-4 py-2 text-sm font-medium"
                        >
                            {{ previewData.subject }}
                        </p>
                        <p
                            v-if="previewData.unknown_variables.length"
                            class="border-b bg-warning-soft px-4 py-2 text-xs text-warning-soft-foreground"
                        >
                            {{
                                t('pf.settings.templates.unknown', {
                                    vars: previewData.unknown_variables.join(
                                        ', ',
                                    ),
                                })
                            }}
                        </p>
                        <div
                            class="answer-prose px-4 py-3 text-sm"
                            v-html="sanitizeAnswer(previewData.html)"
                        />
                    </template>
                </div>
            </div>
        </div>
    </SectionCard>
</template>
