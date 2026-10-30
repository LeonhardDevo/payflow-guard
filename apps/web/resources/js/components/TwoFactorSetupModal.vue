<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { ScanLine } from '@lucide/vue';
import { computed, nextTick, ref, useTemplateRef, watch } from 'vue';
import AlertError from '@/components/AlertError.vue';
import CopyButton from '@/components/common/CopyButton.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    InputOTP,
    InputOTPGroup,
    InputOTPSlot,
} from '@/components/ui/input-otp';
import { Spinner } from '@/components/ui/spinner';
import { useTwoFactorAuth } from '@/composables/useTwoFactorAuth';
import { t } from '@/lib/i18n';
import { confirm } from '@/routes/two-factor';
import type { TwoFactorConfigContent } from '@/types';

type Props = {
    requiresConfirmation: boolean;
    twoFactorEnabled: boolean;
};

const props = defineProps<Props>();
const isOpen = defineModel<boolean>('isOpen');

const { qrCodeSvg, manualSetupKey, clearSetupData, fetchSetupData, errors } =
    useTwoFactorAuth();

const showVerificationStep = ref(false);
const code = ref<string>('');
const pinInputContainerRef = useTemplateRef('pinInputContainerRef');

const modalConfig = computed<TwoFactorConfigContent>(() => {
    if (props.twoFactorEnabled) {
        return {
            title: t('ui.settings.two_factor.modal.enabled_title'),
            description: t('ui.settings.two_factor.modal.enabled_body'),
            buttonText: t('ui.common.close'),
        };
    }

    if (showVerificationStep.value) {
        return {
            title: t('ui.settings.two_factor.modal.verify_title'),
            description: t('ui.settings.two_factor.modal.verify_body'),
            buttonText: t('ui.common.continue'),
        };
    }

    return {
        title: t('ui.settings.two_factor.modal.setup_title'),
        description: t('ui.settings.two_factor.modal.setup_body'),
        buttonText: t('ui.common.continue'),
    };
});

const handleModalNextStep = () => {
    if (props.requiresConfirmation) {
        showVerificationStep.value = true;

        nextTick(() => {
            pinInputContainerRef.value?.querySelector('input')?.focus();
        });

        return;
    }

    clearSetupData();
    isOpen.value = false;
};

const resetModalState = () => {
    if (props.twoFactorEnabled) {
        clearSetupData();
    }

    showVerificationStep.value = false;
    code.value = '';
};

watch(
    () => isOpen.value,
    async (open) => {
        if (!open) {
            resetModalState();

            return;
        }

        if (!qrCodeSvg.value) {
            await fetchSetupData();
        }
    },
);
</script>

<template>
    <Dialog :open="isOpen" @update:open="isOpen = $event">
        <DialogContent class="sm:max-w-md">
            <DialogHeader class="flex flex-col items-center text-center">
                <span
                    class="mb-2 inline-flex size-12 items-center justify-center rounded-2xl bg-primary-soft text-primary-soft-foreground"
                    aria-hidden="true"
                >
                    <ScanLine class="size-6" />
                </span>
                <DialogTitle>{{ modalConfig.title }}</DialogTitle>
                <DialogDescription class="text-center">
                    {{ modalConfig.description }}
                </DialogDescription>
            </DialogHeader>

            <div class="flex flex-col items-center gap-5">
                <template v-if="!showVerificationStep">
                    <AlertError v-if="errors?.length" :errors="errors" />
                    <template v-else>
                        <div
                            class="relative mx-auto aspect-square w-56 overflow-hidden rounded-xl border bg-white p-4"
                        >
                            <div
                                v-if="!qrCodeSvg"
                                class="absolute inset-0 flex items-center justify-center bg-white"
                            >
                                <Spinner class="size-6 text-neutral-500" />
                            </div>
                            <div
                                v-else
                                class="flex size-full items-center justify-center [&_svg]:size-full"
                                :aria-label="
                                    t('ui.settings.two_factor.modal.qr_label')
                                "
                                role="img"
                                v-html="qrCodeSvg"
                            />
                        </div>

                        <Button class="w-full" @click="handleModalNextStep">
                            {{ modalConfig.buttonText }}
                        </Button>

                        <div
                            class="relative flex w-full items-center justify-center"
                        >
                            <div
                                class="absolute inset-x-0 top-1/2 h-px bg-border"
                            />
                            <span
                                class="relative bg-background px-2 text-xs text-muted-foreground"
                            >
                                {{ t('ui.settings.two_factor.modal.manual') }}
                            </span>
                        </div>

                        <div
                            class="flex w-full items-stretch overflow-hidden rounded-lg border"
                        >
                            <div
                                v-if="!manualSetupKey"
                                class="flex w-full items-center justify-center bg-muted p-3"
                            >
                                <Spinner />
                            </div>
                            <template v-else>
                                <input
                                    type="text"
                                    readonly
                                    :value="manualSetupKey"
                                    :aria-label="
                                        t(
                                            'ui.settings.two_factor.modal.setup_key',
                                        )
                                    "
                                    class="w-full bg-background p-3 font-mono text-sm text-foreground outline-none"
                                />
                                <div class="flex items-center border-l px-1">
                                    <CopyButton :value="manualSetupKey" />
                                </div>
                            </template>
                        </div>
                    </template>
                </template>

                <Form
                    v-else
                    v-bind="confirm.form()"
                    error-bag="confirmTwoFactorAuthentication"
                    reset-on-error
                    class="w-full"
                    @finish="code = ''"
                    @success="isOpen = false"
                    v-slot="{ errors: formErrors, processing }"
                >
                    <input type="hidden" name="code" :value="code" />
                    <div ref="pinInputContainerRef" class="w-full space-y-4">
                        <div class="flex flex-col items-center gap-3 py-2">
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
                                    />
                                </InputOTPGroup>
                            </InputOTP>
                            <InputError :message="formErrors?.code" />
                        </div>
                        <div class="flex w-full gap-3">
                            <Button
                                type="button"
                                variant="outline"
                                class="flex-1"
                                :disabled="processing"
                                @click="showVerificationStep = false"
                            >
                                {{ t('ui.common.back') }}
                            </Button>
                            <Button
                                type="submit"
                                class="flex-1"
                                :disabled="processing || code.length < 6"
                            >
                                <Spinner v-if="processing" />
                                {{ t('ui.common.confirm') }}
                            </Button>
                        </div>
                    </div>
                </Form>
            </div>
        </DialogContent>
    </Dialog>
</template>
