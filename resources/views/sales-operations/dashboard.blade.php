{{-- Sales Operations — copy of the Lovable portal's sales.sales-operations.tsx: tabs Overview (group × period matrix + 6 pies),
     Data Entry / Preparation / Review / Delivery (person × period Inv | Items | Qty), Post-Delivery (90-day checks + drawer).
     Each tab loads once, on first open. Pies are Chart.js in place of their Recharts (same palette, blank = grey, % label ≥ 4%). --}}
<x-app-layout>
    @include('lovable.shell-styles')
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        .so-tabs { display: flex; overflow-x: auto; border-bottom: 1px solid var(--border); padding: 0 8px; }
        .so-tab { margin-bottom: -1px; flex-shrink: 0; white-space: nowrap; border: 0; border-bottom: 2px solid transparent; background: none; padding: 10px 16px;
            font-size: 13px; font-weight: 500; color: var(--fg); cursor: pointer; font-family: inherit; }
        .so-tab:hover { color: var(--primary); } .so-tab.on { border-bottom-color: var(--primary); font-weight: 600; color: var(--primary); }
        .so-box { overflow-x: auto; border: 1px solid var(--border); border-radius: .25rem; }
        .so-box table { width: max-content; min-width: 0; border-collapse: separate; border-spacing: 0; font-size: 12px; }
        .lv .so-box th, .lv .so-box td { white-space: nowrap; border-bottom: 1px solid var(--border); border-inline-end: 0; padding: 4px 8px; }
        .lv .so-box th { cursor: default; background: var(--muted); font-size: 11.5px; font-weight: 600; }
        .lv .so-box tbody tr:nth-child(n) { background: var(--surface); }
        .so-box .num { text-align: end; font-variant-numeric: tabular-nums; } .so-box .zero { color: var(--muted-fg); }
        .so-box .sep { border-inline-start: 2px solid var(--border); }
        .so-box .stick { position: sticky; inset-inline-start: 0; z-index: 1; background: var(--surface); border-inline-end: 1px solid var(--border); text-align: start; }
        .lv .so-box th.stick { background: var(--muted); }
        .so-box .grp td { background: color-mix(in oklch, var(--muted) 50%, transparent); font-size: 11.5px; font-weight: 600; }
        .so-box .rng { font-size: 10.5px; font-weight: 400; color: var(--muted-fg); }
        .so-blank { font-style: italic; color: var(--muted-fg); }
        .so-note { font-size: 11.5px; color: var(--muted-fg); margin: 0; } .so-warn { font-size: 12px; color: var(--destructive); margin: 0; }
        .so-pies { display: grid; grid-template-columns: 1fr; gap: 12px; } @media (min-width: 768px) { .so-pies { grid-template-columns: 1fr 1fr; } }
        .so-card { border: 1px solid var(--border); border-radius: .25rem; padding: 12px; background: var(--surface); }
        .so-checks { display: grid; grid-template-columns: 1fr; gap: 12px; } @media (min-width: 640px) { .so-checks { grid-template-columns: repeat(3, 1fr); } }
        .so-check { text-align: start; cursor: pointer; font-family: inherit; } .so-check:hover { border-color: var(--primary); }
        .so-overlay { position: fixed; inset: 0; z-index: 40; background: rgba(0, 0, 0, .4); }
        .so-drawer { position: fixed; inset-block: 0; inset-inline-end: 0; z-index: 50; display: flex; flex-direction: column; width: 100%; max-width: 720px;
            background: var(--surface); box-shadow: -4px 0 16px rgba(0, 0, 0, .15); }
        .so-drawer table { width: 100%; min-width: 0; font-size: 12.5px; }
        .lv .so-drawer th { position: sticky; top: 0; z-index: 1; cursor: default; }
    </style>

    <div class="lv" x-data="lvSalesOps(@js([
        'overview' => route('sales-operations.overview'), 'employees' => route('sales-operations.employees'),
        'audit' => route('sales-operations.audit'), 'auditList' => route('sales-operations.audit-list'),
        'invoice' => route('invoices.show', '__ID__'), 'customer' => route('customers.show', '__ID__'),
    ]))">
        <header>
            <div class="lv-crumb">Sales Data</div>
            <h1>Sales Operations</h1>
            <p class="lv-sub">Sales operational data and workflow</p>
        </header>

        <section class="lv-panel">
            <div class="so-tabs" role="tablist" aria-label="Sales Operations sections">
                <template x-for="t in tabs" :key="t.key">
                    <button type="button" role="tab" class="so-tab" :class="tab === t.key && 'on'" :aria-selected="tab === t.key" @click="open(t.key)" x-text="t.label"></button>
                </template>
            </div>

            <div role="tabpanel" style="flex: 1; padding: 12px">
                <p class="lv-fld" x-show="state(tab).loading" style="padding: 16px; font-size: 12.5px">Loading…</p>
                <p class="so-warn" x-show="state(tab).error" x-cloak style="padding: 16px">Could not load sales operations data. <button type="button" class="lv-btn" @click="load(tab, true)">Retry</button></p>

                {{-- Overview --}}
                <template x-if="tab === 'overview' && data.overview">
                    <div style="display: flex; flex-direction: column; gap: 16px">
                        <p class="so-warn" x-show="data.overview.source.conflicting_invoices > 0" x-text="conflictText(data.overview.source.conflicting_invoices)"></p>
                        <div class="so-box">
                            <table>
                                <thead><tr>
                                    <th class="stick">KPI</th>
                                    <template x-for="p in PERIODS" :key="p"><th class="sep" style="text-align: center"><div x-text="PLAB[p]"></div><div class="rng" dir="ltr" x-text="range(data.overview.periods[p])"></div></th></template>
                                </tr></thead>
                                <tbody>
                                    <tr style="font-weight: 600"><td class="stick">All Invoices</td>
                                        <template x-for="p in PERIODS" :key="p"><td class="num sep" :class="! data.overview.totals[p] && 'zero'" dir="ltr" x-text="fmt(data.overview.totals[p])"></td></template></tr>
                                    {{-- Group header rows and their value rows, flattened into one list (no nested tbody). --}}
                                    <template x-for="(m, mi) in matrixRows()" :key="mi">
                                        <tr :class="m.head && 'grp'">
                                            <template x-if="m.head"><td :colspan="PERIODS.length + 1">
                                                <span x-text="GLAB[m.g]"></span>
                                                <span x-show="m.g === 'packaging'" style="display: block; font-size: 11px; font-weight: 400; color: var(--muted-fg)">Sum of quantities, not invoice counts.</span>
                                            </td></template>
                                            <template x-if="! m.head"><td class="stick" style="padding-inline-start: 16px"><template x-if="m.c.blank"><span class="so-blank">Blank</span></template><template x-if="! m.c.blank"><bdi x-text="m.c.value"></bdi></template></td></template>
                                            <template x-for="p in (m.head ? [] : PERIODS)" :key="p"><td class="num sep" :class="! m.c.metrics[p] && 'zero'" dir="ltr" x-text="fmt(m.c.metrics[p], m.c.qty)"></td></template>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                        <p class="so-note" x-text="footnote(data.overview.as_of)"></p>
                        <div class="so-pies">
                            <template x-for="pc in PIES" :key="pc.group + pc.period">
                                <div class="so-card">
                                    <div style="margin-bottom: 8px; font-size: 12.5px; font-weight: 600"><span x-text="GLAB[pc.group] + ' — ' + PLAB[pc.period]"></span>
                                        <span dir="ltr" style="margin-inline-start: 8px; font-size: 11px; font-weight: 400; color: var(--muted-fg)" x-text="range(data.overview.periods[pc.period])"></span></div>
                                    <div x-show="! pieTotal(pc)" style="display: flex; height: 16rem; align-items: center; justify-content: center; font-size: 12px; color: var(--muted-fg)">No invoices in this period.</div>
                                    <div x-show="pieTotal(pc)" style="position: relative; height: 18rem" dir="ltr"><canvas x-init="$nextTick(() => drawPie($el, pc))"></canvas></div>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>

                {{-- Data Entry / Preparation / Review / Delivery --}}
                <template x-for="f in FIELD_TABS" :key="f.tab">
                    <template x-if="tab === f.tab && data[f.tab]">
                        <div style="display: flex; flex-direction: column; gap: 12px">
                            <p class="so-warn" x-show="data[f.tab].conflicting_invoices > 0" x-text="conflictText(data[f.tab].conflicting_invoices)"></p>
                            <div class="so-box">
                                <table>
                                    <thead>
                                        <tr><th class="stick" rowspan="2" x-text="f.label"></th>
                                            <template x-for="p in PERIODS" :key="p"><th class="sep" colspan="3" style="text-align: center"><div x-text="PLAB[p]"></div><div class="rng" dir="ltr" x-text="range(data[f.tab].periods[p])"></div></th></template></tr>
                                        <tr><template x-for="pm in PM" :key="pm.p + pm.k"><th class="num" :class="pm.i === 0 && 'sep'" style="font-weight: 500" x-text="MLAB[pm.k]"></th></template></tr>
                                    </thead>
                                    <tbody>
                                        <tr style="font-weight: 600"><td class="stick">Total</td>
                                            <template x-for="pm in PM" :key="pm.p + pm.k"><td class="num" :class="[pm.i === 0 && 'sep', ! data[f.tab].total[pm.p][pm.k] && 'zero']" dir="ltr" x-text="fmt(data[f.tab].total[pm.p][pm.k], pm.k === 'qty')"></td></template></tr>
                                        <template x-for="(r, ri) in data[f.tab].rows" :key="ri">
                                            <tr><td class="stick"><template x-if="r.blank"><span class="so-blank">Blank</span></template><template x-if="! r.blank"><bdi x-text="r.value"></bdi></template></td>
                                                <template x-for="pm in PM" :key="pm.p + pm.k"><td class="num" :class="[pm.i === 0 && 'sep', ! r.metrics[pm.p][pm.k] && 'zero']" dir="ltr" x-text="fmt(r.metrics[pm.p][pm.k], pm.k === 'qty')"></td></template></tr>
                                        </template>
                                    </tbody>
                                </table>
                            </div>
                            <p class="so-note" x-text="footnote(data[f.tab].as_of)"></p>
                        </div>
                    </template>
                </template>

                {{-- Post-Delivery --}}
                <template x-if="tab === 'postDelivery' && data.postDelivery">
                    <div style="display: flex; flex-direction: column; gap: 12px">
                        <p style="margin: 0; font-size: 12.5px; font-weight: 500">Last 90 days: <bdi dir="ltr" x-text="dmy(data.postDelivery.window.from)"></bdi> – <bdi dir="ltr" x-text="dmy(data.postDelivery.window.to)"></bdi></p>
                        <div class="so-checks">
                            <template x-for="c in CHECKS" :key="c.key">
                                <button type="button" class="so-card so-check" @click="openCheck(c.key)">
                                    <div style="font-size: 12px; font-weight: 500; color: var(--muted-fg)" x-text="c.label"></div>
                                    <div dir="ltr" style="margin-top: 4px; font-size: 22px; font-weight: 600; font-variant-numeric: tabular-nums; text-align: start" x-text="fmt(data.postDelivery.counts[c.key])"></div>
                                </button>
                            </template>
                        </div>
                        <p class="so-note">Each card counts its own invoices; one invoice can appear under more than one check.</p>
                    </div>
                </template>
            </div>
        </section>

        {{-- Post-Delivery drawer (their AuditDrawer) --}}
        <template x-if="drawer.check"><div class="so-overlay" @click="drawer.check = null"></div></template>
        <aside class="so-drawer" x-show="drawer.check" x-cloak role="dialog" aria-modal="true" @keydown.escape.window="drawer.check = null">
            <div style="position: relative; border-bottom: 1px solid var(--border); padding: 12px 48px 12px 16px">
                <div style="font-size: 15px; font-weight: 600"><span x-text="checkLabel(drawer.check)"></span> <span style="color: var(--muted-fg)">(<bdi dir="ltr" x-text="fmt(drawer.check ? data.postDelivery.counts[drawer.check] : 0)"></bdi>)</span></div>
                <p class="so-note" style="margin-top: 2px; font-size: 12px">Each card counts its own invoices; one invoice can appear under more than one check.</p>
                <button type="button" class="lv-btn" style="position: absolute; top: 10px; inset-inline-end: 12px" @click="drawer.check = null" aria-label="Close">×</button>
            </div>
            <div style="min-height: 0; flex: 1; overflow: auto">
                <p x-show="drawer.loading" style="padding: 24px 16px; font-size: 13px; color: var(--muted-fg)">Loading invoices…</p>
                <p x-show="drawer.error" x-cloak style="padding: 24px 16px; font-size: 13px; color: var(--destructive)">Could not load invoices.</p>
                <p x-show="! drawer.loading && ! drawer.error && ! drawer.rows.length" x-cloak style="padding: 24px 16px; font-size: 13px; color: var(--muted-fg)">No invoices.</p>
                <table x-show="! drawer.loading && ! drawer.error && drawer.rows.length">
                    <thead><tr><th>Invoice Date</th><th>Invoice Number</th><th>Customer Name</th></tr></thead>
                    <tbody>
                        <template x-for="(r, i) in drawer.rows" :key="(r.invoice_id || r.invoice_number) + ':' + i">
                            <tr>
                                <td dir="ltr" x-text="dmy(r.invoice_date)"></td>
                                <td dir="ltr"><template x-if="r.invoice_id"><a class="lk" :href="urls.invoice.replace('__ID__', r.invoice_id)" x-text="r.invoice_number || r.invoice_id"></a></template><template x-if="! r.invoice_id"><span x-text="r.invoice_number || '—'"></span></template></td>
                                <td dir="auto"><template x-if="r.cust_id"><a class="lk" dir="auto" :href="urls.customer.replace('__ID__', r.cust_id)" x-text="r.customer_name || '—'"></a></template><template x-if="! r.cust_id"><span x-text="r.customer_name || '—'"></span></template></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
            <div class="lv-foot">
                <span class="m">Showing <bdi dir="ltr" x-text="fmt(drawer.total ? drawer.page * 50 + 1 : 0)"></bdi>–<bdi dir="ltr" x-text="fmt(Math.min(drawer.total, (drawer.page + 1) * 50))"></bdi> of <bdi dir="ltr" x-text="fmt(drawer.total)"></bdi></span>
                <div style="display: flex; gap: 8px">
                    <button type="button" class="lv-btn" :disabled="drawer.page === 0 || drawer.loading" @click="loadCheck(drawer.page - 1)">Previous</button>
                    <button type="button" class="lv-btn" :disabled="(drawer.page + 1) * 50 >= drawer.total || drawer.loading" @click="loadCheck(drawer.page + 1)">Next</button>
                </div>
            </div>
        </aside>
    </div>

    <script>
        function lvSalesOps(urls) {
            const intF = new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 });
            const qtyF = new Intl.NumberFormat('en-US', { maximumFractionDigits: 2 });
            const PALETTE = ['#2563eb', '#16a34a', '#f59e0b', '#dc2626', '#7c3aed', '#0891b2', '#db2777', '#65a30d', '#ea580c', '#4f46e5', '#0d9488', '#ca8a04'];
            const BLANK = '#9ca3af';
            const FIELD_TABS = [{ tab: 'dataEntry', field: 'data_entry', label: 'Data Entry' }, { tab: 'preparation', field: 'prepared_by', label: 'Preparation' },
                { tab: 'review', field: 'review_by', label: 'Review' }, { tab: 'delivery', field: 'delivery_by', label: 'Delivery' }];
            const CHECKS = [{ key: 'attachment_not_received', label: 'Attachment Not Received' }, { key: 'missing_exit_time', label: 'Missing Exit Time' },
                { key: 'missing_delivery_time', label: 'Missing Delivery Time' }];
            const charts = [];
            // Their pie label: white percentage inside the slice, hidden under 4%.
            const pctLabels = { id: 'soPct', afterDatasetsDraw(chart) {
                const { ctx } = chart; const ds = chart.data.datasets[0]; const total = ds.data.reduce((a, b) => a + b, 0);
                chart.getDatasetMeta(0).data.forEach((arc, i) => {
                    const p = total ? ds.data[i] / total * 100 : 0; if (p < 4) return;
                    const a = (arc.startAngle + arc.endAngle) / 2, r = arc.innerRadius + (arc.outerRadius - arc.innerRadius) * .6;
                    ctx.save(); ctx.fillStyle = '#fff'; ctx.font = '600 11px sans-serif'; ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
                    ctx.fillText(p.toFixed(1) + '%', arc.x + r * Math.cos(a), arc.y + r * Math.sin(a)); ctx.restore();
                });
            } };
            return {
                urls, FIELD_TABS, CHECKS,
                tabs: [{ key: 'overview', label: 'Overview' }, ...FIELD_TABS.map((f) => ({ key: f.tab, label: f.label })), { key: 'postDelivery', label: 'Post-Delivery' }],
                PERIODS: ['lifetime', 'prev_year', 'ytd', 'prev_month', 'mtd', 'yesterday', 'today'],
                PLAB: { lifetime: 'Lifetime', prev_year: 'Previous Year', ytd: 'YTD', prev_month: 'Previous Month', mtd: 'MTD', yesterday: 'Yesterday', today: 'Today' },
                GROUPS: ['invoice_status', 'warehouse', 'e_invoice_status', 'rasd', 'packaging', 'attachment_status'],
                GLAB: { invoice_status: 'Invoice Status', warehouse: 'Warehouse', e_invoice_status: 'E-Invoice Status', rasd: 'RASD', packaging: 'Packaging', attachment_status: 'Invoice Attachment Status' },
                PIES: ['invoice_status', 'warehouse', 'attachment_status'].flatMap((group) => [{ group, period: 'ytd' }, { group, period: 'mtd' }]),
                METRICS: ['inv', 'items', 'qty'],
                // period × metric cells of the employee tables, as one flat list (Inv | Items | Qty under each period).
                PM: ['lifetime', 'prev_year', 'ytd', 'prev_month', 'mtd', 'yesterday', 'today'].flatMap((p) => ['inv', 'items', 'qty'].map((k, i) => ({ p, k, i }))),
                MLAB: { inv: 'Inv', items: 'Items', qty: 'Qty' },
                tab: 'overview', data: {}, st: {},
                drawer: { check: null, page: 0, rows: [], total: 0, loading: false, error: false },
                fmt: (v, qty) => (! v ? '0' : (qty ? qtyF : intF).format(v)),
                dmy: (d) => (d ? d.slice(0, 10).split('-').reverse().join('-') : '—'),
                range(p) { return p ? `${this.dmy(p.from)} – ${this.dmy(p.to)}` : ''; },
                footnote: (d) => `As of ${d ? d.split('-').reverse().join('-') : '—'} (Asia/Riyadh). Counts are distinct invoices.`,
                conflictText: (n) => `${intF.format(n)} invoice(s) have different values on their source rows; the first value is shown.`,
                checkLabel: (k) => (CHECKS.find((c) => c.key === k) || {}).label || '',
                state(t) { return this.st[t] || {}; },
                groupRows(g) {
                    const d = this.data.overview;
                    if (g !== 'packaging') return d.groups[g];
                    return [['carton', 'Carton'], ['icebox', 'Icebox'], ['pallets', 'Pallets']].map(([k, l]) => ({ value: l, blank: false, metrics: d.packaging[k], qty: true }));
                },
                matrixRows() { return this.GROUPS.flatMap((g) => [{ head: true, g }, ...this.groupRows(g).map((c) => ({ head: false, g, c }))]); },
                pieTotal(pc) { return this.data.overview.groups[pc.group].reduce((a, c) => a + c.metrics[pc.period], 0); },
                drawPie(el, pc) {
                    let i = 0;
                    const list = this.data.overview.groups[pc.group].map((c) => ({ c, color: c.blank ? BLANK : PALETTE[i++ % PALETTE.length] }))
                        .filter((x) => x.c.metrics[pc.period] > 0);
                    const total = list.reduce((a, x) => a + x.c.metrics[pc.period], 0);
                    charts.push(new Chart(el, {
                        type: 'pie', plugins: [pctLabels],
                        data: { labels: list.map((x) => (x.c.blank ? 'Blank' : x.c.value)), datasets: [{ data: list.map((x) => x.c.metrics[pc.period]), backgroundColor: list.map((x) => x.color), borderColor: '#fff', borderWidth: 1 }] },
                        options: { responsive: true, maintainAspectRatio: false, animation: false, radius: '75%',
                            plugins: { legend: { position: 'bottom', labels: { font: { size: 11 }, boxWidth: 10 } },
                                tooltip: { callbacks: { label: (t) => `${t.label}: ${intF.format(t.raw)} (${(total ? t.raw / total * 100 : 0).toFixed(1)}%)` } } } },
                    }));
                },
                open(t) { this.tab = t; this.load(t); },
                async load(t, retry) {
                    if (this.data[t] || (this.state(t).loading && ! retry)) return;
                    this.st = { ...this.st, [t]: { loading: true, error: false } };
                    const f = FIELD_TABS.find((x) => x.tab === t);
                    const url = t === 'overview' ? urls.overview : t === 'postDelivery' ? urls.audit : urls.employees + '?field=' + f.field;
                    try {
                        const r = await fetch(url, { headers: { Accept: 'application/json' } });
                        if (! r.ok) throw new Error();
                        this.data = { ...this.data, [t]: await r.json() };
                        this.st = { ...this.st, [t]: { loading: false, error: false } };
                    } catch (e) { this.st = { ...this.st, [t]: { loading: false, error: true } }; }
                },
                openCheck(k) { this.drawer = { check: k, page: 0, rows: [], total: 0, loading: false, error: false }; this.loadCheck(0); },
                async loadCheck(page) {
                    const k = this.drawer.check; this.drawer.loading = true; this.drawer.error = false;
                    try {
                        const r = await fetch(urls.auditList + '?' + new URLSearchParams({ check: k, page }), { headers: { Accept: 'application/json' } });
                        if (! r.ok) throw new Error(); const d = await r.json();
                        if (this.drawer.check !== k) return;
                        Object.assign(this.drawer, { page, rows: d.rows, total: d.total });
                    } catch (e) { this.drawer.error = true; }
                    this.drawer.loading = false;
                },
                init() { this.load('overview'); },
            };
        }
    </script>
</x-app-layout>
