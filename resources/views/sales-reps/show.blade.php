<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Sales Reps') }}
        </h2>
    </x-slot>

    @include('partials.c360-styles')

    <div class="py-12 c360" x-data="{ tab: '{{ request('tab', 'overview') }}' }">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">

            <div>
                <a href="{{ route('sales-reps.index') }}" class="text-sm text-gray-500 hover:text-gray-700">
                    &larr; Back to Sales Reps
                </a>
            </div>

            <div class="customer-head">
                <div class="customer-title">
                    <div class="avatar">{{ strtoupper(substr($repName, 0, 1)) }}</div>
                    <div>
                        <h1>{{ $repName }}</h1>
                        <p>Sales Representative — {{ number_format($totalCustomers) }} customers</p>
                    </div>
                </div>
                <div class="head-badges">
                    <span class="badge">Active</span>
                </div>
            </div>

            <div class="tabs">
                <button @click="tab = 'overview'" :class="tab === 'overview' ? 'active' : ''" class="tab">Overview</button>
                <button @click="tab = 'sales'" :class="tab === 'sales' ? 'active' : ''" class="tab">Sales</button>
                <button @click="tab = 'credit_note'" :class="tab === 'credit_note' ? 'active' : ''" class="tab">Credit Note</button>
                <button @click="tab = 'sales_orders'" :class="tab === 'sales_orders' ? 'active' : ''" class="tab">Sales Orders</button>
                <button @click="tab = 'profitability'" :class="tab === 'profitability' ? 'active' : ''" class="tab">Profitability</button>
                <button @click="tab = 'collection'" :class="tab === 'collection' ? 'active' : ''" class="tab">Collection</button>
                <button @click="tab = 'ar'" :class="tab === 'ar' ? 'active' : ''" class="tab">Accounts Receivable</button>
                <button @click="tab = 'due_ages'" :class="tab === 'due_ages' ? 'active' : ''" class="tab">Due Ages</button>
                <button @click="tab = 'credit_limit'" :class="tab === 'credit_limit' ? 'active' : ''" class="tab">Credit Limit</button>
                <button @click="tab = 'item_performance'" :class="tab === 'item_performance' ? 'active' : ''" class="tab">Item Performance</button>
                <button @click="tab = 'insights'" :class="tab === 'insights' ? 'active' : ''" class="tab">Insights</button>
            </div>

            <div class="tab-content">

                <div x-show="tab === 'overview'">
                <div class="section-head"><h2><span class="section-icon">360</span>Overview</h2></div>
                <div class="group-grid">

                    <div class="group">
                        <div class="group-title tint-blue">Account Information</div>
                        <div class="c-rows">
                            <div class="c-row"><span class="label">Rep Name</span><span class="val">{{ $repName }}</span></div>
                            <div class="c-row"><span class="label">Total Customers</span><span class="val">{{ number_format($totalCustomers) }}</span></div>
                            <div class="c-row"><span class="label">Active Customers</span><span class="val green">{{ number_format($activeCustomers) }}</span></div>
                            <div class="c-row"><span class="label">Inactive Customers</span><span class="val">{{ number_format($inactiveCustomers) }}</span></div>
                            <div class="c-row"><span class="label">Business Unit</span><span class="val">{{ $businessUnit ?: '—' }}</span></div>
                        </div>
                    </div>

                    <div class="group">
                        <div class="group-title tint-green">Classification</div>
                        <div class="c-rows">
                            <div class="c-row"><span class="label">Sales Classification</span><span class="val">{{ $salesClassification ?: '—' }}</span></div>
                            <div class="c-row"><span class="label">Collection Classification</span><span class="val">{{ $collectionClassification ?: '—' }}</span></div>
                            <div class="c-row"><span class="label">Profit Classification</span><span class="val">{{ $profitClassification ?: '—' }}</span></div>
                            <div class="c-row"><span class="label">Documents Classification</span><span class="val">{{ $documentsClassification ?: '—' }}</span></div>
                        </div>
                    </div>

                    <div class="group">
                        <div class="group-title tint-amber">Finance</div>
                        <div class="c-rows">
                            <div class="c-row"><span class="label">Total Credit Limit</span><span class="val">SAR {{ number_format($totalCreditLimit, 2) }}</span></div>
                            <div class="c-row"><span class="label">Outstanding Balance</span><span class="val {{ $outstandingBalance > 0 ? 'amber' : '' }}">SAR {{ number_format($outstandingBalance, 2) }}</span></div>
                            <div class="c-row"><span class="label">Attested Account — YES</span><span class="val">{{ number_format($attestedYes) }}</span></div>
                            <div class="c-row"><span class="label">Attested Account — NO</span><span class="val">{{ number_format($attestedNo) }}</span></div>
                        </div>
                        @if ($paymentTermsBuckets->isNotEmpty())
                            <div class="c-rows" style="border-top:1px solid var(--line2)">
                                @foreach (['0', '30', '45', '60', '90'] as $bucket)
                                    <div class="c-row"><span class="label">{{ $bucket }} Days</span><span class="val">{{ number_format($paymentTermsBuckets[$bucket] ?? 0) }}</span></div>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    <div class="group full">
                        <div class="group-title tint-teal">Performance</div>
                        <div class="c-rows">
                            <div class="c-row"><span class="label">Net Sales (Lifetime)</span><span class="val">SAR {{ number_format($repNetSalesLifetime, 2) }}</span></div>
                            <div class="c-row"><span class="label">Revenue Rank — Company (Lifetime)</span><span class="val">{{ $revenueRank ? '#'.$revenueRank.' / '.number_format($totalRepsRanked) : '—' }}</span></div>
                            <div class="c-row"><span class="label">Last Sales Order</span><span class="val">{{ $lastSalesOrderDate ? \Illuminate\Support\Carbon::parse($lastSalesOrderDate)->format('Y-m-d') : '—' }}</span></div>
                            <div class="c-row"><span class="label">YTD Highest Revenue Month</span><span class="val">{{ $ytdHighestMonth['label'] ?? '—' }}</span></div>
                            <div class="c-row"><span class="label">YTD Lowest Revenue Month</span><span class="val">{{ $ytdLowestMonth['label'] ?? '—' }}</span></div>
                            <div class="c-row"><span class="label">Last Payment</span><span class="val">{{ $lastPaymentDate ? \Illuminate\Support\Carbon::parse($lastPaymentDate)->format('Y-m-d') : '—' }}</span></div>
                            <div class="c-row"><span class="label">Revenue Rank — Company (YTD)</span><span class="val">{{ $salesKpiTable['YTD']['Revenue Rank - Company'] ?? '—' }}</span></div>
                            <div class="c-row"><span class="label">Collection Rank — Company (YTD)</span><span class="val">{{ $collectionKpiTable['YTD']['Collection Rank - Company'] ?? '—' }}</span></div>
                            <div class="c-row"><span class="label">Insights Count</span><span class="val">{{ number_format($insightsCount) }}</span></div>
                            @php
                                $repYoyTint = match (true) {
                                    ($overviewTargets['yoy_growth_pct'] ?? null) === null => '',
                                    str_starts_with($overviewTargets['yoy_growth_pct'], '-') => 'rose',
                                    default => 'green',
                                };
                            @endphp
                            <div class="c-row"><span class="label">YoY Growth %</span><span class="val {{ $repYoyTint }}">{{ $overviewTargets['yoy_growth_pct'] ?? '—' }}</span></div>
                            <div class="c-row"><span class="label">YTD Sales TGT Ach%</span><span class="val">{{ $overviewTargets['ytd_sales_tgt_ach_pct'] ?? '—' }}</span></div>
                            <div class="c-row"><span class="label">MTD Sales TGT Ach%</span><span class="val">{{ $overviewTargets['mtd_sales_tgt_ach_pct'] ?? '—' }}</span></div>
                            <div class="c-row"><span class="label">YTD Collection TGT Ach%</span><span class="val">{{ $overviewTargets['ytd_collection_tgt_ach_pct'] ?? '—' }}</span></div>
                            <div class="c-row"><span class="label">MTD Collection TGT Ach%</span><span class="val">{{ $overviewTargets['mtd_collection_tgt_ach_pct'] ?? '—' }}</span></div>
                        </div>
                    </div>

                </div>
                </div>

                <div x-show="tab === 'sales'" x-cloak>
                    <div class="section-head"><h2><span class="section-icon">▦</span>Sales</h2></div>

                    @include('customers.partials.kpi-table', ['table' => $salesKpiTable, 'kpis' => ['Net Sales', 'Gross Sales', 'Sales Average', 'Sales Median', 'Revenue Volatility %', 'Revenue Contribution %', 'Revenue Rank - Company', 'Invoice Count', 'Average Invoice Value', 'Largest Invoice']])

                    @if (! empty($salesCharts))
                        <div class="charts-label">Charts</div>
                        <div class="chart-grid">
                            @foreach ($salesCharts as $metric => $points)
                                @include('customers.partials.line-chart', ['title' => $metric, 'points' => $points, 'color' => ['#2f7de1', '#21a59a', '#7b61d8', '#ef9a42', '#2f9b61', '#d05a7a', '#3979a8'][$loop->index % 7]])
                            @endforeach
                        </div>
                    @endif
                </div>

                <div x-show="tab === 'credit_note'" x-cloak>
                    <div class="section-head"><h2><span class="section-icon">▦</span>Credit Note</h2></div>

                    @include('customers.partials.kpi-table', ['table' => $creditNoteKpiTable, 'kpis' => ['Return Value', 'Credit Notes Count', 'Return Average', 'Return Median', 'Return Rate %', 'Average Credit Note']])

                    @if (! empty($creditNoteCharts))
                        <div class="charts-label">Charts</div>
                        <div class="chart-grid">
                            @foreach ($creditNoteCharts as $metric => $points)
                                @include('customers.partials.line-chart', ['title' => $metric, 'points' => $points, 'color' => ['#2f7de1', '#21a59a', '#7b61d8', '#ef9a42', '#2f9b61', '#d05a7a', '#3979a8'][$loop->index % 7]])
                            @endforeach
                        </div>
                    @endif
                </div>

                <div x-show="tab === 'sales_orders'" x-cloak>
                    <div class="section-head"><h2><span class="section-icon">▦</span>Sales Orders</h2></div>
                    @include('customers.partials.kpi-table', ['table' => $salesOrdersKpiTable, 'kpis' => ['Order Quantity', 'Sales Order Value', 'SKU Count', 'Pending Quantity', 'Pending Value', 'Pending SKU Count', 'Pending Rate %', 'Lost Quantity', 'Lost Sales Value', 'Lost SKU Count', 'Lost Sales Rate %', 'Quantity Fulfillment Rate %', 'Value Fulfillment Rate %', 'SKU Fulfillment Rate %']])

                    @if (! empty($salesOrdersCharts))
                        <div class="charts-label">Charts</div>
                        <div class="chart-grid">
                            @foreach ($salesOrdersCharts as $metric => $points)
                                @include('customers.partials.line-chart', ['title' => $metric, 'points' => $points, 'color' => ['#2f7de1', '#21a59a', '#7b61d8', '#ef9a42', '#2f9b61', '#d05a7a', '#3979a8'][$loop->index % 7]])
                            @endforeach
                        </div>
                    @endif
                </div>

                <div x-show="tab === 'profitability'" x-cloak>
                    <div class="section-head"><h2><span class="section-icon">▦</span>Profitability</h2></div>
                    @include('customers.partials.kpi-table', ['table' => $profitabilityKpiTable, 'kpis' => ['Gross Profit', 'Gross Margin %', 'GP Contribution %', 'GP Rank', 'Average GP% per Invoice']])

                    @if (! empty($profitabilityCharts))
                        <div class="charts-label">Charts</div>
                        <div class="chart-grid">
                            @foreach ($profitabilityCharts as $metric => $points)
                                @include('customers.partials.line-chart', ['title' => $metric, 'points' => $points, 'color' => ['#2f7de1', '#21a59a'][$loop->index % 2]])
                            @endforeach
                        </div>
                    @endif
                </div>

                <div x-show="tab === 'collection'" x-cloak>
                    <div class="section-head"><h2><span class="section-icon">▦</span>Collection</h2></div>
                    @include('customers.partials.kpi-table', ['table' => $collectionKpiTable, 'kpis' => ['Collection', 'Collection Ratio vs Sales', 'Collection Average', 'Collection Median', 'Collection Volatility %', 'Collection Contribution %', 'Collection Rank - Company', 'Payment Count', 'Average Payment Value', 'Largest Payment Value', 'Average Payment Interval']])

                    @if (! empty($collectionCharts))
                        <div class="charts-label">Charts</div>
                        <div class="chart-grid">
                            @foreach ($collectionCharts as $metric => $points)
                                @include('customers.partials.line-chart', ['title' => $metric, 'points' => $points, 'color' => ['#2f7de1', '#21a59a', '#7b61d8'][$loop->index % 3]])
                            @endforeach
                        </div>
                    @endif
                </div>

                <div x-show="tab === 'ar'" x-cloak>
                    <div class="section-head"><h2><span class="section-icon">▦</span>Accounts Receivable</h2></div>
                    @php
                        $repArLifetimeTable = array_intersect_key($arKpiTable, ['Lifetime' => true]);
                        $repArLifetimeTable = ['All Invoices — Balance As Of Today' => $repArLifetimeTable['Lifetime'] ?? []];
                    @endphp
                    @include('customers.partials.kpi-table', ['table' => $repArLifetimeTable, 'kpis' => ['Outstanding AR', 'Outstanding Invoices Count', 'Outstanding Contribution % (Company)', 'Overdue Amount', 'Overdue Invoices Count', 'Overdue Ratio %', 'Overdue Contribution % (Company)', 'Due This Month', 'Due This Month Ratio %']])

                    <div class="charts-label">Charts</div>
                    <div class="chart-grid">
                        @php
                            $repArDonuts = [
                                ['key' => 'outstanding_contribution_pct', 'title' => 'Outstanding Contribution % (Company)', 'legend' => 'Rep AR / Company AR', 'sub' => 'Share of company outstanding debt', 'color' => 'var(--blue)'],
                                ['key' => 'overdue_ratio_pct', 'title' => 'Overdue Ratio %', 'legend' => 'Rep overdue / Rep AR', 'sub' => 'Overdue share of this rep debt', 'color' => 'var(--teal)'],
                                ['key' => 'overdue_contribution_pct', 'title' => 'Overdue Contribution % (Company)', 'legend' => 'Rep overdue / Company overdue', 'sub' => 'Share of company overdue debt', 'color' => 'var(--violet)'],
                                ['key' => 'due_this_month_ratio_pct', 'title' => 'Due This Month Ratio %', 'legend' => 'Due this month / Rep AR', 'sub' => 'Share due this month from rep debt', 'color' => 'var(--amber)'],
                            ];
                        @endphp
                        @foreach ($repArDonuts as $d)
                            @php $pct = $arSnapshot[$d['key']] ?? null; @endphp
                            <div class="chart-card">
                                <div class="chart-title">{{ $d['title'] }}</div>
                                <div style="font-size:9.5px;color:#8a9bb2;margin:-4px 0 6px">Current ratio snapshot</div>
                                <div class="donut-wrap">
                                    <div class="donut" style="--p:{{ $pct !== null ? number_format(min(100, $pct), 1) : 0 }};--donutColor:{{ $d['color'] }}">
                                        <div class="donut-center">{{ $pct !== null ? number_format($pct, 1).'%' : '—' }}</div>
                                    </div>
                                    <div class="donut-legend">
                                        <div><span class="legend-dot" style="background:{{ $d['color'] }}"></span>{{ $d['legend'] }}</div>
                                        <div><span class="legend-dot light"></span>{{ $d['sub'] }}</div>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div x-show="tab === 'due_ages'" x-cloak>
                    <div class="section-head"><h2><span class="section-icon">▦</span>Due Ages</h2></div>
                    @php
                        $repAgingTotal = $arAging->sum('total');
                        $repAgingInvoiceCount = $arAging->sum('count');
                    @endphp
                    <table class="kpi-table">
                        <thead><tr><th>Aging Bucket</th><th>Invoices</th><th>Balance</th><th>%</th></tr></thead>
                        <tbody>
                            @forelse ($arAging as $bucket)
                                <tr>
                                    <td>{{ $bucket->balance_aging }}</td>
                                    <td>{{ number_format($bucket->count) }}</td>
                                    <td>{{ number_format((float) $bucket->total, 2) }} SAR</td>
                                    <td>{{ $repAgingTotal > 0 ? number_format((float) $bucket->total / $repAgingTotal * 100, 1).'%' : '—' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" style="text-align:center;color:var(--muted)">No data</td></tr>
                            @endforelse
                            <tr style="font-weight:800">
                                <td>Outstanding AR</td>
                                <td>{{ number_format($repAgingInvoiceCount) }}</td>
                                <td>{{ number_format((float) $repAgingTotal, 2) }} SAR</td>
                                <td>{{ $repAgingTotal > 0 ? '100.0%' : '—' }}</td>
                            </tr>
                        </tbody>
                    </table>

                    @php $repLedgerAdjustment = round($outstandingBalance - $repAgingTotal, 2); @endphp
                    @if (abs($repLedgerAdjustment) >= 1)
                        <div style="font-size:11px;color:var(--muted);margin-top:6px">
                            Outstanding AR on the Accounts Receivable tab is {{ number_format($outstandingBalance, 2) }} SAR —
                            the {{ number_format(abs($repLedgerAdjustment), 2) }} SAR difference is
                            {{ $repLedgerAdjustment > 0 ? 'additional' : 'reduced' }} balance from unapplied payments,
                            journal entries, or an opening balance — rows with no invoice to place in a bucket above.
                        </div>
                    @endif

                    @if (! empty($dueAgesChart['months']))
                        <div class="charts-label">Charts</div>
                        <div class="chart-card">
                            <div class="chart-title">Due Ages Composition</div>
                            <div class="chart-sub">Stacked bars by debt age · rolling last 12 months · current month is MTD</div>
                            <div class="sbar-wrap">
                                @foreach ($dueAgesChart['months'] as $i => $month)
                                    <div class="sbar-col">
                                        <div class="sbar-bar">
                                            @foreach (array_reverse($dueAgesChart['buckets']) as $bucket)
                                                @php $share = $month['shares'][$bucket] ?? 0; @endphp
                                                @if ($share > 0)
                                                    <div class="sbar-seg" style="height:{{ number_format($share, 2) }}%;background:{{ $dueAgesChart['colors'][$bucket] }}" title="{{ $bucket }}: {{ $share }}%"></div>
                                                @endif
                                            @endforeach
                                        </div>
                                        <span class="sbar-label {{ $i === count($dueAgesChart['months']) - 1 ? 'current' : '' }}">{{ $month['label'] }}{{ $i === count($dueAgesChart['months']) - 1 ? '*' : '' }}</span>
                                    </div>
                                @endforeach
                            </div>
                            <div class="sbar-legend">
                                @foreach ($dueAgesChart['buckets'] as $bucket)
                                    <span><span class="legend-dot" style="background:{{ $dueAgesChart['colors'][$bucket] }}"></span>{{ str_replace('Aging ', '', $bucket) }}</span>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>

                <div x-show="tab === 'credit_limit'" x-cloak>
                    <div class="section-head"><h2><span class="section-icon">▦</span>Credit Limit</h2></div>
                    @if ($creditUsage)
                        <div class="group-grid">
                            <div class="group">
                                <div class="c-rows">
                                    <div class="c-row"><span class="label">Credit Limit</span><span class="val">{{ $creditUsage->credit_limit !== null ? number_format((float) $creditUsage->credit_limit, 2).' SAR' : '—' }}</span></div>
                                    <div class="c-row"><span class="label">Used Credit</span><span class="val">{{ $creditUsage->used_credit !== null ? number_format((float) $creditUsage->used_credit, 2).' SAR' : '—' }}</span></div>
                                    <div class="c-row"><span class="label">Credit Utilisation %</span><span class="val {{ $creditUsage->credit_utilization_pct > 90 ? 'rose' : '' }}">{{ $creditUsage->credit_utilization_pct !== null ? number_format((float) $creditUsage->credit_utilization_pct, 1).'%' : '—' }}</span></div>
                                    <div class="c-row"><span class="label">Remaining Credit</span><span class="val">{{ $creditUsage->remaining_credit !== null ? number_format((float) $creditUsage->remaining_credit, 2).' SAR' : '—' }}</span></div>
                                    <div class="c-row"><span class="label">Available Credit %</span><span class="val">{{ $creditUsage->available_credit_pct !== null ? number_format((float) $creditUsage->available_credit_pct, 1).'%' : '—' }}</span></div>
                                    @php
                                        $repCreditStatusTint = match (true) {
                                            in_array($creditUsage->credit_status, ['OVER LIMIT', 'FULLY USED', 'BLOCK'], true) => 'rose',
                                            in_array($creditUsage->credit_status, ['HIGH UTILIZATION', 'MEDIUM UTILIZATION'], true) => 'amber',
                                            $creditUsage->credit_status === 'LOW UTILIZATION' => 'green',
                                            default => '',
                                        };
                                    @endphp
                                    <div class="c-row"><span class="label">Credit Status</span><span class="val {{ $repCreditStatusTint }}">{{ $creditUsage->credit_status ?: '—' }}</span></div>
                                </div>
                            </div>
                            @if ($creditUsage->credit_utilization_pct !== null)
                                @php
                                    $repUtilPct = max(0, min(100, (float) $creditUsage->credit_utilization_pct));
                                    $repDonutColor = $repUtilPct >= 90 ? 'var(--rose)' : ($repUtilPct >= 70 ? 'var(--amber)' : 'var(--blue)');
                                @endphp
                                <div class="group">
                                    <div class="chart-card" style="border:0;box-shadow:none">
                                        <div class="chart-title">Credit Utilisation</div>
                                        <div class="donut-wrap">
                                            <div class="donut" style="--p:{{ number_format($repUtilPct, 1) }};--donutColor:{{ $repDonutColor }}">
                                                <div class="donut-center">{{ number_format($repUtilPct, 0) }}%</div>
                                            </div>
                                            <div class="donut-legend">
                                                <div><span class="legend-dot" style="background:{{ $repDonutColor }}"></span>Used</div>
                                                <div><span class="legend-dot light"></span>Available</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @endif
                        </div>
                    @else
                        <div class="placeholder-card">No data</div>
                    @endif
                    <div class="c-rows" style="margin-top:10px;border:1px solid var(--line2);border-radius:9px;overflow:hidden">
                        <div class="c-row"><span class="label">DSO (12M)</span><span class="val">{{ $creditDso['dso_12m'] ?? '—' }}</span></div>
                        <div class="c-row"><span class="label">DSO (6M)</span><span class="val">{{ $creditDso['dso_6m'] ?? '—' }}</span></div>
                        <div class="c-row"><span class="label">DSO (3M)</span><span class="val">{{ $creditDso['dso_3m'] ?? '—' }}</span></div>
                        <div class="c-row"><span class="label">DSO Insight / Trend</span><span class="val">
                            @if (($creditDso['dso_trend'] ?? null) === 'Coming Soon')
                                <span class="badge soon">Coming Soon</span>
                            @else
                                {{ $creditDso['dso_trend'] ?? '—' }}
                            @endif
                        </span></div>
                        <div class="c-row"><span class="label">Recommended Credit Limit <span style="font-weight:400;opacity:.65">(estimate)</span></span><span class="val">{{ $creditDso['recommended_credit_limit'] ?? '—' }}</span></div>
                    </div>
                </div>

                <div x-show="tab === 'item_performance'" x-cloak x-data="{ ownerIdx: 0 }">
                    <div class="section-head"><h2><span class="section-icon">▦</span>Item Performance</h2></div>
                    @if (empty($itemPerformanceOwners))
                        <div class="placeholder-card">No item-level sales history found for this rep's customers.</div>
                    @else
                        <div class="owner-tabs">
                            @foreach ($itemPerformanceOwners as $i => $owner)
                                <button type="button" @click="ownerIdx = {{ $i }}" :class="ownerIdx === {{ $i }} ? 'owner-tab active' : 'owner-tab'">{{ $owner['label'] }}</button>
                            @endforeach
                        </div>
                        <div style="font-size:10.5px;color:var(--muted);margin:6px 0 10px">Purchaser / item owner responsible for the assigned SKUs</div>

                        @foreach ($itemPerformanceOwners as $i => $owner)
                            <div x-show="ownerIdx === {{ $i }}" x-cloak>
                                <div style="font-size:11.5px;font-weight:700;color:#21496f;margin-bottom:8px">Selected owner: {{ $owner['label'] }} — {{ $owner['name'] }} · 7 KPIs across Lifetime, Previous Year, YTD, Previous Month, MTD, Yesterday and Today</div>
                                @include('customers.partials.kpi-table', ['table' => $owner['table'], 'kpis' => ['Net Sales', 'Avg Sales', 'Sales Contribution %', 'SKU Count', 'SKU Avg Sales', 'Profit %', 'Profit Contribution %']])

                                @if (! empty($owner['charts']))
                                    <div class="charts-label">Charts</div>
                                    <div class="chart-grid">
                                        @foreach ($owner['charts'] as $metric => $points)
                                            @include('customers.partials.line-chart', ['title' => $metric, 'points' => $points, 'color' => ['#2f7de1', '#21a59a', '#7b61d8', '#ef9a42', '#2f9b61', '#d05a7a', '#3979a8'][$loop->index % 7]])
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    @endif
                </div>

                <div x-show="tab === 'insights'" x-cloak>
                    <div class="section-head"><h2><span class="section-icon">i</span>Insights</h2></div>
                    <div class="placeholder-card">Coming Soon</div>
                </div>

            </div>
        </div>
    </div>
</x-app-layout>
