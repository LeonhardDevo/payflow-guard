import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import i18n from 'laravel-vue-i18n/vite';
import { defineConfig, lazyPlugins } from 'vite-plus';

/*
 * Fonts are self-hosted through @fontsource-variable/* packages imported in
 * resources/css/brand.css — no font CDN (Bunny/Google) is ever contacted,
 * neither at build time nor at runtime (PLATFORM.md §0.1).
 */
export default defineConfig({
    plugins: lazyPlugins(() => [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.ts'],
            refresh: true,
        }),
        inertia(),
        tailwindcss(),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        wayfinder({
            formVariants: true,
        }),
        // lang/{en,ru}/*.php -> lang/php_{en,ru}.json (merged with lang/{en,ru}.json at runtime)
        i18n(),
    ]),
    build: {
        // The largest chunk is the tree-shaken ECharts renderer (~570 kB,
        // ~195 kB gzip), loaded lazily by ThemedChart only on chart pages.
        chunkSizeWarningLimit: 640,
    },
    optimizeDeps: {
        // Chart deps are only reached through the lazy EChart.vue import, so the dev
        // optimizer would discover them on the first chart page and bundle them in a
        // second pass with its own Vue copy ("Missing ref owner context" / ECharts
        // "invalid dom" until a full reload). Pre-bundle them with everything else.
        include: [
            'echarts/core',
            'echarts/charts',
            'echarts/components',
            'echarts/renderers',
            'vue-echarts',
        ],
    },
    server: {
        // IPv4 loopback on purpose: on Windows/Node "localhost" may bind to [::1], and an
        // IPv6 literal in public/hot cannot be allowed by the CSP (dev stylesheets get
        // blocked). `vp dev --host <addr>` still overrides this.
        host: '127.0.0.1',
        watch: {
            ignored: [
                '**/.agents/**',
                '**/.claude/**',
                '**/.cursor/**',
                '**/.junie/**',
                '**/vendor/**',
                '**/lang/php_*.json',
            ],
        },
    },
    test: {
        include: ['resources/js/**/*.test.ts'],
        environment: 'node',
    },
    lint: {
        ignorePatterns: [
            'vendor/**',
            'node_modules/**',
            'public/**',
            'bootstrap/ssr/**',
            'tailwind.config.js',
            'resources/js/actions/**',
            'resources/js/components/ui/**',
            'resources/js/routes/**',
            'resources/js/wayfinder/**',
        ],
        options: {
            denyWarnings: true,
            typeAware: true,
        },
    },
    fmt: {
        printWidth: 80,
        tabWidth: 4,
        singleQuote: true,
        semi: true,
        singleAttributePerLine: false,
        htmlWhitespaceSensitivity: 'css',
        ignorePatterns: [
            '.github/**',
            'composer.json',
            'lang/**',
            'resources/js/components/ui/**',
            'resources/views/mail/*',
        ],
        sortTailwindcss: {
            functions: ['clsx', 'cn', 'cva'],
            stylesheet: 'resources/css/app.css',
        },
    },
});
