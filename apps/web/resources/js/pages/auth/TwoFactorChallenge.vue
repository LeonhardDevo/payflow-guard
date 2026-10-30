<script setup lang="ts">
import { Form, Head, setLayoutProps } from '@inertiajs/vue3';
import { computed, ref, watchEffect } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    InputOTP,
    InputOTPGroup,
    InputOTPSlot,
} from '@/components/ui/input-otp';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/lib/i18n';
import { store } from '@/routes/two-factor/login';

const showRecoveryInput = ref<boolean>(false);
const code = ref<string>('');

const content = computed(() =>
    showRecoveryInput.value
        ? {
              title: 'ui.auth.two_factor.recovery_title',
              description: 'ui.auth.two_factor.recovery_description',
              toggle: 'ui.auth.two_factor.use_code',
          }
        : {
              title: 'ui.auth.two_factor.code_title',
              description: 'ui.auth.two_factor.code_description',
              toggle: 'ui.auth.two_factor.use_recovery',
          },
);

watchEffect(() => {
    setLayoutProps({
        title: content.value.title,
        description: content.value.description,
    });
});

const toggleRecoveryMode = (clearErrors: () => void): void => {
    showRecoveryInput.value = !showRecoveryInput.value;
    clearErrors();
    code.value = '';
};
</script>

<template>
    <Head :title="t('ui.auth.two_factor.head')" />

    <div class="space-y-6">
        <Form
            v-if="!showRecoveryInput"
            v-bind="store.form()"
            class="space-y-5"
            reset-on-error
            @error="code = ''"
            #default="{ errors, processing, clearErrors }"
        >
            <input type="hidden" name="code" :value="code" />
            <div class="flex flex-col items-center gap-3 text-center">
                <InputOTP
                    id="otp"
                    v-model="code"
                    :maxlength="6"
                    :disabled="processing"
                    autofocus
                    :aria-label="t('ui.auth.two_factor.code_label')"
                >
                    <InputOTPGroup>
                        <InputOTPSlot
                            v-for="index in 6"
                            :key="index"
                            :index="index - 1"
                            class="size-11 text-lg"
                        />
                    </InputOTPGroup>
                </InputOTP>
                <InputError :message="errors.code" />
            </div>
            <Button
                type="submit"
                class="h-10 w-full"
                :disabled="processing || code.length < 6"
            >
                <Spinner v-if="processing" />
                {{ t('ui.common.continue') }}
            </Button>
            <p class="text-center text-sm text-muted-foreground">
                <button
                    type="button"
                    class="font-medium text-primary underline decoration-primary/30 underline-offset-4 hover:decoration-primary"
                    @click="() => toggleRecoveryMode(clearErrors)"
                >
                    {{ t(content.toggle) }}
                </button>
            </p>
        </Form>

        <Form
            v-else
            v-bind="store.form()"
            class="space-y-5"
            reset-on-error
            #default="{ errors, processing, clearErrors }"
        >
            <div class="grid gap-2">
                <Input
                    v-focus
                    name="recovery_code"
                    type="text"
                    class="h-10 font-mono"
                    autocomplete="one-time-code"
                    :aria-label="t('ui.auth.two_factor.recovery_label')"
                    :placeholder="t('ui.auth.two_factor.recovery_placeholder')"
                    required
                />
                <InputError :message="errors.recovery_code" />
            </div>
            <Button type="submit" class="h-10 w-full" :disabled="processing">
                <Spinner v-if="processing" />
                {{ t('ui.common.continue') }}
            </Button>
            <p class="text-center text-sm text-muted-foreground">
                <button
                    type="button"
                    class="font-medium text-primary underline decoration-primary/30 underline-offset-4 hover:decoration-primary"
                    @click="() => toggleRecoveryMode(clearErrors)"
                >
                    {{ t(content.toggle) }}
                </button>
            </p>
        </Form>
    </div>
</template>
