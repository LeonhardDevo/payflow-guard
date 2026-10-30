import type { MarketingConfig } from '@/components/marketing/types';
import { endpoints } from '@/lib/endpoints';

export const marketingConfig: MarketingConfig = {
    nav: [
        { label: 'marketing.nav.features', href: '/#features', anchor: true },
        { label: 'marketing.nav.how', href: '/#how-it-works', anchor: true },
        { label: 'marketing.nav.pricing', href: endpoints.pricing() },
        { label: 'marketing.nav.security', href: endpoints.security() },
        { label: 'marketing.nav.faq', href: '/#faq', anchor: true },
    ],
    footer: [
        {
            title: 'marketing.footer.product',
            links: [
                {
                    label: 'marketing.nav.features',
                    href: '/#features',
                    anchor: true,
                },
                {
                    label: 'marketing.nav.how',
                    href: '/#how-it-works',
                    anchor: true,
                },
                { label: 'marketing.nav.pricing', href: endpoints.pricing() },
                { label: 'marketing.nav.faq', href: '/#faq', anchor: true },
            ],
        },
        {
            title: 'marketing.footer.trust',
            links: [
                { label: 'marketing.nav.security', href: endpoints.security() },
                { label: 'ui.legal.docs.ai', href: endpoints.legal('ai') },
                {
                    label: 'ui.legal.docs.subprocessors',
                    href: endpoints.legal('subprocessors'),
                },
            ],
        },
        {
            title: 'marketing.footer.legal',
            links: [
                {
                    label: 'ui.legal.docs.terms',
                    href: endpoints.legal('terms'),
                },
                {
                    label: 'ui.legal.docs.privacy',
                    href: endpoints.legal('privacy'),
                },
                {
                    label: 'ui.legal.docs.cookies',
                    href: endpoints.legal('cookies'),
                },
                { label: 'ui.legal.docs.dpa', href: endpoints.legal('dpa') },
                { label: 'ui.legal.docs.aup', href: endpoints.legal('aup') },
                {
                    label: 'marketing.footer.all_legal',
                    href: endpoints.legalPages.index(),
                },
            ],
        },
    ],
};
