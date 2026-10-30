<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import {
    LogOut,
    Palette,
    Scale,
    ShieldCheck,
    ShieldUser,
    UserRound,
} from '@lucide/vue';
import UserAvatar from '@/components/common/UserAvatar.vue';
import {
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuSub,
    DropdownMenuSubContent,
    DropdownMenuSubTrigger,
} from '@/components/ui/dropdown-menu';
import { endpoints } from '@/lib/endpoints';
import { t } from '@/lib/i18n';
import { legalDocIcon, legalDocLabelKey, PUBLIC_LEGAL_DOCS } from '@/lib/legal';
import { logout } from '@/routes';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import type { User } from '@/types';

defineProps<{ user: User }>();

const handleLogout = () => {
    router.flushAll();
};
</script>

<template>
    <DropdownMenuLabel class="p-0 font-normal">
        <div class="flex items-center gap-2.5 px-2 py-2 text-left text-sm">
            <UserAvatar :name="user.name" :src="user.avatar" />
            <div class="grid min-w-0 flex-1 leading-tight">
                <span class="truncate font-semibold">{{ user.name }}</span>
                <span class="truncate text-xs text-muted-foreground">{{
                    user.email
                }}</span>
            </div>
        </div>
    </DropdownMenuLabel>
    <DropdownMenuSeparator />
    <DropdownMenuGroup>
        <DropdownMenuItem as-child>
            <Link class="w-full" :href="editProfile()" prefetch>
                <UserRound class="size-4" aria-hidden="true" />
                {{ t('ui.settings.nav.profile') }}
            </Link>
        </DropdownMenuItem>
        <DropdownMenuItem as-child>
            <Link class="w-full" :href="editSecurity()">
                <ShieldCheck class="size-4" aria-hidden="true" />
                {{ t('ui.settings.nav.security') }}
            </Link>
        </DropdownMenuItem>
        <DropdownMenuItem as-child>
            <Link class="w-full" :href="editAppearance()">
                <Palette class="size-4" aria-hidden="true" />
                {{ t('ui.settings.nav.appearance') }}
            </Link>
        </DropdownMenuItem>
        <DropdownMenuItem as-child>
            <Link
                class="w-full"
                :href="endpoints.privacy.show()"
                data-test="user-menu-privacy"
            >
                <ShieldUser class="size-4" aria-hidden="true" />
                {{ t('ui.settings.nav.privacy') }}
            </Link>
        </DropdownMenuItem>
    </DropdownMenuGroup>
    <DropdownMenuSeparator />
    <DropdownMenuSub>
        <DropdownMenuSubTrigger data-test="user-menu-legal">
            <Scale class="size-4 text-muted-foreground" aria-hidden="true" />
            {{ t('ui.legal.menu') }}
        </DropdownMenuSubTrigger>
        <DropdownMenuSubContent class="min-w-52">
            <DropdownMenuItem
                v-for="key in PUBLIC_LEGAL_DOCS"
                :key="key"
                as-child
            >
                <Link class="w-full" :href="endpoints.legal(key)">
                    <component
                        :is="legalDocIcon(key)"
                        class="size-4"
                        aria-hidden="true"
                    />
                    {{ t(legalDocLabelKey(key)) }}
                </Link>
            </DropdownMenuItem>
            <DropdownMenuSeparator />
            <DropdownMenuItem as-child>
                <Link class="w-full" :href="endpoints.security()">
                    <ShieldCheck class="size-4" aria-hidden="true" />
                    {{ t('ui.legal.security') }}
                </Link>
            </DropdownMenuItem>
            <DropdownMenuItem as-child>
                <Link class="w-full" :href="endpoints.legalPages.index()">
                    <Scale class="size-4" aria-hidden="true" />
                    {{ t('ui.legal.all') }}
                </Link>
            </DropdownMenuItem>
        </DropdownMenuSubContent>
    </DropdownMenuSub>
    <DropdownMenuSeparator />
    <DropdownMenuItem as-child>
        <Link
            class="w-full"
            :href="logout()"
            as="button"
            data-test="logout-button"
            @click="handleLogout"
        >
            <LogOut class="size-4" aria-hidden="true" />
            {{ t('ui.shell.log_out') }}
        </Link>
    </DropdownMenuItem>
</template>
