<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    Breadcrumb,
    BreadcrumbItem,
    BreadcrumbLink,
    BreadcrumbList,
    BreadcrumbPage,
    BreadcrumbSeparator,
} from '@/components/ui/breadcrumb';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

defineProps<{ breadcrumbs: BreadcrumbItemType[] }>();
</script>

<template>
    <Breadcrumb :aria-label="t('ui.shell.breadcrumbs')">
        <BreadcrumbList class="flex-nowrap">
            <template v-for="(item, index) in breadcrumbs" :key="index">
                <BreadcrumbItem
                    :class="
                        index < breadcrumbs.length - 1
                            ? 'hidden md:inline-flex'
                            : 'min-w-0'
                    "
                >
                    <BreadcrumbPage
                        v-if="index === breadcrumbs.length - 1"
                        class="max-w-[40vw] truncate font-medium md:max-w-xs"
                    >
                        {{ t(item.title) }}
                    </BreadcrumbPage>
                    <BreadcrumbLink v-else as-child>
                        <Link :href="item.href" class="whitespace-nowrap">{{
                            t(item.title)
                        }}</Link>
                    </BreadcrumbLink>
                </BreadcrumbItem>
                <BreadcrumbSeparator
                    v-if="index !== breadcrumbs.length - 1"
                    class="hidden md:inline-flex"
                />
            </template>
        </BreadcrumbList>
    </Breadcrumb>
</template>
