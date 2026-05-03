import './bootstrap';
import 'flowbite';
import '@hotwired/turbo';
import Alpine from 'alpinejs';

import { registerTurboCore } from './core/turbo';
import { registerSessionTimeout } from './core/session-timeout';

import { registerGeminiStore } from './stores/gemini-store';
import { registerHelpersStore } from './stores/helpers-store';
import { registerSidebarStore } from './stores/sidebar-store';
import { registerKeyboardStore } from './stores/keyboard-store';
import { registerNavigationStore } from './stores/navigation-store';
import { registerReportStore } from './stores/report-store';
import { registerNotificationStore } from './stores/notification-store';
import { registerFormProtectionStore } from './stores/form-protection-store';
import { registerPageLoadingStore } from './stores/page-loading-store';

import { registerGeminiChat } from './features/gemini-chat';
import { registerReportTemplateFeatures } from './features/report-template-manager';
import { registerNotificationHandler } from './features/notification-handler';
import { registerFormDiagnostics } from './features/form-diagnostics';
import { registerSidebarFeatures } from './features/sidebar';
import { registerTopbarFeatures } from './features/topbar';
import { registerSettingsModalFeatures } from './features/settings-modal';
import { registerGeminiChatDebug } from './features/gemini-chat-debug';
import { registerDashboard } from './features/dashboard';
import { registerBobotNilaiForm } from './features/bobot-nilai-form';
import { registerKkmForm } from './features/kkm-form';
import { registerPlaceholderGuide } from './features/placeholder-guide';
import { registerRaporManager } from './features/rapor-manager';

import { registerFormProtectionComponent } from './components/form-protection';
import { registerAnalisisNilaiStore } from './stores/analisis-nilai-store';
import { registerContentLoadingStore } from './stores/content-loading-store';

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
};

async function loadCurrentPageModule() {
    const pageEl = document.querySelector('[data-page]');
    const pageName = pageEl?.dataset?.page;

    if (!pageName || !pageLoaders[pageName]) return;
    await pageLoaders[pageName]();
}

window.Alpine = Alpine;
window.__loadCurrentPageModule = loadCurrentPageModule;

registerTurboCore();
registerDashboard();
registerGeminiChat();
registerGeminiChatDebug();
registerFormDiagnostics();
registerTopbarFeatures();
registerSettingsModalFeatures();
registerBobotNilaiForm();
registerKkmForm();
registerPlaceholderGuide();
registerRaporManager();

registerGeminiStore();
registerHelpersStore();
registerSidebarStore();
registerKeyboardStore();
registerReportTemplateFeatures();
registerNavigationStore();
registerReportStore();
registerFormProtectionStore();
registerFormProtectionComponent();
registerNotificationStore();
registerSessionTimeout();
registerNotificationHandler();
registerPageLoadingStore();
registerAnalisisNilaiStore();
registerContentLoadingStore();
registerSidebarFeatures();

async function bootstrapApp() {
    await loadCurrentPageModule();

    if (!window.alpineInitialized) {
        Alpine.start();
        window.alpineInitialized = true;
    }
}

bootstrapApp().catch(error => {
    console.error(`Failed to bootstrap page module for ${document.querySelector('[data-page]')?.dataset?.page || 'unknown page'}`, error);
});
