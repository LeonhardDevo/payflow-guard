<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { TriangleAlert } from '@lucide/vue';
import { useTemplateRef } from 'vue';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import SectionCard from '@/components/common/SectionCard.vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/lib/i18n';

const passwordInput = useTemplateRef('passwordInput');
</script>

<template>
    <SectionCard
        tone="danger"
        :icon="TriangleAlert"
        :title="t('ui.settings.delete_account.title')"
        :description="t('ui.settings.delete_account.description')"
    >
        <div
            class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
        >
            <p class="text-sm text-muted-foreground">
                {{ t('ui.settings.delete_account.warning') }}
            </p>
            <Dialog>
                <DialogTrigger as-child>
                    <Button
                        variant="destructive"
                        class="shrink-0"
                        data-test="delete-user-button"
                    >
                        {{ t('ui.settings.delete_account.button') }}
                    </Button>
                </DialogTrigger>
                <DialogContent>
                    <Form
                        v-bind="ProfileController.destroy.form()"
                        reset-on-success
                        :options="{ preserveScroll: true }"
                        class="space-y-6"
                        v-slot="{ errors, processing, reset, clearErrors }"
                        @error="() => passwordInput?.focus()"
                    >
                        <DialogHeader class="space-y-3">
                            <DialogTitle>{{
                                t('ui.settings.delete_account.confirm_title')
                            }}</DialogTitle>
                            <DialogDescription>
                                {{
                                    t('ui.settings.delete_account.confirm_body')
                                }}
                            </DialogDescription>
                        </DialogHeader>

                        <div class="grid gap-2">
                            <Label for="delete-password" class="sr-only">{{
                                t('ui.fields.password')
                            }}</Label>
                            <PasswordInput
                                id="delete-password"
                                ref="passwordInput"
                                name="password"
                                autocomplete="current-password"
                                :placeholder="t('ui.fields.password')"
                            />
                            <InputError :message="errors.password" />
                        </div>

                        <DialogFooter class="gap-2">
                            <DialogClose as-child>
                                <Button
                                    variant="secondary"
                                    @click="
                                        () => {
                                            clearErrors();
                                            reset();
                                        }
                                    "
                                >
                                    {{ t('ui.common.cancel') }}
                                </Button>
                            </DialogClose>
                            <Button
                                type="submit"
                                variant="destructive"
                                :disabled="processing"
                                data-test="confirm-delete-user-button"
                            >
                                <Spinner v-if="processing" />
                                {{ t('ui.settings.delete_account.button') }}
                            </Button>
                        </DialogFooter>
                    </Form>
                </DialogContent>
            </Dialog>
        </div>
    </SectionCard>
</template>
