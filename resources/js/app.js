import './bootstrap';
import '@hotwired/turbo';
import Alpine from 'alpinejs';

import { registerTurboCore } from './core/turbo';
import { registerSessionTimeout } from './core/session-timeout';

import { registerHelpersStore } from './stores/helpers-store';
import { registerSidebarStore } from './stores/sidebar-store';
import { registerKeyboardStore } from './stores/keyboard-store';
import { registerNavigationStore } from './stores/navigation-store';
import { registerNotificationStore } from './stores/notification-store';
import { registerFormProtectionStore } from './stores/form-protection-store';
import { registerPageLoadingStore } from './stores/page-loading-store';
import { registerAnalisisNilaiStore } from './stores/analisis-nilai-store';
import { registerContentLoadingStore } from './stores/content-loading-store';

import { registerFormDiagnostics } from './features/form-diagnostics';
import { registerSidebarFeatures } from './features/sidebar';
import { registerTopbarFeatures } from './features/topbar';
import { registerDashboard } from './features/dashboard';
import { registerSettingsModalFeatures } from './features/settings-modal';
import { registerLiveList } from './features/live-list';
import { registerBulkDelete } from './features/bulk-delete';

import { registerFormProtectionComponent } from './components/form-protection';

const loadedDynamicModules = new Set();
let flowbiteLoader = null;

const flowbiteSelectors = [
    '[data-drawer-target]',
    '[data-drawer-toggle]',
    '[data-dropdown-toggle]',
    '[data-modal-target]',
    '[data-modal-toggle]',
];

const pageLoaders = {
    'add-subject': () => import('./pages/add-subject').then(module => module.initAddSubjectPage()),
    'edit-subject': () => import('./pages/edit-subject').then(module => module.initEditSubjectPage()),
    'admin-report': () => import('./pages/admin-report').then(module => module.initAdminReportPage()),
    'pengajar-input-score': () => import('./pages/pengajar-input-score').then(module => module.initPengajarInputScorePage()),
    'admin-dashboard': () => import('./pages/admin-dashboard').then(module => module.initAdminDashboardPage()),
    'pengajar-dashboard': () => import('./pages/pengajar-dashboard').then(module => module.initPengajarDashboardPage()),
    'wali-dashboard': () => import('./pages/wali-dashboard').then(module => module.initWaliDashboardPage()),
    'pengajar-score': () => import('./pages/pengajar-score').then(module => module.initPengajarScorePage()),
    'kenaikan-kelas-show': () => import('./pages/kenaikan-kelas-show-siswa').then(module => module.initKenaikanKelasShowPage()),
    'pengajar-add-tp': () => import('./pages/pengajar-add-tp').then(module => module.initPengajarAddTpPage()),
    'create-teacher': () => import('./pages/create-teacher').then(module => module.initCreateTeacherPage()),
    'edit-teacher': () => import('./pages/edit-teacher').then(module => module.initEditTeacherPage()),
    'pengajar-add-subject': () => import('./pages/pengajar-add-subject').then(module => module.initPengajarAddSubjectPage()),
    'pengajar-edit-subject': () => import('./pages/pengajar-edit-subject').then(module => module.initPengajarEditSubjectPage()),
    'admin-add-tp': () => import('./pages/admin-add-tp').then(module => module.initAdminAddTpPage()),
    'add-student': () => import('./pages/add-student').then(module => module.initAddStudentPage()),
    'edit-student': () => import('./pages/edit-student').then(module => module.initEditStudentPage()),
    'kenaikan-kelas-index': () => import('./pages/kenaikan-kelas-index').then(module => module.initKenaikanKelasIndexPage()),
    'tahun-ajaran-create': () => import('./pages/tahun-ajaran-create').then(module => module.initTahunAjaranCreatePage()),
    'tahun-ajaran-edit': () => import('./pages/tahun-ajaran-edit').then(module => module.initTahunAjaranEditPage()),
    'tahun-ajaran-copy': () => import('./pages/tahun-ajaran-copy').then(module => module.initTahunAjaranCopyPage()),
    'tahun-ajaran-index': () => import('./pages/tahun-ajaran-index').then(module => module.initTahunAjaranIndexPage()),
    'admin-profile': () => import('./pages/admin-profile').then(module => module.initAdminProfilePage()),
    'edit-class': () => import('./pages/edit-class').then(module => module.initEditClassPage()),
    'staging-simulation': () => import('./pages/staging-simulation').then(module => module.initStagingSimulationPage()),
};

function shouldLoadFlowbite() {
    return Boolean(document.querySelector(flowbiteSelectors.join(', ')));
}

async function ensureFlowbiteLoaded() {
    if (typeof window.initFlowbite === 'function') {
        window.initFlowbite();
        return window.initFlowbite;
    }

    if (!flowbiteLoader) {
        flowbiteLoader = import('flowbite')
            .then(module => {
                const initFlowbite = module.initFlowbite;

                if (typeof initFlowbite === 'function') {
                    window.initFlowbite = initFlowbite;
                    return initFlowbite;
                }

                return null;
            })
            .catch(error => {
                console.error('Failed to lazy load Flowbite:', error);
                flowbiteLoader = null;
                return null;
            });
    }

    const initFlowbite = await flowbiteLoader;
    if (typeof initFlowbite === 'function') {
        initFlowbite();
    }

    return initFlowbite;
}

