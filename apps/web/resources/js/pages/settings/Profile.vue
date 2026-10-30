<script setup lang="ts">
import { Form, Head, Link, usePage } from '@inertiajs/vue3';
import { MailWarning, UserRound } from '@lucide/vue';
import { computed } from 'vue';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import SectionCard from '@/components/common/SectionCard.vue';
import UserAvatar from '@/components/common/UserAvatar.vue';
import DeleteUser from '@/components/DeleteUser.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/lib/i18n';
import { edit } from '@/routes/profile';
import { send } from '@/routes/verification';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'ui.settings.title', href: edit() },
            { title: 'ui.settings.nav.profile', href: edit() },
        ],
    },
});

defineProps<{ mustVerifyEmail?: boolean; status?: string }>();

const page = usePage();
const user = computed(() => page.props.auth.user);
</script>

<template>
    <Head :title="t('ui.settings.profile.title')" />

    <SectionCard
        v-if="user"
        :icon="UserRound"
        :title="t('ui.settings.profile.title')"
        :description="t('ui.settings.profile.description')"
    >
        <Form
            v-bind="ProfileController.update.form()"
            class="space-y-6"
            :options="{ preserveScroll: true }"
            v-slot="{ errors, processing, recentlySuccessful }"
        >
            <div class="flex items-center gap-4">
                <UserAvatar :name="user.name" :src="user.avatar" size="lg" />
                <div class="min-w-0">
                    <p class="truncate font-semibold">{{ user.name }}</p>
                    <p class="truncate text-sm text-muted-foreground">
                        {{ user.email }}
                    </p>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="name">{{ t('ui.fields.name') }}</Label>
                    <Input
                        id="name"
                        name="name"
                        :default-value="user.name"
                        required
                        autocomplete="name"
                        :placeholder="t('ui.fields.name_placeholder')"
                        :aria-invalid="Boolean(errors.name)"
                    />
                    <InputError :message="errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label for="email">{{ t('ui.fields.email') }}</Label>
                    <Input
                        id="email"
                        type="email"
                        name="email"
                        :default-value="user.email"
                        required
                        autocomplete="username"
                        placeholder="email@example.com"
                        :aria-invalid="Boolean(errors.email)"
                    />
                    <InputError :message="errors.email" />
                </div>
            </div>

            <div
                v-if="mustVerifyEmail && !user.email_verified_at"
                class="flex gap-3 rounded-xl border border-warning/40 bg-warning-soft p-4 text-sm text-warning-soft-foreground"
            >
                <MailWarning
                    class="mt-0.5 size-4 shrink-0"
                    aria-hidden="true"
                />
                <div class="space-y-1">
                    <p>
                        {{ t('ui.settings.profile.unverified') }}
                        <Link
                            :href="send()"
                            as="button"
                            class="font-semibold underline underline-offset-4"
                        >
                            {{ t('ui.settings.profile.resend') }}
                        </Link>
                    </p>
                    <p
                        v-if="status === 'verification-link-sent'"
                        class="font-medium"
                    >
                        {{ t('ui.settings.profile.link_sent') }}
                    </p>
                </div>
            </div>

            <div class="flex items-center gap-3">
                <Button
                    :disabled="processing"
                    data-test="update-profile-button"
                >
                    <Spinner v-if="processing" />
                    {{ t('ui.common.save') }}
                </Button>
                <Transition
                    enter-active-class="transition ease-out"
                    enter-from-class="opacity-0"
                    leave-active-class="transition ease-in"
                    leave-to-class="opacity-0"
                >
                    <p
                        v-show="recentlySuccessful"
                        class="text-sm text-muted-foreground"
                        role="status"
                    >
                        {{ t('ui.common.saved') }}
                    </p>
                </Transition>
            </div>
        </Form>
    </SectionCard>

    <DeleteUser />
</template>
