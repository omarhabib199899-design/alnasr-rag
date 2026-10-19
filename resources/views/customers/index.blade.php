<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-base text-gray-900 leading-tight">
            {{ __('Customers') }}
        </h2>
    </x-slot>

    @php
        // Layout mirrors the reference portal's Customers page (PageShell + one panel:
        // stats strip, filter bar, active-filter chips, compact grid table, footer).
        // Every column and filter this page already had is kept; the reference's
        // additions (search-all, multi-select filters with counts, Management column,
        // filtered stats, chips) are added on top.
        $facetLabels = [
            'status' => __('Status'),
            'sales_rep' => __('Rep Name'),
            'account_type' => __('Account Type'),
            'payment_term' => __('Payment Term'),
            'management' => __('Management'),
            'customer_number' => __('Customer Number'),
            'sales_owner' => __('Sales Owner'),
        ];

        $columns = [
            ['key' => 'customer_name', 'label' => __('Customer Name')],
            ['key' => 'status', 'label' => __('Status')],
            ['key' => 'customer_number', 'label' => __('Customer Number')],
            ['key' => 'rep_name', 'label' => __('Rep Name')],
            ['key' => 'data_entry', 'label' => __('Data Entry')],
            ['key' => 'sales_owner', 'label' => __('Sales Owner')],
            ['key' => 'account_type', 'label' => __('Account Type')],
            ['key' => 'payment_terms_label', 'label' => __('Payment Term')],
            ['key' => 'management', 'label' => __('Management')],
            ['key' => 'live_credit_limit', 'label' => __('Credit Limit'), 'end' => true],
            ['key' => 'outstanding_balance', 'label' => __('Outstanding Balance'), 'end' => true],
        ];

        $activeSort = $sortKey ?? 'customer_name';
        $activeDirection = $sortKey ? $sortDirection : 'asc';
        $sortUrl = function (string $key) use ($activeSort, $activeDirection) {
            $direction = $activeSort === $key && $activeDirection === 'asc' ? 'desc' : 'asc';

            return request()->fullUrlWithQuery(['sort' => $key, 'direction' => $direction, 'page' => null]);
        };

        $withoutValue = function (string $param, ?string $value = null) {
            $query = request()->except(['page']);
            if ($value === null) {
                unset($query[$param]);
            } else {
                $current = (array) ($query[$param] ?? []);
                $query[$param] = array_values(array_filter($current, fn ($v) => (string) $v !== $value));
                if ($query[$param] === []) {
                    unset($query[$param]);
                }
            }

            return route('customers.index', $query);
        };

        $chips = [];
        foreach ($selections as $param => $values) {
            foreach ($values as $value) {
                $chips[] = ['label' => $facetLabels[$param], 'value' => $value, 'url' => $withoutValue($param, $value)];
            }
        }
        if ($customerName) {
            $chips[] = ['label' => __('Customer Name'), 'value' => $customerName, 'url' => $withoutValue('customer_name')];
        }
        if ($filter) {
            $chips[] = ['label' => __('Balance'), 'value' => str($filter)->replace('_', ' ')->title(), 'url' => $withoutValue('filter')];
        }
        if ($portal) {
            $chips[] = ['label' => __('Portal'), 'value' => ucfirst($portal), 'url' => $withoutValue('portal')];
        }
        $hasActiveFilters = $chips !== [] || $search !== '';
        $dash = '—';
    @endphp

    <div class="cl-page">
        <header>
            <div class="cl-eyebrow">{{ __('Sales Data') }}</div>
            <h1 class="cl-title">{{ __('Customers') }}</h1>
            <p class="cl-subtitle">
                {{ __('Customer master list') }}
                @if ($lastUpdated)
                    · {{ __('Last synced') }} {{ \Illuminate\Support\Carbon::parse($lastUpdated)->diffForHumans() }}
                @endif
            </p>
        </header>

        <section class="cl-panel">
            <div class="cl-stats">
                @foreach ([__('Total customers') => $stats['total'], __('Active') => $stats['active'], __('Inactive') => $stats['inactive'], __('Number of reps') => $stats['reps']] as $statLabel => $statValue)
                    <div class="cl-stat">
                        <div class="cl-stat-label">{{ $statLabel }}</div>
                        <div class="cl-stat-value" dir="ltr">{{ number_format($statValue) }}</div>
                    </div>
                @endforeach
            </div>

            <form method="GET" action="{{ route('customers.index') }}" class="cl-filters">
                <input type="text" name="search" value="{{ $search }}" placeholder="{{ __('Search customers…') }}" class="cl-input cl-search">
                @foreach ($facetLabels as $param => $facetLabel)
                    <div class="cl-facet">
                        <x-multi-select-filter :name="$param" :label="$facetLabel" :options="$facetOptions[$param]" :selected="$selections[$param]" />
                    </div>
                @endforeach
                @if ($sortKey)
                    <input type="hidden" name="sort" value="{{ $sortKey }}">
                    <input type="hidden" name="direction" value="{{ $sortDirection }}">
                @endif
                @foreach (['customer_name' => $customerName, 'filter' => $filter, 'portal' => $portal] as $hiddenName => $hiddenValue)
                    @if ($hiddenValue)
                        <input type="hidden" name="{{ $hiddenName }}" value="{{ $hiddenValue }}">
                    @endif
                @endforeach
                <button type="submit" class="hidden" aria-hidden="true"></button>
                @if ($hasActiveFilters)
                    <a href="{{ route('customers.index') }}" class="cl-btn">{{ __('Clear all') }}</a>
                @else
                    <span class="cl-btn cl-btn-disabled">{{ __('Clear all') }}</span>
                @endif
            </form>

            @if ($chips !== [])
                <div class="cl-chips">
                    @foreach ($chips as $chip)
                        <a href="{{ $chip['url'] }}" class="cl-chip">
                            <span class="cl-chip-label">{{ $chip['label'] }}:</span>
                            <span dir="auto">{{ $chip['value'] }}</span>
                            <span aria-hidden="true">×</span>
                        </a>
                    @endforeach
                </div>
            @endif

            <div class="cl-table-wrap">
                <table class="cl-table">
                    <thead>
                        <tr>
                            @foreach ($columns as $column)
                                <th @class(['cl-end' => $column['end'] ?? false])>
                                    <a href="{{ $sortUrl($column['key']) }}">
                                        {{ $column['label'] }}
                                        @if ($activeSort === $column['key'])
                                            {{ $activeDirection === 'asc' ? '▲' : '▼' }}
                                        @endif
                                    </a>
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($customers as $customer)
                            @php $showUrl = route('customers.show', ['customer' => $customer, 'tab' => 'overview']); @endphp
                            <tr onclick="if (! event.target.closest('a')) window.location='{{ $showUrl }}'">
                                <td dir="auto">
                                    <a href="{{ $showUrl }}" class="cl-link" dir="auto">{{ $customer->customer_name ?: $dash }}</a>
                                    @if ($customer->company_name && $customer->company_name !== $customer->customer_name)
                                        <span class="cl-muted" dir="auto"> · {{ $customer->company_name }}</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($customer->status)
                                        <span @class(['cl-status', 'cl-status-active' => $customer->status === 'Active'])>{{ $customer->status }}</span>
                                    @else
                                        {{ $dash }}
                                    @endif
                                </td>
                                <td dir="ltr">{{ $customer->customer_number ?: $dash }}</td>
                                <td dir="auto">{{ $customer->rep_name ?: $dash }}</td>
                                <td dir="auto">{{ $customer->data_entry ?: $dash }}</td>
                                <td dir="auto">{{ $customer->sales_owner ?: $dash }}</td>
                                <td dir="auto">{{ $customer->account_type ?: $dash }}</td>
                                <td dir="ltr">{{ $customer->payment_terms_label ?: $dash }}</td>
                                <td dir="auto">{{ $customer->management ?: $dash }}</td>
                                <td class="cl-end" dir="ltr">{{ $customer->live_credit_limit !== null ? number_format((float) $customer->live_credit_limit) : $dash }}</td>
                                <td class="cl-end" dir="ltr">{{ number_format((float) ($customer->outstanding_balance ?? 0)) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ count($columns) }}" class="cl-empty">{{ __('No customers match the current search and filters.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="cl-footer">
                <span class="cl-muted">
                    {{ __('Showing') }} {{ number_format($customers->firstItem() ?? 0) }}–{{ number_format($customers->lastItem() ?? 0) }} {{ __('of') }} {{ number_format($customers->total()) }}
                    @if ($customers->total() !== $totalUnfiltered)
                        ({{ __('filtered from') }} {{ number_format($totalUnfiltered) }})
                    @endif
                </span>
                <div class="cl-pager">
                    @if ($customers->onFirstPage())
                        <span class="cl-btn cl-btn-disabled">{{ __('Previous') }}</span>
                    @else
                        <a href="{{ $customers->previousPageUrl() }}" class="cl-btn">{{ __('Previous') }}</a>
                    @endif
                    <span class="cl-muted">{{ __('Page') }} {{ $customers->currentPage() }} {{ __('of') }} {{ max(1, $customers->lastPage()) }}</span>
                    @if ($customers->hasMorePages())
                        <a href="{{ $customers->nextPageUrl() }}" class="cl-btn">{{ __('Next') }}</a>
                    @else
                        <span class="cl-btn cl-btn-disabled">{{ __('Next') }}</span>
                    @endif
                </div>
            </div>
        </section>
    </div>

    <style>
        .cl-page{--fg:oklch(24% .028 258);--muted-fg:oklch(53% .026 257.4);--muted:oklch(95.5% .007 247.9);--border:oklch(91.2% .01 255.5);--bg:oklch(97.5% .003 247.9);--primary:oklch(47% .108 244.5);--success:oklch(55% .14 150);
            display:flex;min-height:calc(100vh - 56px);flex-direction:column;gap:12px;padding:20px;background:var(--bg);color:var(--fg)}
        .cl-eyebrow{margin-bottom:4px;font-size:11px;text-transform:uppercase;letter-spacing:.1em;color:var(--muted-fg)}
        .cl-title{font-size:18px;font-weight:600;letter-spacing:-.025em;line-height:1.5}
        .cl-subtitle{margin-top:2px;font-size:13px;color:var(--muted-fg)}
        .cl-panel{display:flex;flex:1;flex-direction:column;min-height:0;background:#fff;border:1px solid var(--border);border-radius:4px}
        .cl-stats{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));border-bottom:1px solid var(--border)}
        @media (min-width:768px){.cl-stats{grid-template-columns:repeat(4,minmax(0,1fr))}}
        .cl-stat{border-inline-end:1px solid var(--border);padding:8px 16px}
        .cl-stat:last-child{border-inline-end:0}
        .cl-stat-label{font-size:11px;text-transform:uppercase;letter-spacing:.025em;color:var(--muted-fg)}
        .cl-stat-value{font-size:16px;font-weight:600;font-variant-numeric:tabular-nums;text-align:start}
        .cl-filters{display:flex;flex-wrap:wrap;align-items:flex-end;gap:8px;border-bottom:1px solid var(--border);padding:12px}
        .cl-page .cl-input{height:28px;border-radius:2px;border:1px solid var(--border);background:var(--bg);padding:0 8px;font-size:12px;color:var(--fg)}
        .cl-page .cl-input:focus{border-color:var(--primary);box-shadow:none;outline:none}
        .cl-search{width:256px}
        .cl-facet{width:176px}
        .cl-btn{display:inline-flex;align-items:center;height:28px;border-radius:2px;border:1px solid var(--border);padding:0 8px;font-size:12px;color:var(--fg);background:#fff;text-decoration:none;white-space:nowrap}
        a.cl-btn:hover{background:var(--muted)}
        .cl-btn-disabled{opacity:.4;cursor:default}
        .cl-chips{display:flex;flex-wrap:wrap;gap:6px;border-bottom:1px solid var(--border);padding:8px 12px}
        .cl-chip{display:flex;align-items:center;gap:4px;border-radius:2px;border:1px solid var(--border);background:var(--muted);padding:2px 8px;font-size:11.5px;color:var(--fg);text-decoration:none}
        .cl-chip:hover{background:oklch(94% .017 244.5)}
        .cl-chip-label{color:var(--muted-fg)}
        .cl-table-wrap{flex:1;min-height:16rem;max-height:calc(100vh - 24rem);overflow:auto}
        .cl-table{width:max-content;min-width:100%;border-collapse:collapse;font-size:12px}
        .cl-table thead{position:sticky;top:0;z-index:10;background:var(--muted)}
        .cl-table th{white-space:nowrap;border-bottom:1px solid var(--border);border-inline-end:1px solid var(--border);padding:6px 8px;font-weight:600;text-align:start;color:var(--fg);user-select:none}
        .cl-table th a{color:inherit;text-decoration:none;display:block}
        .cl-table .cl-end{text-align:end}
        .cl-table tbody tr{cursor:pointer}
        .cl-table tbody tr:nth-child(odd){background:#fff}
        .cl-table tbody tr:nth-child(even){background:var(--bg)}
        .cl-table tbody tr:hover{background:var(--muted)}
        .cl-table td{white-space:nowrap;border-bottom:1px solid var(--border);border-inline-end:1px solid var(--border);padding:4px 8px;color:var(--fg)}
        .cl-table td.cl-end{font-variant-numeric:tabular-nums}
        .cl-link{color:var(--primary);text-decoration:none}
        .cl-link:hover{text-decoration:underline}
        .cl-muted{color:var(--muted-fg)}
        .cl-status{border-radius:2px;padding:2px 6px;font-size:11px;font-weight:500;background:var(--muted);color:var(--muted-fg)}
        .cl-status-active{background:oklch(55% .14 150 / .15);color:var(--success)}
        .cl-empty{padding:32px;text-align:center !important;color:var(--muted-fg)}
        .cl-footer{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:8px;border-top:1px solid var(--border);padding:8px 12px;font-size:12px}
        .cl-pager{display:flex;align-items:center;gap:8px}
    </style>
</x-app-layout>