function bindFlowbiteLoader() {
    window.ensureFlowbiteLoaded = ensureFlowbiteLoaded;

    const loadFlowbiteIfNeeded = () => {
        if (shouldLoadFlowbite()) {
            ensureFlowbiteLoaded();
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', loadFlowbiteIfNeeded);
    } else {
        loadFlowbiteIfNeeded();
    }

    document.addEventListener('turbo:load', loadFlowbiteIfNeeded);
}

async function registerDynamicModuleOnce(key, loader) {
    if (loadedDynamicModules.has(key)) {
        return;
    }

    await loader();
    loadedDynamicModules.add(key);
}

function getConditionalModules() {
    return [
        {
            key: 'report-store',
            shouldLoad: () => Boolean(document.querySelector('#admin-report-page, [x-data="raporManager"], [x-data="reportTemplateManager"]')),
            load: async () => {
                const module = await import('./stores/report-store');
                module.registerReportStore();
            },
        },
        {
            key: 'notification-handler',
            shouldLoad: () => Boolean(document.querySelector('[x-data="notificationHandler"]')),
            load: async () => {
                const module = await import('./features/notification-handler');
                module.registerNotificationHandler();
            },
        },
        {
            key: 'report-template-manager',
            shouldLoad: () => Boolean(document.querySelector('[x-data="reportTemplateManager"]')),
            load: async () => {
                const module = await import('./features/report-template-manager');
                module.registerReportTemplateFeatures();
            },
        },
        {
            key: 'bobot-nilai-form',
            shouldLoad: () => Boolean(document.querySelector('[x-data="bobotNilaiForm"]')),
            load: async () => {
                const module = await import('./features/bobot-nilai-form');
                module.registerBobotNilaiForm();
            },
        },
        {
            key: 'kkm-form',
            shouldLoad: () => Boolean(document.querySelector('[x-data="kkmForm"]')),
            load: async () => {
                const module = await import('./features/kkm-form');
                module.registerKkmForm();
            },
        },
        {
            key: 'placeholder-guide',
            shouldLoad: () => Boolean(document.querySelector('[x-data="placeholderGuide"]')),
            load: async () => {
                const module = await import('./features/placeholder-guide');
                module.registerPlaceholderGuide();
            },
        },
        {
            key: 'rapor-manager',
            shouldLoad: () => Boolean(document.querySelector('[x-data="raporManager"]')),
            load: async () => {
                const module = await import('./features/rapor-manager');
                module.registerRaporManager();
            },
        },
        {
            key: 'gemini-chat',
            shouldLoad: () => Boolean(document.querySelector('[x-data="geminiChat"]')),
            load: async () => {
                const [storeModule, chatModule] = await Promise.all([
                    import('./stores/gemini-store'),
                    import('./features/gemini-chat'),
                ]);

                storeModule.registerGeminiStore();
                chatModule.registerGeminiChat();
            },
        },
        {
            key: 'help-center',
            shouldLoad: () => Boolean(document.querySelector('[x-data="helpCenter"]')),
            load: async () => {
                const module = await import('./features/help-center');
                module.registerHelpCenter();
            },
        },
        {
            key: 'gemini-chat-debug',
            shouldLoad: () => Boolean(document.querySelector('[x-data="geminiChatDebug"]')),
            load: async () => {
                const module = await import('./features/gemini-chat-debug');
                module.registerGeminiChatDebug();
            },
        },
    ];
}

async function loadConditionalModules() {
    const modules = getConditionalModules()
        .filter(module => module.shouldLoad())
        .map(module => registerDynamicModuleOnce(module.key, module.load));

    await Promise.all(modules);
}

async function loadCurrentPageModule() {
    const pageEl = document.querySelector('[data-page]');
    const pageName = pageEl?.dataset?.page;

    if (!pageName || !pageLoaders[pageName]) return;
    await pageLoaders[pageName]();
}

async function hydrateCurrentPage() {
    await loadConditionalModules();
    await loadCurrentPageModule();
}

window.Alpine = Alpine;
window.__loadCurrentPageModule = hydrateCurrentPage;

bindFlowbiteLoader();

registerTurboCore();
registerDashboard();
registerFormDiagnostics();
registerTopbarFeatures();
registerSettingsModalFeatures();
registerLiveList();
registerBulkDelete();

registerHelpersStore();
registerSidebarStore();
registerKeyboardStore();
registerNavigationStore();
registerFormProtectionStore();
registerFormProtectionComponent();
registerNotificationStore();
registerSessionTimeout();
registerPageLoadingStore();
registerAnalisisNilaiStore();
registerContentLoadingStore();
registerSidebarFeatures();

async function bootstrapApp() {
    await hydrateCurrentPage();

    if (!window.alpineInitialized) {
        Alpine.start();
        window.alpineInitialized = true;
    }
}

bootstrapApp().catch(error => {
    console.error(`Failed to bootstrap page module for ${document.querySelector('[data-page]')?.dataset?.page || 'unknown page'}`, error);
});
