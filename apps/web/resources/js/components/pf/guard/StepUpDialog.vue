<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { startAuthentication } from '@simplewebauthn/browser';
import { Fingerprint, KeyRound, ShieldCheck, Smartphone } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
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
import { useStepUp } from '@/composables/pf/useStepUp';
import { t } from '@/lib/i18n';
import { stepUp } from '@/routes/guard';
import { confirmOptions } from '@/routes/passkey';
import { edit as securityEdit } from '@/routes/security';
import type { StepUpMethod } from '@/types/payflow-guard';

const page = usePage();
const step = useStepUp();

const methods = computed<StepUpMethod[]>(
    () => step.stepup.value?.methods ?? ['password'],
);
const needsSecondFactor = computed(
    () =>
        Boolean(step.stepup.value?.second_factor_required) &&
        !step.stepup.value?.has_second_factor,
);

const method = ref<StepUpMethod>('password');
const code = ref('');
const password = ref('');
const error = ref<string | null>(null);
const processing = ref(false);

watch(
    () => page.props.pf?.stepup?.prompt === true,
    (prompt) => {
        if (prompt) {
            step.open.value = true;
        }
    },
    { immediate: true },
);

watch(step.open, (value) => {
    if (value) {
        method.value = methods.value[0] ?? 'password';
        code.value = '';
        password.value = '';
        error.value = null;
    }
});

const icons = { passkey: Fingerprint, totp: Smartphone, password: KeyRound };

function submit(payload: Record<string, unknown>): void {
    processing.value = true;
    error.value = null;
    router.post(stepUp().url, payload as never, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => step.completed(),
        onError: (errors) => {
            error.value =
                errors.code ??
                errors.password ??
                errors.assertion ??
                errors.method ??
                t('guard.stepup.failed');
        },
        onFinish: () => {
            processing.value = false;
        },
    });
}

async function usePasskey(): Promise<void> {
    processing.value = true;
    error.value = null;

    try {
        const response = await fetch(confirmOptions().url, {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        });

        if (!response.ok) {
            throw new Error(String(response.status));
        }

        const { options } = (await response.json()) as {
            options: Parameters<typeof startAuthentication>[0]['optionsJSON'];
        };
        const assertion = await startAuthentication({ optionsJSON: options });
        submit({ method: 'passkey', assertion });
    } catch {
        processing.value = false;
        error.value = t('guard.stepup.passkey_failed');
    }
}

function confirm(): void {
    if (method.value === 'passkey') {
        void usePasskey();
    } else if (method.value === 'totp') {
        submit({ method: 'totp', code: code.value });
    } else {
        submit({ method: 'password', password: password.value });
    }
}
</script>

<template>
    <Dialog
        :open="step.open.value"
        @update:open="(value) => (value ? null : step.cancel())"
    >
        <DialogContent class="sm:max-w-md" data-test="step-up-dialog">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2">
                    <ShieldCheck
                        class="size-5 text-primary"
                        aria-hidden="true"
                    />
                    {{ t('guard.stepup.title') }}
                </DialogTitle>
                <DialogDescription>{{
                    t('guard.stepup.description')
                }}</DialogDescription>
            </DialogHeader>

            <div v-if="needsSecondFactor" class="grid gap-3 text-sm">
                <p
                    class="rounded-xl border border-warning/40 bg-warning-soft p-3 text-warning-soft-foreground"
                >
                    {{ t('guard.stepup.second_factor_required') }}
                </p>
                <Button as-child>
                    <Link :href="securityEdit().url">{{
                        t('guard.stepup.setup_second_factor')
                    }}</Link>
                </Button>
            </div>

            <form v-else class="grid gap-4" @submit.prevent="confirm">
                <div
                    v-if="methods.length > 1"
                    class="grid grid-cols-3 gap-2"
                    role="radiogroup"
                    :aria-label="t('guard.stepup.method')"
                >
                    <button
                        v-for="item in methods"
                        :key="item"
                        type="button"
                        role="radio"
                        :aria-checked="method === item"
                        :class="[
                            'flex flex-col items-center gap-1 rounded-xl border p-2.5 text-xs transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                            method === item
                                ? 'border-primary bg-primary/5'
                                : 'hover:bg-muted/50',
                        ]"
                        :data-test="`step-up-${item}`"
                        @click="method = item"
                    >
                        <component
                            :is="icons[item]"
                            class="size-4"
                            aria-hidden="true"
                        />
                        {{ t(`guard.stepup.methods.${item}`) }}
                    </button>
                </div>

                <p
                    v-if="method === 'passkey'"
                    class="text-sm text-muted-foreground"
                >
                    {{ t('guard.stepup.passkey_hint') }}
                </p>
                <div v-else-if="method === 'totp'" class="grid gap-2">
                    <Label for="step-up-code">{{
                        t('guard.stepup.code')
                    }}</Label>
                    <Input
                        id="step-up-code"
                        v-model="code"
                        v-focus
                        inputmode="numeric"
                        autocomplete="one-time-code"
                        maxlength="10"
                        class="text-center font-mono tracking-[0.3em]"
                        data-test="step-up-code"
                    />
                </div>
                <div v-else class="grid gap-2">
                    <Label for="step-up-password">{{
                        t('guard.stepup.password')
                    }}</Label>
                    <Input
                        id="step-up-password"
                        v-model="password"
                        v-focus
                        type="password"
                        autocomplete="current-password"
                        data-test="step-up-password"
                    />
                </div>
                <InputError :message="error" />

                <DialogFooter>
                    <Button
                        type="button"
                        variant="ghost"
                        @click="step.cancel()"
                        >{{ t('ui.common.cancel') }}</Button
                    >
                    <Button
                        type="submit"
                        :disabled="
                            processing ||
                            (method === 'totp' && code.trim().length < 6) ||
                            (method === 'password' && !password)
                        "
                        data-test="step-up-confirm"
                    >
                        <Spinner v-if="processing" class="size-4" />
                        {{
                            method === 'passkey'
                                ? t('guard.stepup.use_passkey')
                                : t('guard.stepup.confirm')
                        }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
