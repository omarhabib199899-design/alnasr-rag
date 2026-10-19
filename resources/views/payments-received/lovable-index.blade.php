{{-- Payments Received — copy of the Lovable portal's sales.payments-received.tsx: one row per payment, expandable invoice
     allocations, 5 cascading multiselects + payment date range, sort, 50-row pages, KPI strip (count, collected, applied of ALL
     matched rows). --}}
<x-app-layout>
    @include('lovable.shell-styles')

    <div class="lv" x-data="lvPayments(@js(route('payments-received.data')), @js(route('payments-received.facets')), @js(route('payments-received.allocations', '__ID__')), @js(route('customers.show', '__ID__')), @js(route('invoices.show', '__ID__')))">
        <header>
            <div class="lv-crumb">Sales Data</div>
            <h1>Payments Received</h1>
            <p class="lv-sub">Customer payments and the invoices they were applied to</p>
        </header>

        <section class="lv-panel">
            <div class="lv-bar">
                <div style="width: 16rem">@include('lovable.multi-select', ['label' => 'Customer', 'opts' => 'opts.customer', 'sel' => 'sel.customer'])</div>
                <div style="width: 11rem">@include('lovable.multi-select', ['label' => 'Sales Rep', 'opts' => 'opts.rep', 'sel' => 'sel.rep'])</div>
                <div style="width: 11rem">@include('lovable.multi-select', ['label' => 'Management', 'opts' => 'opts.management', 'sel' => 'sel.management'])</div>
                <div style="width: 11rem">@include('lovable.multi-select', ['label' => 'Sales Owner', 'opts' => 'opts.owner', 'sel' => 'sel.owner'])</div>
                <div style="width: 11rem">@include('lovable.multi-select', ['label' => 'Data Entry', 'opts' => 'opts.entry', 'sel' => 'sel.entry'])</div>
                <label class="lv-fld">Payment Date From <input type="date" dir="ltr" class="lv-in" style="width: 9rem" x-model="dates.from" @input="page = 0"></label>
                <label class="lv-fld">To <input type="date" dir="ltr" class="lv-in" style="width: 9rem" x-model="dates.to" @input="page = 0"></label>
                <span x-show="! dateOk" x-cloak role="alert" style="font-size: 11.5px; color: var(--destructive)">Invalid date range</span>
                <button type="button" class="lv-btn" :disabled="! chips.length" @click="clearAll()">Clear all</button>
                <span class="lv-fld" x-show="loading" x-cloak>Updating…</span>
            </div>
            <div class="lv-chips" x-show="chips.length" x-cloak>
                <template x-for="c in chips" :key="c.label + ':' + c.value">
                    <button type="button" class="lv-chip" @click="c.clear(); page = 0"><span class="k" x-text="c.label + ':'"></span><span dir="auto" x-text="c.value"></span><span aria-hidden="true">×</span></button>
                </template>
            </div>

            {{-- Object form of :style merges with the static style; a string would replace it and drop the grid. --}}
            <div style="display: grid; grid-template-columns: repeat(3, 1fr); border-bottom: 1px solid var(--border)" :style="{ opacity: loading ? .6 : 1 }">
                <template x-for="k in [['Total Payments', total], ['Total Collected (SAR)', sumCollected], ['Total Applied (SAR)', sumApplied]]" :key="k[0]">
                    <div style="padding: 8px 16px; border-inline-end: 1px solid var(--border)">
                        <div style="font-size: 11px; text-transform: uppercase; letter-spacing: .05em; color: var(--muted-fg)" x-text="k[0]"></div>
                        <div style="font-size: 16px; font-weight: 600; font-variant-numeric: tabular-nums"><bdi dir="ltr" x-text="k[0] === 'Total Payments' ? int(k[1]) : m2(k[1])"></bdi></div>
                    </div>
                </template>
            </div>
            <p class="lv-msg err" x-show="error" x-cloak x-text="error"></p>

            <div class="lv-scroll">
                <table>
                    <thead><tr>
                        <th style="cursor: default"></th>
                        <template x-for="c in cols" :key="c.key">
                            <th :class="c.num && 'end'" @click="toggleSort(c.key)" x-text="c.label + ' ' + (sort.key === c.key ? (sort.dir === 'asc' ? '▲' : '▼') : '')"></th>
                        </template>
                    </tr></thead>
                    <tbody x-show="! rows.length && ! loading"><tr><td colspan="6" class="lv-empty">No payments match the current filters.</td></tr></tbody>
                    {{-- One <tbody> per payment: its row + the expandable allocations row. --}}
                    <template x-for="r in rows" :key="r.payment_id">
                            <tbody>
                                <tr>
                                    <td><button type="button" class="lv-btn" style="height: 22px" :aria-expanded="!! open[r.payment_id]" @click="toggle(r)">
                                        <span x-text="open[r.payment_id] ? '▾' : '▸'"></span> <span x-text="r.allocation_count + (r.allocation_count === 1 ? ' invoice' : ' invoices')"></span>
                                    </button></td>
                                    <td dir="ltr" x-text="r.payment_number || '—'"></td>
                                    <td dir="ltr" x-text="dmy(r.payment_date)"></td>
                                    <td dir="auto"><template x-if="r.cust_id && r.customer_name"><a class="lk" dir="auto" :href="custTpl.replace('__ID__', r.cust_id)" x-text="r.customer_name"></a></template><template x-if="! (r.cust_id && r.customer_name)"><span x-text="r.customer_name ?? ''"></span></template></td>
                                    <td x-text="r.payment_mode || '—'"></td>
                                    <td class="end" dir="ltr" x-text="m2(r.total_payment_amount)"></td>
                                </tr>
                                <tr x-show="open[r.payment_id]" x-cloak>
                                    <td colspan="6" style="background: var(--muted); padding: 6px 8px 10px 40px">
                                        <p class="lv-fld" x-show="allocs[r.payment_id] === undefined">Loading…</p>
                                        <p class="lv-fld" x-show="allocs[r.payment_id] === null" style="color: var(--destructive)">Could not load invoices.</p>
                                        <table x-show="Array.isArray(allocs[r.payment_id])" style="min-width: 0; width: max-content">
                                            <thead><tr>
                                                <template x-for="h in ['Invoice Number', 'Applied Amount', 'Invoice Due Date', 'Invoice Current Balance', 'Days to Payment', 'Days vs Due Date', 'Invoice Payment Coverage %']" :key="h">
                                                    <th style="cursor: default" :class="h !== 'Invoice Number' && h !== 'Invoice Due Date' && 'end'" x-text="h"></th>
                                                </template>
                                            </tr></thead>
                                            <tbody>
                                                <template x-for="a in (allocs[r.payment_id] || [])" :key="a.invoice_payment_id">
                                                    <tr>
                                                        <td dir="ltr"><template x-if="a.invoice_id"><a class="lk" :href="invTpl.replace('__ID__', a.invoice_id)" x-text="a.invoice_number"></a></template><template x-if="! a.invoice_id"><span x-text="a.invoice_number || '—'"></span></template></td>
                                                        <td class="end" dir="ltr" x-text="m2(a.applied_amount)"></td>
                                                        <td dir="ltr" x-text="dmy(a.invoice_due_date)"></td>
                                                        <td class="end" dir="ltr" x-text="m2(a.invoice_current_balance)"></td>
                                                        <td class="end" dir="ltr" x-text="a.days_to_payment ?? '—'"></td>
                                                        <td class="end" dir="ltr" x-text="a.days_vs_due_date ?? '—'"></td>
                                                        <td class="end" dir="ltr" x-text="a.invoice_payment_coverage_pct == null ? '—' : pct(a.invoice_payment_coverage_pct) + '%'"></td>
                                                    </tr>
                                                </template>
                                            </tbody>
                                        </table>
                                    </td>
                                </tr>
                            </tbody>
                    </template>
                </table>
            </div>

            <div class="lv-foot">
                <span class="m">Showing <bdi dir="ltr" x-text="total ? int(cur * 50 + 1) : 0"></bdi>–<bdi dir="ltr" x-text="int(cur * 50 + rows.length)"></bdi> of <bdi dir="ltr" x-text="int(total)"></bdi><span x-show="total !== totalAll"> (filtered from <bdi dir="ltr" x-text="int(totalAll)"></bdi>)</span></span>
                <div style="display: flex; align-items: center; gap: 8px">
                    <button type="button" class="lv-btn" :disabled="cur === 0 || loading" @click="page = cur - 1">Previous</button>
                    <span class="m" x-text="`Page ${cur + 1} of ${pages}`"></span>
                    <button type="button" class="lv-btn" :disabled="cur >= pages - 1 || loading" @click="page = cur + 1">Next</button>
                </div>
            </div>
        </section>
    </div>

    <script>
        function lvPayments(dataUrl, facetUrl, allocTpl, custTpl, invTpl) {
            const FILTERS = ['customer', 'rep', 'management', 'owner', 'entry'];
            const LAB = { customer: 'Customer', rep: 'Sales Rep', management: 'Management', owner: 'Sales Owner', entry: 'Data Entry' };
            const empty = () => Object.fromEntries(FILTERS.map((k) => [k, []]));
            const f2 = new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            const fp = new Intl.NumberFormat('en-US', { maximumFractionDigits: 2 });
            return {
                custTpl, invTpl,
                cols: [['number', 'Payment Number'], ['date', 'Payment Date'], ['customer', 'Customer'], ['mode', 'Payment Mode'], ['amount', 'Total Payment Amount', 1]]
                    .map((c) => ({ key: c[0], label: c[1], num: !!c[2] })),
                sel: empty(), opts: empty(), dates: { from: '', to: '' }, sort: { key: 'date', dir: 'desc' }, page: 0,
                rows: [], total: 0, totalAll: 0, sumCollected: 0, sumApplied: 0, loading: true, error: '', timer: null, seq: 0,
                open: {}, allocs: {},
                int: (v) => Number(v).toLocaleString('en-US'),
                m2: (v) => (v == null ? '—' : f2.format(v)), pct: (v) => fp.format(v),
                dmy: (d) => (d ? d.slice(0, 10).split('-').reverse().join('-') : '—'),
                get pages() { return Math.max(1, Math.ceil(this.total / 50)); },
                get cur() { return Math.min(this.page, this.pages - 1); },
                get dateOk() { return ! (this.dates.from && this.dates.to && this.dates.from > this.dates.to); },
                get chips() {
                    return [...[['from', 'Payment Date · From'], ['to', 'Payment Date · To']].filter(([k]) => this.dates[k])
                        .map(([k, l]) => ({ label: l, value: this.dmy(this.dates[k]), clear: () => this.dates[k] = '' })),
                        ...FILTERS.flatMap((k) => this.sel[k].map((x) => ({ label: LAB[k], value: x, clear: () => this.sel[k] = this.sel[k].filter((y) => y !== x) })))];
                },
                query(withPage) {
                    const p = new URLSearchParams();
                    FILTERS.forEach((k) => this.sel[k].forEach((v) => p.append(k + '[]', v)));
                    if (this.dates.from) p.set('date_from', this.dates.from);
                    if (this.dates.to) p.set('date_to', this.dates.to);
                    if (withPage) { p.set('sort', this.sort.key); p.set('dir', this.sort.dir); p.set('page', this.page); }
                    return p.toString();
                },
                async load() {
                    if (! this.dateOk) return;
                    const my = ++this.seq; this.loading = true; this.error = '';
                    try {
                        const r = await fetch(dataUrl + '?' + this.query(true), { headers: { Accept: 'application/json' } });
                        if (! r.ok) throw new Error();
                        const d = await r.json(); if (my !== this.seq) return;
                        Object.assign(this, { rows: d.rows, total: d.total, totalAll: d.total_all, sumCollected: d.sum_collected, sumApplied: d.sum_applied });
                        if (this.page > this.pages - 1) this.page = this.pages - 1;
                    } catch (e) { if (my === this.seq) this.error = 'Could not load payments.'; }
                    if (my === this.seq) this.loading = false;
                },
                async loadFacets() {
                    if (! this.dateOk) return;
                    try {
                        const r = await fetch(facetUrl + '?' + this.query(false), { headers: { Accept: 'application/json' } });
                        if (! r.ok) return; const d = await r.json();
                        // keep selected-but-absent values visible with count 0
                        FILTERS.forEach((k) => { const o = d[k] || []; this.sel[k].forEach((x) => { if (! o.some((b) => b.value === x)) o.push({ value: x, count: 0 }); }); this.opts[k] = o; });
                    } catch (e) { /* options keep their previous values */ }
                },
                // Allocations are fetched once per payment and kept (theirs: react-query cache, no refetch on re-expand).
                async toggle(r) {
                    const id = r.payment_id;
                    this.open = { ...this.open, [id]: ! this.open[id] };
                    if (! this.open[id] || this.allocs[id]) return;
                    try {
                        const res = await fetch(allocTpl.replace('__ID__', id), { headers: { Accept: 'application/json' } });
                        if (! res.ok) throw new Error();
                        this.allocs = { ...this.allocs, [id]: await res.json() };
                    } catch (e) { this.allocs = { ...this.allocs, [id]: null }; }
                },
                clearAll() { this.sel = empty(); this.dates = { from: '', to: '' }; this.page = 0; },
                toggleSort(k) { this.sort = this.sort.key === k ? { key: k, dir: this.sort.dir === 'asc' ? 'desc' : 'asc' } : { key: k, dir: 'asc' }; this.page = 0; },
                init() {
                    const debounced = () => { clearTimeout(this.timer); this.timer = setTimeout(() => { this.load(); this.loadFacets(); }, 300); };
                    this.$watch('sel', () => debounced());
                    this.$watch('dates', () => debounced());
                    this.$watch('sort', () => this.load());
                    this.$watch('page', () => this.load());
                    this.load(); this.loadFacets();
                },
            };
        }
    </script>
</x-app-layout>
