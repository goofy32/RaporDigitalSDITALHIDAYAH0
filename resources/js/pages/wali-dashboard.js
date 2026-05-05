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

function setupWaliDashboardPage() {
    var pageEl = document.querySelector('[data-page="wali-dashboard"]');
    if (!pageEl) return;

    if (pageEl.dataset.dashboardInit === 'true') {
        return;
    }

    pageEl.dataset.dashboardInit = 'true';

    var overallProgress = Number(pageEl.dataset.overallProgress || 0);
    var progressEndpoint = pageEl.dataset.progressEndpoint || '';

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
    window.fetchSubjectProgress = function (subjectId) {
        var selectedSubject = subjectId || document.getElementById('subject')?.value;

        if (!selectedSubject || !progressEndpoint) {
            updateDashboardClassChart(0);
            return Promise.resolve();
        }

        return fetch(`${progressEndpoint}/${selectedSubject}`, {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        })
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }

                return response.json();
            })
            .then(data => {
                updateDashboardClassChart(data.progress);
            })
            .catch(error => {
                console.error('Error fetching subject progress:', error);
                updateDashboardClassChart(0);
            });
    };

    waitForChart(function () {
        window.initCharts();
    });
}

export function initWaliDashboardPage() {
    document.addEventListener('turbo:load', setupWaliDashboardPage);

    if (document.readyState === 'complete' || document.readyState === 'interactive') {
        setupWaliDashboardPage();
    }
}
