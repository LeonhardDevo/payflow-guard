<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { Eye, EyeOff, LockKeyhole, RefreshCw } from '@lucide/vue';
import { nextTick, onMounted, ref, useTemplateRef } from 'vue';
import AlertError from '@/components/AlertError.vue';
import CopyButton from '@/components/common/CopyButton.vue';
import { Button } from '@/components/ui/button';
import { useTwoFactorAuth } from '@/composables/useTwoFactorAuth';
import { t } from '@/lib/i18n';
import { regenerateRecoveryCodes } from '@/routes/two-factor';

const { recoveryCodesList, fetchRecoveryCodes, errors } = useTwoFactorAuth();
const visible = ref<boolean>(false);
const section = useTemplateRef('section');

const toggle = async () => {
    if (!visible.value && !recoveryCodesList.value.length) {
        await fetchRecoveryCodes();
    }

    visible.value = !visible.value;

    if (visible.value) {
        await nextTick();
        section.value?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
};

onMounted(async () => {
    if (!recoveryCodesList.value.length) {
        await fetchRecoveryCodes();
    }
});
</script>

<template>
    <div class="w-full rounded-xl border bg-muted/30 p-4">
        <div class="flex items-start gap-3">
            <LockKeyhole
                class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                aria-hidden="true"
            />
            <div class="space-y-1">
                <p class="text-sm font-semibold">
                    {{ t('ui.settings.recovery.title') }}
                </p>
                <p class="text-sm text-muted-foreground">
                    {{ t('ui.settings.recovery.description') }}
                </p>
            </div>
        </div>
        <div
            class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
        >
            <Button
                variant="outline"
                class="w-fit"
                :aria-expanded="visible"
                @click="toggle"
            >
                <component
                    :is="visible ? EyeOff : Eye"
                    class="size-4"
                    aria-hidden="true"
                />
                {{
                    visible
                        ? t('ui.settings.recovery.hide')
                        : t('ui.settings.recovery.view')
                }}
            </Button>
            <Form
                v-if="visible && recoveryCodesList.length"
                v-bind="regenerateRecoveryCodes.form()"
                method="post"
                :options="{ preserveScroll: true }"
                @success="fetchRecoveryCodes"
                #default="{ processing }"
            >
                <Button
                    variant="secondary"
                    type="submit"
                    :disabled="processing"
                >
                    <RefreshCw class="size-4" aria-hidden="true" />
                    {{ t('ui.settings.recovery.regenerate') }}
                </Button>
            </Form>
        </div>
        <div v-if="visible" class="mt-4 space-y-3">
            <AlertError v-if="errors?.length" :errors="errors" />
            <template v-else>
                <div
                    ref="section"
                    class="relative rounded-lg border bg-card p-4"
                >
                    <div class="absolute top-2 right-2">
                        <CopyButton
                            v-if="recoveryCodesList.length"
                            :value="recoveryCodesList.join('\n')"
                            :label="t('ui.settings.recovery.copy')"
                        />
                    </div>
                    <div
                        v-if="!recoveryCodesList.length"
                        class="grid grid-cols-1 gap-2 sm:grid-cols-2"
                    >
                        <div
                            v-for="n in 8"
                            :key="n"
                            class="h-4 animate-pulse rounded bg-muted"
                        />
                    </div>
                    <ul
                        v-else
                        class="grid grid-cols-1 gap-1.5 font-mono text-sm sm:grid-cols-2"
                    >
                        <li
                            v-for="(code, index) in recoveryCodesList"
                            :key="index"
                        >
                            {{ code }}
                        </li>
                    </ul>
                </div>
                <p class="text-xs text-muted-foreground">
                    {{ t('ui.settings.recovery.note') }}
                </p>
            </template>
        </div>
    </div>
</template>
