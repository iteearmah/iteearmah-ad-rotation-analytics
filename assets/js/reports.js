(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function() {
        var chartCanvas = document.getElementById('performanceChart');
        if (!chartCanvas || typeof Chart === 'undefined' || typeof window.iteaAdserverReportsData === 'undefined') {
            return;
        }

        var reportData = window.iteaAdserverReportsData;
        var ctx = chartCanvas.getContext('2d');

        new Chart(ctx, {
            type: 'line',
            data: {
                labels: reportData.labels,
                datasets: [{
                    label: reportData.impressionsLabel,
                    data: reportData.impressions,
                    borderColor: '#2271b1',
                    backgroundColor: function(context) {
                        var chart = context.chart;
                        var chartCtx = chart.ctx;
                        var chartArea = chart.chartArea;

                        if (!chartArea) {
                            return null;
                        }

                        var gradient = chartCtx.createLinearGradient(0, chartArea.bottom, 0, chartArea.top);
                        gradient.addColorStop(0, 'rgba(34, 113, 177, 0)');
                        gradient.addColorStop(1, 'rgba(34, 113, 177, 0.2)');
                        return gradient;
                    },
                    fill: true,
                    tension: 0.4,
                    pointRadius: 4,
                    pointHoverRadius: 6
                }, {
                    label: reportData.clicksLabel,
                    data: reportData.clicks,
                    borderColor: '#d63638',
                    backgroundColor: function(context) {
                        var chart = context.chart;
                        var chartCtx = chart.ctx;
                        var chartArea = chart.chartArea;

                        if (!chartArea) {
                            return null;
                        }

                        var gradient = chartCtx.createLinearGradient(0, chartArea.bottom, 0, chartArea.top);
                        gradient.addColorStop(0, 'rgba(214, 54, 56, 0)');
                        gradient.addColorStop(1, 'rgba(214, 54, 56, 0.2)');
                        return gradient;
                    },
                    fill: true,
                    tension: 0.4,
                    pointRadius: 4,
                    pointHoverRadius: 6
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'top'
                    },
                    tooltip: {
                        mode: 'index',
                        intersect: false
                    }
                },
                interaction: {
                    mode: 'nearest',
                    axis: 'x',
                    intersect: false
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            drawBorder: false,
                            color: 'rgba(0, 0, 0, 0.05)'
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });
    });
})();
