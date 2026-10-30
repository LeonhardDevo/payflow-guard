import type { Directive } from 'vue';
import type { BreadcrumbItem } from '@/types/navigation';
import type { SharedProps } from '@/types/shared';
import type { FlashToast } from '@/types/ui';

declare module 'vite/client' {
    interface ImportMetaEnv {
        readonly VITE_APP_NAME: string;
        [key: string]: string | boolean | undefined;
    }

    interface ImportMeta {
        readonly env: ImportMetaEnv;
        readonly glob: <T>(pattern: string) => Record<string, () => Promise<T>>;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: SharedProps & { [key: string]: unknown };
        flashDataType: { toast?: FlashToast; [key: string]: unknown };
        layoutProps: {
            breadcrumbs?: BreadcrumbItem[];
            title?: string;
            description?: string;
        };
    }
}

declare module 'vue' {
    interface GlobalDirectives {
        vFocus: Directive<HTMLElement, boolean | undefined>;
    }
}
