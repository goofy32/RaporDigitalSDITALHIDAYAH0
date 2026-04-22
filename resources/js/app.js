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

import { registerFormProtectionComponent } from './components/form-protection';

window.renderAsync = renderAsync;
window.Alpine = Alpine;

registerTurboCore();
registerGeminiChat();
registerFormDiagnostics();

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
registerSidebarFeatures();

if (!window.alpineInitialized) {
    Alpine.start();
    window.alpineInitialized = true;
}
