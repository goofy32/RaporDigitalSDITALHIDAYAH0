import './bootstrap';
import 'flowbite';
import '@hotwired/turbo';
import Alpine from 'alpinejs';
import { renderAsync } from 'docx-preview';

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
import { initAdminReportPage } from './pages/admin-report';
import { initPengajarInputScorePage } from './pages/pengajar-input-score';

window.renderAsync = renderAsync;
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
initAdminReportPage();
initPengajarInputScorePage();

if (!window.alpineInitialized) {
    Alpine.start();
    window.alpineInitialized = true;
}
