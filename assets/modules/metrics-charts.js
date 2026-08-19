import Chart from 'chart.js/auto';

const chartInstances = new Map();

/**
 * Détruit une instance de graphique existante pour un identifiant de canvas donné.
 *
 * @param {string} canvasId
 */
function destroyChart(canvasId) {
    if (chartInstances.has(canvasId)) {
        chartInstances.get(canvasId).destroy();
        chartInstances.delete(canvasId);
    }
}

/**
 * Initialise le graphique d'activité (messages et utilisateurs actifs dans le temps).
 */
function initActivityTimelineChart() {
    const canvas = document.getElementById('activity-timeline-chart');
    if (!canvas) return;

    destroyChart('activity-timeline-chart');

    const rawData = canvas.getAttribute('data-timeline');
    if (!rawData) return;

    let timeline = [];
    try {
        timeline = JSON.parse(rawData);
    } catch (e) {
        console.error('Failed to parse timeline data for chart:', e);
        return;
    }

    const labels = timeline.map(item => item.label);
    const messageData = timeline.map(item => item.messages);
    const userData = timeline.map(item => item.active_users);

    const isDark = document.documentElement.getAttribute('data-theme') !== 'light';
    const gridColor = isDark ? 'rgba(255, 255, 255, 0.08)' : 'rgba(0, 0, 0, 0.06)';
    const textColor = isDark ? '#94a3b8' : '#64748b';

    const ctx = canvas.getContext('2d');
    if (!ctx) return;

    const chart = new Chart(ctx, {
        type: 'line',
        data: {
            labels,
            datasets: [
                {
                    label: 'Messages',
                    data: messageData,
                    borderColor: '#3b82f6',
                    backgroundColor: 'rgba(59, 130, 246, 0.15)',
                    fill: true,
                    tension: 0.35,
                    borderWidth: 2,
                    pointRadius: labels.length > 31 ? 0 : 3,
                    pointHoverRadius: 5,
                    yAxisID: 'y',
                },
                {
                    label: 'Membres actifs',
                    data: userData,
                    borderColor: '#10b981',
                    backgroundColor: 'rgba(16, 185, 129, 0.1)',
                    fill: false,
                    tension: 0.35,
                    borderWidth: 2,
                    borderDash: [4, 4],
                    pointRadius: labels.length > 31 ? 0 : 3,
                    pointHoverRadius: 5,
                    yAxisID: 'y1',
                },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                mode: 'index',
                intersect: false,
            },
            plugins: {
                legend: {
                    position: 'top',
                    labels: {
                        color: textColor,
                        usePointStyle: true,
                        boxWidth: 8,
                    },
                },
                tooltip: {
                    backgroundColor: isDark ? 'rgba(15, 23, 42, 0.9)' : 'rgba(255, 255, 255, 0.95)',
                    titleColor: isDark ? '#f8fafc' : '#0f172a',
                    bodyColor: isDark ? '#cbd5e1' : '#334155',
                    borderColor: isDark ? 'rgba(255, 255, 255, 0.1)' : 'rgba(0, 0, 0, 0.1)',
                    borderWidth: 1,
                    padding: 10,
                },
            },
            scales: {
                x: {
                    grid: { color: gridColor },
                    ticks: {
                        color: textColor,
                        maxTicksLimit: 12,
                    },
                },
                y: {
                    type: 'linear',
                    display: true,
                    position: 'left',
                    grid: { color: gridColor },
                    ticks: {
                        color: textColor,
                        precision: 0,
                    },
                    beginAtZero: true,
                },
                y1: {
                    type: 'linear',
                    display: true,
                    position: 'right',
                    grid: { drawOnChartArea: false },
                    ticks: {
                        color: textColor,
                        precision: 0,
                    },
                    beginAtZero: true,
                },
            },
        },
    });

    chartInstances.set('activity-timeline-chart', chart);
}

/**
 * Initialise le graphique de répartition du stockage.
 */
function initStorageBreakdownChart() {
    const canvas = document.getElementById('storage-breakdown-chart');
    if (!canvas) return;

    destroyChart('storage-breakdown-chart');

    const rawData = canvas.getAttribute('data-storage');
    if (!rawData) return;

    let storage = [];
    try {
        storage = JSON.parse(rawData);
    } catch (e) {
        console.error('Failed to parse storage data for chart:', e);
        return;
    }

    const filtered = storage.filter(item => item.bytes > 0);
    const isDark = document.documentElement.getAttribute('data-theme') !== 'light';
    const textColor = isDark ? '#94a3b8' : '#64748b';

    const ctx = canvas.getContext('2d');
    if (!ctx) return;

    if (filtered.length === 0) {
        // Empty state chart
        const chart = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: ['Aucun fichier'],
                datasets: [{
                    data: [1],
                    backgroundColor: [isDark ? 'rgba(255, 255, 255, 0.05)' : 'rgba(0, 0, 0, 0.05)'],
                    borderWidth: 0,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: { enabled: false },
                },
                cutout: '70%',
            },
        });
        chartInstances.set('storage-breakdown-chart', chart);
        return;
    }

    const labels = filtered.map(item => item.label);
    const data = filtered.map(item => item.bytes);
    const colors = filtered.map(item => item.color);

    const chart = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels,
            datasets: [{
                data,
                backgroundColor: colors,
                borderWidth: 2,
                borderColor: isDark ? '#1e293b' : '#ffffff',
                hoverOffset: 4,
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        color: textColor,
                        usePointStyle: true,
                        boxWidth: 8,
                        padding: 12,
                    },
                },
                tooltip: {
                    backgroundColor: isDark ? 'rgba(15, 23, 42, 0.9)' : 'rgba(255, 255, 255, 0.95)',
                    titleColor: isDark ? '#f8fafc' : '#0f172a',
                    bodyColor: isDark ? '#cbd5e1' : '#334155',
                    borderColor: isDark ? 'rgba(255, 255, 255, 0.1)' : 'rgba(0, 0, 0, 0.1)',
                    borderWidth: 1,
                    padding: 10,
                    callbacks: {
                        label: function (context) {
                            const item = filtered[context.dataIndex];
                            return ` ${item.label}: ${item.percentage}% (${item.files_count} fichier${item.files_count > 1 ? 's' : ''})`;
                        },
                    },
                },
            },
            cutout: '65%',
        },
    });

    chartInstances.set('storage-breakdown-chart', chart);
}

/**
 * Initialise tous les graphiques de la page métriques.
 */
export function initMetricsCharts() {
    initActivityTimelineChart();
    initStorageBreakdownChart();
}

window.initMetricsCharts = initMetricsCharts;
