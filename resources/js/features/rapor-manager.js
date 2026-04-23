import Alpine from 'alpinejs';
import { raporManagerCore } from './rapor-manager/core';
import { raporManagerPdf } from './rapor-manager/pdf';

export function registerRaporManager() {
    Alpine.data('raporManager', () => ({
        activeTab: 'UTS',
        loading: false,
        initialized: false,
        searchQuery: '',
        showPreview: false,
        previewContent: '',
        templateUTSActive: false,
        templateUASActive: false,
        loadingPdf: null,
        tahunAjaranId: '',
        semester: 0,
        ...raporManagerCore,
        ...raporManagerPdf
    }));
}
