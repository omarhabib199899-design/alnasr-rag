{{-- Sales Orders — exact copy of the Lovable portal's sales.sales-orders.tsx: all rows loaded once, date range / customer /
     sales order # / status filters with cascading counts, chips, click-to-sort columns and 50-row pages, all client-side. --}}
<x-app-layout>
    @include('lovable.shell-styles')

    <div class="lv" x-data="lvSalesOrders()">
        <header>
            <div class="lv-crumb">Sales Data</div>
            <h1>Sales Orders</h1>
            <p class="lv-sub">Sales order data</p>
        </header>

        <section class="lv-panel">
            <div class="lv-bar">
                <label style="width: 9rem"><span class="lv-fld">From</span>
                    <input type="date" dir="ltr" class="lv-in" x-model="from"></label>
                <label style="width: 9rem"><span class="lv-fld">To</span>
                    <input type="date" dir="ltr" class="lv-in" x-model="to"></label>
                <div style="width: 14rem">@include('lovable.multi-select', ['label' => 'Customer Name', 'opts' => 'custOpts', 'sel' => 'custSel'])</div>
                <label style="width: 11rem"><span class="lv-fld">Sales Order#</span>
                    <input dir="ltr" class="lv-in" x-model="soQ"></label>
                <div style="width: 11rem">@include('lovable.multi-select', ['label' => 'Order Status', 'opts' => 'statOpts', 'sel' => 'statSel'])</div>
                <button type="button" class="lv-btn" :disabled="! chips.length" @click="clearAll()">Clear all</button>
            </div>

            <div class="lv-chips" x-show="chips.length" x-cloak>
                <template x-for="c in chips" :key="c.label + ':' + c.value">
                    <button type="button" class="lv-chip" @click="c.clear(); page = 0">
                        <span class="k" x-text="c.label + ':'"></span><span dir="auto" x-text="c.value"></span><span aria-hidden="true">×</span>
                    </button>
                </template>
            </div>

            <div class="lv-scroll">
                <table>
                    <thead><tr>
                        <template x-for="c in cols" :key="c.key">
                            <th @click="toggleSort(c.key)" x-text="c.label + ' ' + (sort && sort.key === c.key ? (sort.dir === 1 ? '▲' : '▼') : '')"></th>
                        </template>
                    </tr></thead>
                    <tbody>
                        <tr x-show="! view.length"><td colspan="5" class="lv-empty">No sales orders match the current filters.</td></tr>
                        <template x-for="(r, i) in view" :key="r[1] + '-' + i">
                            <tr>
                                <td dir="ltr" x-text="r[0]"></td>
                                <td dir="ltr" x-text="r[1]"></td>
                                <td dir="auto" x-text="r[2]"></td>
                                <td dir="auto" x-text="r[3]"></td>
                                <td dir="ltr" class="end" x-text="Number.isFinite(r[5]) ? money(r[5]) : ''"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <div class="lv-foot">
                <span class="m">Showing <bdi dir="ltr" x-text="total ? cur * 50 + 1 : 0"></bdi>–<bdi dir="ltr" x-text="cur * 50 + view.length"></bdi> of <bdi dir="ltr" x-text="total"></bdi></span>
                <div style="display: flex; align-items: center; gap: 8px">
                    <button type="button" class="lv-btn" :disabled="cur === 0" @click="page = cur - 1">Previous</button>
                    <span class="m" x-text="`Page ${cur + 1} of ${pages}`"></span>
                    <button type="button" class="lv-btn" :disabled="cur >= pages - 1" @click="page = cur + 1">Next</button>
                </div>
            </div>
        </section>
    </div>

    <script>
        // ~29k rows stay OUTSIDE Alpine's reactivity: [date, sales order, customer, status, invoiced status, total bcy].
        // Filtering/sorting runs once per change (one pass over the rows, facet counts included) and Alpine only ever
        // holds the 50 visible rows — recomputing 29k rows inside reactive getters is what froze the page before.
        const LV_SO_ROWS = {!! json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) !!};
        let LV_SO_FILTERED = LV_SO_ROWS;

        function lvSalesOrders() {
            const lc = (a, b) => (a || '').localeCompare(b || '', ['ar', 'en'], { numeric: true });
            const fmt = new Intl.NumberFormat('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
            const IDX = { date: 0, so: 1, cust: 2, status: 3 };
            return {
                cols: [{ key: 'date', label: 'Date' }, { key: 'so', label: 'Sales Order#' }, { key: 'cust', label: 'Customer Name' },
                    { key: 'status', label: 'Order Status' }, { key: 'amt', label: 'Amount' }],
                from: '', to: '', soQ: '', custSel: [], statSel: [], sort: null, page: 0,
                view: [], total: 0, custOpts: [], statOpts: [], timer: null,
                money(v) { const s = fmt.format(v); return s === '-0' ? '0' : s; },
                get pages() { return Math.max(1, Math.ceil(this.total / 50)); },
                get cur() { return Math.min(this.page, this.pages - 1); },
                get chips() {
                    return [
                        ...(this.from ? [{ label: 'From', value: this.from, clear: () => this.from = '' }] : []),
                        ...(this.to ? [{ label: 'To', value: this.to, clear: () => this.to = '' }] : []),
                        ...(this.soQ.trim() ? [{ label: 'Sales Order#', value: this.soQ.trim(), clear: () => this.soQ = '' }] : []),
                        ...this.custSel.map((x) => ({ label: 'Customer Name', value: x, clear: () => this.custSel = this.custSel.filter((y) => y !== x) })),
                        ...this.statSel.map((x) => ({ label: 'Order Status', value: x, clear: () => this.statSel = this.statSel.filter((y) => y !== x) })),
                    ];
                },
                recompute() {
                    const so = this.soQ.trim().toLowerCase(), from = this.from, to = this.to;
                    const cs = this.custSel.length ? new Set(this.custSel) : null, ss = this.statSel.length ? new Set(this.statSel) : null;
                    const out = [], cust = new Map(), stat = new Map();
                    for (const r of LV_SO_ROWS) {
                        const dOk = (! from || r[0] >= from) && (! to || r[0] <= to);
                        const sOk = ! so || r[1].toLowerCase().includes(so);
                        if (! dOk || ! sOk) continue;
                        const cOk = ! cs || cs.has(r[2]), tOk = ! ss || ss.has(r[3]);
                        if (tOk && r[2] !== '') cust.set(r[2], (cust.get(r[2]) ?? 0) + 1);   // customer facet ignores its own filter
                        if (cOk && r[3] !== '') stat.set(r[3], (stat.get(r[3]) ?? 0) + 1);   // status facet ignores its own filter
                        if (cOk && tOk) out.push(r);
                    }
                    const opts = (m) => [...m].map(([value, count]) => ({ value, count })).sort((a, b) => lc(a.value, b.value));
                    this.custOpts = opts(cust); this.statOpts = opts(stat);
                    if (this.sort) {
                        const { key, dir } = this.sort, i = IDX[key];
                        out.sort((a, b) => {
                            if (key === 'amt') {
                                const x = a[5], y = b[5];
                                if (x == null || y == null) return x == null ? (y == null ? 0 : 1) : -1;
                                return (x - y) * dir;
                            }
                            const x = a[i], y = b[i];
                            if (! x || ! y) return ! x ? (! y ? 0 : 1) : -1;
                            return (key === 'date' ? (x < y ? -1 : x > y ? 1 : 0) : lc(x, y)) * dir;
                        });
                    }
                    LV_SO_FILTERED = out; this.total = out.length; this.slice();
                },
                slice() { const s = this.cur * 50; this.view = LV_SO_FILTERED.slice(s, s + 50); },
                clearAll() { this.from = ''; this.to = ''; this.soQ = ''; this.custSel = []; this.statSel = []; },
                toggleSort(k) { this.sort = this.sort?.key === k ? { key: k, dir: this.sort.dir === 1 ? -1 : 1 } : { key: k, dir: k === 'date' ? -1 : 1 }; },
                init() {
                    const re = () => { this.page = 0; this.recompute(); };
                    ['from', 'to', 'custSel', 'statSel', 'sort'].forEach((k) => this.$watch(k, re));
                    this.$watch('soQ', () => { clearTimeout(this.timer); this.timer = setTimeout(re, 200); });
                    this.$watch('page', () => this.slice());
                    this.recompute();
                },
            };
        }
    </script>
</x-app-layout>
