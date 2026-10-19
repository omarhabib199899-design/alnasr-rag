{{-- ZOHO Data — copy of the Lovable portal's reports.zoho-data.index.tsx: read-only sync monitor. Six summary cards, one row per
     dataset (click → drawer with column mapping and the latest 20 rows). Re-reads every 60 s while the tab is visible; it never
     starts a sync. --}}
<x-app-layout>
    @include('lovable.shell-styles')
    <style>
        .zd-cards { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; } @media (min-width: 1024px) { .zd-cards { grid-template-columns: repeat(6, 1fr); } }
        .zd-card { border: 1px solid var(--border); border-radius: .25rem; background: var(--surface); padding: 10px 12px; }
        .zd-card .l { font-size: 11px; text-transform: uppercase; letter-spacing: .05em; color: var(--muted-fg); }
        .zd-card .v { margin-top: 2px; font-size: 18px; font-weight: 600; font-variant-numeric: tabular-nums; }
        .zd-card .s { margin-top: 2px; font-size: 11px; color: var(--muted-fg); }
        .zd-badge { display: inline-block; border: 1px solid var(--border); border-radius: .25rem; padding: 0 6px; font-size: 11px; line-height: 18px; background: var(--muted); color: var(--muted-fg); }
        .zd-badge.ok { background: color-mix(in oklch, var(--primary) 10%, transparent); color: var(--primary); border-color: color-mix(in oklch, var(--primary) 30%, transparent); }
        .zd-badge.bad { background: color-mix(in oklch, var(--destructive) 10%, transparent); color: var(--destructive); border-color: color-mix(in oklch, var(--destructive) 30%, transparent); }
        .zd-badge.run { background: var(--accent); color: var(--fg); }
        .lv tbody tr.zd-row { cursor: pointer; }
        .zd-overlay { position: fixed; inset: 0; z-index: 40; background: rgba(0, 0, 0, .4); }
        .zd-drawer { position: fixed; inset-block: 0; inset-inline-end: 0; z-index: 50; width: 100%; max-width: 48rem; overflow-y: auto;
            background: var(--surface); box-shadow: -4px 0 16px rgba(0, 0, 0, .15); padding: 16px; display: flex; flex-direction: column; gap: 14px; }
        .zd-drawer h3 { margin: 0; font-size: 13px; font-weight: 600; }
        .zd-kv { display: grid; grid-template-columns: 11rem 1fr; gap: 4px 12px; font-size: 12.5px; } .zd-kv dt { color: var(--muted-fg); } .zd-kv dd { margin: 0; }
    </style>

    <div class="lv" x-data="lvZohoData(@js(route('zoho-data.overview')), @js(route('zoho-data.dataset')))">
        <header>
            <div class="lv-crumb">Reports</div>
            <h1>ZOHO Data</h1>
            <p class="lv-sub">Direct ZOHO Analytics sync — monitoring (read only, refreshes every 60s)</p>
        </header>

        <p class="lv-fld" x-show="! data && ! error" style="font-size: 12.5px">Loading sync status…</p>
        <p class="lv-msg err" x-show="error" x-cloak style="padding: 0">Could not load sync status.</p>

        <template x-if="data && ! error">
            <section class="lv-panel" style="flex: none; gap: 12px; padding: 12px">
                <div class="zd-cards">
                    <div class="zd-card"><div class="l">Active datasets</div><div class="v" dir="ltr" x-text="active + ' / ' + data.datasets.length"></div></div>
                    <div class="zd-card"><div class="l">Failed / error</div><div class="v" dir="ltr" :style="failed && 'color: var(--destructive)'" x-text="failed"></div></div>
                    <div class="zd-card"><div class="l">Total live rows</div><div class="v" dir="ltr" x-text="num(liveRows)"></div></div>
                    <div class="zd-card"><div class="l">Runs (24h)</div><div class="v" dir="ltr" x-text="num(data.summary.runs_24h)"></div><div class="s" x-text="data.summary.running_now + ' running now'"></div></div>
                    <div class="zd-card"><div class="l">Last run finished</div><div class="v" dir="ltr" style="font-size: 14px" x-text="data.summary.last_finished_at || '—'"></div><div class="s">Asia/Riyadh</div></div>
                    <div class="zd-card"><div class="l">Next refresh</div><div class="v" dir="ltr" style="font-size: 14px" x-text="data.summary.next_refresh_at || '—'"></div><div class="s">Hourly 07:00–20:00 (not Friday) + 23:00</div></div>
                </div>

                <div class="lv-scroll" style="flex: none; max-height: none; min-height: 0; border: 1px solid var(--border)">
                        <table>
                            <thead><tr>
                                <template x-for="h in ['Dataset', 'Source System', 'Source Type', 'Status', 'Live Rows', 'Source Rows', 'Last Successful Sync', 'Last Run Status', 'Last Run Duration', 'Next Scheduled Sync', 'Mode', 'Frequency']" :key="h">
                                    <th style="cursor: default" :class="(h === 'Live Rows' || h === 'Source Rows') && 'end'" x-text="h"></th>
                                </template>
                            </tr></thead>
                            <tbody>
                                <template x-for="d in data.datasets" :key="d.key">
                                    <tr class="zd-row" @click="openSet(d)">
                                        <td style="color: var(--primary); font-weight: 500" x-text="d.dataset"></td>
                                        <td x-text="d.source_system || '—'"></td>
                                        <td x-text="d.source_type || '—'"></td>
                                        <td><span class="zd-badge" :class="badge(d.status)" x-text="d.status"></span></td>
                                        <td class="end" dir="ltr" x-text="num(d.row_count)"></td>
                                        <td class="end" dir="ltr" :title="d.source_type === 'Table' ? 'Rows exported by the last full reload' : 'Computed on our server from the raw tables'" x-text="d.source_row_count == null ? 'Not measured' : num(d.source_row_count)"></td>
                                        <td dir="ltr" x-text="d.last_success_at || '—'"></td>
                                        <td><template x-if="d.last_run_status"><span class="zd-badge" :class="badge(d.last_run_status)" :title="d.last_run_error || ''" x-text="d.last_run_status"></span></template><template x-if="! d.last_run_status"><span>—</span></template></td>
                                        <td dir="ltr" x-text="dur(d.last_run_duration_seconds)"></td>
                                        <td dir="ltr"><div x-text="d.next_sync_at || '—'"></div><div style="font-size: 11px; color: var(--muted-fg)" x-text="d.next_sync_kind || ''"></div></td>
                                        <td x-text="d.mode_label || '—'"></td>
                                        <td x-text="d.frequency_label || '—'"></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                </div>
            </section>
        </template>

        {{-- Dataset drawer (their DatasetPanel) --}}
        <template x-if="open"><div class="zd-overlay" @click="close()"></div></template>
        <aside class="zd-drawer" x-show="open" x-cloak role="dialog" aria-modal="true" @keydown.escape.window="close()">
            <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 12px">
                <div><div style="font-size: 15px; font-weight: 600" x-text="open?.dataset"></div>
                    <div style="font-size: 12px; color: var(--muted-fg)">Column mapping and latest synced rows (read only)</div></div>
                <button type="button" class="lv-btn" @click="close()" aria-label="Close">×</button>
            </div>
            <p x-show="! set && ! setError" style="margin: 0; font-size: 12.5px; color: var(--muted-fg)">Loading…</p>
            <p x-show="setError" x-cloak style="margin: 0; font-size: 12.5px; color: var(--destructive)">Could not load dataset.</p>
            <template x-if="set">
                <div style="display: flex; flex-direction: column; gap: 14px">
                    <dl class="zd-kv" style="margin: 0">
                        <dt>Primary key</dt><dd dir="ltr" x-text="set.pk_columns.length ? set.pk_columns.join(', ') : '—'"></dd>
                        <dt>Last-modified column</dt><dd dir="ltr" x-text="set.lmt_column || '—'"></dd>
                        <dt>Watermark</dt><dd dir="ltr" x-text="set.watermark || '—'"></dd>
                        <dt>Last successful run</dt><dd dir="ltr" x-text="set.last_success_kind ? set.last_success_kind + ' · ' + (set.last_success_finished_at || '') : '—'"></dd>
                        <dt>Next run</dt><dd dir="ltr" x-text="set.next_run_at || '—'"></dd>
                    </dl>

                    <div>
                        <h3 x-text="'Columns (' + set.mapping.length + ')'"></h3>
                        <div class="lv-scroll" style="max-height: 18rem; min-height: 0; margin-top: 6px; border: 1px solid var(--border)">
                            <table>
                                <thead><tr><th style="cursor: default" class="end">#</th><th style="cursor: default">Zoho column</th><th style="cursor: default">MySQL column</th><th style="cursor: default">Type</th><th style="cursor: default">Flags</th></tr></thead>
                                <tbody>
                                    <template x-for="c in set.mapping" :key="c.pg_column">
                                        <tr><td class="end" dir="ltr" x-text="c.position"></td><td dir="auto" x-text="c.zoho_column || '—'"></td><td dir="ltr" x-text="c.pg_column"></td>
                                            <td dir="ltr" x-text="c.pg_type"></td><td><template x-if="c.is_pk"><span class="zd-badge">PK</span></template></td></tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div>
                        <h3>Latest 20 rows</h3>
                        <p x-show="! set.rows.length" style="margin: 6px 0 0; font-size: 12.5px; color: var(--muted-fg)">No rows.</p>
                        <div class="lv-scroll" x-show="set.rows.length" style="max-height: 24rem; min-height: 0; margin-top: 6px; border: 1px solid var(--border)">
                            <table>
                                <thead><tr><template x-for="c in set.mapping" :key="c.pg_column"><th style="cursor: default" x-text="c.zoho_column || c.pg_column"></th></template></tr></thead>
                                <tbody>
                                    <template x-for="(r, ri) in set.rows" :key="ri">
                                        <tr><template x-for="c in set.mapping" :key="c.pg_column"><td dir="auto" x-text="cell(r[c.pg_column])"></td></template></tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </template>
        </aside>
    </div>

    <script>
        function lvZohoData(overviewUrl, datasetUrl) {
            return {
                data: null, error: false, open: null, set: null, setError: false, timer: null,
                get active() { return this.data.datasets.filter((d) => d.status === 'active').length; },
                get failed() { return this.data.datasets.filter((d) => d.status === 'error' || d.last_run_status === 'failed').length; },
                get liveRows() { return this.data.datasets.reduce((a, d) => a + Number(d.row_count ?? 0), 0); },
                num: (v) => (v == null ? '—' : Number(v).toLocaleString('en-US')),
                dur: (s) => (s == null ? '—' : s < 60 ? `${s}s` : `${Math.floor(s / 60)}m ${s % 60}s`),
                cell: (v) => (v == null || v === '' ? '—' : String(v)),
                badge(v) { v = String(v || '').toLowerCase(); return v === 'active' || v === 'succeeded' ? 'ok' : v === 'failed' || v === 'error' ? 'bad' : v === 'running' ? 'run' : ''; },
                async loadOverview() {
                    try {
                        const r = await fetch(overviewUrl, { headers: { Accept: 'application/json' } });
                        if (! r.ok) throw new Error();
                        this.data = await r.json(); this.error = false;
                    } catch (e) { this.error = true; }
                },
                async loadSet() {
                    const key = this.open?.key; if (! key) return;
                    try {
                        const r = await fetch(datasetUrl + '?' + new URLSearchParams({ key }), { headers: { Accept: 'application/json' } });
                        if (! r.ok) throw new Error(); const d = await r.json();
                        if (this.open?.key === key) { this.set = d; this.setError = false; }
                    } catch (e) { if (this.open?.key === key) this.setError = true; }
                },
                openSet(d) { this.open = d; this.set = null; this.setError = false; this.loadSet(); },
                close() { this.open = null; this.set = null; },
                init() {
                    this.loadOverview();
                    // Read-only polling every 60 s, paused while the tab is hidden (their React Query refetchInterval).
                    this.timer = setInterval(() => { if (document.visibilityState === 'visible') { this.loadOverview(); this.loadSet(); } }, 60000);
                },
            };
        }
    </script>
</x-app-layout>
