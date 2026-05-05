import {
    initializeDashboardCharts,
    registerDashboardChartCleanup,
    updateDashboardClassChart,
} from './dashboard-shared';

function waitForChart(callback, maxAttempts) {
    var attempts = typeof maxAttempts === 'number' ? maxAttempts : 10;

    if (typeof window.Chart !== 'undefined') {
        callback();
        return;
    }

    if (attempts <= 0) {
        return;
    }

    setTimeout(function () {
        waitForChart(callback, attempts - 1);
    }, 100);
}

function setupAdminDashboardPage() {
    var pageEl = document.querySelector('[data-page="admin-dashboard"]');
    if (!pageEl) return;

    if (pageEl.dataset.dashboardInit === 'true') {
        return;
    }

    pageEl.dataset.dashboardInit = 'true';

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

    waitForChart(function () {
        window.initCharts();
    });
}

export function initAdminDashboardPage() {
    document.addEventListener('turbo:load', setupAdminDashboardPage);

    if (document.readyState === 'complete' || document.readyState === 'interactive') {
        setupAdminDashboardPage();
    }
}
