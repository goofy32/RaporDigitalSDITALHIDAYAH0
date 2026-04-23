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

window.Alpine = Alpine;

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

if (!window.alpineInitialized) {
    Alpine.start();
    window.alpineInitialized = true;
}

const pageLoaders = {
    'add-subject': () => import('./pages/add-subject').then(module => module.initAddSubjectPage()),
    'edit-subject': () => import('./pages/edit-subject').then(module => module.initEditSubjectPage()),
    'admin-report': () => import('./pages/admin-report').then(module => module.initAdminReportPage()),
    'pengajar-input-score': () => import('./pages/pengajar-input-score').then(module => module.initPengajarInputScorePage()),
};

async function loadCurrentPageModule() {
    const pageEl = document.querySelector('[data-page]');
    const pageName = pageEl?.dataset?.page;

    if (!pageName || !pageLoaders[pageName]) return;
    await pageLoaders[pageName]();
}

document.addEventListener('turbo:load', () => {
    loadCurrentPageModule().catch(error => {
        console.error(`Failed to load page module for ${document.querySelector('[data-page]')?.dataset?.page || 'unknown page'}`, error);
    });
});
