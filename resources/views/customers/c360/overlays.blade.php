{{-- Shared by Customer 360 and the Entity 360 pages: expanded-chart dialog, AR invoice drawer, item insight / expiry drawer,
     and the c360Page() Alpine component behind them (Entity 360 extends it). --}}
        {{-- Expanded chart (their Dialog: 95vw/92vw × 85vh) --}}
        <template x-if="chart"><div class="c3-overlay" @click="closeChart()"></div></template>
        <div class="c3-dialog" x-show="chart" x-cloak role="dialog" aria-modal="true" @keydown.escape.window="closeChart()">
            <div class="c3-dialog-h"><span x-html="chart && chart.icon"></span><h3 x-text="chart && chart.title"></h3></div>
            <button type="button" class="c3-close" @click="closeChart()" aria-label="Close"><i data-lucide="x" class="ico"></i></button>
            <div class="c3-dialog-b">
                <div class="c3-chart-box" dir="ltr"><canvas x-ref="bigCanvas"></canvas></div>
                <p class="c3-note" x-html="chart && chart.note"></p>
            </div>
        </div>

        {{-- Invoice drawer behind Outstanding / Overdue Invoices Count (their ArInvoiceDrawer) --}}
        <template x-if="drawer"><div class="c3-overlay" @click="drawer = null"></div></template>
        <aside class="c3-drawer" x-show="drawer" x-cloak role="dialog" aria-modal="true" @keydown.escape.window="drawer = null">
            <div class="c3-drawer-h">
                <h3 x-text="drawer && drawer.title"></h3>
                <p x-text="drawerRows.length ? 'Snapshot as of ' + dmy(drawerRows[0].as_of_date) : 'Invoice records behind this KPI.'"></p>
            </div>
            <button type="button" class="c3-close" @click="drawer = null" aria-label="Close"><i data-lucide="x" class="ico"></i></button>
            <div class="c3-drawer-b">
                <p x-show="drawerState === 'loading'" style="padding: 24px 16px; font-size: 13px; color: var(--c360-label)">Loading invoices…</p>
                <p x-show="drawerState === 'error'" style="padding: 24px 16px; font-size: 13px; color: var(--c360-destructive)">Could not load invoices.</p>
                <p x-show="drawerState === 'ready' && ! drawerRows.length" style="padding: 24px 16px; font-size: 13px; color: var(--c360-label)">No invoices.</p>
                <table class="c3-list" x-show="drawerState === 'ready' && drawerRows.length">
                    <thead><tr>
                        <th style="text-align: start">Inv No</th><th style="text-align: start">Date</th><th style="text-align: start">Due Date</th>
                        <th style="text-align: end">Amount</th><th style="text-align: end">Balance</th><th style="text-align: end">Age</th>
                    </tr></thead>
                    <tbody>
                        <template x-for="(r, i) in drawerRows" :key="i">
                            <tr>
                                <td style="color: var(--c360-heading)"><span dir="auto" x-text="r.transaction_number ?? '—'"></span></td>
                                <td class="tnum"><bdi dir="ltr" x-text="slash(r.transaction_date)"></bdi></td>
                                <td class="tnum"><bdi dir="ltr" x-text="slash(r.due_date)"></bdi></td>
                                <td class="tnum" style="text-align: end"><bdi dir="ltr" x-text="money(r.amount)"></bdi></td>
                                <td class="tnum" style="text-align: end; font-weight: 600; color: var(--c360-heading)"><bdi dir="ltr" x-text="money(r.balance)"></bdi></td>
                                <td class="tnum" style="text-align: end"><bdi dir="ltr" x-text="r.age_days === null ? '—' : r.age_days.toLocaleString('en-US')"></bdi></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </aside>

        {{-- Items behind an item insight / batches approaching expiry (their ItemInsightsDrawer / ItemExpiryDrawer) --}}
        <template x-if="idrawer"><div class="c3-overlay" @click="idrawer = null"></div></template>
        <aside class="c3-drawer" x-show="idrawer" x-cloak role="dialog" aria-modal="true" @keydown.escape.window="idrawer = null">
            <div class="c3-drawer-h">
                <h3 x-text="idrawer && idrawer.title"></h3>
                <template x-if="idrawer && idrawer.mode === 'items'">
                    <p><span x-text="`Previous 60D: ${dmy(idrawer.meta.p0)} – ${dmy(idrawer.meta.p1)} · Last 60D: ${dmy(idrawer.meta.c0)} – ${dmy(idrawer.meta.d)}`"></span><span
                        x-show="idrawerRows.length && idrawerRows[0].total_qualifying > idrawerRows.length"
                        x-text="idrawerRows.length ? ` · Showing ${idrawerRows.length.toLocaleString('en-US')} of ${idrawerRows[0].total_qualifying.toLocaleString('en-US')} items` : ''"></span></p>
                </template>
                <template x-if="idrawer && idrawer.mode === 'expiry'">
                    <div>
                        <p x-text="`Sales: ${dmy(idrawer.meta.sales_start)} – ${dmy(idrawer.meta.d)} · Expiry: ${idrawer.meta.e0} – ${idrawer.meta.e1}`"></p>
                        <p style="margin-top: 4px; font-size: 11.5px; line-height: 1.6">Net Purchased Quantity = invoiced quantity minus same-batch returns; it is not confirmed stock at the customer.</p>
                    </div>
                </template>
            </div>
            <button type="button" class="c3-close" @click="idrawer = null" aria-label="Close"><i data-lucide="x" class="ico"></i></button>
            <div class="c3-drawer-b">
                <p x-show="idrawerState === 'loading'" style="padding: 24px 16px; font-size: 13px; color: var(--c360-label)"
                   x-text="idrawer && idrawer.mode === 'expiry' ? 'Loading batches…' : 'Loading items…'"></p>
                <p x-show="idrawerState === 'error'" style="padding: 24px 16px; font-size: 13px; color: var(--c360-destructive)"
                   x-text="idrawer && idrawer.mode === 'expiry' ? 'Could not load batches.' : 'Could not load items.'"></p>
                <p x-show="idrawerState === 'ready' && ! idrawerRows.length && idrawer && idrawer.mode === 'items'" style="padding: 24px 16px; font-size: 13px; color: var(--c360-label)">No items.</p>
                <table class="c3-list" x-show="idrawerState === 'ready' && idrawerRows.length">
                    <thead x-show="idrawer && idrawer.mode === 'items'"><tr>
                        <th style="text-align: start">Item</th><th style="text-align: end">Previous 60D Qty</th><th style="text-align: end">Last 60D Qty</th><th style="text-align: end">Sales Change (SAR)</th>
                    </tr></thead>
                    <thead x-show="idrawer && idrawer.mode === 'expiry'"><tr>
                        <th style="text-align: start">Item</th><th style="text-align: end">Net Purchased Qty</th><th style="text-align: start">Batch Number</th><th style="text-align: start">Expiry</th>
                    </tr></thead>
                    <tbody>
                        <template x-for="(r, i) in idrawerRows" :key="i">
                            <tr>
                                <td style="color: var(--c360-heading); white-space: normal">
                                    <span dir="auto" x-text="r.item_name || '—'"></span><span x-show="idrawer.action === 'different_items' && (r.item_status === 'lost' || r.item_status === 'new')"
                                        class="c3-tag" :class="r.item_status === 'new' && 'new'" x-text="r.item_status === 'new' ? 'New' : 'Lost'"></span>
                                    <div style="font-size: 11px; color: var(--c360-label)">Owner: <span dir="auto" x-text="r.owner || 'Not Assigned'"></span></div>
                                </td>
                                <template x-if="idrawer.mode === 'items'">
                                    <td class="tnum" style="text-align: end"><bdi dir="ltr" x-text="qty(r.prev_qty)"></bdi></td>
                                </template>
                                <template x-if="idrawer.mode === 'items'">
                                    <td class="tnum" style="text-align: end"><bdi dir="ltr" x-text="qty(r.last_qty)"></bdi></td>
                                </template>
                                <template x-if="idrawer.mode === 'items'">
                                    <td class="tnum" style="text-align: end; font-weight: 600; color: var(--c360-heading)"><bdi dir="ltr" x-text="money(r.value_diff)"></bdi></td>
                                </template>
                                <template x-if="idrawer.mode === 'expiry'">
                                    <td class="tnum" style="text-align: end"><bdi dir="ltr" x-text="qty(r.net_qty)"></bdi></td>
                                </template>
                                <template x-if="idrawer.mode === 'expiry'">
                                    <td><bdi dir="ltr" x-text="r.batch"></bdi></td>
                                </template>
                                <template x-if="idrawer.mode === 'expiry'">
                                    <td><bdi dir="ltr" x-text="r.expiry_label"></bdi></td>
                                </template>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </aside>
    <script>
        function c360Page(initialTab, arDetailsUrl, insightItemsUrl, expiryItemsUrl) {
            const money = (v) => { if (v === null || v === undefined) return '—'; const s = Math.round(v).toLocaleString('en-US'); return s === '-0' ? '0' : s; };
            const qtyFmt = new Intl.NumberFormat('en-US', { maximumFractionDigits: 2 });
            return {
                tab: initialTab, chart: null, drawer: null, drawerRows: [], drawerState: 'idle',
                idrawer: null, idrawerRows: [], idrawerState: 'idle',
                money,
                qty: (v) => (v === null || v === undefined ? '—' : qtyFmt.format(v)),
                async openInsightDrawer(mode, action, title, meta, base = null) {
                    this.idrawer = { mode, action, title, meta }; this.idrawerRows = []; this.idrawerState = 'loading';
                    try {
                        const url = mode === 'expiry' ? (base || expiryItemsUrl) : `${base || insightItemsUrl}?action=${encodeURIComponent(action)}`;
                        const res = await fetch(url, { headers: { Accept: 'application/json' } });
                        if (!res.ok) throw new Error(res.status);
                        this.idrawerRows = await res.json(); this.idrawerState = 'ready';
                    } catch (e) { this.idrawerState = 'error'; }
                },
                dmy: (iso) => { if (!iso) return ''; const [y, m, d] = iso.split('-'); return `${d}-${m}-${y}`; },
                slash: (iso) => { if (!iso) return '—'; const [y, m, d] = iso.slice(0, 10).split('-'); return `${d}/${m}/${y}`; },
                init() {
                    this.$nextTick(() => { C360.icons(); C360.initIn(this.$el.querySelector(`[data-tab="${this.tab}"]`)); });
                    this.$watch('tab', (t) => this.$nextTick(() => C360.initIn(this.$el.querySelector(`[data-tab="${t}"]`))));
                },
                setTab(t) {
                    this.tab = t;
                    const url = new URL(window.location); url.searchParams.set('tab', t); history.replaceState(null, '', url);
                },
                openChart(meta, event) {
                    const svg = event.currentTarget.closest('.c3-card').querySelector('.c3-card-h svg');
                    this.chart = { ...meta, icon: svg ? svg.outerHTML.replace('ico-18', 'ico-18" style="width:20px;height:20px') : '' };
                    this.$nextTick(() => C360.expand(meta.id, this.$refs.bigCanvas));
                },
                closeChart() { C360.collapse(); this.chart = null; },
                async openDrawer(kind, title) {
                    this.drawer = { kind, title }; this.drawerRows = []; this.drawerState = 'loading';
                    try {
                        const res = await fetch(`${arDetailsUrl}?kind=${kind}`, { headers: { Accept: 'application/json' } });
                        if (!res.ok) throw new Error(res.status);
                        this.drawerRows = await res.json(); this.drawerState = 'ready';
                    } catch (e) { this.drawerState = 'error'; }
                },
            };
        }
    </script>
