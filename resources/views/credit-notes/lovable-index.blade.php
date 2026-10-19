{{-- Credit Notes — copy of the Lovable portal's sales.credit-notes.tsx: server-side search / 5 cascading multiselects / date
     range / sort / 50-row pages, KPI strip (count and amount of ALL matched rows), "Back to Credit Notes" state carried in ?state=. --}}
<x-app-layout>
    @include('lovable.shell-styles')
    @php
        $people = ['rep' => 'sales-reps', 'entry' => 'data-entry', 'owner' => 'sales-owners'];
        $personUrls = collect($people)->map(fn ($p) => route($p.'.show', '__N__'))->all();
    @endphp

    <div class="lv" x-data="lvCreditNotes(@js(route('credit-notes.data')), @js(route('credit-notes.facets')), @js(route('credit-notes.show', '__ID__')), @js(route('customers.show', '__ID__')), @js($personUrls))">
        <header>
            <div class="lv-crumb">Sales Data</div>
            <h1>Credit Notes</h1>
            <p class="lv-sub">Customer credit notes</p>
        </header>

        <section class="lv-panel">
            <div class="lv-bar">
                <input class="lv-in" style="width: 16rem" placeholder="Search credit notes…" x-model="search" @input="page = 0">
                <div style="width: 11rem">@include('lovable.multi-select', ['label' => 'Sales Rep', 'opts' => 'opts.rep', 'sel' => 'sel.rep'])</div>
                <div style="width: 11rem">@include('lovable.multi-select', ['label' => 'Data Entry', 'opts' => 'opts.entry', 'sel' => 'sel.entry'])</div>
                <div style="width: 11rem">@include('lovable.multi-select', ['label' => 'Sales Owner', 'opts' => 'opts.owner', 'sel' => 'sel.owner'])</div>
                <div style="width: 11rem">@include('lovable.multi-select', ['label' => 'Management', 'opts' => 'opts.management', 'sel' => 'sel.management'])</div>
                <div style="width: 11rem">@include('lovable.multi-select', ['label' => 'Warehouse', 'opts' => 'opts.warehouse', 'sel' => 'sel.warehouse'])</div>
                <label class="lv-fld">From <input type="date" dir="ltr" class="lv-in" style="width: 9rem" :max="dates.to || null" x-model="dates.from" @input="page = 0"></label>
                <label class="lv-fld">To <input type="date" dir="ltr" class="lv-in" style="width: 9rem" :min="dates.from || null" x-model="dates.to" @input="page = 0"></label>
                <span x-show="! dateOk" x-cloak role="alert" style="font-size: 11.5px; color: var(--destructive)">Invalid date range</span>
                <button type="button" class="lv-btn" :disabled="! chips.length && ! search.trim()" @click="clearAll()">Clear all</button>
                <span class="lv-fld" x-show="loading" x-cloak>Updating…</span>
            </div>
            <div class="lv-chips" x-show="chips.length" x-cloak>
                <template x-for="c in chips" :key="c.label + ':' + c.value">
                    <button type="button" class="lv-chip" @click="c.clear(); page = 0"><span class="k" x-text="c.label + ':'"></span><span dir="auto" x-text="c.value"></span><span aria-hidden="true">×</span></button>
                </template>
            </div>

            {{-- Object form of :style merges with the static style; a string would replace it and drop the grid. --}}
            <div style="display: grid; grid-template-columns: repeat(2, 1fr); border-bottom: 1px solid var(--border)" :style="{ opacity: loading ? .6 : 1 }">
                <template x-for="k in [['Total Credit Notes', total], ['Total Amount (SAR)', sumAmount]]" :key="k[0]">
                    <div style="padding: 8px 16px; border-inline-end: 1px solid var(--border)">
                        <div style="font-size: 11px; text-transform: uppercase; letter-spacing: .05em; color: var(--muted-fg)" x-text="k[0]"></div>
                        <div style="font-size: 16px; font-weight: 600; font-variant-numeric: tabular-nums"><bdi dir="ltr" x-text="k[0] === 'Total Credit Notes' ? int(k[1]) : money2(k[1])"></bdi></div>
                    </div>
                </template>
            </div>
            <p class="lv-msg err" x-show="error" x-cloak x-text="error"></p>

            <div class="lv-scroll">
                <table>
                    <thead><tr>
                        <template x-for="c in cols" :key="c.key">
                            <th :class="c.num && 'end'" @click="toggleSort(c.key)" x-text="c.label + ' ' + (sort.key === c.key ? (sort.dir === 'asc' ? '▲' : '▼') : '')"></th>
                        </template>
                    </tr></thead>
                    <tbody>
                        <tr x-show="! rows.length && ! loading"><td colspan="8" class="lv-empty">No credit notes match the current search and filters.</td></tr>
                        <template x-for="r in rows" :key="r.id">
                            <tr>
                                <td dir="ltr" x-text="dmy(r.date)"></td>
                                <td><a class="lk" dir="ltr" :href="showUrl(r)" x-text="r.number"></a></td>
                                <td dir="auto"><template x-if="r.cust_id && r.customer"><a class="lk" dir="auto" :href="custTpl.replace('__ID__', r.cust_id)" x-text="r.customer"></a></template><template x-if="! (r.cust_id && r.customer)"><span x-text="r.customer ?? ''"></span></template></td>
                                <td class="end" dir="ltr" x-text="money2(r.amount, true)"></td>
                                <template x-for="p in ['rep', 'entry', 'owner']" :key="p">
                                    <td><template x-if="r[p]"><a class="lk" dir="auto" :href="personTpl[p].replace('__N__', encodeURIComponent(r[p]))" x-text="r[p]"></a></template></td>
                                </template>
                                <td dir="ltr" x-text="r.warehouse || '—'"></td>
                            </tr>
                        </template>
                    </tbody>
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
        function lvCreditNotes(dataUrl, facetUrl, showTpl, custTpl, personTpl) {
            const FILTERS = ['rep', 'entry', 'owner', 'management', 'warehouse'];
            const LAB = { rep: 'Sales Rep', entry: 'Data Entry', owner: 'Sales Owner', management: 'Management', warehouse: 'Warehouse' };
            const empty = () => Object.fromEntries(FILTERS.map((k) => [k, []]));
            const money = new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            const saved = (() => { try { return JSON.parse(new URLSearchParams(location.search).get('state') || '{}') || {}; } catch (e) { return {}; } })();
            return {
                custTpl, personTpl,
                cols: [['date', 'Date'], ['number', 'Credit Note Number'], ['customer', 'Customer Name'], ['amount', 'Amount', 1],
                    ['rep', 'Sales Rep'], ['entry', 'Data Entry'], ['owner', 'Sales Owner'], ['warehouse', 'Warehouse']].map((c) => ({ key: c[0], label: c[1], num: !!c[2] })),
                search: saved.q || '', page: saved.page || 0, sort: saved.sort || { key: 'date', dir: 'desc' },
                sel: Object.assign(empty(), saved.sel || {}), dates: Object.assign({ from: '', to: '' }, saved.dates || {}), opts: empty(),
                rows: [], total: 0, totalAll: 0, sumAmount: 0, loading: true, error: '', timer: null, seq: 0,
                int: (v) => Number(v).toLocaleString('en-US'),
                money2(v, blank) { return v == null || Number.isNaN(v) ? (blank ? '' : '—') : money.format(v); },
                dmy: (d) => (d ? d.slice(0, 10).split('-').reverse().join('-') : ''),
                get pages() { return Math.max(1, Math.ceil(this.total / 50)); },
                get cur() { return Math.min(this.page, this.pages - 1); },
                get dateOk() { return ! (this.dates.from && this.dates.to && this.dates.from > this.dates.to); },
                get chips() {
                    return [...[['from', 'Date · From'], ['to', 'Date · To']].filter(([k]) => this.dates[k])
                        .map(([k, l]) => ({ label: l, value: this.dmy(this.dates[k]), clear: () => this.dates[k] = '' })),
                        ...FILTERS.flatMap((k) => this.sel[k].map((x) => ({ label: LAB[k], value: x, clear: () => this.sel[k] = this.sel[k].filter((y) => y !== x) })))];
                },
                query(withPage) {
                    const p = new URLSearchParams();
                    if (this.search.trim()) p.set('search', this.search.trim());
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
                        Object.assign(this, { rows: d.rows, total: d.total, totalAll: d.total_all, sumAmount: d.sum_amount });
                        if (this.page > this.pages - 1) this.page = this.pages - 1;
                    } catch (e) { if (my === this.seq) this.error = 'Could not load credit notes.'; }
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
                // Clear All keeps the sort, as theirs.
                clearAll() { this.search = ''; this.sel = empty(); this.dates = { from: '', to: '' }; this.page = 0; },
                toggleSort(k) { this.sort = this.sort.key === k ? { key: k, dir: this.sort.dir === 'asc' ? 'desc' : 'asc' } : { key: k, dir: 'asc' }; this.page = 0; },
                backState() { return JSON.stringify({ q: this.search, sel: this.sel, sort: this.sort, page: this.cur, dates: this.dates }); },
                showUrl(r) { return showTpl.replace('__ID__', r.id) + '?back=' + encodeURIComponent(this.backState()); },
                init() {
                    const debounced = () => { clearTimeout(this.timer); this.timer = setTimeout(() => { this.load(); this.loadFacets(); }, 300); };
                    this.$watch('search', debounced);
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
