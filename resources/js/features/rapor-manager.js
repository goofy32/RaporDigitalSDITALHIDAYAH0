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
        pdfTemplateAvailability: {},
        pdfStatuses: {},
        pdfStatusUrl: '',
        pdfStatusTimer: null,
        pdfStatusFailures: 0,
        dashboardWarmupEnabled: false,
        loadingPdf: null,
        tahunAjaranId: '',
        semester: 0,
        batchStudentIds: [],
        docxPrepareUrl: '',
        batchPackageUrl: '',
        batchProcessing: false,
        batchState: 'idle',
        batchCurrent: 0,
        batchTotal: 0,
        batchMessage: '',
        batchPreparationFailures: [],
        ...raporManagerCore,
        ...raporManagerPdf
    }));
}
