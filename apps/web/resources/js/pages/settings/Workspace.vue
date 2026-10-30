<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Building2, Globe2, TriangleAlert } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import ConfirmDialog from '@/components/common/ConfirmDialog.vue';
import SearchableSelect from '@/components/common/SearchableSelect.vue';
import SectionCard from '@/components/common/SectionCard.vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
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
import { useCan } from '@/composables/useCan';
import { useFormat } from '@/composables/useFormat';
import { useLocale } from '@/composables/useLocale';
import { endpoints } from '@/lib/endpoints';
import { t } from '@/lib/i18n';
import { currencyOptions, timezoneOptions } from '@/lib/regional';
import type { WorkspaceSettings } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'ui.settings.title',
                href: endpoints.settings.workspace.show(),
            },
            {
                title: 'ui.settings.nav.workspace',
                href: endpoints.settings.workspace.show(),
            },
        ],
    },
});

const props = defineProps<{
    workspace: WorkspaceSettings;
    timezones: string[];
    currencies: string[];
}>();

const { can } = useCan();
const { locale, locales } = useLocale();
const fmt = useFormat();
const canManage = computed(() => can('workspace.manage'));
const canDelete = computed(() => can('workspace.delete'));

const form = useForm({
    name: props.workspace.name,
    timezone: props.workspace.timezone,
    currency: props.workspace.currency,
    locale: props.workspace.locale,
});

const tzOptions = computed(() => timezoneOptions(props.timezones));
const curOptions = computed(() =>
    currencyOptions(props.currencies, locale.value),
);

const preview = computed(() => {
    const now = new Date();

    try {
        return {
            money: new Intl.NumberFormat(
                form.locale === 'ru' ? 'ru-RU' : 'en-US',
                {
                    style: 'currency',
                    currency: form.currency,
                },
            ).format(12450.5),
            time: new Intl.DateTimeFormat(
                form.locale === 'ru' ? 'ru-RU' : 'en-US',
                {
                    dateStyle: 'medium',
                    timeStyle: 'short',
                    timeZone: form.timezone,
                },
            ).format(now),
        };
    } catch {
        return {
            money: fmt.value.money(12450.5),
            time: fmt.value.dateTime(now),
        };
    }
});

function submit(): void {
    form.patch(endpoints.settings.workspace.update().url, {
        preserveScroll: true,
    });
}

const deleteOpen = ref(false);
const deleting = ref(false);
const deletePassword = ref('');
const deleteError = ref<string | null>(null);

watch(deleteOpen, (open) => {
    if (!open) {
        deletePassword.value = '';
        deleteError.value = null;
    }
});

function destroyWorkspace(): void {
    deleteError.value = null;

    router.delete(endpoints.settings.workspace.destroy().url, {
        data: { password: deletePassword.value },
        preserveScroll: true,
        onStart: () => {
            deleting.value = true;
        },
        onSuccess: () => {
            deleteOpen.value = false;
        },
        onError: (errors) => {
            deleteError.value =
                errors.password ??
                Object.values(errors)[0] ??
                t('ui.errors.generic');
        },
        onFinish: () => {
            deleting.value = false;
        },
    });
}
</script>

