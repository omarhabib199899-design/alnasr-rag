{{-- Sales Reps / Sales Owners / Data Entry list — exact copy of the Lovable portal's CustomerDirectory.tsx
     (customer_directory: one row per stored value, NULL/blank = Unassigned; client-side search, sort and 25-row pages). --}}
<x-app-layout>
    @include('customers.c360.styles')
    @include('customers.c360.scripts')
    <style>
        .c3-dir-sum { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        @media (min-width: 640px) { .c3-dir-sum { max-width: 28rem; } }
        .c3-dir-sum > div { border-radius: 8px; border: 1px solid var(--c360-border); background: var(--c360-card); padding: 12px 16px; box-shadow: 0 1px 2px rgba(20,36,59,.05); }
        .c3-dir-sum .l { font-size: 12.5px; color: var(--c360-label); }
        .c3-dir-sum .v { margin-top: 4px; font-size: 20px; font-weight: 600; color: var(--c360-heading); font-variant-numeric: tabular-nums; }
        .c3-dir-search { position: relative; display: block; width: 100%; }
        @media (min-width: 640px) { .c3-dir-search { width: 18rem; } }
        .c3-dir-search svg { pointer-events: none; position: absolute; inset-inline-start: 10px; top: 50%; transform: translateY(-50%); width: 16px; height: 16px; color: var(--c360-label); }
        .c3-dir-search input { height: 36px; width: 100%; border-radius: 6px; border: 1px solid var(--c360-border); background: var(--c360-card); padding: 0 12px 0 32px;
            font-size: 13px; color: var(--c360-value); outline: none; font-family: inherit; box-shadow: none; }
        .c3-dir-search input:focus { border-color: var(--c360-accent); box-shadow: none; }
        .c3-dir table { width: 100%; min-width: 560px; border-collapse: collapse; font-size: 13px; }
        .c3-dir th { border-bottom: 1px solid var(--c360-border); background: var(--c360-th); padding: 10px 16px; font-size: 12.5px; font-weight: 600; color: var(--c360-heading); }
        .c3-dir th button { display: inline-flex; align-items: center; gap: 4px; border: 0; background: none; padding: 0; font: inherit; color: inherit; cursor: pointer; }
        .c3-dir th svg { width: 14px; height: 14px; }
        .c3-dir td { padding: 10px 16px; border-top: 1px solid var(--c360-border); }
        .c3-dir tbody tr:hover { background: var(--c360-bg); }
        .c3-dir a { color: oklch(47% .108 244.5); text-decoration: none; } .c3-dir a:hover { text-decoration: underline; }
        .c3-dir-foot { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px; border-top: 1px solid var(--c360-border); padding: 10px 16px; font-size: 12.5px; color: var(--c360-label); }
        .c3-pgbtn { height: 32px; border-radius: 6px; border: 1px solid var(--c360-border); background: var(--c360-card); padding: 0 12px; font-size: 12.5px; font-weight: 500; color: var(--c360-value); cursor: pointer; font-family: inherit; }
        .c3-pgbtn:hover { background: var(--c360-bg); } .c3-pgbtn:disabled { opacity: .4; cursor: default; }
    </style>

    <div class="c3" x-data="c3Directory(@js($rows), @js(route($prefix.'.show', '__NAME__')))">
        <header class="c3-head" style="display: block">
            <nav class="c3-crumbs" aria-label="Breadcrumb">
                <span>Sales Data</span><i data-lucide="chevron-right"></i><span class="cur">{{ $title }}</span>
            </nav>
            <h1 class="c3-title" style="line-height: 1.25">{{ $title }}</h1>
        </header>

        <div class="c3-stack" style="flex: 1; padding: 0 16px 24px">
            <div class="c3-dir-sum">
                <div><div class="l">Total Groups</div><div class="v"><bdi dir="ltr" x-text="int(rows.length)"></bdi></div></div>
                <div><div class="l">Total Customers</div><div class="v"><bdi dir="ltr" x-text="int(totalCustomers)"></bdi></div></div>
            </div>

            <section class="c3-card c3-dir">
                <div class="c3-card-h" style="justify-content: space-between; gap: 12px">
                    <div style="display: flex; align-items: center; gap: 10px">
                        <i data-lucide="users" class="ico-18"></i><h2>{{ $title }}</h2>
                    </div>
                    <label class="c3-dir-search">
                        <i data-lucide="search"></i>
                        <input type="text" x-model="search" @input="page = 0" placeholder="Search by name…" aria-label="Search by name">
                    </label>
                </div>
                <div class="c3-scroll">
                    <table>
                        <thead><tr>
                            <th style="text-align: start"><button type="button" @click="toggle('name')">Name <span x-html="icon('name')"></span></button></th>
                            <th style="width: 11rem; text-align: end"><button type="button" @click="toggle('count')">Customers Count <span x-html="icon('count')"></span></button></th>
                            <th style="width: 14rem; text-align: end"><button type="button" @click="toggle('balance')">Outstanding Balance <span x-html="icon('balance')"></span></button></th>
                        </tr></thead>
                        <tbody>
                            <tr x-show="! visible.length"><td colspan="3" style="padding: 40px 16px; text-align: center; color: var(--c360-label)"
                                x-text="rows.length ? 'No names match your search.' : 'No customers found.'"></td></tr>
                            <template x-for="r in visible" :key="(r.is_unassigned ? '1:' : '0:') + r.group_name">
                                <tr>
                                    <td>
                                        <template x-if="r.is_unassigned"><span class="c3-badge neutral" style="line-height: 18px">Unassigned</span></template>
                                        <template x-if="! r.is_unassigned"><a :href="url(r.group_name)" dir="auto" x-text="r.group_name"></a></template>
                                    </td>
                                    <td class="tnum" style="text-align: end; color: var(--c360-value)"><bdi dir="ltr" x-text="int(r.customers_count)"></bdi></td>
                                    <td class="tnum" style="text-align: end; font-weight: 500; color: var(--c360-heading)"><bdi dir="ltr" x-text="money(r.outstanding_balance)"></bdi></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
                <div class="c3-dir-foot">
                    <span>Showing <bdi dir="ltr" x-text="filtered.length ? int(cur * 25 + 1) : 0"></bdi>–<bdi dir="ltr" x-text="int(cur * 25 + visible.length)"></bdi> of <bdi dir="ltr" x-text="int(filtered.length)"></bdi></span>
                    <div x-show="pages > 1" style="display: flex; align-items: center; gap: 8px">
                        <button type="button" class="c3-pgbtn" :disabled="cur === 0" @click="page = cur - 1">Previous</button>
                        <span x-text="`Page ${cur + 1} of ${pages}`"></span>
                        <button type="button" class="c3-pgbtn" :disabled="cur >= pages - 1" @click="page = cur + 1">Next</button>
                    </div>
                </div>
            </section>
        </div>
    </div>

    <script>
        function c3Directory(rows, showUrl) {
            const collator = new Intl.Collator(['ar', 'en'], { numeric: true });
            const svg = (d) => `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;vertical-align:-2px">${d}</svg>`;
            return {
                rows, search: '', sort: { key: 'name', dir: 1 }, page: 0,
                int: (v) => Number(v).toLocaleString('en-US'),
                money: (v) => { const s = Math.round(v).toLocaleString('en-US'); return s === '-0' ? '0' : s; },
                url: (name) => showUrl.replace('__NAME__', encodeURIComponent(name)),
                get totalCustomers() { return this.rows.reduce((n, r) => n + r.customers_count, 0); },
                get filtered() {
                    const s = this.search.trim().toLowerCase();
                    const out = s ? this.rows.filter((r) => r.group_name.toLowerCase().includes(s)) : [...this.rows];
                    const { key, dir } = this.sort;
                    out.sort((a, b) => {
                        if (key === 'name') {
                            if (a.is_unassigned !== b.is_unassigned) return a.is_unassigned ? 1 : -1; // Unassigned always last
                            return collator.compare(a.group_name, b.group_name) * dir;
                        }
                        const f = key === 'count' ? 'customers_count' : 'outstanding_balance';
                        return (a[f] - b[f]) * dir;
                    });
                    return out;
                },
                get pages() { return Math.max(1, Math.ceil(this.filtered.length / 25)); },
                get cur() { return Math.min(this.page, this.pages - 1); },
                get visible() { return this.filtered.slice(this.cur * 25, this.cur * 25 + 25); },
                toggle(key) {
                    this.sort = this.sort.key === key ? { key, dir: this.sort.dir === 1 ? -1 : 1 } : { key, dir: key === 'name' ? 1 : -1 };
                    this.page = 0;
                },
                icon(k) {
                    if (this.sort.key !== k) return svg('<path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/>').replace('style="', 'style="opacity:.5;');
                    return this.sort.dir === 1 ? svg('<path d="m18 15-6-6-6 6"/>') : svg('<path d="m6 9 6 6 6-6"/>');
                },
                init() { this.$nextTick(() => C360.icons()); },
            };
        }
    </script>
</x-app-layout>
