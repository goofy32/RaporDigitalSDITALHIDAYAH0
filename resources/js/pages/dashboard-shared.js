var overallChart = null;
var classChart = null;
var kelasProgress = 0;
var dashboardCleanupRegistered = false;

function getChartInstance(chartId) {
    if (typeof window.Chart === 'undefined' || typeof window.Chart.getChart !== 'function') {
        return null;
    }

    return window.Chart.getChart(chartId);
}

function destroyChartInstance(chart) {
    if (chart && typeof chart.destroy === 'function') {
        chart.destroy();
    }
}

function createDefaultOptions() {
    return {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'bottom',
            },
            tooltip: {
                enabled: false,
            },
        },
    };
}

function createCenterTextPlugin(getText) {
    return {
        id: 'centerText',
        afterDraw(chart) {
            var width = chart.width;
            var height = chart.height;
            var ctx = chart.ctx;

            ctx.restore();
            ctx.font = (height / 114).toFixed(2) + 'em sans-serif';
            ctx.textBaseline = 'middle';
            ctx.fillStyle = '#1F2937';

            var text = getText();
            var textX = Math.round((width - ctx.measureText(text).width) / 2);
            var textY = height / 2;

            ctx.fillText(text, textX, textY);
            ctx.save();
        },
    };
}

export function destroyDashboardCharts() {
    destroyChartInstance(overallChart);
    destroyChartInstance(classChart);
    destroyChartInstance(getChartInstance('overallPieChart'));
    destroyChartInstance(getChartInstance('classProgressChart'));
    overallChart = null;
    classChart = null;
}

export function updateDashboardClassChart(progress) {
    kelasProgress = Math.min(100, Math.max(0, Number(progress) || 0));

    if (classChart) {
        classChart.data.datasets[0].data = [kelasProgress, 100 - kelasProgress];
        classChart.update();
    }
}

export function initializeDashboardCharts(overallProgressValue) {
    var safeOverallProgress = Math.min(100, Math.max(0, Number(overallProgressValue) || 0));
    var defaultOptions = createDefaultOptions();
    var overallCanvas = document.getElementById('overallPieChart');
    var classCanvas = document.getElementById('classProgressChart');

    destroyDashboardCharts();

    if (overallCanvas && overallCanvas.getContext && typeof window.Chart !== 'undefined') {
        overallChart = new window.Chart(overallCanvas.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: ['Selesai', 'Belum'],
                datasets: [{
                    data: [safeOverallProgress, 100 - safeOverallProgress],
                    backgroundColor: ['rgb(34, 197, 94)', 'rgb(229, 231, 235)'],
                    borderWidth: 0,
                }],
            },
            options: {
                ...defaultOptions,
                cutout: '60%',
            },
            plugins: [createCenterTextPlugin(function () {
                return Math.round(safeOverallProgress) + '%';
            })],
        });
    }

    if (classCanvas && classCanvas.getContext && typeof window.Chart !== 'undefined') {
        classChart = new window.Chart(classCanvas.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: ['Selesai', 'Belum'],
                datasets: [{
                    data: [kelasProgress, 100 - kelasProgress],
                    backgroundColor: ['rgb(34, 197, 94)', 'rgb(229, 231, 235)'],
                    borderWidth: 0,
                }],
            },
            options: {
                ...defaultOptions,
                cutout: '60%',
            },
            plugins: [createCenterTextPlugin(function () {
                return Math.round(kelasProgress) + '%';
            })],
        });
    }
}

export function registerDashboardChartCleanup() {
    if (dashboardCleanupRegistered) return;

    document.addEventListener('turbo:before-cache', destroyDashboardCharts);
    window.addEventListener('unload', destroyDashboardCharts);

    dashboardCleanupRegistered = true;
}