<template>
    <Head :title="t('ui.settings.workspace.title')" />

    <SectionCard
        :icon="Building2"
        :title="t('ui.settings.workspace.title')"
        :description="t('ui.settings.workspace.description')"
    >
        <form class="space-y-6" @submit.prevent="submit">
            <fieldset
                :disabled="!canManage || form.processing"
                class="space-y-6"
            >
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="ws-name">{{
                            t('ui.settings.workspace.name')
                        }}</Label>
                        <Input
                            id="ws-name"
                            v-model="form.name"
                            required
                            maxlength="80"
                            :aria-invalid="Boolean(form.errors.name)"
                        />
                        <InputError :message="form.errors.name" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="ws-slug">{{
                            t('ui.settings.workspace.slug')
                        }}</Label>
                        <Input
                            id="ws-slug"
                            :model-value="workspace.slug"
                            readonly
                            class="font-mono text-muted-foreground"
                        />
                        <p class="text-xs text-muted-foreground">
                            {{ t('ui.settings.workspace.slug_hint') }}
                        </p>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="ws-timezone">{{
                            t('ui.fields.timezone')
                        }}</Label>
                        <SearchableSelect
                            id="ws-timezone"
                            v-model="form.timezone"
                            :options="tzOptions"
                            :disabled="!canManage"
                            :invalid="Boolean(form.errors.timezone)"
                            :search-placeholder="t('ui.fields.timezone_search')"
                        />
                        <InputError :message="form.errors.timezone" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="ws-currency">{{
                            t('ui.fields.currency')
                        }}</Label>
                        <SearchableSelect
                            id="ws-currency"
                            v-model="form.currency"
                            :options="curOptions"
                            :disabled="!canManage"
                            :invalid="Boolean(form.errors.currency)"
                        />
                        <InputError :message="form.errors.currency" />
                    </div>
                </div>

                <div class="grid gap-2 sm:max-w-xs">
                    <Label for="ws-locale">{{
                        t('ui.settings.workspace.locale')
                    }}</Label>
                    <Select v-model="form.locale" :disabled="!canManage">
                        <SelectTrigger id="ws-locale" class="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="option in locales"
                                :key="option.code"
                                :value="option.code"
                            >
                                {{ option.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <p class="text-xs text-muted-foreground">
                        {{ t('ui.settings.workspace.locale_hint') }}
                    </p>
                    <InputError :message="form.errors.locale" />
                </div>
            </fieldset>

            <div
                class="flex items-start gap-3 rounded-xl border border-dashed bg-muted/40 p-4"
            >
                <Globe2
                    class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                    aria-hidden="true"
                />
                <dl
                    class="grid grid-cols-1 gap-1 text-sm sm:grid-cols-2 sm:gap-6"
                >
                    <div>
                        <dt class="text-xs text-muted-foreground">
                            {{ t('ui.settings.workspace.preview_money') }}
                        </dt>
                        <dd class="font-semibold tabular-nums">
                            {{ preview.money }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">
                            {{ t('ui.settings.workspace.preview_time') }}
                        </dt>
                        <dd class="font-semibold tabular-nums">
                            {{ preview.time }}
                        </dd>
                    </div>
                </dl>
            </div>

            <div v-if="canManage" class="flex items-center gap-3">
                <Button
                    type="submit"
                    :disabled="form.processing || !form.isDirty"
                    data-test="save-workspace"
                >
                    <Spinner v-if="form.processing" />
                    {{ t('ui.common.save_changes') }}
                </Button>
                <p
                    v-show="form.recentlySuccessful"
                    class="text-sm text-muted-foreground"
                    role="status"
                >
                    {{ t('ui.common.saved') }}
                </p>
            </div>
            <p v-else class="text-sm text-muted-foreground">
                {{ t('ui.settings.workspace.read_only') }}
            </p>
        </form>
    </SectionCard>

    <SectionCard
        v-if="canDelete && workspace"
        tone="danger"
        :icon="TriangleAlert"
        :title="t('ui.settings.workspace.danger_title')"
        :description="t('ui.settings.workspace.danger_description')"
    >
        <div
            class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
        >
            <p class="text-sm text-muted-foreground">
                {{ t('ui.settings.workspace.delete_warning') }}
            </p>
            <Button
                variant="destructive"
                class="shrink-0"
                @click="deleteOpen = true"
            >
                {{ t('ui.settings.workspace.delete') }}
            </Button>
        </div>
        <ConfirmDialog
            v-model:open="deleteOpen"
            destructive
            :processing="deleting"
            :confirm-text="workspace.name"
            :title="
                t('ui.settings.workspace.delete_title', {
                    name: workspace.name,
                })
            "
            :description="t('ui.settings.workspace.delete_body')"
            :confirm-label="t('ui.settings.workspace.delete')"
            @confirm="destroyWorkspace"
        >
            <div class="grid gap-2">
                <Label for="delete-workspace-password">{{
                    t('ui.fields.current_password')
                }}</Label>
                <PasswordInput
                    id="delete-workspace-password"
                    v-model="deletePassword"
                    autocomplete="current-password"
                    :aria-invalid="Boolean(deleteError)"
                />
                <InputError :message="deleteError" />
            </div>
        </ConfirmDialog>
    </SectionCard>
</template>
