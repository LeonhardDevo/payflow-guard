<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { CreditCard, Hourglass, TriangleAlert } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useCan } from '@/composables/useCan';
import { useFormat } from '@/composables/useFormat';
import { endpoints } from '@/lib/endpoints';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';

const page = usePage();
const fmt = useFormat();
const { can } = useCan();

const alert = computed(() => page.props.billing?.alert ?? null);
const canManage = computed(() => can('billing.manage'));
const portalBusy = ref(false);
const pastDue = computed(() => alert.value?.status === 'past_due');
const portalAvailable = computed(
    () => pastDue.value && page.props.billing?.driver !== 'fake',
);

function openPortal(): void {
    router.post(
        endpoints.settings.billing.portal().url,
        {},
        {
            preserveScroll: true,
            onStart: () => {
                portalBusy.value = true;
            },
            onFinish: () => {
                portalBusy.value = false;
            },
        },
    );
}
</script>

<template>
    <div
        v-if="alert"
        :class="
            cn(
                'mx-4 mt-4 flex flex-col gap-3 rounded-2xl border p-4 text-sm sm:flex-row sm:items-center sm:justify-between md:mx-6',
                pastDue
                    ? 'border-warning/40 bg-warning-soft text-warning-soft-foreground'
                    : 'border-info/30 bg-info-soft text-info-soft-foreground',
            )
        "
        :role="pastDue ? 'alert' : 'status'"
        data-test="billing-alert"
        :data-status="alert.status"
    >
        <div class="flex items-start gap-3">
            <TriangleAlert
                v-if="pastDue"
                class="mt-0.5 size-4 shrink-0"
                aria-hidden="true"
            />
            <Hourglass
                v-else
                class="mt-0.5 size-4 shrink-0"
                aria-hidden="true"
            />
            <div>
                <p class="font-semibold">
                    {{
                        pastDue
                            ? t('ui.billing.alert.past_due_title')
                            : t('ui.billing.alert.trial_ending_title')
                    }}
                </p>
                <p class="opacity-90">
                    {{
                        pastDue
                            ? t('ui.billing.alert.past_due_body')
                            : t('ui.billing.alert.trial_ending_body', {
                                  date: alert.at
                                      ? fmt.date(alert.at, 'long')
                                      : '',
                              })
                    }}
                </p>
            </div>
        </div>
        <div v-if="canManage" class="flex shrink-0 gap-2">
            <Button
                v-if="portalAvailable"
                size="sm"
                :disabled="portalBusy"
                data-test="billing-alert-portal"
                @click="openPortal"
            >
                <Spinner v-if="portalBusy" />
                <CreditCard v-else class="size-4" aria-hidden="true" />
                {{ t('ui.billing.alert.update_payment') }}
            </Button>
            <Button v-else size="sm" variant="outline" as-child>
                <Link :href="endpoints.settings.billing.show().url">
                    {{ t('ui.billing.alert.review') }}
                </Link>
            </Button>
        </div>
    </div>
</template>
