<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { MotionConfig } from 'motion-v';
import { computed } from 'vue';
import { marketingConfig } from '@/components/brand/marketing-config';
import MarketingFooter from '@/components/marketing/MarketingFooter.vue';
import MarketingNav from '@/components/marketing/MarketingNav.vue';
import CookieNotice from '@/components/legal/CookieNotice.vue';
import AppToaster from '@/components/shell/AppToaster.vue';
import SkipLink from '@/components/shell/SkipLink.vue';

const page = usePage();

const canRegister = computed(() =>
    typeof page.props.canRegister === 'boolean' ? page.props.canRegister : true,
);
</script>

<template>
    <MotionConfig reduced-motion="user">
        <SkipLink />
        <div class="flex min-h-svh flex-col">
            <MarketingNav
                :links="marketingConfig.nav"
                :can-register="canRegister"
            />
            <main
                id="main-content"
                tabindex="-1"
                class="flex-1 focus:outline-none"
            >
                <slot />
            </main>
            <MarketingFooter :columns="marketingConfig.footer" />
        </div>
        <AppToaster />
        <CookieNotice />
    </MotionConfig>
</template>
