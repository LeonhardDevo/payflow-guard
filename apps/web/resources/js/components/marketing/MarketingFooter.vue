<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Mail } from '@lucide/vue';
import { computed } from 'vue';
import type { FooterColumn } from '@/components/marketing/types';
import AppLogo from '@/components/shell/AppLogo.vue';
import LocaleSwitcher from '@/components/shell/LocaleSwitcher.vue';
import ThemeToggle from '@/components/shell/ThemeToggle.vue';
import { t } from '@/lib/i18n';
import { toUrl } from '@/lib/utils';

defineProps<{ columns: FooterColumn[] }>();

const page = usePage();
const year = new Date().getFullYear();
const supportEmail = computed(() => page.props.product?.supportEmail ?? null);
</script>

<template>
    <footer class="border-t bg-card/60" data-test="marketing-footer">
        <div
            class="mx-auto grid max-w-7xl grid-cols-1 gap-10 px-4 py-14 sm:grid-cols-2 sm:px-6 lg:grid-cols-[1.4fr_repeat(3,1fr)] lg:px-8"
        >
            <div class="space-y-4 sm:col-span-2 lg:col-span-1">
                <AppLogo />
                <p class="max-w-xs text-sm text-muted-foreground">
                    {{ t('marketing.footer.tagline') }}
                </p>
                <div class="flex items-center gap-1">
                    <LocaleSwitcher variant="outline" :test-id="null" />
                    <ThemeToggle variant="outline" :test-id="null" />
                </div>
            </div>
            <nav
                v-for="column in columns"
                :key="column.title"
                :aria-label="t(column.title)"
                class="space-y-3"
            >
                <p class="text-sm font-semibold">{{ t(column.title) }}</p>
                <ul class="space-y-1">
                    <li v-for="link in column.links" :key="link.label">
                        <component
                            :is="link.anchor ? 'a' : Link"
                            :href="
                                typeof link.href === 'string'
                                    ? link.href
                                    : toUrl(link.href)
                            "
                            class="inline-flex min-h-8 items-center text-sm text-muted-foreground transition-colors hover:text-foreground pointer-coarse:min-h-11"
                        >
                            {{ t(link.label) }}
                        </component>
                    </li>
                </ul>
            </nav>
        </div>
        <div class="border-t">
            <div
                class="mx-auto flex max-w-7xl flex-col gap-2 px-4 py-5 text-xs text-muted-foreground sm:flex-row sm:items-center sm:justify-between sm:px-6 lg:px-8"
            >
                <p>
                    {{
                        t('marketing.footer.copyright', {
                            year,
                            name: page.props.name,
                        })
                    }}
                </p>
                <p class="flex flex-wrap items-center gap-x-4 gap-y-1">
                    <a
                        v-if="supportEmail"
                        :href="`mailto:${supportEmail}`"
                        class="inline-flex items-center gap-1.5 hover:text-foreground"
                        data-test="footer-contact"
                    >
                        <Mail class="size-3.5" aria-hidden="true" />
                        {{ t('marketing.footer.contact') }}
                    </a>
                    <span>{{ t('marketing.footer.made_for') }}</span>
                </p>
            </div>
        </div>
    </footer>
</template>
