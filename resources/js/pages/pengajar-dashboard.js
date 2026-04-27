import {
    initializeDashboardCharts,
    registerDashboardChartCleanup,
    updateDashboardClassChart,
} from './dashboard-shared';

export function initPengajarDashboardPage() {
    var pageEl = document.querySelector('[data-page="pengajar-dashboard"]');
    if (!pageEl) return;

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
}
