{{-- Sales Rep / Sales Owner / Data Entry 360 — exact copy of the Lovable portal's Entity360Page.tsx: breadcrumb, selected name,
     page type, Back button, the Customer 360 tab bar (10 tabs), one panel per tab. Shared tab partials run with $isEntity. --}}
@php
    use App\Support\C360Format as F;
    [, , $directory, $pageTitle, $backLabel] = $k;
    $urls = [
        'ar' => route($prefix.'.ar-details', $name), 'cls' => route($prefix.'.classification-customers', $name),
        'acts' => route($prefix.'.action-customers', $name), 'detail' => route($prefix.'.action-detail', $name),
    ];
    $relatedLabels = ['rep_name' => 'Sales Rep', 'sales_owner' => 'Sales Owner', 'data_entry' => 'Data Entry'];
    $relatedUrls = ['rep_name' => route('sales-reps.show', '__N__'), 'sales_owner' => route('sales-owners.show', '__N__'), 'data_entry' => route('data-entry.show', '__N__')];
    $customerUrl = route('customers.show', '__ID__');
    $cfg = ['related' => $related, 'labels' => $relatedLabels, 'relatedUrls' => $relatedUrls, 'customerUrl' => $customerUrl];
@endphp
<x-app-layout>
    @include('customers.c360.styles')
    @include('customers.c360.scripts')
    <style>
        /* ActionDetail inside the Customer Actions drawer: the customer's own Insights card for one action, without its section chrome */
        .c3-pa-detail .c3-card { border: 0; box-shadow: none; background: none; }
        .c3-pa-detail .c3-card-h, .c3-pa-detail .c3-card-b > .c3-note { display: none; }
        .c3-pa-detail .c3-card-b { padding: 0; }
        .c3-pa-detail .c3-stack { gap: 0; }
        .c3-pa-search { height: 36px; width: 100%; border-radius: 6px; border: 1px solid var(--c360-border); padding: 0 12px; font-size: 13px; font-family: inherit; color: var(--c360-value); box-shadow: none; }
        .c3-pa-search:focus { border-color: var(--c360-accent); box-shadow: none; outline: none; }
        .c3-pgbtn { height: 32px; border-radius: 6px; border: 1px solid var(--c360-border); background: var(--c360-card); padding: 0 12px; font-size: 12px; font-weight: 500; color: var(--c360-value); cursor: pointer; font-family: inherit; }
        .c3-pgbtn:hover { background: var(--c360-bg); } .c3-pgbtn:disabled { opacity: .5; cursor: default; }
        .c3-drawer a.lnk { color: var(--c360-accent); text-decoration: none; } .c3-drawer a.lnk:hover { text-decoration: underline; }
    </style>

    <div class="c3" x-data="entity360Page(@js($activeTab), @js($urls), @js($cfg))">
        <header class="c3-head">
            <div style="min-width: 0; flex: 1">
                <nav class="c3-crumbs" aria-label="Breadcrumb">
                    <span>Sales Data</span>
                    <i data-lucide="chevron-right"></i>
                    <a href="{{ route($prefix.'.index') }}">{{ $directory }}</a>
                    <i data-lucide="chevron-right"></i>
                    <span class="cur" dir="auto">{{ $name }}</span>
                </nav>
                <h1 class="c3-title" dir="auto">{{ $name }}</h1>
                <p class="c3-sub">{{ $pageTitle }}</p>
            </div>
            <a href="{{ route($prefix.'.index') }}" class="c3-back"><i data-lucide="arrow-left" class="ico"></i>{{ $backLabel }}</a>
        </header>

        <div class="c3-tabsbar">
            <div class="c3-tabs" role="tablist" aria-label="{{ $pageTitle }} sections">
                @foreach ($tabs as $key => [$label, $icon])
                    <button type="button" role="tab" class="c3-tab" :class="tab === '{{ $key }}' && 'on'" :aria-selected="tab === '{{ $key }}'" @click="setTab('{{ $key }}')">
                        <i data-lucide="{{ $icon }}" class="ico"></i>{{ $label }}
                    </button>
                @endforeach
            </div>
        </div>

        @foreach (array_keys($tabs) as $key)
            <div class="c3-panel" role="tabpanel" x-show="tab === '{{ $key }}'" @if ($key !== $activeTab) x-cloak @endif data-tab="{{ $key }}">
                @switch($key)
                    @case('overview') @include('entities.tab-overview') @break
                    @case('sales') @include('customers.c360.tab-sales') @break
                    @case('credit_note') @include('customers.c360.tab-credit-note') @break
                    @case('sales_orders') @include('customers.c360.tab-sales-orders') @break
                    @case('profitability') @include('customers.c360.tab-profitability') @break
                    @case('collection') @include('customers.c360.tab-collection') @break
                    @case('ar') @include('customers.c360.tab-ar') @break
                    @case('credit_limit') @include('customers.c360.tab-credit-limit') @break
                    @case('item_performance') @include('customers.c360.tab-item-performance') @break
                    @case('insights') @include('entities.tab-insights') @break
                @endswitch
            </div>
        @endforeach

        {{-- ClassificationCustomersDrawer --}}
        <template x-if="cls"><div class="c3-overlay" @click="cls = null"></div></template>
        <aside class="c3-drawer" x-show="cls" x-cloak role="dialog" aria-modal="true" @keydown.escape.window="cls = null">
            <div class="c3-drawer-h">
                <h3 x-text="cls && cls.title"></h3>
                <p><template x-if="cls"><span><bdi dir="auto" x-text="cls.value"></bdi> (<bdi dir="ltr" x-text="int(cls.count)"></bdi> Customers)</span></template></p>
            </div>
            <button type="button" class="c3-close" @click="cls = null" aria-label="Close"><i data-lucide="x" class="ico"></i></button>
            <div class="c3-drawer-b">
                <p x-show="clsState === 'loading'" style="padding: 24px 16px; font-size: 13px; color: var(--c360-label)">Loading customers…</p>
                <p x-show="clsState === 'error'" style="padding: 24px 16px; font-size: 13px; color: var(--c360-destructive)">Could not load classifications</p>
                <p x-show="clsState === 'ready' && ! clsRows.length" style="padding: 24px 16px; font-size: 13px; color: var(--c360-label)">No classified customers in this portfolio</p>
                <table class="c3-list" x-show="clsState === 'ready' && clsRows.length">
                    <thead><tr>
                        <th style="text-align: start">Customer Name</th>
                        <template x-for="f in cfg.related" :key="f"><th style="text-align: start" x-text="cfg.labels[f]"></th></template>
                    </tr></thead>
                    <tbody>
                        <template x-for="r in clsRows" :key="r.customer_id">
                            <tr>
                                <td><template x-if="r.local_id"><a class="lnk" :href="customerLink(r)" dir="auto" x-text="r.customer_name ?? '—'"></a></template>
                                    <template x-if="! r.local_id"><span dir="auto" x-text="r.customer_name ?? '—'"></span></template></td>
                                <template x-for="f in cfg.related" :key="f">
                                    <td><template x-if="r[f]"><a class="lnk" :href="relatedLink(f, r[f])" dir="auto" x-text="r[f]"></a></template></td>
                                </template>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </aside>

        {{-- PortfolioActionCustomersDrawer --}}
        <template x-if="act"><div class="c3-overlay" @click="closeAction()"></div></template>
        <aside class="c3-drawer" x-show="act" x-cloak role="dialog" aria-modal="true" @keydown.escape.window="if (! idrawer) closeAction()">
            <div class="c3-drawer-h">
                <h3 x-text="act && act.title"></h3>
                <p><template x-if="act"><span><bdi dir="ltr" x-text="int(act.count)"></bdi> customers · Snapshot updated: <bdi dir="ltr" x-text="act.published"></bdi></span></template></p>
            </div>
            <button type="button" class="c3-close" @click="closeAction()" aria-label="Close"><i data-lucide="x" class="ico"></i></button>
            <div class="c3-drawer-b" style="padding: 0 16px">
                <p x-show="actState === 'loading'" style="padding: 24px 0; font-size: 13px; color: var(--c360-label)">Loading customer actions…</p>
                <p x-show="actState === 'error'" style="padding: 24px 0; font-size: 13px; color: var(--c360-destructive)">Could not load customer actions.</p>
                <div x-show="actState === 'changed'" style="padding: 24px 0">
                    <p style="font-size: 13px; color: var(--c360-label)">The Customer Actions data has been updated. Please reload to see the latest snapshot.</p>
                    <button type="button" class="c3-pgbtn" style="margin-top: 12px" @click="window.location.reload()">Reload</button>
                </div>
                <template x-if="actState === 'ready'">
                    <div>
                        <div style="padding: 12px 0"><input type="text" class="c3-pa-search" x-model="actSearch" @input="actPage = 1; loadDetails()" placeholder="Search customer number or name" aria-label="Search customer number or name"></div>
                        <p x-show="! actVisible.length" style="padding: 24px 0; font-size: 13px; color: var(--c360-label)">No customers match your search.</p>
                        <template x-for="r in actVisible" :key="r.customer_id">
                            <div style="border-bottom: 1px solid var(--c360-border); padding: 16px 0">
                                <div style="margin-bottom: 12px; display: flex; flex-wrap: wrap; align-items: flex-start; justify-content: space-between; gap: 8px; font-size: 13px">
                                    <div style="min-width: 0; flex: 1; word-break: break-word">
                                        <template x-if="r.local_id"><a class="lnk" style="font-weight: 600" :href="customerLink(r)" dir="auto" x-text="r.customer_name ?? '—'"></a></template>
                                        <template x-if="! r.local_id"><span style="font-weight: 600" dir="auto" x-text="r.customer_name ?? '—'"></span></template>
                                        <div style="margin-top: 4px; font-size: 12px; color: var(--c360-label)">
                                            <template x-for="(f, i) in cfg.related.filter((f) => r[f])" :key="f">
                                                <span><span x-text="(i > 0 ? ' · ' : '') + cfg.labels[f] + ': '"></span><a class="lnk" :href="relatedLink(f, r[f])" dir="auto" x-text="r[f]"></a></span>
                                            </template>
                                        </div>
                                    </div>
                                    <template x-if="r.local_id"><a class="lnk" :href="customerLink(r)">Open Customer 360</a></template>
                                </div>
                                <div class="c3-pa-detail">
                                    <p x-show="actDetails[r.customer_id] === undefined" style="padding: 12px 0; font-size: 13px; color: var(--c360-label)">Loading customer actions…</p>
                                    <p x-show="actDetails[r.customer_id] === null" style="padding: 12px 0; font-size: 13px; color: var(--c360-label)">This action is no longer present in the customer's latest data. The portfolio snapshot will be updated after the next successful refresh.</p>
                                    <p x-show="actDetails[r.customer_id] === false" style="padding: 12px 0; font-size: 13px; color: var(--c360-destructive)">Could not load customer actions.</p>
                                    <div x-show="actDetails[r.customer_id]" x-html="actDetails[r.customer_id] || ''" x-effect="actDetails[r.customer_id] && $nextTick(() => C360.icons())"></div>
                                </div>
                            </div>
                        </template>
                    </div>
                </template>
            </div>
            <div x-show="actState === 'ready'" style="display: flex; align-items: center; justify-content: space-between; gap: 8px; border-top: 1px solid var(--c360-border); padding: 12px 16px">
                <button type="button" class="c3-pgbtn" :disabled="actCur <= 1" @click="actPage = actCur - 1; loadDetails()">Previous</button>
                <span style="font-size: 12px; color: var(--c360-label)">Page <bdi dir="ltr" x-text="actCur"></bdi> of <bdi dir="ltr" x-text="actPages"></bdi></span>
                <button type="button" class="c3-pgbtn" :disabled="actCur >= actPages" @click="actPage = actCur + 1; loadDetails()">Next</button>
            </div>
        </aside>

        {{-- Chart dialog, AR invoice drawer, item / expiry drawer (opened from an action's View Items) --}}
        @include('customers.c360.overlays')
    </div>

    <script>
        function entity360Page(initialTab, urls, cfg) {
            const base = c360Page(initialTab, urls.ar, null, null);
            // copy descriptors (not values) so the getters below stay live
            const ext = {
                cfg,
                int: (v) => Number(v).toLocaleString('en-US'),
                customerLink: (r) => cfg.customerUrl.replace('__ID__', r.local_id),
                relatedLink: (f, v) => cfg.relatedUrls[f].replace('__N__', encodeURIComponent(v)),

                cls: null, clsRows: [], clsState: 'idle',
                async openClassification(group, title, value, count) {
                    this.cls = { group, title, value, count }; this.clsRows = []; this.clsState = 'loading';
                    try {
                        const res = await fetch(`${urls.cls}?group=${encodeURIComponent(group)}&value=${encodeURIComponent(value)}`, { headers: { Accept: 'application/json' } });
                        if (!res.ok) throw new Error(res.status);
                        this.clsRows = await res.json(); this.clsState = 'ready';
                    } catch (e) { this.clsState = 'error'; }
                },

                act: null, actRows: [], actState: 'idle', actSearch: '', actPage: 1, actDetails: {},
                get actFiltered() {
                    const t = this.actSearch.trim().toLocaleLowerCase();
                    return this.actRows.filter((r) => !t || (r.customer_number ?? '').toLocaleLowerCase().includes(t) || (r.customer_name ?? '').toLocaleLowerCase().includes(t));
                },
                get actPages() { return Math.max(1, Math.ceil(this.actFiltered.length / 25)); },
                get actCur() { return Math.min(this.actPage, this.actPages); },
                get actVisible() { return this.actFiltered.slice((this.actCur - 1) * 25, this.actCur * 25); },
                async openAction(action, title, generation, count, published) {
                    this.act = { action, title, generation, count, published };
                    this.actRows = []; this.actDetails = {}; this.actSearch = ''; this.actPage = 1; this.actState = 'loading';
                    try {
                        const res = await fetch(`${urls.acts}?action=${encodeURIComponent(action)}&generation=${generation}`, { headers: { Accept: 'application/json' } });
                        if (!res.ok) throw new Error(res.status);
                        const data = await res.json();
                        if (!data.generation_available) { this.actState = 'changed'; return; }
                        this.actRows = data.rows; this.actState = 'ready';
                        this.loadDetails();
                    } catch (e) { this.actState = 'error'; }
                },
                closeAction() { this.act = null; this.actSearch = ''; this.actPage = 1; },
                async loadDetails() {
                    const act = this.act;
                    const ids = this.actVisible.map((r) => r.customer_id).filter((id) => !(id in this.actDetails));
                    if (!act || !ids.length) return;
                    try {
                        const res = await fetch(`${urls.detail}?action=${encodeURIComponent(act.action)}&customers=${ids.join(',')}`, { headers: { Accept: 'application/json' } });
                        if (!res.ok) throw new Error(res.status);
                        const html = await res.json();
                        if (this.act !== act) return;
                        this.actDetails = { ...this.actDetails, ...html };
                    } catch (e) {
                        const failed = Object.fromEntries(ids.map((id) => [id, false]));
                        this.actDetails = { ...this.actDetails, ...failed };
                    }
                },
            };
            return Object.defineProperties(base, Object.getOwnPropertyDescriptors(ext));
        }
    </script>
</x-app-layout>
