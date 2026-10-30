<script setup lang="ts">
import { usePasskeyRegister } from '@laravel/passkeys/vue';
import { Plus } from '@lucide/vue';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/lib/i18n';

const emit = defineEmits<{ success: [] }>();

const getDefaultPasskeyName = () => {
    const ua = navigator.userAgent;

    const browser = [
        { pattern: /Edg|Edge/, name: 'Edge' },
        { pattern: /OPR|Opera|OPiOS/, name: 'Opera' },
        { pattern: /Firefox|FxiOS/, name: 'Firefox' },
        { pattern: /Chrome|CriOS/, name: 'Chrome' },
        { pattern: /Safari/, name: 'Safari' },
    ].find(({ pattern }) => pattern.test(ua))?.name;

    const os = [
        { pattern: /iPhone/, name: 'iPhone' },
        { pattern: /iPad|Macintosh(?=.*Mobile)/, name: 'iPad' },
        { pattern: /Android/, name: 'Android' },
        { pattern: /Mac/, name: 'Mac' },
        { pattern: /Windows/, name: 'Windows' },
    ].find(({ pattern }) => pattern.test(ua))?.name;

    return [browser, os].filter(Boolean).join(' · ') || '';
};

const name = ref(getDefaultPasskeyName());
const showForm = ref(false);

const { register, isLoading, error, isSupported } = usePasskeyRegister({
    onSuccess: () => {
        name.value = '';
        showForm.value = false;
        emit('success');
    },
});

const handleSubmit = async (event: Event) => {
    event.preventDefault();

    if (!name.value.trim()) {
        return;
    }

    await register(name.value);
};

const handleCancel = () => {
    showForm.value = false;
    name.value = getDefaultPasskeyName();
};
</script>

<template>
    <p v-if="!isSupported" class="text-sm text-muted-foreground">
        {{ t('ui.settings.passkeys.unsupported') }}
    </p>

    <Button v-else-if="!showForm" variant="outline" @click="showForm = true">
        <Plus class="size-4" aria-hidden="true" />
        {{ t('ui.settings.passkeys.add') }}
    </Button>

    <form
        v-else
        class="space-y-4 rounded-xl border bg-muted/40 p-4"
        @submit="handleSubmit"
    >
        <div class="grid gap-2">
            <Label for="passkey-name">{{
                t('ui.settings.passkeys.name')
            }}</Label>
            <Input
                id="passkey-name"
                v-model="name"
                v-focus
                type="text"
                :placeholder="t('ui.settings.passkeys.name_placeholder')"
            />
            <p class="text-xs text-muted-foreground">
                {{ t('ui.settings.passkeys.name_hint') }}
            </p>
        </div>

        <InputError v-if="error" :message="error" />

        <div class="flex gap-2">
            <Button type="submit" :disabled="isLoading || !name.trim()">
                <Spinner v-if="isLoading" />
                {{
                    isLoading
                        ? t('ui.settings.passkeys.registering')
                        : t('ui.settings.passkeys.register')
                }}
            </Button>
            <Button type="button" variant="ghost" @click="handleCancel">
                {{ t('ui.common.cancel') }}
            </Button>
        </div>
    </form>
</template>
