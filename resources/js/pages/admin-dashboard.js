import {
    initializeDashboardCharts,
    registerDashboardChartCleanup,
    updateDashboardClassChart,
} from './dashboard-shared';

export function initAdminDashboardPage() {
    var pageEl = document.querySelector('[data-page="admin-dashboard"]');
    if (!pageEl) return;

    var overallProgress = Number(pageEl.dataset.overallProgress || 0);

    registerDashboardChartCleanup();

    window.overallProgress = overallProgress;
    window.navigateTo = function (url) {
        window.location.href = url;
    };
    window.initCharts = function () {
        initializeDashboardCharts(window.overallProgress);
    };
    window.updateClassChart = function (progress) {
        updateDashboardClassChart(progress);
    };
}
