{{-- Customer 360 charts + icons. Chart.js reproduces the Lovable portal's Recharts charts (same colours, dots, gaps,
     zero line, tooltip box, expanded view); Lucide is the same icon set they use. Charts are created lazily when their
     tab (or the YTD/MTD toggle) makes them visible, and are fully interactive (hover tooltips, expand dialog, resize). --}}
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/lucide@0.460.0/dist/umd/lucide.min.js"></script>
<script>
window.C360 = (() => {
    const ACCENT = '#0875E1', GRID = '#E2EAF2', AXIS = '#64748B', HEAD = '#14243B', SURFACE = '#FFFFFF';
    const FONT = '"IBM Plex Sans", "IBM Plex Sans Arabic", ui-sans-serif, system-ui, sans-serif';
    const intFmt = new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 });
    const pctAxis = new Intl.NumberFormat('en-US', { maximumFractionDigits: 1 });
    const configs = {};
    const charts = {};
    let expandedChart = null;

    // CSS colour (incl. oklch theme tokens) → rgba, via a 1px canvas, so Chart.js can use their exact palette.
    const px = document.createElement('canvas'); px.width = px.height = 1;
    const pctx = px.getContext('2d', { willReadFrequently: true });
    const rgba = (css, alpha = 1) => {
        pctx.clearRect(0, 0, 1, 1); pctx.fillStyle = '#000'; pctx.fillStyle = css; pctx.fillRect(0, 0, 1, 1);
        const [r, g, b] = pctx.getImageData(0, 0, 1, 1).data;
        return `rgba(${r},${g},${b},${alpha})`;
    };
    const token = (name) => getComputedStyle(document.querySelector('.c3')).getPropertyValue(name).trim();

    const tooltip = (titles, bodies) => ({
        backgroundColor: SURFACE, borderColor: GRID, borderWidth: 1, cornerRadius: 6, caretSize: 0, displayColors: false,
        padding: { x: 12, y: 8 }, titleColor: AXIS, titleFont: { size: 12, weight: 'normal', family: FONT }, titleMarginBottom: 2,
        bodyColor: HEAD, bodyFont: { size: 13, weight: '600', family: FONT },
        callbacks: { title: (items) => titles[items[0].dataIndex], label: (item) => bodies[item.dataIndex] },
    });

    // Recharts' vertical hover cursor (stroke = grid colour).
    const cursorLine = {
        id: 'c3cursor',
        afterDatasetsDraw(chart) {
            if (chart.config.type !== 'line') return;
            const active = chart.tooltip && chart.tooltip.getActiveElements();
            if (!active || !active.length) return;
            const x = active[0].element.x, { top, bottom } = chart.chartArea, ctx = chart.ctx;
            ctx.save(); ctx.strokeStyle = GRID; ctx.lineWidth = 1; ctx.beginPath(); ctx.moveTo(x, top); ctx.lineTo(x, bottom); ctx.stroke(); ctx.restore();
        },
    };

    function lineChart(canvas, c, big) {
        const size = big ? 13 : 11, r = big ? 5 : 4;
        const flat = big && window.innerWidth >= 900;
        const cur = (ctx) => c.current[ctx.dataIndex];
        return new Chart(canvas, {
            type: 'line',
            data: { labels: c.labels, datasets: [{
                data: c.values, borderColor: ACCENT, borderWidth: 2, tension: 0, spanGaps: false, borderCapStyle: 'round', borderJoinStyle: 'round',
                pointRadius: (ctx) => (c.values[ctx.dataIndex] === null ? 0 : r), pointBorderWidth: 2,
                pointBackgroundColor: (ctx) => (cur(ctx) ? SURFACE : ACCENT), pointBorderColor: (ctx) => (cur(ctx) ? ACCENT : SURFACE),
                pointHoverRadius: r + 1, pointHoverBackgroundColor: ACCENT, pointHoverBorderColor: SURFACE, pointHoverBorderWidth: 2,
            }] },
            options: {
                responsive: true, maintainAspectRatio: false, animation: false,
                layout: { padding: { top: 10, right: big ? 24 : 16, left: 8 } },
                interaction: { mode: 'index', intersect: false },
                plugins: { legend: { display: false }, tooltip: tooltip(c.tipTitles, c.tipValues) },
                scales: {
                    x: { grid: { display: false }, border: { color: GRID }, ticks: { color: AXIS, font: { size, family: FONT }, autoSkip: false, maxRotation: flat ? 0 : 35, minRotation: flat ? 0 : 35 } },
                    y: {
                        beginAtZero: true, border: { display: false },
                        grid: { color: (ctx) => (c.zero && ctx.tick && ctx.tick.value === 0 ? AXIS : GRID), drawTicks: false },
                        ticks: { color: AXIS, font: { size, family: FONT }, padding: 6, precision: c.kind === 'int' ? 0 : undefined,
                            callback: (v) => (c.kind === 'pct' ? `${pctAxis.format(v)}%` : intFmt.format(v)) },
                    },
                },
            },
            plugins: [cursorLine],
        });
    }

    // AR aging: horizontal bars, amounts, accent colour, rounded right ends.
    function agingChart(canvas, c) {
        return new Chart(canvas, {
            type: 'bar',
            data: { labels: c.labels, datasets: [{ data: c.values, backgroundColor: ACCENT, hoverBackgroundColor: ACCENT, borderRadius: { topRight: 3, bottomRight: 3 }, borderSkipped: 'start', categoryPercentage: 0.8, barPercentage: 0.9 }] },
            options: {
                indexAxis: 'y', responsive: true, maintainAspectRatio: false, animation: false,
                layout: { padding: { top: 4, right: 24, bottom: 4, left: 8 } },
                plugins: { legend: { display: false }, tooltip: tooltip(c.labels, c.tipLines) },
                scales: {
                    x: { beginAtZero: true, border: { color: GRID }, grid: { color: GRID, drawTicks: false }, ticks: { color: AXIS, font: { size: 11, family: FONT }, callback: (v) => intFmt.format(v) } },
                    y: { grid: { display: false }, border: { display: false }, afterFit: (s) => { s.width = 110; }, ticks: { color: AXIS, font: { size: 11, family: FONT } } },
                },
            },
        });
    }

    // Contribution: donut when every slice is >= 0, otherwise signed horizontal bars (negatives in destructive red).
    function contributionChart(canvas, c) {
        const pctText = c.values.map((v) => `${v.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}%`);
        if (c.donut) {
            const colors = c.colors.map(([name, alpha]) => rgba(token(name), alpha));
            return new Chart(canvas, {
                type: 'doughnut',
                data: { labels: c.labels, datasets: [{ data: c.values, backgroundColor: colors, hoverBackgroundColor: colors, borderColor: SURFACE, borderWidth: 1, spacing: 1 }] },
                options: { responsive: true, maintainAspectRatio: false, animation: false, cutout: '55%', radius: '85%',
                    plugins: { legend: { display: false }, tooltip: tooltip(c.labels, pctText) } },
            });
        }
        const neg = rgba(token('--c360-destructive'));
        return new Chart(canvas, {
            type: 'bar',
            data: { labels: c.labels, datasets: [{ data: c.values, backgroundColor: c.values.map((v) => (v < 0 ? neg : ACCENT)), borderRadius: 2, categoryPercentage: 0.8, barPercentage: 0.9 }] },
            options: {
                indexAxis: 'y', responsive: true, maintainAspectRatio: false, animation: false,
                layout: { padding: { top: 4, right: 16, bottom: 4, left: 4 } },
                plugins: { legend: { display: false }, tooltip: tooltip(c.labels, pctText) },
                scales: {
                    x: { border: { color: GRID }, grid: { color: (ctx) => (ctx.tick && ctx.tick.value === 0 ? AXIS : GRID), drawTicks: false },
                        ticks: { color: AXIS, font: { size: 11, family: FONT }, callback: (v) => `${pctAxis.format(v)}%` } },
                    y: { grid: { display: false }, border: { display: false }, afterFit: (s) => { s.width = 130; },
                        ticks: { color: AXIS, font: { size: 11, family: FONT }, autoSkip: false, callback(v) { const s = this.getLabelForValue(v); return s.length > 18 ? `${s.slice(0, 17)}…` : s; } } },
                },
            },
        });
    }

    const build = (canvas, c, big = false) => (c.type === 'line' ? lineChart(canvas, c, big) : c.type === 'aging' ? agingChart(canvas, c) : contributionChart(canvas, c));

    return {
        register(id, cfg) { configs[id] = cfg; },
        // Create every not-yet-created chart inside a now-visible container.
        initIn(root) {
            (root || document).querySelectorAll('canvas[data-c3chart]').forEach((cv) => {
                const id = cv.dataset.c3chart;
                if (charts[id] || !configs[id] || cv.offsetParent === null) return;
                charts[id] = build(cv, configs[id]);
            });
        },
        expand(id, canvas) {
            if (expandedChart) expandedChart.destroy();
            expandedChart = build(canvas, configs[id], true);
        },
        collapse() { if (expandedChart) { expandedChart.destroy(); expandedChart = null; } },
        icons() { if (window.lucide) window.lucide.createIcons({ attrs: { 'stroke-width': 1.75 } }); },
    };
})();
</script>
