{{-- Invoice Details — copy of the Lovable portal's sales.invoices_.$invoiceId.tsx: invoice information, operations, sales orders, paged item/batch lines. --}}
<x-app-layout>
    @include('lovable.shell-styles')
    @php
        $dmy = fn ($d) => $d ? implode('-', array_reverse(explode('-', substr((string) $d, 0, 10)))) : '—';
        $m2 = fn ($v) => $v === null ? '—' : number_format((float) $v, 2);
        $dt = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('d-m-Y H:i') : null;
        $num = fn ($v) => $v === null || $v === '' ? null : rtrim(rtrim(number_format((float) $v, 2, '.', ','), '0'), '.');
        $info = [
            ['Invoice Date', $dmy($inv->transaction_date)], ['Invoice Number', $inv->transaction_number ?: '—'], ['Customer Number', $inv->customer_number ?: '—'],
            ['Amount', $m2($inv->statement_amount)], ['Balance', $m2($inv->balance)], ['Due Date', $dmy($inv->inv_balance_due_date)],
            ['COGS', $m2($inv->cogs)], ['Gross Profit', $m2($inv->gross_profit)], ['GP %', $inv->gp_pct === null ? '—' : $m2($inv->gp_pct).'%'], ['Balance Aging', $inv->balance_aging ?: '—'],
        ];
        // zoho_sales_operations column => [label, optional formatter]. total_margin / no_of_errors_after_delivery are always empty in Zoho, so left out.
        $opsMap = [
            'warehause' => ['WAREHOUSE'], 'pricelist_name' => ['PRICE LIST'], 'e_invoice_status' => ['E-INVOICE STATUS'],
            'total_bcy' => ['TOTAL (BCY)', $m2], 'invoice_item_count' => ['ITEM COUNT', $num], 'invoice_item_qty' => ['ITEM QTY', $num],
            'invoice_data_entry' => ['INVOICE DATA ENTRY'], 'sales_order_data_entry' => ['SALES ORDER DATA ENTRY'],
            'prepared_by' => ['PREPARED BY'], 'review_by' => ['REVIEW BY'], 'delivery_by' => ['DELIVERY BY'],
            'exit_date_time' => ['EXIT DATE TIME', $dt], 'delivery_date_time' => ['DELIVERY DATE TIME', $dt],
            'customer_check' => ['CUSTOMER CHECK'], 'rasd' => ['RASD'],
            'invoice_attachment_status' => ['INVOICE ATTACHMENT STATUS'], 'invoice_attached_by' => ['INVOICE ATTACHED BY'],
            'carton_no' => ['CARTON NO', $num], 'icebox_no' => ['ICEBOX NO', $num], 'pallets_no' => ['PALLETS NO', $num],
        ];
        $backUrl = route('invoices.index').($back ? '?state='.urlencode($back) : '');
    @endphp
    <style>
        .lv-sec { border-bottom: 1px solid var(--border); } .lv-sec > h2 { margin: 0; border-bottom: 1px solid var(--border); background: var(--muted); padding: 8px 12px;
            font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; }
        .lv-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(11rem, 1fr)); }
        .lv-grid > div { border-bottom: 1px solid var(--border); border-inline-end: 1px solid var(--border); padding: 8px 12px; min-width: 0; }
        .lv-grid .l { font-size: 11px; text-transform: uppercase; letter-spacing: .05em; color: var(--muted-fg); } .lv-grid .v { margin-top: 2px; font-size: 13px; font-weight: 500; word-break: break-word; }
    </style>

    <div class="lv" x-data="lvInvoiceItems(@js(route('invoices.items', $inv->entity_id)))">
        <header>
            <div class="lv-crumb">Sales Data / Invoices</div>
            <h1 dir="auto">{{ $inv->customer_name ?: 'Invoice Details' }}</h1>
            <p class="lv-sub">Invoice {{ $inv->transaction_number }}</p>
        </header>

        <section class="lv-panel">
            <div style="display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px; border-bottom: 1px solid var(--border); padding: 12px">
                <a href="{{ $backUrl }}" class="lv-btn" style="display: inline-flex; align-items: center; text-decoration: none">← Back to Invoices</a>
                @if ($inv->local_customer_id)<a class="lk" style="font-size: 12px" href="{{ route('customers.show', $inv->local_customer_id) }}">Open Customer 360</a>@endif
            </div>

            <div class="lv-sec"><h2>Invoice Information</h2>
                <div class="lv-grid">@foreach ($info as [$l, $v])<div><div class="l">{{ $l }}</div><div class="v" dir="ltr">{{ $v }}</div></div>@endforeach</div>
            </div>

            <div class="lv-sec"><h2>Operations</h2>
                @if ($ops === null)
                    <p class="lv-msg">No Sales Operations record for this invoice.</p>
                @else
                    <div class="lv-grid">
                        @if ($opsStatus)<div><div class="l">Invoice Status</div><div class="v">{{ $opsStatus }}</div></div>@endif
                        @foreach ($opsMap as $k => $def)
                            @php
                                $raw = $ops[$k] ?? null;
                                $val = ($raw === null || $raw === '') ? null : (isset($def[1]) ? $def[1]($raw) : $raw);
                            @endphp
                            <div><div class="l">{{ $def[0] }}</div><div class="v" dir="auto">{{ $val ?? '—' }}</div></div>
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="lv-sec"><h2>Sales Orders <span style="font-weight: 400; color: var(--muted-fg)">({{ $salesOrders->count() }})</span></h2>
                @if ($salesOrders->isEmpty())
                    <p class="lv-msg">No linked sales orders.</p>
                @else
                    <div style="display: flex; flex-wrap: wrap; gap: 6px; padding: 10px 12px">
                        @foreach ($salesOrders as $so)
                            <span class="lv-chip" style="cursor: default" dir="ltr">{{ $so }}</span>
                        @endforeach
                    </div>
                @endif
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
                            <tr x-show="! rows.length && ! loading"><td colspan="11" class="lv-empty" x-text="totalAll ? 'No items match the current search.' : 'No item rows for this invoice.'"></td></tr>
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
                                    <td class="end" dir="ltr" x-text="m2(r.cogs)"></td>
                                    <td class="end" dir="ltr" x-text="m2(r.gp)"></td>
                                    <td class="end" dir="ltr" x-text="r.gp_pct == null ? '—' : m2(r.gp_pct) + '%'"></td>
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
        function lvInvoiceItems(url) {
            const f2 = new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            const f0 = new Intl.NumberFormat('en-US', { maximumFractionDigits: 2 });
            return {
                cols: [['rn', '#', 1], ['name', 'Name'], ['price', 'Price', 1], ['qty', 'Quantity', 1], ['batch', 'Batch'], ['expiry', 'Expiry'], ['discount', '%', 1],
                    ['total', 'Total', 1], ['cogs', 'COGS', 1], ['gp', 'GP', 1], ['gp_pct', 'GP %', 1]].map((c) => ({ key: c[0], label: c[1], num: !!c[2] })),
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