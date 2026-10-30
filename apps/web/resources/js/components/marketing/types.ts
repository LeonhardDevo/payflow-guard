import type { Href } from '@/types/navigation';

export type MarketingLink = {
    label: string;
    href: Href | string;
    anchor?: boolean;
};

export type FooterColumn = {
    title: string;
    links: MarketingLink[];
};

export type MarketingConfig = {
    nav: MarketingLink[];
    footer: FooterColumn[];
};
