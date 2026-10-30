import type { InertiaLinkProps } from '@inertiajs/vue3';
import type { LucideIcon } from '@lucide/vue';

export type Href = NonNullable<InertiaLinkProps['href']>;

export type BreadcrumbItem = {
    title: string;
    href: Href;
};

export type NavItem = {
    title: string;
    href: Href;
    icon?: LucideIcon;
    isActive?: boolean;
    matchPrefix?: boolean;
    badge?: string | number | null;
};

export type NavGroup = {
    label: string;
    items: NavItem[];
};
