<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Customers') }}
        </h2>
    </x-slot>

    @include('partials.c360-styles')

    <div class="py-12 c360" x-data="{ tab: '{{ request('tab', 'overview') }}' }">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-0">

            <div class="crumbs-bar">
                <div class="crumbs">Customers / <b>{{ $customer->customer_name }}</b></div>
                <a href="{{ route('customers.index') }}" class="btn-outline">Back to Customers</a>
            </div>

            <div class="customer-head">
                <div class="customer-title">
                    <div class="avatar">{{ strtoupper(substr($customer->customer_name ?? '?', 0, 2)) }}</div>
                    <div>
                        <h1>{{ $customer->customer_name }}</h1>
                        <p>
                            {{ $customer->customer_number ?: '—' }}
                            @if ($customer->account_type) &middot; {{ $customer->account_type }} @endif
                        </p>
                    </div>
                </div>
                <div class="head-badges">
                    <span @class(['badge', 'gray' => $customer->status !== 'Active'])>{{ strtoupper($customer->status ?? '—') }}</span>
                    @if ($customer->live_contact_synced_at)
                        <span class="badge gray" title="{{ $customer->live_contact_synced_at }}">Synced {{ $customer->live_contact_synced_at->diffForHumans() }}</span>
                    @endif
                </div>
            </div>

            @php
                $tabs = [
                    'overview' => 'Overview',
                    'general' => 'General',
                    'sales' => 'Sales',
                    'return' => 'Credit Note',
                    'sales_orders' => 'Sales Orders',
                    'profitability' => 'Profitability',
                    'collection' => 'Collection',
                    'ar' => 'Accounts Receivable',
                    'due_ages' => 'Due Ages',
                    'credit_limit' => 'Credit Limit',
                    'item_performance' => 'Item Performance',
                    'insights' => 'Insights ('.count($insights).')',
                    'history' => 'Change History ('.$changeLogs->total().')',
                ];
                // Hides disallowed tab buttons for a role-scoped user — a
                // visual gate only, per the explicit "skip detailed
                // permission enforcement for now" instruction. The tab's
                // content still renders in the page (just not linked to),
                // so this is not a real access-control boundary yet.
                $tabs = collect($tabs)->filter(fn ($label, $key) => Auth::user()->hasPermission("customers.tab.{$key}"))->all();
            @endphp

            <div class="tabs">
                @foreach ($tabs as $key => $label)
                    <button @click="tab = '{{ $key }}'" :class="tab === '{{ $key }}' ? 'active' : ''" class="tab">{{ $label }}</button>
                @endforeach
            </div>

            <div class="tab-content">

                {{-- Overview --}}
                <div x-show="tab === 'overview'">
                    <div class="section-head"><h2><span class="section-icon">360</span>Overview</h2></div>
                    <div class="group-grid">

                        <div class="group">
                            <div class="group-title tint-blue">Account Information</div>
                            <div class="c-rows">
                                <div class="c-row"><span class="label">Customer Name</span><span class="val">{{ $customer->customer_name ?: '—' }}</span></div>
                                <div class="c-row"><span class="label">Status</span><span class="val {{ $customer->status === 'Active' ? 'green' : '' }}">{{ $customer->status ?: '—' }}</span></div>
                                <div class="c-row"><span class="label">Customer Number</span><span class="val">{{ $customer->customer_number ?: '—' }}</span></div>
                                <div class="c-row"><span class="label">Account Type</span><span class="val">{{ $customer->account_type ?: '—' }}</span></div>
                                <div class="c-row"><span class="label">Business Unit</span><span class="val">{{ $customer->extra['management'] ?? '—' }}</span></div>
                                <div class="c-row"><span class="label">Business Relation Time</span><span class="val">{{ $customer->zoho_created_time ? number_format(now()->diffInYears($customer->zoho_created_time, true), 1).' Years' : '—' }}</span></div>
                            </div>
                        </div>

                        <div class="group">
                            <div class="group-title tint-green">Classification</div>
                            <div class="c-rows">
                                @php
                                    $classTint = fn ($label) => match (true) {
                                        $label === null => '',
                                        // No current activity to judge at all — a plain
                                        // status, not a health signal, so neutral gray
                                        // rather than defaulting to green (confirmed live
                                        // 2026-10-06, manager report: "Healthy" in green
                                        // for a 14-month-dormant customer read as "doing
                                        // fine" when there was nothing current to base
                                        // that on — see ComputeProfitabilityClassification).
                                        $label === 'No Sales L6M' => 'gray',
                                        str_contains($label, 'Critical') || str_contains($label, 'Severe') || str_contains($label, 'Loss') || str_contains($label, 'Lost') => 'rose',
                                        str_contains($label, 'Attention') || str_contains($label, 'Late') || str_contains($label, 'Declining') || str_contains($label, 'Low') => 'amber',
                                        default => 'green',
                                    };
                                @endphp
                                @php
                                    // Rebuilt 2026-10-05 (reports:compute-sales-classification, replacing
                                    // the Zoho-sourced version) — Growth % here now compares the last 45
                                    // days against this customer's OWN trailing-12-month average (prorated
                                    // to a 45-day share), not the immediately preceding 45 days. Confirmed
                                    // live (CUS-5): two consecutive weak 45-day windows used to read as
                                    // "Strong Growth" (69K vs a prior-45D of 35K, both already mid-decline
                                    // from a 362K baseline 5 months earlier) — comparing against the real
                                    // 12-month average catches that a short recovery is still far below
                                    // normal. Still a DIFFERENT window than "YoY Growth %" above (full
                                    // calendar YTD vs. same period last year) — both can legitimately
                                    // disagree without being a contradiction.
                                    $salesClassData = $classifications['sales']->data ?? null;
                                @endphp
                                <div class="c-row"><span class="label">Sales Classification <span style="font-weight:400;opacity:.65">(Last 45 Days vs 12M Avg)</span></span><span class="val {{ $classTint($classifications['sales']->classification ?? null) }}" title="{{ $salesClassData ? 'Current 45D: '.number_format((float) ($salesClassData['current_45d_net_sales'] ?? 0), 0).' SAR vs normal 45D (12-month avg): '.number_format((float) ($salesClassData['baseline_45d_equivalent'] ?? 0), 0).' SAR — a different window than YoY Growth % above' : '' }}">{{ $classifications['sales']->classification ?? 'No Sales Yet' }}</span></div>
                                @php
                                    // Collection Classification only ever looks at a customer's
                                    // last 5 invoices that were actually CLOSED BY PAYMENT (Dr.
                                    // Ahmed's own SQL, confirmed live 2026-10-05) — it answers
                                    // "when they did pay, how promptly?", not "are they current
                                    // right now?". Confirmed live on CUS-998: showed "On Time /
                                    // Perfect" (median -16 days — genuinely paid 16 days early on
                                    // their last 5 settled invoices) while simultaneously sitting
                                    // 100% overdue, no sale or payment in 2+ months — both true at
                                    // once, since the open/unpaid invoices never entered this
                                    // calculation at all. Tooltip surfaces the actual last
                                    // settlement date so this reads as a historical metric, not a
                                    // current-status one — same fix pattern as Sales
                                    // Classification's 45-day tooltip above.
                                    $collectionClassData = $classifications['collection']->data ?? null;
                                    $collectionTooltip = null;
                                    if (! empty($collectionClassData['Last Settlement Date'])) {
                                        try {
                                            $lastSettle = \Carbon\Carbon::createFromFormat('d/m/Y', $collectionClassData['Last Settlement Date'])->startOfDay();
                                            $daysSinceSettle = (int) $lastSettle->diffInDays(now());
                                            $medianDelay = $collectionClassData['Median Settlement Delay Days'] ?? null;
                                            $delayDesc = $medianDelay !== null
                                                ? ((float) $medianDelay < 0 ? number_format(abs((float) $medianDelay), 0).' days early' : number_format((float) $medianDelay, 0).' days late')
                                                : 'n/a';
                                            $invoicesUsed = $collectionClassData['Invoices Used'] ?? '?';
                                            $collectionTooltip = "Based on last {$invoicesUsed} PAID invoices — last settled {$lastSettle->format('Y-m-d')} ({$daysSinceSettle} days ago), median {$delayDesc}. Reflects payment history on closed invoices only, not current open balance — see Due Ages / Accounts Receivable for that.";
                                        } catch (\Throwable $e) {
                                            $collectionTooltip = null;
                                        }
                                    }
                                @endphp
                                @php
                                    // "Not synced yet" read as a sync failure — confirmed live
                                    // 2026-10-05 (CUS-1460, CUS-1526) it's neither: Dr. Ahmed's
                                    // query requires at least one invoice CLOSED BY PAYMENT before
                                    // a customer gets a row at all, and that's confirmed true even
                                    // in the Zoho Analytics source table directly (empty result
                                    // filtered to either customer, screenshot-verified) — not a gap
                                    // on our end. CUS-1460 even has 3 real partial payments on
                                    // record, just none that fully closed an invoice yet, so this
                                    // label needed to read as "pending first settlement," not
                                    // "broken."
                                    $noCollectionDataTooltip = 'No invoice has been fully closed by payment yet — Collection Classification needs at least one fully-settled invoice before it can show a pattern. Will appear automatically once one closes (daily sync).';
                                @endphp
                                <div class="c-row"><span class="label">Collection Classification</span><span class="val {{ $classTint($classifications['collection']->classification ?? null) }}" title="{{ $collectionTooltip ?? $noCollectionDataTooltip }}">{{ $classifications['collection']->classification ?? 'No Closed Invoice Yet' }}</span></div>
                                <div class="c-row"><span class="label">Profit Classification</span><span class="val {{ $classTint($classifications['profitability']->classification ?? null) }}">{{ $classifications['profitability']->classification ?? 'Not synced yet' }}</span></div>
                                <div class="c-row"><span class="label">Documents Classification</span><span class="val {{ $classTint($classifications['documents']->classification ?? null) }}">{{ $classifications['documents']->classification ?? 'Not synced yet' }}</span></div>
                                <div class="c-row"><span class="label">AR Risk Classification</span><span class="val"><span class="badge soon">Coming Soon</span></span></div>
                            </div>
                        </div>

                        <div class="group">
                            <div class="group-title tint-amber">Finance</div>
                            <div class="c-rows">
                                <div class="c-row"><span class="label">CR Expiry Date</span><span class="val {{ $customer->cr_expiry_date && $customer->cr_expiry_date->isPast() ? 'rose' : '' }}">{{ optional($customer->cr_expiry_date)->format('Y-m-d') ?? '—' }}</span></div>
                                <div class="c-row"><span class="label">Attested Account</span><span class="val">{{ $customer->attested_account ?: '—' }}</span></div>
                                {{-- customer_credit_usage.credit_limit, not customers.credit_limit — same
                                     stale-one-time-import issue as the customer list page (see
                                     CustomerController::index()'s 'live_credit_limit' comment); this
                                     spot was missed in that fix (Ahmed Mohsen, 2026-10-02: "same label
                                     gives different values across the dashboard"). --}}
                                <div class="c-row"><span class="label">Credit Limit</span><span class="val">{{ $creditUsage?->credit_limit !== null ? number_format((float) $creditUsage->credit_limit, 2).' SAR' : '—' }}</span></div>
                                <div class="c-row"><span class="label">Last Reconciliation Date</span><span class="val">{{ optional($customer->last_reconciliation_date)->format('Y-m-d') ?? '—' }}</span></div>
                                <div class="c-row"><span class="label">Payment Terms</span><span class="val">{{ $customer->payment_terms_label ?: ($customer->payments_due ? $customer->payments_due.' days' : '—') }}</span></div>
                                <div class="c-row"><span class="label">Promissory Note</span><span class="val">{{ $customer->extra['promissory_note'] ?? '—' }}</span></div>
                                <div class="c-row"><span class="label">Promissory Note Issue Date</span><span class="val">{{ $customer->extra['promissory_note_issue_date'] ?? '—' }}</span></div>
                            </div>
                        </div>

                        <div class="group">
                            <div class="group-title tint-rose">Operations</div>
                            <div class="c-rows">
                                <div class="c-row"><span class="label">Rep Name</span><span class="val">{{ $customer->rep_name ?: '—' }}</span></div>
                                <div class="c-row"><span class="label">Sales Owner</span><span class="val">{{ $customer->sales_owner ?: '—' }}</span></div>
                                <div class="c-row"><span class="label">Warehouse City</span><span class="val">{{ $customer->warehouse_city ?: '—' }}</span></div>
                                <div class="c-row"><span class="label">Data Entry</span><span class="val">{{ $customer->data_entry ?: '—' }}</span></div>
                            </div>
                        </div>

                        {{-- "Zoho Contact Details" group hidden per request (2026-10-09)
                             — $customerExtra is still computed and passed by the
                             controller, just not rendered here. --}}

                        <div class="group full">
                            <div class="group-title tint-teal">Performance</div>
                            <div class="c-rows">
                                <div class="c-row"><span class="label">Last Sales Order</span><span class="val">{{ $lastSalesOrderDate ? \Illuminate\Support\Carbon::parse($lastSalesOrderDate)->format('Y-m-d') : '—' }}</span></div>
                                <div class="c-row"><span class="label">YTD Highest Revenue Month</span><span class="val">{{ $ytdHighestMonth['label'] ?? '—' }}</span></div>
                                <div class="c-row"><span class="label">YTD Lowest Revenue Month</span><span class="val">{{ $ytdLowestMonth['label'] ?? '—' }}</span></div>
                                <div class="c-row"><span class="label">Last Payment</span><span class="val">{{ $lastPaymentDate ? \Illuminate\Support\Carbon::parse($lastPaymentDate)->format('Y-m-d') : '—' }}</span></div>
                                {{-- Switched Lifetime -> YTD (Omar, 2026-10-03, confirmed twice
                                     against an earlier "keep it Lifetime" message from Ahmed Mohsen
                                     2026-10-01 — flagged the conflict once, this is the standing
                                     decision now). Revenue Rank now reads the Sales tab's own live
                                     per-period rank ($salesKpiTable — see buildSalesKpiTable()),
                                     not $salesRecommendation->revenue_rank, which has no date filter
                                     at all (ComputeCustomerRevenueRanks.php) and could only ever be
                                     Lifetime. Collection Rank just swaps which column of
                                     $collectionKpiTable it reads — that table was already live
                                     per-period. --}}
                                <div class="c-row"><span class="label">Revenue Rank — Company (YTD)</span><span class="val">{{ $salesKpiTable['YTD']['Revenue Rank - Company'] ?? '—' }}</span></div>
                                <div class="c-row"><span class="label">Collection Rank — Company (YTD)</span><span class="val">{{ $collectionKpiTable['YTD']['Collection Rank - Company'] ?? '—' }}</span></div>
                                <div class="c-row"><span class="label">Insights Count</span><span class="val">{{ count($insights) }}</span></div>
                                @php
                                    // Confirmed live 2026-10-02 (CUS-1407, a brand-new 0.2-year customer
                                    // with no prior-year sales to compare): the old ternary only had two
                                    // branches, so a null value (shown as "—") fell into the same "not
                                    // negative" branch as a real positive number and rendered green —
                                    // implying growth where there's actually no data at all.
                                    $yoyTint = match (true) {
                                        ($overviewTargets['yoy_growth_pct'] ?? null) === null => '',
                                        str_starts_with($overviewTargets['yoy_growth_pct'], '-') => 'rose',
                                        default => 'green',
                                    };
                                @endphp
                                <div class="c-row"><span class="label">YoY Growth %</span><span class="val {{ $yoyTint }}">{{ $overviewTargets['yoy_growth_pct'] ?? '—' }}</span></div>
                                <div class="c-row"><span class="label">YTD Sales TGT Ach%</span><span class="val" title="Needs an Annual/Monthly Sales Target set on the customer — most customers don't have one yet">{{ $overviewTargets['ytd_sales_tgt_ach_pct'] ?? '—' }}</span></div>
                                <div class="c-row"><span class="label">MTD Sales TGT Ach%</span><span class="val" title="Needs an Annual/Monthly Sales Target set on the customer — most customers don't have one yet">{{ $overviewTargets['mtd_sales_tgt_ach_pct'] ?? '—' }}</span></div>
                                <div class="c-row"><span class="label">YTD Collection TGT Ach%</span><span class="val" title="Needs an Annual/Monthly Collection Target set on the customer — most customers don't have one yet">{{ $overviewTargets['ytd_collection_tgt_ach_pct'] ?? '—' }}</span></div>
                                <div class="c-row"><span class="label">MTD Collection TGT Ach%</span><span class="val" title="Needs an Annual/Monthly Collection Target set on the customer — most customers don't have one yet">{{ $overviewTargets['mtd_collection_tgt_ach_pct'] ?? '—' }}</span></div>
                            </div>
                        </div>

                    </div>
                </div>

                {{-- General — mirrors the Al Nasr Customer Profile Excel mockup's field
                     groups, but only fields genuinely sourced from Zoho's live Contacts
                     data today (confirmed live 2026-10-15 against a real contact detail
                     response) — PriceList ID, Promissory Note (+ issue date), Finance
                     Dept Recommendation, all 10 branch locations/cities, Delivery time,
                     and the 24 monthly sales/collection targets are NOT present anywhere
                     in Zoho's contact payload for this org, so they're deliberately left
                     out rather than shown as permanently-blank fields (explicit decision,
                     same day). CR Status, Tax Registration Number, Portal Enabled, MOH
                     SFDA License Expiry Date, and Reconciliation Status are also left out
                     for the same reason — ZohoContactMapper's own docblock already flags
                     those as never confirmed against a live field, so values in those
                     columns (populated only by the one-time Excel import) would never
                     refresh in this tab. --}}
                <div x-show="tab === 'general'" x-cloak>
                    <div class="section-head"><h2><span class="section-icon">ℹ</span>General</h2></div>
                    <div class="group-grid">

                        <div class="group">
                            <div class="group-title tint-blue">Basic Information</div>
                            <div class="c-rows">
                                <div class="c-row"><span class="label">Customer Name</span><span class="val">{{ $customer->customer_name ?: '—' }}</span></div>
                                <div class="c-row"><span class="label">Customer Number</span><span class="val">{{ $customer->customer_number ?: '—' }}</span></div>
                                <div class="c-row"><span class="label">Status</span><span class="val {{ $customer->status === 'Active' ? 'green' : '' }}">{{ $customer->status ?: '—' }}</span></div>
                                <div class="c-row"><span class="label">Account Type</span><span class="val">{{ $customer->account_type ?: '—' }}</span></div>
                                <div class="c-row"><span class="label">City</span><span class="val">{{ $customer->city ?: '—' }}</span></div>
                            </div>
                        </div>

                        <div class="group">
                            <div class="group-title tint-violet">Legal &amp; Regulatory</div>
                            <div class="c-rows">
                                <div class="c-row"><span class="label">CR No</span><span class="val">{{ $customer->cr_no ?: '—' }}</span></div>
                                <div class="c-row"><span class="label">CR Expiry Date</span><span class="val">{{ $customer->cr_expiry_date?->format('Y-m-d') ?? '—' }}</span></div>
                                <div class="c-row"><span class="label">GLN No</span><span class="val">{{ $customer->gln_no ?: '—' }}</span></div>
                            </div>
                        </div>

                        <div class="group">
                            <div class="group-title tint-teal">Sales &amp; Management</div>
                            <div class="c-rows">
                                <div class="c-row"><span class="label">Management</span><span class="val">{{ $customer->extra['management'] ?? '—' }}</span></div>
                                <div class="c-row"><span class="label">Rep Name</span><span class="val">{{ $customer->rep_name ?: '—' }}</span></div>
                                <div class="c-row"><span class="label">Sales Owner</span><span class="val">{{ $customer->sales_owner ?: '—' }}</span></div>
                                <div class="c-row"><span class="label">Warehouse City</span><span class="val">{{ $customer->warehouse_city ?: '—' }}</span></div>
                            </div>
                        </div>

                        <div class="group">
                            <div class="group-title tint-green">Credit &amp; Payment</div>
                            <div class="c-rows">
                                <div class="c-row"><span class="label">Credit Limit</span><span class="val">{{ $customer->credit_limit !== null ? number_format((float) $customer->credit_limit, 2) : '—' }}</span></div>
                                <div class="c-row"><span class="label">Payments Due</span><span class="val">{{ $customer->payment_terms_label ?: ($customer->payments_due ? $customer->payments_due.' days' : '—') }}</span></div>
                                <div class="c-row"><span class="label">Opening Balance</span><span class="val">{{ $customer->opening_balance !== null ? number_format((float) $customer->opening_balance, 2) : '—' }}</span></div>
                            </div>
                        </div>

                        <div class="group">
                            <div class="group-title tint-amber">Reconciliation</div>
                            <div class="c-rows">
                                <div class="c-row"><span class="label">Attested Account</span><span class="val">{{ $customer->attested_account ?: '—' }}</span></div>
                                <div class="c-row"><span class="label">Last Reconciliation Date</span><span class="val">{{ $customer->last_reconciliation_date?->format('Y-m-d') ?? '—' }}</span></div>
                            </div>
                        </div>

                        <div class="group">
                            <div class="group-title tint-rose">Finance Review</div>
                            <div class="c-rows">
                                <div class="c-row"><span class="label">Finance Dept Last Review Date</span><span class="val">{{ $customer->finance_dept_last_review_date?->format('Y-m-d') ?? '—' }}</span></div>
                            </div>
                        </div>

                        <div class="group">
                            <div class="group-title tint-blue">Sales &amp; Collection Targets</div>
                            <div class="c-rows">
                                <div class="c-row"><span class="label">Annual Sales Target</span><span class="val">{{ isset($customer->extra['annual_sales_tgt']) ? number_format((float) $customer->extra['annual_sales_tgt'], 2) : '—' }}</span></div>
                                <div class="c-row"><span class="label">Annual Collection Target</span><span class="val">{{ isset($customer->extra['annual_collection_tgt']) ? number_format((float) $customer->extra['annual_collection_tgt'], 2) : '—' }}</span></div>
                            </div>
                        </div>

                        <div class="group">
                            <div class="group-title tint-violet">System Information</div>
                            <div class="c-rows">
                                <div class="c-row"><span class="label">Created Time</span><span class="val">{{ $customer->zoho_created_time?->format('Y-m-d H:i') ?? '—' }}</span></div>
                                <div class="c-row"><span class="label">Last Modified Time</span><span class="val">{{ $customer->zoho_last_modified_time?->format('Y-m-d H:i') ?? '—' }}</span></div>
                                <div class="c-row"><span class="label">Data Entry</span><span class="val">{{ $customer->data_entry ?: '—' }}</span></div>
                            </div>
                        </div>

                    </div>
                </div>

                {{-- Sales — real data from customer_sales_behavior + customer_sales_recommendations --}}
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

                {{-- Credit Note (formerly "Return") — real data from ar_ledger_entries (entity_type=creditnote) --}}
                <div x-show="tab === 'return'" x-cloak>
                    <div class="section-head"><h2><span class="section-icon">▦</span>Credit Note</h2></div>
                    @include('customers.partials.kpi-table', ['table' => $returnKpiTable, 'kpis' => ['Return Value', 'Credit Notes Count', 'Return Average', 'Return Median', 'Return Rate %', 'Average Credit Note']])

                    @if (! empty($returnCharts))
                        <div class="charts-label">Charts</div>
                        <div class="chart-grid">
                            @foreach ($returnCharts as $metric => $points)
                                @include('customers.partials.line-chart', ['title' => $metric, 'points' => $points, 'color' => ['#ad4056', '#a96d12', '#6c50b2', '#2f7de1'][$loop->index % 4]])
                            @endforeach
                        </div>
                    @endif
                </div>

                {{-- Sales Orders — quantity-based real data (no reliable monetary/fulfillment column on sale_items) --}}
                <div x-show="tab === 'sales_orders'" x-cloak>
                    <div class="section-head"><h2><span class="section-icon">▦</span>Sales Orders</h2></div>
                    @include('customers.partials.kpi-table', ['table' => $salesOrdersKpiTable, 'kpis' => ['Order Quantity', 'Sales Order Value', 'SKU Count', 'Pending Quantity', 'Pending Value', 'Pending SKU Count', 'Pending Rate %', 'Lost Quantity', 'Lost Sales Value', 'Lost SKU Count', 'Lost Sales Rate %', 'Quantity Fulfillment Rate %', 'Value Fulfillment Rate %', 'SKU Fulfillment Rate %']])

                    @if (! empty($salesOrdersCharts))
                        <div class="charts-label">Charts</div>
                        <div class="chart-grid">
                            @foreach ($salesOrdersCharts as $metric => $points)
                                @include('customers.partials.line-chart', ['title' => $metric, 'points' => $points, 'color' => ['#2f7de1', '#139653', '#ad4056', '#a96d12'][$loop->index % 4]])
                            @endforeach
                        </div>
                    @endif
                </div>

                {{-- Item Performance — real data: sales_item_performance joined to item_owners (Zoho
                     "Items" export) by zoho_item_id. "Owner" = Purchaser responsible for the item's
                     SKUs (Dr. Ahmed, voice note 2026-09-27), ranked by this customer's own Lifetime
                     Net Sales — not a fixed roster, a customer with fewer owners just shows fewer tabs. --}}
                <div x-show="tab === 'item_performance'" x-cloak x-data="{ ownerIdx: 0 }">
                    <div class="section-head"><h2><span class="section-icon">▦</span>Item Performance</h2></div>
                    @if (empty($itemPerformanceOwners))
                        <div class="placeholder-card">No item-level sales history found for this customer.</div>
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

                {{-- Profitability — real data from ar_ledger_entries + the Profitability Classification detail --}}
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

                {{-- Collection — real data from ar_ledger_entries + Collection Classification detail --}}
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

                {{-- Accounts Receivable — real data from invoice_documents, period-bucketed by
                     invoice_date (see buildArKpiTable's docblock for why: AR balance is a running
                     total, not a dated transaction, so periods bucket by invoice date and report
                     today's balance for those invoices — same convention as every other tab, not a
                     fabricated historical snapshot) --}}
                <div x-show="tab === 'ar'" x-cloak>
                    <div class="section-head"><h2><span class="section-icon">▦</span>Accounts Receivable</h2></div>
                    @php
                        // Relabeled for display only (Ahmed Mohsen feedback): this is every
                        // invoice ever issued, balance AS OF TODAY — not a true historical
                        // "Lifetime" aggregate like Collection/Sales use the same key name
                        // for. See buildArKpiTable()'s docblock for the full reasoning.
                        $arLifetimeTable = array_intersect_key($arKpiTable, ['Lifetime' => true]);
                        $arLifetimeTable = ['All Invoices — Balance As Of Today' => $arLifetimeTable['Lifetime'] ?? []];
                    @endphp
                    @include('customers.partials.kpi-table', ['table' => $arLifetimeTable, 'kpis' => ['Outstanding AR', 'Outstanding Invoices Count', 'Outstanding Contribution % (Company)', 'Overdue Amount', 'Overdue Invoices Count', 'Overdue Ratio %', 'Overdue Contribution % (Company)', 'Due This Month', 'Due This Month Ratio %']])

                    <div class="charts-label">Charts</div>
                    <div class="chart-grid">
                        @php
                            $arDonuts = [
                                ['key' => 'outstanding_contribution_pct', 'title' => 'Outstanding Contribution % (Company)', 'legend' => 'Customer AR / Company AR', 'sub' => 'Share of company outstanding debt', 'color' => 'var(--blue)'],
                                ['key' => 'overdue_ratio_pct', 'title' => 'Overdue Ratio %', 'legend' => 'Customer overdue / Customer AR', 'sub' => 'Overdue share of this customer debt', 'color' => 'var(--teal)'],
                                ['key' => 'overdue_contribution_pct', 'title' => 'Overdue Contribution % (Company)', 'legend' => 'Customer overdue / Company overdue', 'sub' => 'Share of company overdue debt', 'color' => 'var(--violet)'],
                                ['key' => 'due_this_month_ratio_pct', 'title' => 'Due This Month Ratio %', 'legend' => 'Due this month / Customer AR', 'sub' => 'Share due this month from customer debt', 'color' => 'var(--amber)'],
                            ];
                        @endphp
                        @foreach ($arDonuts as $d)
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

                {{-- Due Ages — ar_ledger_entries.balance_aging (Zoho's own pre-computed bucket),
                     not invoice_documents — switched 2026-10-04 after that table's sync lag
                     produced a real, reproduced mismatch against this same customer's Credit
                     Limit tab (Ahmed Mohsen, CUS-1184). --}}
                <div x-show="tab === 'due_ages'" x-cloak>
                    <div class="section-head"><h2><span class="section-icon">▦</span>Due Ages</h2></div>
                    @php
                        $agingTotal = $arAging->sum('total');
                        $agingInvoiceCount = $arAging->sum('count');
                    @endphp
                    <table class="kpi-table">
                        <thead><tr><th>Aging Bucket</th><th>Invoices</th><th>Balance</th><th>%</th></tr></thead>
                        <tbody>
                            @forelse ($arAging as $bucket)
                                <tr>
                                    <td>{{ $bucket->balance_aging }}</td>
                                    <td>{{ number_format($bucket->count) }}</td>
                                    <td>{{ number_format((float) $bucket->total, 2) }} SAR</td>
                                    <td>{{ $agingTotal > 0 ? number_format((float) $bucket->total / $agingTotal * 100, 1).'%' : '—' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" style="text-align:center;color:var(--muted)">No data</td></tr>
                            @endforelse
                            <tr style="font-weight:800">
                                <td>Outstanding AR</td>
                                <td>{{ number_format($agingInvoiceCount) }}</td>
                                <td>{{ number_format((float) $agingTotal, 2) }} SAR</td>
                                <td>{{ $agingTotal > 0 ? '100.0%' : '—' }}</td>
                            </tr>
                        </tbody>
                    </table>

                    {{-- These 7 buckets total every invoice/credit-note row that still carries
                         an open balance_aging bucket. The AR tab's Outstanding AR is the sum of
                         statement_amount across every ledger row for this customer, including
                         any unapplied-payment, journal, or opening-balance rows that have no
                         invoice to bucket against — so a real gap here means one of those, not
                         a sync-lag mismatch (that class of bug was the old invoice_documents
                         source's issue, fixed 2026-10-04 by switching to this same ledger table). --}}
                    @php $ledgerAdjustment = round($outstandingBalance - $agingTotal, 2); @endphp
                    @if (abs($ledgerAdjustment) >= 1)
                        <div style="font-size:11px;color:var(--muted);margin-top:6px">
                            Outstanding AR on the Accounts Receivable tab is {{ number_format($outstandingBalance, 2) }} SAR —
                            the {{ number_format(abs($ledgerAdjustment), 2) }} SAR difference is
                            {{ $ledgerAdjustment > 0 ? 'additional' : 'reduced' }} balance from unapplied payments,
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

                {{-- Credit Limit — real data from customer_credit_usage --}}
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
                                        // Real distinct values confirmed live (2026-10-01): LOW
                                        // UTILIZATION, MEDIUM UTILIZATION, HIGH UTILIZATION, OVER
                                        // LIMIT, FULLY USED, NO CREDIT LIMIT. None contain "Exceed",
                                        // so the old check rendered every status green except one
                                        // that never occurs — HIGH UTILIZATION/OVER LIMIT/FULLY USED
                                        // all showed as "healthy" green. Fixed to a real severity map.
                                        $creditStatusTint = match (true) {
                                            in_array($creditUsage->credit_status, ['OVER LIMIT', 'FULLY USED', 'BLOCK'], true) => 'rose',
                                            in_array($creditUsage->credit_status, ['HIGH UTILIZATION', 'MEDIUM UTILIZATION'], true) => 'amber',
                                            $creditUsage->credit_status === 'LOW UTILIZATION' => 'green',
                                            default => '',
                                        };
                                    @endphp
                                    <div class="c-row"><span class="label">Credit Status</span><span class="val {{ $creditStatusTint }}">{{ $creditUsage->credit_status ?: '—' }}</span></div>
                                </div>
                            </div>
                            @if ($creditUsage->credit_utilization_pct !== null)
                                @php
                                    $utilPct = max(0, min(100, (float) $creditUsage->credit_utilization_pct));
                                    $donutColor = $utilPct >= 90 ? 'var(--rose)' : ($utilPct >= 70 ? 'var(--amber)' : 'var(--blue)');
                                @endphp
                                <div class="group">
                                    <div class="chart-card" style="border:0;box-shadow:none">
                                        <div class="chart-title">Credit Utilisation</div>
                                        <div class="donut-wrap">
                                            <div class="donut" style="--p:{{ number_format($utilPct, 1) }};--donutColor:{{ $donutColor }}">
                                                <div class="donut-center">{{ number_format($utilPct, 0) }}%</div>
                                            </div>
                                            <div class="donut-legend">
                                                <div><span class="legend-dot" style="background:{{ $donutColor }}"></span>Used</div>
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
                                <span class="badge soon" title="Removed 2026-10-02 (Ahmed Mohsen): comparing DSO across different trailing-sales windows reads as a collection-speed trend but is actually driven by sales-volume swings — confirmed wrong on a real customer where it claimed 'Improving' during a month collections were only 40% of sales">Coming Soon</span>
                            @else
                                {{ $creditDso['dso_trend'] ?? '—' }}
                            @endif
                        </span></div>
                        <div class="c-row"><span class="label">Recommended Credit Limit <span style="font-weight:400;opacity:.65">(estimate)</span></span><span class="val" title="Average Monthly Net Sales × (Payment Terms + 15 grace days) / 30 + 20% safety margin — Ahmed Mohsen's formula, 2026-10-02, replacing the old circular DSO-based one. The grace-day and safety-margin figures are reasonable placeholders, not confirmed company policy yet.">{{ $creditDso['recommended_credit_limit'] ?? '—' }}</span></div>
                    </div>
                </div>

                {{-- Insights — one card per category, only for categories with something to flag
                     (per Dr. Ahmed's direction: no forced fixed list, empty tab for a clean customer).
                     Temporarily forced to "Coming Soon" (2026-09-30, explicit ask: keep all
                     underlying data/computation exactly as-is, just don't display it yet) — flip
                     $insightsTabEnabled back to true below to restore; nothing else was changed. --}}
                <div x-show="tab === 'insights'" x-cloak>
                    <div class="section-head"><h2><span class="section-icon">i</span>Insights</h2></div>

                    @php
                        $insightsTabEnabled = false;
                    @endphp
                    @if (! $insightsTabEnabled)
                    <div class="placeholder-card">Coming Soon</div>
                    @else
                    @if (empty($insights))
                        <div class="placeholder-card">No issues flagged for this customer.</div>
                    @else
                        <div style="display:flex;flex-direction:column;gap:8px">
                            @foreach ($insights as $insight)
                                <div class="group">
                                    <div class="group-title tint-{{ $insight['tint'] }}">
                                        {{ $insight['title'] }}
                                        @if ($insight['meta'])
                                            <span style="font-weight:600;opacity:.75"> — {{ $insight['meta'] }}</span>
                                        @endif
                                    </div>
                                    <div style="padding:9px 11px;font-size:12.5px">{{ $insight['body'] }}</div>

                                    @if ($insight['key'] === 'sales' && $salesRecommendation)
                                        <div class="c-rows">
                                            <div class="c-row"><span class="label">Key Risk</span><span class="val rose" style="font-weight:600">{{ $salesRecommendation->key_risk ?: '—' }}</span></div>
                                            <div class="c-row"><span class="label">Key Opportunity</span><span class="val green" style="font-weight:600">{{ $salesRecommendation->key_opportunity ?: '—' }}</span></div>
                                        </div>
                                        @if ($salesRecommendation->recommended_sales_rep_action)
                                            <div style="font-size:12px;background:#f7fbff;border-top:1px solid var(--line2);padding:8px 11px">
                                                <b>Suggested action:</b> {{ $salesRecommendation->recommended_sales_rep_action }}
                                            </div>
                                        @endif
                                    @endif

                                    @if ($insight['key'] === 'documents')
                                        <div class="c-rows" style="grid-template-columns:1fr">
                                            @foreach ($documentInsightRows as $row)
                                                <div class="c-row" style="display:block">
                                                    <div style="font-weight:700">{{ $row->action }}</div>
                                                    @if ($row->why)
                                                        <div style="color:var(--muted);font-size:11.5px">{{ $row->why }}</div>
                                                    @endif
                                                </div>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                    @endif
                </div>

                {{-- Sales History tab — scoped to the rolling last 12 months, same window as every chart on this page --}}
                <div x-show="tab === 'sales_history'" x-cloak class="space-y-4">
                    <p class="text-xs text-gray-500">Rolling last 12 months</p>
                    <form method="GET" class="flex flex-wrap gap-3 items-end">
                        <input type="hidden" name="tab" value="sales_history">
                        <div class="flex-1 min-w-[240px]">
                            <label class="block text-xs font-medium text-gray-500 uppercase mb-1">Search document # / SKU</label>
                            <input type="text" name="sales_search" value="{{ $salesSearch }}"
                                   class="w-full rounded-md border-gray-300 text-sm focus:border-gray-500 focus:ring-gray-500">
                        </div>
                        <button type="submit" class="px-4 py-2 bg-gray-800 text-white text-sm font-semibold rounded-md hover:bg-gray-700">
                            Search
                        </button>
                        @if ($salesSearch)
                            <a href="{{ route('customers.show-legacy', $customer) }}" class="text-sm text-gray-500 hover:text-gray-700">Clear</a>
                        @endif
                    </form>

                    <div class="flex flex-wrap gap-2 text-xs text-gray-500">
                        @foreach ($salesCountsByType as $type => $count)
                            <span class="px-3 py-1.5 rounded-full bg-gray-100">{{ $sourceLabels[$type] }}s: {{ $count }}</span>
                        @endforeach
                    </div>

                    <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Document #</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">SKU</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Quantity</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">View</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                @forelse ($salesHistory as $row)
                                    @php
                                        $reportType = match ($row->type) { 'sales' => 'sales', 'invoices' => 'invoices', 'returns' => 'returns', default => $row->type };
                                    @endphp
                                    <tr>
                                        <td class="px-4 py-3 text-sm text-gray-500">{{ $sourceLabels[$row->type] ?? $row->type }}</td>
                                        <td class="px-4 py-3 text-sm text-gray-500">
                                            <a href="{{ route('reports.document', [$reportType, $row->document_number]) }}" class="underline decoration-dotted hover:text-gray-900">
                                                {{ $row->document_number }}
                                            </a>
                                        </td>
                                        <td class="px-4 py-3 text-sm text-gray-900">{{ $row->sku }}</td>
                                        <td class="px-4 py-3 text-sm text-right text-gray-500">{{ number_format($row->quantity) }}</td>
                                        <td class="px-4 py-3 text-sm text-gray-500">{{ \Illuminate\Support\Carbon::parse($row->transaction_date)->format('Y-m-d') }}</td>
                                        <td class="px-4 py-3 text-sm">
                                            <span class="px-2 py-1 rounded text-xs font-semibold bg-gray-100 text-gray-700 whitespace-nowrap">
                                                {{ str($row->status)->title() }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 text-sm text-center">
                                            <a href="{{ route('reports.document', [$reportType, $row->document_number]) }}"
                                               class="inline-flex items-center justify-center w-8 h-8 rounded-md text-gray-500 hover:text-gray-900 hover:bg-gray-100">
                                                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                </svg>
                                            </a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7" class="px-6 py-8 text-center text-sm text-gray-500">
                                            No sales history found for this customer.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    {{ $salesHistory->links() }}
                </div>

                {{-- Change History tab (existing functionality, preserved) --}}
                <div x-show="tab === 'history'" x-cloak class="space-y-4">
                    <p class="text-sm text-gray-500">
                        Every field change detected on this customer since the change log started tracking (2026-09-22) — earlier history can't be reconstructed.
                    </p>

                    <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Field</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Old Value</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">New Value</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                @forelse ($changeLogs as $log)
                                    <tr>
                                        <td class="px-4 py-3 text-sm text-gray-500 whitespace-nowrap">{{ $log->changed_at->format('Y-m-d H:i') }}</td>
                                        <td class="px-4 py-3 text-sm text-gray-800">{{ str($log->field)->replace('_', ' ')->title() }}</td>
                                        <td class="px-4 py-3 text-sm text-gray-500">{{ $log->old_value ?? '—' }}</td>
                                        <td class="px-4 py-3 text-sm text-gray-800">{{ $log->new_value ?? '—' }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="px-6 py-8 text-center text-sm text-gray-500">
                                            No tracked changes for this customer yet.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    {{ $changeLogs->links() }}
                </div>

            </div>
        </div>
    </div>
</x-app-layout>
