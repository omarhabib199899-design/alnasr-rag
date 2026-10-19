{{-- Credit Note Details — copy of the Lovable portal's sales.credit-notes_.$creditNoteId.tsx: header fields, paged item/batch lines. --}}
<x-app-layout>
    @include('lovable.shell-styles')
    @php
        $dmy = fn ($d) => $d ? implode('-', array_reverse(explode('-', substr((string) $d, 0, 10)))) : '—';
        $m2 = fn ($v) => $v === null ? '—' : number_format((float) $v, 2);
        // As theirs: Balance is left out only when null, Balance Aging only when blank; COGS shows — when null.
        $info = array_filter([
            ['Credit Note Date', $dmy($cn->transaction_date)], ['Credit Note Number', $cn->transaction_number ?: '—'],
            ['Customer Number', $cn->customer_number ?: '—'], ['Amount', $m2($cn->statement_amount)],
            $cn->balance === null ? null : ['Balance', $m2($cn->balance)], ['COGS', $m2($cn->cogs)],
            trim((string) $cn->balance_aging) === '' ? null : ['Balance Aging', $cn->balance_aging],
        ]);
        $backUrl = route('credit-notes.index').($back ? '?state='.urlencode($back) : '');
    @endphp
    <style>
        .lv-sec { border-bottom: 1px solid var(--border); } .lv-sec > h2 { margin: 0; border-bottom: 1px solid var(--border); background: var(--muted); padding: 8px 12px;
            font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; }
        .lv-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(11rem, 1fr)); }
        .lv-grid > div { border-bottom: 1px solid var(--border); border-inline-end: 1px solid var(--border); padding: 8px 12px; min-width: 0; }
        .lv-grid .l { font-size: 11px; text-transform: uppercase; letter-spacing: .05em; color: var(--muted-fg); } .lv-grid .v { margin-top: 2px; font-size: 13px; font-weight: 500; word-break: break-word; }
    </style>

    <div class="lv" x-data="lvCreditNoteItems(@js(route('credit-notes.items', $cn->entity_id)))">
        <header>
            <div class="lv-crumb">Sales Data / Credit Notes</div>
            <h1 dir="auto">{{ trim((string) $cn->customer_name) !== '' ? $cn->customer_name : 'Credit Note Details' }}</h1>
            <p class="lv-sub">Credit Note {{ $cn->transaction_number }}</p>
        </header>

        <section class="lv-panel">
            <div style="display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px; border-bottom: 1px solid var(--border); padding: 12px">
                <a href="{{ $backUrl }}" class="lv-btn" style="display: inline-flex; align-items: center; text-decoration: none">← Back to Credit Notes</a>
                @if ($cn->local_customer_id)<a class="lk" style="font-size: 12px" href="{{ route('customers.show', $cn->local_customer_id) }}">Open Customer 360</a>@endif
            </div>

            <div class="lv-sec"><h2>Credit Note Information</h2>
                <div class="lv-grid">@foreach ($info as [$l, $v])<div><div class="l">{{ $l }}</div><div class="v" dir="ltr">{{ $v }}</div></div>@endforeach</div>
            </div>

            <div class="lv-sec"><h2>Items</h2>
                <div style="display: flex; flex-wrap: wrap; align-items: center; gap: 8px; border-bottom: 1px solid var(--border); padding: 12px">
                    <input class="lv-in" style="width: 16rem" placeholder="Search items or batches…" x-model="search" @input="page = 0">
                    <span class="lv-fld" x-show="loading" x-cloak>Updating…</span>
                </div>
                <p class="lv-msg err" x-show="error" x-cloak x-text="error"></p>
                <div class="lv-scroll" style="max-height: calc(100vh - 16rem); min-height: 12rem">
                    <table>
                        <thead><tr>
                            <template x-for="c in cols" :key="c.key">
                                <th :class="c.num && 'end'" @click="c.key !== 'rn' && toggleSort(c.key)" x-text="c.label + ' ' + (sort.key === c.key ? (sort.dir === 'asc' ? '▲' : '▼') : '')"></th>
                            </template>
                        </tr></thead>
                        <tbody>
                            <tr x-show="! rows.length && ! loading"><td colspan="8" class="lv-empty" x-text="totalAll ? 'No items match the current search.' : 'No item rows for this credit note.'"></td></tr>
                            <template x-for="r in rows" :key="r.rn">
                                <tr>
                                    <td class="end" dir="ltr" x-text="int(r.rn)"></td>
                                    <td dir="auto" style="min-width: 16rem" x-text="r.name || '—'"></td>
                                    <td class="end" dir="ltr" x-text="m2(r.price)"></td>
                                    <td class="end" dir="ltr" x-text="plain(r.qty)"></td>
                                    <td dir="ltr" x-text="r.batch || '—'"></td>
                                    <td dir="ltr" x-text="dmy(r.expiry)"></td>
                                    <td class="end" dir="ltr" x-text="plain(r.discount)"></td>
                                    <td class="end" dir="ltr" x-text="m2(r.total)"></td>
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
            </div>
        </section>
    </div>

    <script>
        function lvCreditNoteItems(url) {
            const f2 = new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            const f0 = new Intl.NumberFormat('en-US', { maximumFractionDigits: 2 });
            return {
                cols: [['rn', '#', 1], ['name', 'Name'], ['price', 'Price', 1], ['qty', 'Quantity', 1], ['batch', 'Batch'], ['expiry', 'Expiry'],
                    ['discount', '%', 1], ['total', 'Total', 1]].map((c) => ({ key: c[0], label: c[1], num: !!c[2] })),
                search: '', sort: { key: 'name', dir: 'asc' }, page: 0, rows: [], total: 0, totalAll: 0, loading: true, error: '', timer: null, seq: 0,
                int: (v) => Number(v).toLocaleString('en-US'),
                m2: (v) => (v == null ? '—' : f2.format(v)), plain: (v) => (v == null ? '—' : f0.format(v)),
                dmy: (d) => (d ? d.slice(0, 10).split('-').reverse().join('-') : '—'),
                get pages() { return Math.max(1, Math.ceil(this.total / 50)); },
                get cur() { return Math.min(this.page, this.pages - 1); },
                toggleSort(k) { this.sort = this.sort.key === k ? { key: k, dir: this.sort.dir === 'asc' ? 'desc' : 'asc' } : { key: k, dir: 'asc' }; this.page = 0; },
                async load() {
                    const my = ++this.seq; this.loading = true; this.error = '';
                    const p = new URLSearchParams({ sort: this.sort.key, dir: this.sort.dir, page: this.page });
                    if (this.search.trim()) p.set('search', this.search.trim());
                    try {
                        const r = await fetch(url + '?' + p, { headers: { Accept: 'application/json' } });
                        if (! r.ok) throw new Error(); const d = await r.json(); if (my !== this.seq) return;
                        Object.assign(this, { rows: d.rows, total: d.total, totalAll: d.total_all });
                    } catch (e) { if (my === this.seq) this.error = 'Could not load items.'; }
                    if (my === this.seq) this.loading = false;
                },
                init() {
                    this.$watch('search', () => { clearTimeout(this.timer); this.timer = setTimeout(() => this.load(), 300); });
                    this.$watch('sort', () => this.load()); this.$watch('page', () => this.load());
                    this.load();
                },
            };
        }
    </script>
</x-app-layout>
