import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

const storeModules = [
    'resources/js/stores/analisis-nilai-store.js',
    'resources/js/stores/content-loading-store.js',
    'resources/js/stores/form-protection-store.js',
    'resources/js/stores/gemini-store.js',
    'resources/js/stores/helpers-store.js',
    'resources/js/stores/keyboard-store.js',
    'resources/js/stores/navigation-store.js',
    'resources/js/stores/notification-store.js',
    'resources/js/stores/page-loading-store.js',
    'resources/js/stores/report-store.js',
    'resources/js/stores/sidebar-store.js',
];

const featureModules = [
    'resources/js/features/bobot-nilai-form.js',
    'resources/js/features/dashboard.js',
    'resources/js/features/form-diagnostics.js',
    'resources/js/features/gemini-chat-debug.js',
    'resources/js/features/gemini-chat.js',
    'resources/js/features/kkm-form.js',
    'resources/js/features/notification-handler.js',
    'resources/js/features/placeholder-guide.js',
    'resources/js/features/rapor-manager.js',
    'resources/js/features/rapor-manager/core.js',
    'resources/js/features/rapor-manager/pdf.js',
    'resources/js/features/report-template-manager.js',
    'resources/js/features/settings-modal.js',
    'resources/js/features/sidebar.js',
    'resources/js/features/subject-form.js',
    'resources/js/features/topbar.js',
];

const componentModules = [
    'resources/js/components/form-protection.js',
];

const coreModules = [
    'resources/js/bootstrap.js',
    'resources/js/core/session-timeout.js',
    'resources/js/core/turbo.js',
];

function normalize(id) {
    return id.replace(/\\/g, '/');
}

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js'
            ],
            refresh: true,
        }),
    ],
    resolve: {
        alias: {
            '@': '/resources/js',
            '@components': '/resources/js/Components',
        },
    },
    build: {
        rollupOptions: {
            output: {
                manualChunks(id) {
                    const normalizedId = normalize(id);

                    if (normalizedId.includes('/node_modules/alpinejs/')) return 'vendor-alpine';
                    if (normalizedId.includes('/node_modules/flowbite/')) return 'vendor-flowbite';
                    if (normalizedId.includes('/node_modules/docx-preview/')) return 'vendor-docx';
                    if (normalizedId.includes('/node_modules/axios/')) return 'vendor-axios';
                    if (normalizedId.includes('/node_modules/@hotwired/turbo/')) return 'vendor-turbo';
                    if (normalizedId.includes('/node_modules/laravel-echo/') || normalizedId.includes('/node_modules/pusher-js/')) {
                        return 'vendor-realtime';
                    }

                    if (coreModules.some(moduleId => normalizedId.endsWith(moduleId))) return 'app-core';
                    if (storeModules.some(moduleId => normalizedId.endsWith(moduleId))) return 'app-stores';
                    if (featureModules.some(moduleId => normalizedId.endsWith(moduleId))) return 'app-features';
                    if (componentModules.some(moduleId => normalizedId.endsWith(moduleId))) return 'app-components';
                },
            }
        }
    }
});
