<script setup lang="ts">
import { router, useForm, usePage } from '@inertiajs/vue3';
import { Landmark, ShieldCheck } from '@lucide/vue';
import { computed, ref } from 'vue';
import SectionCard from '@/components/common/SectionCard.vue';
import StatusBadge from '@/components/common/StatusBadge.vue';
import InputError from '@/components/InputError.vue';
import ReasonDialog from '@/components/pf/invoice/ReasonDialog.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { useStepUp } from '@/composables/pf/useStepUp';
import { useFormat } from '@/composables/useFormat';
import { t } from '@/lib/i18n';
import { cancel, confirm, reject, request } from '@/routes/settings/receiving';
import type { CollectionsSettings } from '@/types/payflow';

const props = defineProps<{
    details: CollectionsSettings['receiving_details'];
    canChange: boolean;
    canConfirm: boolean;
}>();

const fmt = useFormat();
const page = usePage();
const stepUp = useStepUp();

const pending = computed(() => props.details.pending);
const mine = computed(
    () => pending.value?.requested_by.id === page.props.auth.user?.id,
);

const editing = ref(false);
const form = useForm({ details: '' });

function submit(): void {
    stepUp.run(() => {
        form.post(request().url, {
            preserveScroll: true,
            onSuccess: () => {
                editing.value = false;
                form.reset();
            },
        });
    }, true);
}

const busy = ref<string | null>(null);

function act(kind: 'confirm' | 'cancel'): void {
    if (!pending.value) {
        return;
    }

    const id = pending.value.id;
    const go = () => {
        busy.value = kind;
        router.post(
            kind === 'confirm' ? confirm(id).url : cancel(id).url,
            {},
            {
                preserveScroll: true,
                onFinish: () => (busy.value = null),
            },
        );
    };

    if (kind === 'confirm') {
        stepUp.run(go, true);
    } else {
        go();
    }
}

const rejectOpen = ref(false);
</script>

<template>
    <SectionCard
        :icon="Landmark"
        :title="t('pf.settings.receiving.title')"
        :description="t('pf.settings.receiving.description')"
    >
        <div class="flex flex-col gap-4" data-test="receiving-details">
            <div>
                <p class="mb-1 text-xs text-muted-foreground">
                    {{ t('pf.settings.receiving.active') }}
                </p>
                <pre
                    v-if="details.active"
                    class="rounded-xl border bg-muted/30 p-3 text-sm whitespace-pre-wrap"
                    >{{ details.active.details }}</pre>
                <p v-else class="text-sm text-muted-foreground">
                    {{ t('pf.settings.receiving.none') }}
                </p>
                <p
                    v-if="details.active?.confirmed_by"
                    class="mt-1 text-xs text-muted-foreground"
                >
                    {{
                        t('pf.settings.receiving.confirmed_by', {
                            name: details.active.confirmed_by.name,
                            date: fmt.date(details.active.confirmed_at),
                        })
                    }}
                </p>
            </div>

            <div
                v-if="pending"
                class="rounded-xl border border-warning/40 bg-warning-soft p-4 text-sm text-warning-soft-foreground"
                data-test="receiving-pending"
            >
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="font-medium">
                        {{
                            t('pf.settings.receiving.pending_title', {
                                name: pending.requested_by.name,
                                date: fmt.dateTime(
                                    pending.requested_at,
                                    'medium',
                                ),
                            })
                        }}
                    </p>
                    <StatusBadge
                        tone="warning"
                        :label="
                            t(`pf.settings.receiving.status.${pending.status}`)
                        "
                    />
                </div>
                <pre
                    class="mt-2 rounded-lg bg-background/70 p-3 whitespace-pre-wrap text-foreground"
                    >{{ pending.details }}</pre>
                <p v-if="pending.effective_at" class="mt-2 text-xs">
                    {{
                        t('pf.settings.receiving.effective', {
                            date: fmt.dateTime(pending.effective_at, 'medium'),
                        })
                    }}
                </p>
                <div class="mt-3 flex flex-wrap gap-2">
                    <Button
                        v-if="
                            canConfirm &&
                            !mine &&
                            pending.status === 'pending_confirmation'
                        "
                        size="sm"
                        :disabled="Boolean(busy)"
                        @click="act('confirm')"
                    >
                        <Spinner v-if="busy === 'confirm'" class="size-4" />
                        <ShieldCheck
                            v-else
                            class="size-4"
                            aria-hidden="true"
                        />{{ t('pf.settings.receiving.confirm') }}
                    </Button>
                    <Button
                        v-if="canConfirm && !mine"
                        size="sm"
                        variant="outline"
                        @click="rejectOpen = true"
                        >{{ t('pf.review.reject') }}</Button
                    >
                    <Button
                        v-if="mine && canChange"
                        size="sm"
                        variant="outline"
                        :disabled="Boolean(busy)"
                        @click="act('cancel')"
                    >
                        <Spinner v-if="busy === 'cancel'" class="size-4" />{{
                            t('pf.settings.receiving.cancel')
                        }}
                    </Button>
                </div>
                <p
                    v-if="mine && pending.status === 'pending_confirmation'"
                    class="mt-2 text-xs"
                >
                    {{ t('pf.settings.receiving.waiting_other') }}
                </p>
            </div>

            <template v-if="canChange && !pending">
                <form
                    v-if="editing"
                    class="grid gap-2"
                    @submit.prevent="submit"
                >
                    <Label for="rd-details">{{
                        t('pf.settings.receiving.new')
                    }}</Label>
                    <Textarea
                        id="rd-details"
                        v-model="form.details"
                        rows="5"
                        maxlength="2000"
                        class="font-mono text-sm"
                        :placeholder="t('pf.settings.receiving.placeholder')"
                    />
                    <p class="text-xs text-muted-foreground">
                        {{ t('pf.settings.receiving.safety') }}
                    </p>
                    <InputError :message="form.errors.details" />
                    <div class="flex gap-2">
                        <Button
                            type="button"
                            variant="ghost"
                            @click="editing = false"
                            >{{ t('ui.common.cancel') }}</Button
                        >
                        <Button
                            type="submit"
                            :disabled="form.processing || !form.details.trim()"
                        >
                            <Spinner v-if="form.processing" class="size-4" />{{
                                t('pf.settings.receiving.request')
                            }}
                        </Button>
                    </div>
                </form>
                <Button
                    v-else
                    variant="outline"
                    class="self-start"
                    data-test="receiving-change"
                    @click="editing = true"
                    >{{ t('pf.settings.receiving.change') }}</Button
                >
            </template>
        </div>
    </SectionCard>

    <ReasonDialog
        v-if="pending"
        v-model:open="rejectOpen"
        :url="reject(pending.id).url"
        :title="t('pf.settings.receiving.reject_title')"
        :label="t('pf.common.reason')"
        :confirm-label="t('pf.review.reject')"
        destructive
        :max="500"
    />
</template>
