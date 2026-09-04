/**
 * Chart.js Initialization & Gradient setups for CRM Dashboard
 */

window.crmChartInstances = window.crmChartInstances || {};

function initCRMCharts() {
    if (typeof Chart === 'undefined') return;

    // Destroy existing chart instances before re-initializing
    Object.keys(window.crmChartInstances).forEach(key => {
        if (window.crmChartInstances[key] && typeof window.crmChartInstances[key].destroy === 'function') {
            window.crmChartInstances[key].destroy();
        }
    });
    window.crmChartInstances = {};

    // Resolve theme variables
    const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    const gridColor = isDark ? 'rgba(255, 255, 255, 0.05)' : 'rgba(0, 0, 0, 0.05)';
    const textColor = isDark ? '#94a3b8' : '#64748b';
    
    // Core Chart Colors
    const primaryColor = 'rgba(59, 130, 246, 1)';      // Sleek Blue
    const accentColor = 'rgba(139, 92, 246, 1)';       // Violet
    const successColor = 'rgba(16, 185, 129, 1)';      // Emerald
    const warningColor = 'rgba(245, 158, 11, 1)';      // Amber
    const dangerColor = 'rgba(239, 68, 68, 1)';        // Crimson

    // Standard Font Family configuration
    Chart.defaults.font.family = "'Inter', sans-serif";
    Chart.defaults.color = textColor;

    const chartData = window.dashboardChartData || {};

    // 1. Monthly Leads (Line Chart)
    const ctxLeads = document.getElementById('monthlyLeadsChart');
    if (ctxLeads) {
        const cLeads = ctxLeads.getContext('2d');
        const grad = cLeads.createLinearGradient(0, 0, 0, 300);
        grad.addColorStop(0, 'rgba(59, 130, 246, 0.4)');
        grad.addColorStop(1, 'rgba(59, 130, 246, 0.0)');

        window.crmChartInstances['monthlyLeadsChart'] = new Chart(ctxLeads, {
            type: 'line',
            data: {
                labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
                datasets: [{
                    label: 'Leads Created',
                    data: chartData.leadsCreated || [120, 185, 240, 190, 310, 280, 390, 420, 380, 490, 520, 610],
                    borderColor: primaryColor,
                    borderWidth: 3,
                    backgroundColor: grad,
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: primaryColor
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { grid: { display: false }, ticks: { color: textColor } },
                    y: { grid: { color: gridColor }, ticks: { color: textColor } }
                }
            }
        });
    }

    // 2. Monthly Sales (Bar Chart)
    const ctxSales = document.getElementById('monthlySalesChart');
    if (ctxSales) {
        const cSales = ctxSales.getContext('2d');
        const gradSales = cSales.createLinearGradient(0, 0, 0, 300);
        gradSales.addColorStop(0, 'rgba(139, 92, 246, 0.8)');
        gradSales.addColorStop(1, 'rgba(139, 92, 246, 0.2)');

        window.crmChartInstances['monthlySalesChart'] = new Chart(ctxSales, {
            type: 'bar',
            data: {
                labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
                datasets: [{
                    label: 'Revenue (INR L)',
                    data: chartData.salesVolume || [15, 22, 29, 21, 38, 32, 45, 49, 41, 56, 60, 72],
                    backgroundColor: gradSales,
                    borderRadius: 6,
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { grid: { display: false }, ticks: { color: textColor } },
                    y: { grid: { color: gridColor }, ticks: { color: textColor } }
                }
            }
        });
    }

    // 3. Lead Sources (Doughnut Chart)
    const ctxSources = document.getElementById('leadSourcesChart');
    if (ctxSources) {
        window.crmChartInstances['leadSourcesChart'] = new Chart(ctxSources, {
            type: 'doughnut',
            data: {
                labels: chartData.sourcesLabels || ['Website', 'Google Ads', 'Cold Calls', 'Referrals', 'Exhibitions', 'Self', 'Door to Door'],
                datasets: [{
                    data: chartData.sourcesData || [30, 20, 15, 12, 10, 8, 5],
                    backgroundColor: [primaryColor, accentColor, successColor, warningColor, dangerColor, '#8b5cf6', '#f59e0b'],
                    borderWidth: isDark ? 2 : 1,
                    borderColor: isDark ? '#121826' : '#ffffff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'right',
                        labels: { color: textColor, boxWidth: 12, font: { size: 11 } }
                    }
                },
                cutout: '70%'
            }
        });
    }

    // 4. Employee Performance (Horizontal Bar Chart)
    const ctxPerf = document.getElementById('employeePerformanceChart');
    if (ctxPerf) {
        const perfLabels = chartData.execPerformance ? chartData.execPerformance.map(e => e.name) : ['Amit S.', 'Neha R.', 'Vikram K.', 'Sonal M.', 'Rahul P.'];
        const perfWon = chartData.execPerformance ? chartData.execPerformance.map(e => e.won) : [32, 28, 25, 22, 19];
        const perfDemos = chartData.execPerformance ? chartData.execPerformance.map(e => e.demos) : [45, 38, 40, 32, 28];

        window.crmChartInstances['employeePerformanceChart'] = new Chart(ctxPerf, {
            type: 'bar',
            data: {
                labels: perfLabels,
                datasets: [{
                    label: 'Conversions Won',
                    data: perfWon,
                    backgroundColor: primaryColor,
                    borderRadius: 4
                }, {
                    label: 'Demos Conducted',
                    data: perfDemos,
                    backgroundColor: 'rgba(59, 130, 246, 0.3)',
                    borderRadius: 4
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom', labels: { color: textColor } } },
                scales: {
                    x: { grid: { color: gridColor }, ticks: { color: textColor } },
                    y: { grid: { display: false }, ticks: { color: textColor } }
                }
            }
        });
    }

    // 5. Conversion Funnel (Horizontal Bar)
    const ctxFunnel = document.getElementById('conversionFunnelChart');
    if (ctxFunnel) {
        window.crmChartInstances['conversionFunnelChart'] = new Chart(ctxFunnel, {
            type: 'bar',
            data: {
                labels: ['Leads', 'Contacted', 'Interested', 'Demo Completed', 'Quotation Sent', 'Closed Won'],
                datasets: [{
                    label: 'Conversion %',
                    data: chartData.funnelData || [100, 78, 54, 38, 26, 14],
                    backgroundColor: [
                        'rgba(59, 130, 246, 0.9)',
                        'rgba(59, 130, 246, 0.75)',
                        'rgba(139, 92, 246, 0.8)',
                        'rgba(245, 158, 11, 0.8)',
                        'rgba(99, 102, 241, 0.8)',
                        'rgba(16, 185, 129, 0.9)'
                    ],
                    borderRadius: 6,
                    barThickness: 24
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { max: 100, grid: { color: gridColor }, ticks: { color: textColor } },
                    y: { grid: { display: false }, ticks: { color: textColor } }
                }
            }
        });
    }
}

window.initCRMCharts = initCRMCharts;

document.addEventListener('DOMContentLoaded', () => {
    initCRMCharts();
});
