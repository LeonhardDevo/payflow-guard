<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Mail, Paperclip } from '@lucide/vue';
import { watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import { usePf } from '@/composables/pf/usePf';
import { t } from '@/lib/i18n';
import { send } from '@/routes/invoices';
import type { InvoiceDetail } from '@/types/payflow';

const props = defineProps<{ invoice: InvoiceDetail }>();
const open = defineModel<boolean>('open', { default: false });
const pf = usePf();

const form = useForm({ message: '', cc_me: false });

watch(open, (value) => {
    if (value) {
        form.reset();
        form.clearErrors();
    }
});

function submit(): void {
    form.transform((data) => ({
        message: data.message || null,
        cc_me: data.cc_me,
    })).post(send(props.invoice.id).url, {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>{{
                    invoice.sent_at
                        ? t('pf.send.resend_title')
                        : t('pf.send.title')
                }}</DialogTitle>
                <DialogDescription>{{ t('pf.send.body') }}</DialogDescription>
            </DialogHeader>
            <form class="grid gap-4" @submit.prevent="submit">
                <div class="rounded-xl border bg-muted/30 p-3 text-sm">
                    <p class="flex items-center gap-2">
                        <Mail
                            class="size-4 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <span class="font-medium">{{
                            invoice.client.email ?? t('pf.client.no_email')
                        }}</span>
                    </p>
                    <p
                        class="mt-1 flex items-center gap-2 text-muted-foreground"
                    >
                        <Paperclip class="size-4" aria-hidden="true" />
                        {{
                            t('pf.send.attachment', {
                                number: invoice.number ?? '',
                                amount: pf.money(invoice.balance_due),
                            })
                        }}
                    </p>
                </div>
                <div class="grid gap-2">
                    <Label for="send-message">{{ t('pf.send.message') }}</Label>
                    <Textarea
                        id="send-message"
                        v-model="form.message"
                        rows="4"
                        maxlength="1000"
                        :placeholder="t('pf.send.message_placeholder')"
                    />
                    <InputError :message="form.errors.message" />
                </div>
                <Label class="flex items-center gap-3 text-sm font-normal">
                    <Checkbox v-model="form.cc_me" />
                    {{ t('pf.send.cc_me') }}
                </Label>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="ghost"
                        @click="open = false"
                        >{{ t('ui.common.cancel') }}</Button
                    >
                    <Button
                        type="submit"
                        :disabled="form.processing || !invoice.client.email"
                        data-test="send-confirm"
                    >
                        <Spinner v-if="form.processing" class="size-4" />
                        {{ t('pf.actions.send_invoice') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
