<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { RefreshCw, TriangleAlert } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { humanize, t, tOr } from '@/lib/i18n';

const CONFLICTS = new Set([
    'not_pending',
    'details_changed',
    'invalid_payout_transition',
    'payout_immutable',
    'hold_not_cleared',
    'confirmation_not_pending',
]);

const page = usePage();
const error = computed(() => page.props.pf?.guard_error ?? null);
const open = ref(false);

watch(
    error,
    (value) => {
        open.value = Boolean(value && CONFLICTS.has(value.code));
    },
    { immediate: true },
);

function reload(): void {
    open.value = false;
    router.reload();
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-md" data-test="guard-conflict">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2">
                    <TriangleAlert
                        class="size-5 text-warning"
                        aria-hidden="true"
                    />
                    {{ t('guard.overview.conflict_title') }}
                </DialogTitle>
                <DialogDescription>
                    {{
                        error
                            ? tOr(
                                  `guard.overview.errors.${error.code}`,
                                  humanize(error.code),
                              )
                            : ''
                    }}
                </DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <Button @click="reload">
                    <RefreshCw class="size-4" aria-hidden="true" />
                    {{ t('guard.overview.reload') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
