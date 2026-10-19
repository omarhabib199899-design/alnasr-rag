<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Dashboard') }}
        </h2>
    </x-slot>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <style>
        .viz-root {
            --surface-1: #ffffff;
            --text-primary: #0b0b0f;
            --text-secondary: #5b5b66;
            --text-muted: #9494a0;
            --series-1: #2563eb;
            --series-sales: #2563eb;
            --series-purchases: #14b8a6;
            --series-returns: #f59e0b;
            --gridline: rgba(11, 11, 15, 0.06);
            --baseline: rgba(11, 11, 15, 0.12);
            --border-hairline: rgba(11, 11, 15, 0.06);
            --status-good: #16a34a;
            --status-critical: #ef4444;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }
        .viz-card {
            background: var(--surface-1);
            border: 1px solid var(--border-hairline);
            border-radius: 0.875rem;
            padding: 1.75rem;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.06);
        }
        .viz-kpi-card {
            display: flex;
            align-items: flex-start;
            gap: 0.875rem;
            /* prevents the horizontal-scrollbar-on-a-fixed-width-card bug:
               flex items default to min-width:auto, so a long value (e.g.
               a large dollar figure) can't shrink below its own content
               width and instead forces the whole card to overflow. */
            min-width: 0;
        }
        .viz-kpi-card > div:last-child {
            min-width: 0;
        }
        .viz-kpi-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 3rem;
            height: 3rem;
            border-radius: 0.75rem;
            background: rgba(37, 99, 235, 0.08);
            color: var(--series-1);
            flex-shrink: 0;
        }
        .viz-kpi-icon svg { width: 20px; height: 20px; }
        .viz-kpi-icon.critical { background: rgba(239, 68, 68, 0.08); color: var(--status-critical); }
        .viz-kpi-icon.good { background: rgba(22, 163, 74, 0.08); color: var(--status-good); }
        .viz-kpi-icon.purchases { background: rgba(20, 184, 166, 0.08); color: var(--series-purchases); }
        .viz-kpi-icon.returns { background: rgba(245, 158, 11, 0.08); color: var(--series-returns); }
        .viz-label {
            font-size: 0.8125rem;
            color: var(--text-secondary);
            font-weight: 500;
        }
        .viz-value {
            font-size: 2.25rem;
            font-weight: 700;
            color: var(--text-primary);
            font-variant-numeric: proportional-nums;
            line-height: 1.2;
            letter-spacing: -0.02em;
            overflow-wrap: break-word;
        }
        .viz-card-hero {
            padding: 2rem;
        }
        .viz-section-title {
            font-size: 0.9375rem;
            font-weight: 600;
            letter-spacing: 0.2px;
            color: var(--text-primary);
        }
        .viz-module-title {
            font-size: 0.75rem;
            font-weight: 700;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: var(--text-muted);
        }
        .viz-table {
            width: 100%;
            border-collapse: collapse;
        }
        .viz-table th {
            text-align: left;
            font-size: 0.6875rem;
            font-weight: 600;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            color: var(--text-muted);
            padding: 0.5rem 0.5rem;
            border-bottom: 1px solid var(--gridline);
        }
        .viz-table td {
            padding: 0.5rem 0.5rem;
            font-size: 0.8125rem;
            color: var(--text-primary);
            border-bottom: 1px solid var(--gridline);
        }
        .viz-table tbody tr:last-child td { border-bottom: none; }
        .viz-table tbody tr:hover { background: var(--gridline); }
        .viz-table .num { text-align: right; font-variant-numeric: tabular-nums; color: var(--text-secondary); }
        .viz-toggle {
            font-size: 0.75rem;
            color: var(--text-muted);
            background: none;
            border: none;
            cursor: pointer;
            text-decoration: none;
            font-weight: 500;
        }
        .viz-toggle:hover { color: var(--series-1); }
        .status-icon { display: inline-block; vertical-align: -2px; margin-right: 0.25rem; }
        .sync-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.375rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.8125rem;
            font-weight: 500;
            background: var(--surface-1);
            border: 1px solid var(--border-hairline);
            color: var(--text-secondary);
        }
        .sync-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            flex-shrink: 0;
        }
        .sync-dot.fresh { background: var(--status-good); box-shadow: 0 0 0 3px rgba(22, 163, 74, 0.15); }
        .sync-dot.stale { background: #d97706; box-shadow: 0 0 0 3px rgba(217, 119, 6, 0.15); }
        .sync-dot.old { background: var(--status-critical); box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.15); }
        .sync-dot.none { background: var(--text-muted); }
    </style>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6 viz-root">

            <div class="flex justify-end">
                @if ($lastSyncedAt)
                    @php
                        $syncAgeMinutes = $lastSyncedAt->diffInMinutes(now());
                        $syncFreshness = $syncAgeMinutes < 120 ? 'fresh' : ($syncAgeMinutes < 360 ? 'stale' : 'old');
                        $lastSyncedCairo = $lastSyncedAt->copy()->setTimezone('Africa/Cairo');
                    @endphp
                    <span class="sync-badge" title="{{ $lastSyncedCairo->format('Y-m-d h:i A') }} (Cairo time)">
                        <span class="sync-dot {{ $syncFreshness }}"></span>
                        Last synced {{ $lastSyncedAt->diffForHumans() }} — {{ $lastSyncedCairo->format('M j, h:i A') }}
                    </span>
                @else
                    <span class="sync-badge">
                        <span class="sync-dot none"></span>
                        Never synced
                    </span>
                @endif
            </div>

            @if (session('status'))
                <div class="rounded-md bg-green-50 p-4 text-sm text-green-800">
                    {{ session('status') }}
                </div>
            @endif

            @if (! $hasData)
                <div class="viz-card text-center">
                    <p class="mb-4" style="color: var(--text-secondary)">No data yet. Upload an inventory or sales Excel file to see stats here.</p>
                    <a href="{{ route('imports.create') }}" class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700">
                        {{ __('Upload Excel File') }}
                    </a>
                </div>
            @else
            @if ($hasInventory)
                <h2 class="viz-module-title">Inventory</h2>
                {{-- KPI tiles --}}
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    <div class="viz-card viz-kpi-card">
                        <div class="viz-kpi-icon">
                            <svg width="18" height="18" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M2 5.5 8 2l6 3.5v6L8 15l-6-3.5v-6Z" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/>
                                <path d="M2 5.5 8 9l6-3.5M8 9v6" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/>
                            </svg>
                        </div>
                        <div>
                            <div class="viz-label">{{ $kpis['total_stock']['label'] }}</div>
                            <div class="viz-value">{{ number_format($kpis['total_stock']['value']) }}</div>
                        </div>
                    </div>
                    <div class="viz-card viz-kpi-card">
                        <div class="viz-kpi-icon">
                            <svg width="18" height="18" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <circle cx="8" cy="8" r="6.5" stroke="currentColor" stroke-width="1.3"/>
                                <path d="M8 4.5v7M10.2 6.3c0-.9-1-1.6-2.2-1.6s-2.2.7-2.2 1.6c0 2 4.4.9 4.4 2.9 0 .9-1 1.6-2.2 1.6s-2.2-.7-2.2-1.6" stroke="currentColor" stroke-width="1.1" stroke-linecap="round"/>
                            </svg>
                        </div>
                        <div>
                            <div class="viz-label">{{ $kpis['total_stock_value']['label'] }}</div>
                            <div class="viz-value">{{ $currencySymbol }} {{ number_format($kpis['total_stock_value']['value'], 2) }}</div>
                        </div>
                    </div>
                    <div class="viz-card viz-kpi-card">
                        <div class="viz-kpi-icon">
                            <svg width="18" height="18" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M8 2 14 5.5 8 9 2 5.5 8 2Z" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/>
                                <path d="M2 9 8 12.5 14 9M2 11.5 8 15 14 11.5" stroke="currentColor" stroke-width="1.1" stroke-linejoin="round" stroke-linecap="round"/>
                            </svg>
                        </div>
                        <div>
                            <div class="viz-label">{{ $kpis['item_count']['label'] }}</div>
                            <div class="viz-value">{{ number_format($kpis['item_count']['value']) }}</div>
                        </div>
                    </div>
                    @php $stockouts = $kpis['stockout_count']['value']; @endphp
                    <div class="viz-card viz-kpi-card">
                        <div class="viz-kpi-icon {{ $stockouts > 0 ? 'critical' : 'good' }}">
                            <svg width="18" height="18" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                                @if ($stockouts > 0)
                                    <path d="M8 1 15 14H1L8 1Z" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/>
                                    <path d="M8 6v3.5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/>
                                    <circle cx="8" cy="11.5" r="0.75" fill="currentColor"/>
                                @else
                                    <circle cx="8" cy="8" r="6.5" stroke="currentColor" stroke-width="1.3"/>
                                    <path d="M5.2 8.2 7.2 10.2 11 6" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/>
                                @endif
                            </svg>
                        </div>
                        <div>
                            <div class="viz-label">{{ $kpis['stockout_count']['label'] }}</div>
                            <div class="viz-value" style="color: {{ $stockouts > 0 ? 'var(--status-critical)' : 'var(--status-good)' }}">
                                {{ number_format($stockouts) }}
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Hero chart --}}
                <div class="viz-card viz-card-hero">
                    <div class="flex justify-between items-baseline mb-4">
                        <h3 class="viz-section-title" style="font-size: 1.0625rem;">Stock Value by Category</h3>
                        @if (count($categoryBreakdown['rows']) > 0)
                            <button type="button" class="viz-toggle" onclick="toggleView('category')">View as table</button>
                        @endif
                    </div>
                    @if (count($categoryBreakdown['rows']) > 0)
                        <div id="category-chart-view"><div id="categoryChart" style="height: 320px;"></div></div>
                        <div id="category-table-view" class="hidden">@include('partials.breakdown-table', ['rows' => $categoryBreakdown['rows']])</div>
                    @else
                        <p class="text-sm" style="color: var(--text-muted)">No category data in this import.</p>
                    @endif
                </div>

                {{-- Secondary breakdown charts --}}
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div class="viz-card">
                        <div class="flex justify-between items-baseline mb-3">
                            <h3 class="viz-section-title">Stock Value by Warehouse</h3>
                            @if (count($warehouseBreakdown['rows']) > 0)
                                <button type="button" class="viz-toggle" onclick="toggleView('warehouse')">View as table</button>
                            @endif
                        </div>
                        @if (count($warehouseBreakdown['rows']) > 0)
                            <div id="warehouse-chart-view"><div id="warehouseChart" style="height: 200px;"></div></div>
                            <div id="warehouse-table-view" class="hidden">@include('partials.breakdown-table', ['rows' => $warehouseBreakdown['rows']])</div>
                        @else
                            <p class="text-sm" style="color: var(--text-muted)">No warehouse data in this import.</p>
                        @endif
                    </div>
                    <div class="viz-card">
                        <div class="flex justify-between items-baseline mb-3">
                            <h3 class="viz-section-title">Item Count by Category</h3>
                            @if (count($itemCountByCategory['rows']) > 0)
                                <button type="button" class="viz-toggle" onclick="toggleView('itemcount')">View as table</button>
                            @endif
                        </div>
                        @if (count($itemCountByCategory['rows']) > 0)
                            <div id="itemcount-chart-view"><div id="itemCountChart" style="height: 200px;"></div></div>
                            <div id="itemcount-table-view" class="hidden">@include('partials.breakdown-table', ['rows' => $itemCountByCategory['rows'], 'format' => 'number', 'valueLabel' => 'Items'])</div>
                        @else
                            <p class="text-sm" style="color: var(--text-muted)">No category data in this import.</p>
                        @endif
                    </div>
                    <div class="viz-card">
                        <div class="flex justify-between items-baseline mb-3">
                            <h3 class="viz-section-title">Stockouts by Category</h3>
                            @if (count($stockoutsByCategory['rows']) > 0)
                                <button type="button" class="viz-toggle" onclick="toggleView('stockouts')">View as table</button>
                            @endif
                        </div>
                        @if (count($stockoutsByCategory['rows']) > 0)
                            <div id="stockouts-chart-view"><div id="stockoutsChart" style="height: 200px;"></div></div>
                            <div id="stockouts-table-view" class="hidden">@include('partials.breakdown-table', ['rows' => $stockoutsByCategory['rows'], 'format' => 'number', 'valueLabel' => 'Stockouts'])</div>
                        @else
                            <p class="text-sm" style="color: var(--text-muted)">No stockouts — nothing to show here.</p>
                        @endif
                    </div>
                </div>

                {{-- Listings --}}
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="viz-card">
                        <h3 class="viz-section-title mb-3">Lowest Stock (Reorder Candidates)</h3>
                        <table class="viz-table">
                            <thead>
                                <tr>
                                    <th>SKU</th>
                                    <th>Name</th>
                                    <th class="num">Stock</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($lowestStock['rows'] as $row)
                                    <tr>
                                        <td>{{ $row['sku'] }}</td>
                                        <td>{{ $row['name'] }}</td>
                                        <td class="num">{{ $row['stock'] }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="3" class="text-center" style="color: var(--text-muted)">No items with stock.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="viz-card">
                        <h3 class="viz-section-title mb-3">Highest Value Items</h3>
                        <table class="viz-table">
                            <thead>
                                <tr>
                                    <th>SKU</th>
                                    <th>Name</th>
                                    <th class="num">Stock &times; Price</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($highestValue['rows'] as $row)
                                    <tr>
                                        <td>{{ $row['sku'] }}</td>
                                        <td>{{ $row['name'] }}</td>
                                        <td class="num">{{ $currencySymbol }} {{ number_format($row['stock'] * $row['price'], 2) }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="3" class="text-center" style="color: var(--text-muted)">No items.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            @if ($hasSales)
                <h2 class="viz-module-title">Sales</h2>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="viz-card viz-kpi-card">
                        <div class="viz-kpi-icon">
                            <svg width="18" height="18" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M2 2h1.5l1.2 8.2a1.5 1.5 0 0 0 1.5 1.3h5.6a1.5 1.5 0 0 0 1.5-1.2l1-5.3H4.2" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round" stroke-linecap="round"/>
                                <circle cx="6.5" cy="14" r="1" fill="currentColor"/>
                                <circle cx="11.5" cy="14" r="1" fill="currentColor"/>
                            </svg>
                        </div>
                        <div>
                            <div class="viz-label">{{ $salesKpis['total_quantity_ordered']['label'] }}</div>
                            <div class="viz-value">{{ number_format($salesKpis['total_quantity_ordered']['value']) }}</div>
                        </div>
                    </div>
                    @php $cancelled = $salesKpis['cancelled_orders_count']['value']; @endphp
                    <div class="viz-card viz-kpi-card">
                        <div class="viz-kpi-icon {{ $cancelled > 0 ? 'critical' : 'good' }}">
                            <svg width="18" height="18" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <circle cx="8" cy="8" r="6.5" stroke="currentColor" stroke-width="1.3"/>
                                <path d="M5.8 5.8 10.2 10.2M10.2 5.8 5.8 10.2" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/>
                            </svg>
                        </div>
                        <div>
                            <div class="viz-label">{{ $salesKpis['cancelled_orders_count']['label'] }}</div>
                            <div class="viz-value" style="color: {{ $cancelled > 0 ? 'var(--status-critical)' : 'var(--status-good)' }}">
                                {{ number_format($cancelled) }}
                            </div>
                        </div>
                    </div>
                </div>

                <div class="viz-card viz-card-hero">
                    <div class="flex justify-between items-baseline mb-4">
                        <h3 class="viz-section-title" style="font-size: 1.0625rem;">Orders by Month</h3>
                        @if (count($ordersByMonth['rows']) > 0)
                            <button type="button" class="viz-toggle" onclick="toggleView('ordersmonth')">View as table</button>
                        @endif
                    </div>
                    @if (count($ordersByMonth['rows']) > 0)
                        <div id="ordersmonth-chart-view"><div id="ordersByMonthChart" style="height: 280px;"></div></div>
                        <div id="ordersmonth-table-view" class="hidden">@include('partials.breakdown-table', ['rows' => $ordersByMonth['rows'], 'format' => 'number', 'valueLabel' => 'Orders'])</div>
                    @else
                        <p class="text-sm" style="color: var(--text-muted)">No orders in this import.</p>
                    @endif
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="viz-card">
                        <div class="flex justify-between items-baseline mb-3">
                            <h3 class="viz-section-title">Most Ordered Products (SKU)</h3>
                            @if (count($mostOrdered['rows']) > 0)
                                <button type="button" class="viz-toggle" onclick="toggleView('mostordered')">View as table</button>
                            @endif
                        </div>
                        @if (count($mostOrdered['rows']) > 0)
                            <div id="mostordered-chart-view"><div id="mostOrderedChart" style="height: 220px;"></div></div>
                            <div id="mostordered-table-view" class="hidden">@include('partials.breakdown-table', ['rows' => $mostOrdered['rows'], 'format' => 'number', 'valueLabel' => 'Units'])</div>
                        @else
                            <p class="text-sm" style="color: var(--text-muted)">No orders in this import.</p>
                        @endif
                    </div>
                    <div class="viz-card">
                        <div class="flex justify-between items-baseline mb-3">
                            <h3 class="viz-section-title">Most Cancelled Products (SKU)</h3>
                            @if (count($mostCancelled['rows']) > 0)
                                <button type="button" class="viz-toggle" onclick="toggleView('mostcancelled')">View as table</button>
                            @endif
                        </div>
                        @if (count($mostCancelled['rows']) > 0)
                            <div id="mostcancelled-chart-view"><div id="mostCancelledChart" style="height: 220px;"></div></div>
                            <div id="mostcancelled-table-view" class="hidden">@include('partials.breakdown-table', ['rows' => $mostCancelled['rows'], 'format' => 'number', 'valueLabel' => 'Cancelled'])</div>
                        @else
                            <p class="text-sm" style="color: var(--text-muted)">No cancellations — nothing to show here.</p>
                        @endif
                    </div>
                </div>
            @endif

            @if ($hasPurchases)
                <h2 class="viz-module-title">Purchase Orders</h2>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="viz-card viz-kpi-card">
                        <div class="viz-kpi-icon purchases">
                            <svg width="18" height="18" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M1.5 4.5h6.5v6H1.5zM8 7h3l2 2.2v1.3H8z" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/>
                                <circle cx="4.5" cy="11.5" r="1.2" stroke="currentColor" stroke-width="1.1"/>
                                <circle cx="10.5" cy="11.5" r="1.2" stroke="currentColor" stroke-width="1.1"/>
                            </svg>
                        </div>
                        <div>
                            <div class="viz-label">{{ $purchaseKpis['total_quantity_purchased']['label'] }}</div>
                            <div class="viz-value">{{ number_format($purchaseKpis['total_quantity_purchased']['value']) }}</div>
                        </div>
                    </div>
                    <div class="viz-card viz-kpi-card">
                        <div class="viz-kpi-icon purchases">
                            <svg width="18" height="18" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <circle cx="8" cy="8" r="6.5" stroke="currentColor" stroke-width="1.3"/>
                                <path d="M8 4.5V8l2.5 1.5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </div>
                        <div>
                            <div class="viz-label">{{ $purchaseKpis['pending_purchase_orders_count']['label'] }}</div>
                            <div class="viz-value">{{ number_format($purchaseKpis['pending_purchase_orders_count']['value']) }}</div>
                        </div>
                    </div>
                </div>

                <div class="viz-card viz-card-hero">
                    <div class="flex justify-between items-baseline mb-4">
                        <h3 class="viz-section-title" style="font-size: 1.0625rem;">Purchase Orders by Month</h3>
                        @if (count($purchasesByMonth['rows']) > 0)
                            <button type="button" class="viz-toggle" onclick="toggleView('purchasesmonth')">View as table</button>
                        @endif
                    </div>
                    @if (count($purchasesByMonth['rows']) > 0)
                        <div id="purchasesmonth-chart-view"><div id="purchasesByMonthChart" style="height: 280px;"></div></div>
                        <div id="purchasesmonth-table-view" class="hidden">@include('partials.breakdown-table', ['rows' => $purchasesByMonth['rows'], 'format' => 'number', 'valueLabel' => 'Orders'])</div>
                    @else
                        <p class="text-sm" style="color: var(--text-muted)">No purchase orders in this import.</p>
                    @endif
                </div>

                <div class="viz-card">
                    <div class="flex justify-between items-baseline mb-3">
                        <h3 class="viz-section-title">Most Purchased Products (SKU)</h3>
                        @if (count($mostPurchased['rows']) > 0)
                            <button type="button" class="viz-toggle" onclick="toggleView('mostpurchased')">View as table</button>
                        @endif
                    </div>
                    @if (count($mostPurchased['rows']) > 0)
                        <div id="mostpurchased-chart-view"><div id="mostPurchasedChart" style="height: 220px;"></div></div>
                        <div id="mostpurchased-table-view" class="hidden">@include('partials.breakdown-table', ['rows' => $mostPurchased['rows'], 'format' => 'number', 'valueLabel' => 'Units'])</div>
                    @else
                        <p class="text-sm" style="color: var(--text-muted)">No purchase orders in this import.</p>
                    @endif
                </div>
            @endif

            @if ($hasReturns)
                <h2 class="viz-module-title">Returns</h2>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    @php $totalReturned = $returnsKpis['total_units_returned']['value']; @endphp
                    <div class="viz-card viz-kpi-card">
                        <div class="viz-kpi-icon {{ $totalReturned > 0 ? 'returns' : 'good' }}">
                            <svg width="18" height="18" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M3 8a5 5 0 1 1 1.5 3.6" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/>
                                <path d="M3 4.5V8h3.5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </div>
                        <div>
                            <div class="viz-label">{{ $returnsKpis['total_units_returned']['label'] }}</div>
                            <div class="viz-value" style="color: {{ $totalReturned > 0 ? 'var(--series-returns)' : 'var(--status-good)' }}">
                                {{ number_format($totalReturned) }}
                            </div>
                        </div>
                    </div>
                </div>

                <div class="viz-card viz-card-hero">
                    <div class="flex justify-between items-baseline mb-4">
                        <h3 class="viz-section-title" style="font-size: 1.0625rem;">Returns by Month</h3>
                        @if (count($returnsByMonth['rows']) > 0)
                            <button type="button" class="viz-toggle" onclick="toggleView('returnsmonth')">View as table</button>
                        @endif
                    </div>
                    @if (count($returnsByMonth['rows']) > 0)
                        <div id="returnsmonth-chart-view"><div id="returnsByMonthChart" style="height: 280px;"></div></div>
                        <div id="returnsmonth-table-view" class="hidden">@include('partials.breakdown-table', ['rows' => $returnsByMonth['rows'], 'format' => 'number', 'valueLabel' => 'Returns'])</div>
                    @else
                        <p class="text-sm" style="color: var(--text-muted)">No returns in this import.</p>
                    @endif
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="viz-card">
                        <div class="flex justify-between items-baseline mb-3">
                            <h3 class="viz-section-title">Most Returned Products (SKU)</h3>
                            @if (count($mostReturned['rows']) > 0)
                                <button type="button" class="viz-toggle" onclick="toggleView('mostreturned')">View as table</button>
                            @endif
                        </div>
                        @if (count($mostReturned['rows']) > 0)
                            <div id="mostreturned-chart-view"><div id="mostReturnedChart" style="height: 220px;"></div></div>
                            <div id="mostreturned-table-view" class="hidden">@include('partials.breakdown-table', ['rows' => $mostReturned['rows'], 'format' => 'number', 'valueLabel' => 'Units'])</div>
                        @else
                            <p class="text-sm" style="color: var(--text-muted)">No returns in this import.</p>
                        @endif
                    </div>
                    <div class="viz-card">
                        <div class="flex justify-between items-baseline mb-3">
                            <h3 class="viz-section-title">Returns by Reason</h3>
                            @if (count($returnsByReason['rows']) > 0)
                                <button type="button" class="viz-toggle" onclick="toggleView('returnsreason')">View as table</button>
                            @endif
                        </div>
                        @if (count($returnsByReason['rows']) > 0)
                            <div id="returnsreason-chart-view"><div id="returnsByReasonChart" style="height: 220px;"></div></div>
                            <div id="returnsreason-table-view" class="hidden">@include('partials.breakdown-table', ['rows' => $returnsByReason['rows'], 'format' => 'number', 'valueLabel' => 'Returns'])</div>
                        @else
                            <p class="text-sm" style="color: var(--text-muted)">No returns in this import.</p>
                        @endif
                    </div>
                </div>
            @endif

                {{-- Pinned from chat --}}
                @if (count($pinnedWidgets) > 0)
                    <div>
                        <h3 class="viz-section-title mb-3">Pinned from Chat</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            @foreach ($pinnedWidgets as $entry)
                                @php [$widget, $result] = [$entry['widget'], $entry['result']]; @endphp
                                <div class="viz-card">
                                    <div class="flex justify-between items-start mb-3">
                                        <h4 class="viz-label" style="color: var(--text-primary); font-weight: 500;">{{ $widget->title }}</h4>
                                        <form method="POST" action="{{ route('widgets.destroy', $widget) }}" onsubmit="return confirm('Remove this from your dashboard?');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="viz-toggle" style="text-decoration:none;" onmouseover="this.style.color='var(--status-critical)'" onmouseout="this.style.color='var(--text-secondary)'">Remove</button>
                                        </form>
                                    </div>

                                    @if ($widget->chart_type === 'kpi')
                                        <div class="viz-value">{{ number_format($result['value'], 2) }}</div>
                                    @elseif ($widget->chart_type === 'bar')
                                        @if (count($result['rows']) > 0)
                                            <div id="pinned-chart-{{ $widget->id }}" style="height: 200px;"></div>
                                        @else
                                            <p class="text-sm" style="color: var(--text-muted)">No data.</p>
                                        @endif
                                    @else
                                        <table class="viz-table">
                                            <tbody>
                                                @forelse ($result['rows'] as $row)
                                                    <tr>
                                                        <td>{{ $row['sku'] }}</td>
                                                        <td>{{ $row['name'] }}</td>
                                                        <td class="num">{{ $row['stock'] }}</td>
                                                    </tr>
                                                @empty
                                                    <tr><td style="color: var(--text-muted)">No rows.</td></tr>
                                                @endforelse
                                            </tbody>
                                        </table>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                <script src="https://cdn.jsdelivr.net/npm/echarts@5/dist/echarts.min.js"></script>
                <script>
                    const currencySymbol = @json($currencySymbol ?? 'SAR');
                    const categoryData = @json($hasInventory ? $categoryBreakdown['rows'] : []);
                    const warehouseData = @json($hasInventory ? $warehouseBreakdown['rows'] : []);
                    const itemCountData = @json($hasInventory ? $itemCountByCategory['rows'] : []);
                    const stockoutsData = @json($hasInventory ? $stockoutsByCategory['rows'] : []);
                    const mostOrderedData = @json($hasSales ? $mostOrdered['rows'] : []);
                    const mostCancelledData = @json($hasSales ? $mostCancelled['rows'] : []);
                    const ordersByMonthData = @json($hasSales ? $ordersByMonth['rows'] : []);
                    const mostPurchasedData = @json($hasPurchases ? $mostPurchased['rows'] : []);
                    const purchasesByMonthData = @json($hasPurchases ? $purchasesByMonth['rows'] : []);
                    const mostReturnedData = @json($hasReturns ? $mostReturned['rows'] : []);
                    const returnsByReasonData = @json($hasReturns ? $returnsByReason['rows'] : []);
                    const returnsByMonthData = @json($hasReturns ? $returnsByMonth['rows'] : []);
                    const pinnedBarData = @json(collect($pinnedWidgets)->filter(fn ($e) => $e['widget']->chart_type === 'bar')->map(fn ($e) => ['id' => $e['widget']->id, 'rows' => $e['result']['rows']])->values());

                    const vizColors = {
                        series1: '#2563eb',
                        purchases: '#14b8a6',
                        returns: '#f59e0b',
                        critical: '#ef4444',
                        gridline: 'rgba(11, 11, 15, 0.06)',
                        baseline: 'rgba(11, 11, 15, 0.12)',
                        textSecondary: '#5b5b66',
                        textMuted: '#9494a0',
                        surface: '#ffffff',
                        border: 'rgba(11, 11, 15, 0.08)',
                    };

                    // Two-tone stops (light top -> rich bottom) per base color —
                    // deliberately saturated, not alpha-fades-to-white, so the
                    // gradient actually reads against a light card background.
                    const gradientStops = {
                        [vizColors.series1]: ['#6ba6ee', '#1a4fc4'],
                        [vizColors.purchases]: ['#5eead4', '#0d9488'],
                        [vizColors.returns]: ['#fcd34d', '#d97706'],
                        [vizColors.critical]: ['#f87171', '#b91c1c'],
                    };
                    const gradientFor = (color) => gradientStops[color] || [color, color];

                    // Registry so toggleView() can resize a chart after its
                    // container is un-hidden (ECharts sizes against the DOM
                    // at init time; a chart initialized while `hidden` needs
                    // an explicit resize once it becomes visible).
                    const vizCharts = {};

                    function renderBarChart(elId, data, opts = {}) {
                        const el = document.getElementById(elId);
                        if (!el) return;
                        const format = opts.format || 'currency';
                        const color = opts.color || vizColors.series1;
                        const fmt = (v) => format === 'currency'
                            ? currencySymbol + ' ' + v.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
                            : v.toLocaleString();

                        const chart = echarts.init(el);
                        vizCharts[elId] = chart;

                        chart.setOption({
                            animationDuration: 700,
                            animationEasing: 'cubicOut',
                            grid: { left: 8, right: 8, top: 10, bottom: 8, containLabel: true },
                            xAxis: {
                                type: 'category',
                                data: data.map(r => r.label),
                                axisLine: { lineStyle: { color: vizColors.baseline } },
                                axisTick: { show: false },
                                axisLabel: { color: vizColors.textMuted, fontSize: 11 },
                            },
                            yAxis: {
                                type: 'value',
                                axisLine: { show: false },
                                splitLine: { lineStyle: { color: vizColors.gridline } },
                                axisLabel: { color: vizColors.textMuted, fontSize: 11, formatter: fmt },
                            },
                            series: [{
                                type: 'bar',
                                data: data.map(r => r.value),
                                itemStyle: {
                                    borderRadius: [10, 10, 0, 0],
                                    color: new echarts.graphic.LinearGradient(0, 0, 0, 1, [
                                        { offset: 0, color: gradientFor(color)[0] },
                                        { offset: 1, color: gradientFor(color)[1] },
                                    ]),
                                },
                                emphasis: {
                                    itemStyle: {
                                        shadowBlur: 14,
                                        shadowColor: color + '80',
                                    },
                                },
                                barMaxWidth: 24,
                            }],
                            tooltip: {
                                trigger: 'axis',
                                axisPointer: { type: 'shadow' },
                                backgroundColor: vizColors.surface,
                                borderColor: vizColors.border,
                                borderWidth: 1,
                                padding: 10,
                                textStyle: { color: '#0b0b0b' },
                                formatter: (params) => {
                                    const p = params[0];
                                    return `<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:${color};margin-right:6px;"></span>`
                                        + `<span style="color:${vizColors.textSecondary}">${p.name}</span><br/><b>${fmt(p.value)}</b>`;
                                },
                            },
                        });
                    }

                    function renderLineChart(elId, data, opts = {}) {
                        const el = document.getElementById(elId);
                        if (!el) return;
                        const color = opts.color || vizColors.series1;

                        const chart = echarts.init(el);
                        vizCharts[elId] = chart;

                        chart.setOption({
                            animationDuration: 800,
                            animationEasing: 'cubicOut',
                            grid: { left: 8, right: 8, top: 10, bottom: 8, containLabel: true },
                            xAxis: {
                                type: 'category',
                                data: data.map(r => r.label),
                                axisLine: { lineStyle: { color: vizColors.baseline } },
                                axisTick: { show: false },
                                axisLabel: { color: vizColors.textMuted, fontSize: 11 },
                            },
                            yAxis: {
                                type: 'value',
                                minInterval: 1,
                                axisLine: { show: false },
                                splitLine: { lineStyle: { color: vizColors.gridline } },
                                axisLabel: { color: vizColors.textMuted, fontSize: 11 },
                            },
                            series: [{
                                type: 'line',
                                data: data.map(r => r.value),
                                lineStyle: { color, width: 2.5 },
                                itemStyle: { color, borderColor: vizColors.surface, borderWidth: 2 },
                                symbolSize: 8,
                                emphasis: { scale: 1.3 },
                                areaStyle: {
                                    color: new echarts.graphic.LinearGradient(0, 0, 0, 1, [
                                        { offset: 0, color: color + '40' },
                                        { offset: 1, color: color + '00' },
                                    ]),
                                },
                                smooth: 0.2,
                            }],
                            tooltip: {
                                trigger: 'axis',
                                axisPointer: {
                                    type: 'line',
                                    lineStyle: { color: vizColors.baseline, type: 'dashed' },
                                },
                                backgroundColor: vizColors.surface,
                                borderColor: vizColors.border,
                                borderWidth: 1,
                                padding: 10,
                                textStyle: { color: '#0b0b0b' },
                                formatter: (params) => {
                                    const p = params[0];
                                    return `<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:${color};margin-right:6px;"></span>`
                                        + `<span style="color:${vizColors.textSecondary}">${p.name}</span><br/><b>${p.value.toLocaleString()} orders</b>`;
                                },
                            },
                        });
                    }

                    renderBarChart('categoryChart', categoryData, { format: 'currency' });
                    renderBarChart('warehouseChart', warehouseData, { format: 'currency' });
                    renderBarChart('itemCountChart', itemCountData, { format: 'number' });
                    renderBarChart('stockoutsChart', stockoutsData, { format: 'number', color: vizColors.critical });
                    renderBarChart('mostOrderedChart', mostOrderedData, { format: 'number' });
                    renderBarChart('mostCancelledChart', mostCancelledData, { format: 'number', color: vizColors.critical });
                    renderLineChart('ordersByMonthChart', ordersByMonthData);
                    renderBarChart('mostPurchasedChart', mostPurchasedData, { format: 'number', color: vizColors.purchases });
                    renderLineChart('purchasesByMonthChart', purchasesByMonthData, { color: vizColors.purchases });
                    renderBarChart('mostReturnedChart', mostReturnedData, { format: 'number', color: vizColors.returns });
                    renderBarChart('returnsByReasonChart', returnsByReasonData, { format: 'number', color: vizColors.returns });
                    renderLineChart('returnsByMonthChart', returnsByMonthData, { color: vizColors.returns });
                    pinnedBarData.forEach(w => renderBarChart('pinned-chart-' + w.id, w.rows));

                    function toggleView(key) {
                        const chartView = document.getElementById(key + '-chart-view');
                        const tableView = document.getElementById(key + '-table-view');
                        const showingChart = !chartView.classList.contains('hidden');
                        chartView.classList.toggle('hidden', showingChart);
                        tableView.classList.toggle('hidden', !showingChart);
                        event.target.textContent = showingChart ? 'View as chart' : 'View as table';

                        if (!showingChart) {
                            // chart view was hidden and just became visible again
                            const el = chartView.querySelector('[id]');
                            if (el && vizCharts[el.id]) {
                                vizCharts[el.id].resize();
                            }
                        }
                    }

                    window.addEventListener('resize', () => {
                        Object.values(vizCharts).forEach(chart => chart.resize());
                    });
                </script>
            @endif
        </div>
    </div>
</x-app-layout>
