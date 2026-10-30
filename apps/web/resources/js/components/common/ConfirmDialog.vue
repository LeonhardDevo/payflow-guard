<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/lib/i18n';

const props = withDefaults(
    defineProps<{
        title: string;
        description?: string | null;
        confirmLabel?: string | null;
        destructive?: boolean;
        processing?: boolean;
        confirmText?: string | null;
    }>(),
    {
        description: null,
        confirmLabel: null,
        destructive: false,
        processing: false,
        confirmText: null,
    },
);

const open = defineModel<boolean>('open', { default: false });
const emit = defineEmits<{ confirm: [] }>();

const typed = ref('');

watch(open, (value) => {
    if (!value) {
        typed.value = '';
    }
});

const canConfirm = computed(
    () =>
        !props.processing &&
        (!props.confirmText || typed.value.trim() === props.confirmText),
);
</script>

<template>
    <AlertDialog v-model:open="open">
        <AlertDialogContent>
            <AlertDialogHeader>
                <AlertDialogTitle>{{ title }}</AlertDialogTitle>
                <AlertDialogDescription v-if="description">
                    {{ description }}
                </AlertDialogDescription>
            </AlertDialogHeader>

            <div v-if="confirmText" class="grid gap-2">
                <Label for="confirm-text">
                    {{ t('ui.common.type_to_confirm', { text: confirmText }) }}
                </Label>
                <Input
                    id="confirm-text"
                    v-model="typed"
                    autocomplete="off"
                    :placeholder="confirmText"
                />
            </div>

            <slot />

            <AlertDialogFooter>
                <AlertDialogCancel :disabled="processing">
                    {{ t('ui.common.cancel') }}
                </AlertDialogCancel>
                <Button
                    :variant="destructive ? 'destructive' : 'default'"
                    :disabled="!canConfirm"
                    data-test="confirm-dialog-confirm"
                    @click="emit('confirm')"
                >
                    <Spinner v-if="processing" />
                    {{ confirmLabel ?? t('ui.common.confirm') }}
                </Button>
            </AlertDialogFooter>
        </AlertDialogContent>
    </AlertDialog>
</template>
