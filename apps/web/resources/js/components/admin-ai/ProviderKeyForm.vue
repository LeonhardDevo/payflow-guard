<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Eraser, Save } from '@lucide/vue';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { endpoints } from '@/lib/endpoints';
import { t } from '@/lib/i18n';
import type { Provider } from '@/types';

const props = withDefaults(
    defineProps<{ provider: Provider; disabled?: boolean }>(),
    {
        disabled: false,
    },
);

const value = ref('');
const saving = ref<'save' | 'clear' | null>(null);
const error = ref<string | null>(null);

function submit(apiKey: string | null): void {
    error.value = null;

    router.put(
        endpoints.admin.ai.key(props.provider.id).url,
        { api_key: apiKey },
        {
            preserveScroll: true,
            onStart: () => {
                saving.value = apiKey === null ? 'clear' : 'save';
            },
            onSuccess: () => {
                value.value = '';
            },
            onError: (errors) => {
                error.value =
                    errors.api_key ??
                    Object.values(errors)[0] ??
                    t('ui.errors.generic');
            },
            onFinish: () => {
                saving.value = null;
            },
        },
    );
}
</script>

<template>
    <form
        class="space-y-2"
        @submit.prevent="value.trim() && submit(value.trim())"
    >
        <Label
            :for="`key-${provider.id}`"
            class="text-xs text-muted-foreground"
        >
            {{ t('ui.admin_ai.key_label', { provider: provider.label }) }}
        </Label>
        <div class="flex gap-2">
            <PasswordInput
                :id="`key-${provider.id}`"
                v-model="value"
                autocomplete="off"
                spellcheck="false"
                class="font-mono text-sm"
                :disabled="disabled"
                :data-test="`ai-key-input-${provider.id}`"
                :placeholder="
                    provider.key_hint
                        ? t('ui.admin_ai.key_replace', {
                              hint: provider.key_hint,
                          })
                        : t('ui.admin_ai.key_placeholder')
                "
            />
            <Button
                type="submit"
                size="icon"
                class="shrink-0"
                :disabled="disabled || !value.trim() || saving !== null"
                :aria-label="t('ui.admin_ai.key_save')"
                :data-test="`ai-key-save-${provider.id}`"
            >
                <Spinner v-if="saving === 'save'" />
                <Save v-else class="size-4" aria-hidden="true" />
            </Button>
        </div>
        <div class="flex flex-wrap items-center justify-between gap-2">
            <p class="text-xs text-muted-foreground">
                {{ t('ui.admin_ai.key_note') }}
            </p>
            <Button
                v-if="provider.key_source === 'settings'"
                type="button"
                size="sm"
                variant="ghost"
                class="h-7 px-2 text-xs text-muted-foreground hover:text-destructive"
                :disabled="disabled || saving !== null"
                @click="submit(null)"
            >
                <Spinner v-if="saving === 'clear'" />
                <Eraser v-else class="size-3.5" aria-hidden="true" />
                {{ t('ui.admin_ai.key_clear') }}
            </Button>
        </div>
        <InputError :message="error" />
    </form>
</template>
