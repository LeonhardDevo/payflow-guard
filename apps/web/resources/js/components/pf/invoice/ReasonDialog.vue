<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
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
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { t } from '@/lib/i18n';

const props = withDefaults(
    defineProps<{
        url: string;
        title: string;
        description?: string | null;
        field?: string;
        label: string;
        confirmLabel: string;
        destructive?: boolean;
        min?: number;
        max?: number;
        summary?: string | null;
    }>(),
    {
        description: null,
        field: 'reason',
        destructive: false,
        min: 5,
        max: 255,
        summary: null,
    },
);

const open = defineModel<boolean>('open', { default: false });

const form = useForm<Record<string, string>>({ value: '' });

watch(open, (value) => {
    if (value) {
        form.reset();
        form.clearErrors();
    }
});

function submit(): void {
    form.transform((data) => ({ [props.field]: data.value })).post(props.url, {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>{{ title }}</DialogTitle>
                <DialogDescription v-if="description">{{
                    description
                }}</DialogDescription>
            </DialogHeader>
            <form class="grid gap-4" @submit.prevent="submit">
                <p
                    v-if="summary"
                    class="rounded-xl border bg-muted/40 px-3 py-2 text-sm font-medium tabular-nums"
                >
                    {{ summary }}
                </p>
                <div class="grid gap-2">
                    <Label for="reason-input">{{ label }}</Label>
                    <Textarea
                        id="reason-input"
                        v-model="form.value"
                        rows="3"
                        :maxlength="max"
                        :aria-invalid="Boolean(form.errors[field])"
                        data-test="reason-input"
                    />
                    <p v-if="min > 0" class="text-xs text-muted-foreground">
                        {{ t('pf.common.min_chars', { min }) }}
                    </p>
                    <InputError :message="form.errors[field]" />
                </div>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="ghost"
                        @click="open = false"
                        >{{ t('ui.common.cancel') }}</Button
                    >
                    <Button
                        type="submit"
                        :variant="destructive ? 'destructive' : 'default'"
                        :disabled="
                            form.processing || form.value.trim().length < min
                        "
                        data-test="reason-confirm"
                    >
                        <Spinner v-if="form.processing" class="size-4" />
                        {{ confirmLabel }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
