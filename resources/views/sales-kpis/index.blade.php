<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-base text-gray-900 leading-tight">
            {{ __('Sales KPI Definitions') }}
        </h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-[1600px] mx-auto sm:px-6 lg:px-8 space-y-5">

            <x-page-header :crumbs="['Sales Data', 'Sales KPI Definitions']" title="Sales KPI Definitions" />
            <p class="text-sm text-gray-500 -mt-3">Official reference for approved business performance indicators and calculation methodologies.</p>

            <div
                x-data='{
                    kpis: @json($kpis),
                    search: "",
                    category: "",
                    open: null,
                    get categories() {
                        return [...new Set(this.kpis.map(k => k.category))];
                    },
                    get filtered() {
                        const q = this.search.trim().toLowerCase();
                        return this.kpis.filter(k => {
                            const matchesSearch = q === "" || k.name.toLowerCase().includes(q) || k.summary.toLowerCase().includes(q);
                            const matchesCategory = this.category === "" || k.category === this.category;
                            return matchesSearch && matchesCategory;
                        });
                    },
                    toggle(i) { this.open = this.open === i ? null : i; },
                }'
                class="bg-white rounded-lg border border-gray-200"
            >
                <div class="px-5 py-4 border-b border-gray-100 space-y-3">
                    <h3 class="text-sm font-semibold text-gray-900 flex items-center gap-1.5">
                        <svg class="w-4 h-4 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25" /></svg>
                        {{ __('KPI Directory') }}
                        <span class="text-gray-400 font-normal" x-text="'(' + filtered.length + ' of ' + kpis.length + ')'"></span>
                    </h3>
                    <div class="flex flex-wrap gap-3">
                        <input type="text" x-model="search" placeholder="Search KPIs by name..."
                               class="flex-1 min-w-[220px] rounded-md border-gray-300 text-sm focus:border-gray-400 focus:ring-gray-400">
                        <select x-model="category" class="rounded-md border-gray-300 text-sm focus:border-gray-400 focus:ring-gray-400">
                            <option value="">All categories</option>
                            <template x-for="cat in categories" :key="cat">
                                <option :value="cat" x-text="cat"></option>
                            </template>
                        </select>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-100">
                        <thead class="bg-[#eef3fa]">
                            <tr>
                                <th class="px-5 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">KPI Name</th>
                                <th class="px-5 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Category</th>
                                <th class="px-5 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Status</th>
                                <th class="px-5 py-4"></th>
                            </tr>
                        </thead>
                        <template x-for="(kpi, i) in filtered" :key="kpi.category + '-' + kpi.name">
                            <tbody class="divide-y divide-gray-100">
                                <tr class="cursor-pointer hover:bg-gray-50" @click="toggle(i)">
                                    <td class="px-5 py-3">
                                        <div class="text-sm font-semibold text-gray-900" x-text="kpi.name"></div>
                                        <div class="text-xs text-gray-500 mt-0.5" x-text="kpi.summary"></div>
                                    </td>
                                    <td class="px-5 py-3 text-sm text-gray-500 whitespace-nowrap" x-text="kpi.category"></td>
                                    <td class="px-5 py-3 whitespace-nowrap">
                                        <span class="px-2 py-1 rounded text-xs font-semibold bg-green-100 text-green-700" x-text="kpi.status"></span>
                                    </td>
                                    <td class="px-5 py-3 text-right">
                                        <svg class="w-4 h-4 text-gray-400 transition-transform inline-block" :class="open === i ? 'rotate-180' : ''" viewBox="0 0 20 20" fill="currentColor">
                                            <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
                                        </svg>
                                    </td>
                                </tr>
                                <tr x-show="open === i" x-cloak>
                                    <td colspan="4" class="px-5 py-5 bg-gray-50">
                                        <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
                                            <div class="lg:col-span-2 space-y-4">
                                                <div>
                                                    <div class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Calculation Formula</div>
                                                    <div class="bg-white border border-gray-200 rounded-md p-3 text-sm text-gray-800 font-mono" x-text="kpi.formula"></div>
                                                    <template x-if="kpi.portfolio_formula">
                                                        <div class="bg-white border border-gray-200 rounded-md p-3 text-sm text-gray-600 font-mono mt-2">
                                                            <span class="text-xs font-semibold text-gray-400 uppercase block mb-1">Portfolio</span>
                                                            <span x-text="kpi.portfolio_formula"></span>
                                                        </div>
                                                    </template>
                                                </div>
                                                <template x-if="kpi.rules.length > 0">
                                                    <div>
                                                        <div class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Calculation Rules</div>
                                                        <ul class="list-disc list-inside text-sm text-gray-600 space-y-1">
                                                            <template x-for="rule in kpi.rules" :key="rule">
                                                                <li x-text="rule"></li>
                                                            </template>
                                                        </ul>
                                                    </div>
                                                </template>
                                            </div>
                                            <div class="space-y-4">
                                                <div>
                                                    <div class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Applicable Periods</div>
                                                    <div class="flex flex-wrap gap-1.5">
                                                        <template x-for="p in kpi.periods" :key="p">
                                                            <span class="px-2 py-1 rounded-full text-xs font-medium bg-white border border-gray-300 text-gray-700" x-text="p"></span>
                                                        </template>
                                                    </div>
                                                </div>
                                                <div>
                                                    <div class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Data Source</div>
                                                    <div class="bg-white border border-gray-200 rounded-md p-3">
                                                        <div class="text-sm font-mono font-semibold text-gray-900" x-text="kpi.data_source.table"></div>
                                                        <div class="flex flex-wrap gap-1 mt-2">
                                                            <template x-for="col in kpi.data_source.columns" :key="col">
                                                                <span class="px-1.5 py-0.5 rounded bg-blue-50 text-blue-700 text-xs font-mono" x-text="col"></span>
                                                            </template>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </template>
                        <tbody x-show="filtered.length === 0" x-cloak>
                            <tr><td colspan="4" class="px-5 py-8 text-center text-sm text-gray-500">No KPIs match your search.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="bg-white rounded-lg border border-gray-200">
                <div class="px-5 py-4 border-b border-gray-100">
                    <h3 class="text-sm font-semibold text-gray-900">How These KPIs Are Calculated</h3>
                </div>
                <p class="text-sm text-gray-500 px-5 py-4">
                    Every category above (Sales, Profitability, Collection, Accounts Receivable, Due Ages, Credit Limit,
                    Item Performance) appears identically on the Customers, Sales Reps, Data Entry, and Sales Owners
                    pages — same formulas, scoped to one customer vs. a group of customers' combined rows. Source
                    tables: <code class="text-xs bg-gray-100 px-1 rounded">ar_ledger_entries</code>,
                    <code class="text-xs bg-gray-100 px-1 rounded">invoice_documents</code>,
                    <code class="text-xs bg-gray-100 px-1 rounded">sales_item_performance</code>,
                    <code class="text-xs bg-gray-100 px-1 rounded">customers</code> — the same real data as every
                    other figure in the system.
                </p>
            </div>

            <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
                <h3 class="text-sm font-semibold text-gray-900 px-5 pt-5 pb-3">The 7 Periods</h3>
                <table class="min-w-full divide-y divide-gray-100 mt-4">
                    <thead class="bg-[#eef3fa]">
                        <tr>
                            <th class="px-5 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Period</th>
                            <th class="px-5 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wide">Covers</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr><td class="px-5 py-3 text-sm font-semibold text-gray-900">Lifetime</td><td class="px-5 py-3 text-sm text-gray-600">From the first invoice or credit note ever recorded, through today.</td></tr>
                        <tr><td class="px-5 py-3 text-sm font-semibold text-gray-900">Previous Year</td><td class="px-5 py-3 text-sm text-gray-600">The full previous calendar year (Jan 1 – Dec 31).</td></tr>
                        <tr><td class="px-5 py-3 text-sm font-semibold text-gray-900">YTD</td><td class="px-5 py-3 text-sm text-gray-600">January 1 of this year through today.</td></tr>
                        <tr><td class="px-5 py-3 text-sm font-semibold text-gray-900">Previous Month</td><td class="px-5 py-3 text-sm text-gray-600">The full previous calendar month.</td></tr>
                        <tr><td class="px-5 py-3 text-sm font-semibold text-gray-900">MTD</td><td class="px-5 py-3 text-sm text-gray-600">The 1st of this month through today.</td></tr>
                        <tr><td class="px-5 py-3 text-sm font-semibold text-gray-900">Yesterday</td><td class="px-5 py-3 text-sm text-gray-600">Yesterday only.</td></tr>
                        <tr><td class="px-5 py-3 text-sm font-semibold text-gray-900">Today</td><td class="px-5 py-3 text-sm text-gray-600">Today only.</td></tr>
                    </tbody>
                </table>
            </div>

        </div>
    </div>
</x-app-layout>
