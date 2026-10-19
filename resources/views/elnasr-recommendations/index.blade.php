<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Customers') }}
        </h2>
    </x-slot>

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.2.0/dist/chartjs-plugin-datalabels.min.js"></script>

    <style>
        .er-wrap { max-width: 1280px; margin: 0 auto; padding: 0 1rem 3rem; }
        .er-back { font-size: 0.875rem; color: #6b7280; text-decoration: none; }
        .er-back:hover { color: #374151; }

        .er-header { background: #fff; border: 1px solid #f0f0f2; border-radius: 0.75rem; box-shadow: 0 1px 2px rgba(0,0,0,.04); padding: 1rem 1.5rem; margin: 1.5rem 0; }
        .er-header-row { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 1rem; }
        .er-brand { display: flex; align-items: center; gap: 0.75rem; }
        .er-logo { width: 2.75rem; height: 2.75rem; border-radius: 0.5rem; background: #4f46e5; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .er-logo svg { width: 1.5rem; height: 1.5rem; color: #fff; }
        .er-brand-title { font-size: 1.125rem; font-weight: 700; color: #111827; line-height: 1.2; }
        .er-brand-sub { font-size: 0.75rem; color: #9ca3af; }

        .er-filters { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 0.75rem; }
        .er-field label { display: block; font-size: 10px; font-weight: 700; color: #9ca3af; text-transform: uppercase; letter-spacing: .04em; margin-bottom: 0.25rem; }
        .er-field select, .er-field input[type=text] { border: 1px solid #d1d5db; border-radius: 0.375rem; font-size: 0.875rem; padding: 0.5rem 0.6rem; min-width: 140px; }
        .er-field select:focus, .er-field input:focus { outline: none; border-color: #6366f1; box-shadow: 0 0 0 1px #6366f1; }

        .er-combobox { position: relative; }
        .er-combobox input[type=text] { border: 1px solid #d1d5db; border-radius: 0.375rem; font-size: 0.875rem; padding: 0.5rem 0.6rem; min-width: 180px; width: 100%; }
        .er-combobox input[type=text]:focus { outline: none; border-color: #6366f1; box-shadow: 0 0 0 1px #6366f1; }
        .er-combobox-list { position: absolute; z-index: 30; top: calc(100% + 2px); right: 0; left: 0; background: #fff; border: 1px solid #d1d5db; border-radius: 0.375rem; box-shadow: 0 6px 16px rgba(0,0,0,.12); max-height: 260px; overflow-y: auto; display: none; }
        .er-combobox-list.open { display: block; }
        .er-combobox-option { padding: 0.5rem 0.7rem; font-size: 0.8125rem; cursor: pointer; color: #374151; }
        .er-combobox-option:hover, .er-combobox-option.er-active { background: #eef2ff; color: #4338ca; }
        .er-combobox-empty { padding: 0.5rem 0.7rem; font-size: 0.8rem; color: #9ca3af; }

        .er-icon-btn { display: inline-flex; align-items: center; justify-content: center; width: 2.25rem; height: 2.25rem; border-radius: 0.375rem; border: 1px solid #d1d5db; color: #6b7280; text-decoration: none; }
        .er-icon-btn:hover { background: #f9fafb; }
        .er-icon-btn svg { width: 1rem; height: 1rem; }
        .er-apply-btn { display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.5rem 1rem; border-radius: 0.375rem; background: #111827; color: #fff; font-size: 0.875rem; font-weight: 600; border: none; cursor: pointer; }
        .er-apply-btn:hover { background: #1f2937; }
        .er-apply-btn svg { width: 1rem; height: 1rem; }

        .er-pills { display: flex; flex-wrap: wrap; gap: 0.5rem; padding-top: 1rem; margin-top: 1rem; border-top: 1px solid #f0f0f2; }
        .er-pill-link { padding: 0.375rem 0.9rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 600; border: 1px solid #e5e7eb; background: #fff; color: #4b5563; text-decoration: none; }
        .er-pill-link:hover { background: #f9fafb; }
        .er-pill-link.active { background: #111827; color: #fff; border-color: #111827; }

        .er-quickstats { display: flex; flex-wrap: wrap; gap: 1rem; padding-top: 1rem; margin-top: 1rem; border-top: 1px solid #f0f0f2; }
        .er-quickstats > div { text-align: left; direction: ltr; }
        .er-quickstats > div { flex: 1 1 120px; }
        .er-quickstats-label { font-size: 10px; color: #9ca3af; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; }
        .er-quickstats-value { color: #1f2937; font-weight: 600; margin-top: 0.15rem; }
        .er-quickstats-value--due { color: #dc2626; }

        .er-tabs { display: flex; gap: 0.25rem; border-bottom: 2px solid #f0f0f2; margin-bottom: 1.5rem; }
        .er-tab-btn { padding: 0.75rem 1.25rem; font-size: 0.9rem; font-weight: 700; color: #9ca3af; background: none; border: none; border-bottom: 2px solid transparent; margin-bottom: -2px; cursor: pointer; }
        .er-tab-btn:hover { color: #4b5563; }
        .er-tab-btn.active { color: #4338ca; border-bottom-color: #4338ca; }
        .er-tab-panel { display: none; }
        .er-tab-panel.active { display: block; }
        .er-charts-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 1.5rem; direction: ltr; }
        .er-chart-card { background: #fff; border: 1px solid #f0f0f2; border-radius: 0.75rem; box-shadow: 0 1px 2px rgba(0,0,0,.04); padding: 1.25rem; }
        .er-chart-title { font-size: 0.9rem; font-weight: 700; color: #111827; margin-bottom: 0.75rem; }
        .er-chart-box { position: relative; height: 260px; }
        @media (max-width: 900px) {
            .er-charts-grid { grid-template-columns: 1fr; }
        }

        .er-intro { padding: 0 0.25rem; margin-bottom: 1.5rem; }
        .er-intro-eyebrow { font-size: 0.75rem; font-weight: 700; color: #9ca3af; text-transform: uppercase; letter-spacing: .04em; }
        .er-intro-title { font-size: 1.5rem; font-weight: 700; color: #111827; margin: 0.25rem 0 0; }
        .er-intro-sub { font-size: 0.875rem; color: #6b7280; margin: 0.25rem 0 0; }

        .er-blocks { display: flex; flex-direction: column; gap: 1.5rem; }
        .er-card { background: #fff; border: 1px solid #f0f0f2; border-radius: 0.75rem; box-shadow: 0 1px 2px rgba(0,0,0,.04); overflow: hidden; padding-bottom: 1.25rem; }

        .er-identity { display: flex; flex-wrap: wrap; align-items: flex-start; justify-content: space-between; gap: 0.75rem; padding: 1.25rem 1.5rem 0; }
        .er-badges { display: flex; flex-wrap: wrap; align-items: center; gap: 0.5rem; }
        .er-badge { padding: 0.3rem 0.7rem; border-radius: 9999px; font-size: 11px; font-weight: 700; border: 1px solid #e5e7eb; background: #f9fafb; color: #4b5563; }
        .er-badge--urgent { background: #dc2626; color: #fff; border-color: #dc2626; }
        .er-badge--docs { background: #fff1f2; color: #e11d48; border-color: #fecdd3; }
        .er-customer-name { font-size: 1.125rem; font-weight: 700; color: #111827; text-align: right; }
        .er-customer-rep { font-size: 0.75rem; color: #9ca3af; margin-top: 0.15rem; text-align: right; }

        .er-main-grid { display: flex; flex-wrap: wrap; gap: 1rem; padding: 1rem 1.5rem 0; }
        .er-segments { flex: 2 1 480px; display: flex; flex-wrap: wrap; gap: 1rem; }
        .er-segment-card { flex: 1 1 180px; border: 1px solid #f0f0f2; border-radius: 0.5rem; padding: 1rem; background: #fafafa; }
        .er-segment-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 0.5rem; }
        .er-segment-eyebrow { font-size: 11px; font-weight: 700; color: #6b7280; }
        .er-segment-pill { padding: 0.15rem 0.5rem; border-radius: 9999px; font-size: 10px; font-weight: 700; border: 1px solid; white-space: nowrap; }
        .er-segment-mid { display: flex; align-items: flex-end; justify-content: space-between; gap: 0.5rem; margin-top: 0.75rem; }
        .er-segment-label { font-size: 0.875rem; font-weight: 600; color: #374151; }
        .er-segment-value { font-size: 1.25rem; font-weight: 700; }
        .er-bar-track { height: 6px; border-radius: 9999px; background: #e5e7eb; margin-top: 0.5rem; overflow: hidden; }
        .er-bar-fill { height: 100%; border-radius: 9999px; }
        .er-segment-why { font-size: 11px; color: #9ca3af; margin-top: 0.5rem; }

        .er-color-red .er-segment-pill, .er-color-red { color: #b91c1c; border-color: #fecaca; background: #fef2f2; }
        .er-color-green .er-segment-pill, .er-color-green { color: #047857; border-color: #a7f3d0; background: #ecfdf5; }
        .er-color-amber .er-segment-pill, .er-color-amber { color: #b45309; border-color: #fde68a; background: #fffbeb; }
        .er-color-gray .er-segment-pill, .er-color-gray { color: #4b5563; border-color: #e5e7eb; background: #f3f4f6; }
        .er-bar-red { background: #ef4444; } .er-text-red { color: #dc2626; }
        .er-bar-green { background: #10b981; } .er-text-green { color: #059669; }
        .er-bar-amber { background: #f59e0b; } .er-text-amber { color: #d97706; }
        .er-bar-gray { background: #9ca3af; } .er-text-gray { color: #4b5563; }

        .er-critical { flex: 1 1 260px; background: #0f172a; border-radius: 0.5rem; padding: 1.25rem; color: #fff; display: flex; flex-direction: column; }
        .er-critical-top { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; }
        .er-critical-eyebrow { display: flex; align-items: center; gap: 0.4rem; font-size: 10px; font-weight: 700; color: #d1d5db; text-transform: uppercase; letter-spacing: .04em; }
        .er-critical-dot { width: 6px; height: 6px; border-radius: 9999px; background: #ef4444; display: inline-block; }
        .er-critical-badge { padding: 0.2rem 0.5rem; border-radius: 9999px; font-size: 10px; font-weight: 700; background: rgba(239,68,68,.2); color: #fca5a5; border: 1px solid rgba(239,68,68,.3); }
        .er-critical-title { font-size: 1.1rem; font-weight: 700; color: #fff; margin-top: 0.75rem; line-height: 1.35; }
        .er-critical-box { background: rgba(255,255,255,.05); border-radius: 0.375rem; padding: 0.75rem; margin-top: 1rem; }
        .er-critical-label { font-size: 11px; font-weight: 700; color: #9ca3af; }
        .er-critical-text { font-size: 0.75rem; color: #d1d5db; margin-top: 0.4rem; line-height: 1.55; }
        .er-critical-action { border-inline-start: 2px solid #818cf8; padding-inline-start: 0.75rem; margin-top: 1rem; }

        .er-signals { padding: 1.25rem 1.5rem 0; }
        .er-signals-top { display: flex; align-items: center; justify-content: space-between; }
        .er-signals-eyebrow { font-size: 11px; font-weight: 700; color: #9ca3af; }
        .er-signals-title { font-size: 1rem; font-weight: 700; color: #111827; margin-top: 0.15rem; }
        .er-signals-dot { width: 8px; height: 8px; border-radius: 9999px; background: #10b981; display: inline-block; }
        .er-table-wrap { overflow-x: auto; margin-top: 0.75rem; border: 1px solid #f0f0f2; border-radius: 0.5rem; }
        .er-table { width: 100%; border-collapse: collapse; font-size: 0.875rem; }
        .er-table th { text-align: right; font-size: 10px; font-weight: 700; color: #9ca3af; background: #f9fafb; padding: 0.5rem 1rem; }
        .er-table td { padding: 0.75rem 1rem; border-top: 1px solid #f0f0f2; }
        .er-table td.dim { font-weight: 600; color: #1f2937; white-space: nowrap; }
        .er-table td.status { color: #4b5563; }
        .er-table td.evidence { color: #6b7280; }

        .er-extra { padding: 1.25rem 1.5rem 0; }
        .er-extra > summary { list-style: none; cursor: pointer; display: flex; align-items: center; justify-content: space-between; padding: 0.6rem 0.9rem; border-radius: 0.5rem; font-size: 0.8125rem; font-weight: 700; }
        .er-extra > summary::-webkit-details-marker { display: none; }
        .er-extra > summary::after { content: '▾'; font-size: 0.7rem; }
        .er-extra[open] > summary::after { content: '▴'; }
        .er-extra-docs > summary { background: #fff1f2; color: #e11d48; }
        .er-extra-stats > summary { background: #eef2ff; color: #4338ca; margin-top: 0.75rem; }
        .er-extra-invoices > summary { background: #fffbeb; color: #b45309; margin-top: 0.75rem; }
        .er-extra-aging > summary { background: #fef2f2; color: #b91c1c; margin-top: 0.75rem; }
        .er-extra-due > summary { background: #fdf4ff; color: #a21caf; margin-top: 0.75rem; }
        .er-extra-collections > summary { background: #ecfdf5; color: #047857; margin-top: 0.75rem; }
        .er-aging-pills { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-top: 0.6rem; }
        .er-aging-pill { padding: 0.4rem 0.8rem; border-radius: 0.5rem; background: #fafafa; border: 1px solid #f0f0f2; font-size: 0.75rem; }
        .er-aging-pill b { display: block; font-size: 0.875rem; color: #1f2937; margin-top: 0.15rem; }
        .er-docs-table { width: 100%; border-collapse: collapse; font-size: 0.8125rem; margin-top: 0.6rem; }
        .er-docs-table th { text-align: right; font-size: 10px; font-weight: 700; color: #9ca3af; background: #f9fafb; padding: 0.5rem 0.75rem; }
        .er-docs-table td { padding: 0.6rem 0.75rem; border-top: 1px solid #f0f0f2; vertical-align: top; }
        .er-docs-table td.action { font-weight: 600; color: #1f2937; }
        .er-docs-table td.why { color: #6b7280; }
        .er-scroll-table { max-height: 400px; overflow-y: auto; border: 1px solid #f0f0f2; border-radius: 0.5rem; margin-top: 0.6rem; }
        .er-scroll-table table { margin-top: 0; border: none; border-radius: 0; }
        .er-scroll-table thead th { position: sticky; top: 0; z-index: 1; }
        .er-profit-neg { color: #dc2626; }

        .er-footer { display: flex; flex-wrap: wrap; gap: 1rem; padding: 1rem 1.5rem; margin-top: 1.25rem; background: #f9fafb; border-top: 1px solid #f0f0f2; font-size: 0.875rem; }
        .er-footer > div { flex: 1 1 120px; }
        .er-footer-label { font-size: 10px; color: #9ca3af; font-weight: 600; }
        .er-footer-value { color: #1f2937; margin-top: 0.1rem; }
        .er-footer-value--due { color: #dc2626; font-weight: 600; }

        .er-empty { background: #fff; border: 1px solid #f0f0f2; border-radius: 0.75rem; padding: 2.5rem; text-align: center; font-size: 0.875rem; color: #6b7280; }

        .er-pagination { margin-top: 1.5rem; }

        @media (max-width: 640px) {
            .er-header-row { flex-direction: column; align-items: stretch; }
            .er-identity { flex-direction: column; }
            .er-customer-name, .er-customer-rep { text-align: right; }
        }
    </style>

    @php
        $splitSegment = function (?string $value): array {
            if (! $value) return [null, null];
            if (str_contains($value, ' - ')) {
                [$label, $rest] = explode(' - ', $value, 2);
                return [trim($label), trim($rest)];
            }
            return [trim($value), null];
        };

        $statusColor = function (?string $label): string {
            $label = strtolower((string) $label);
            $decline = ['decline', 'shrink', 'drop', 'churn', 'severe', 'dormant', 'negative'];
            $growth = ['growth', 'increas', 'improv', 'expand', 'healthy', 'positive'];
            $stagnant = ['stagnant', 'stable', 'flat', 'steady'];

            foreach ($decline as $needle) { if (str_contains($label, $needle)) return 'red'; }
            foreach ($growth as $needle) { if (str_contains($label, $needle)) return 'green'; }
            foreach ($stagnant as $needle) { if (str_contains($label, $needle)) return 'amber'; }
            return 'gray';
        };

        $progressWidth = function (?string $value): int {
            if (! $value) return 35;
            preg_match('/-?\d+(\.\d+)?/', $value, $m);
            if (! isset($m[0])) return 35;
            $n = abs((float) $m[0]);
            if ($n <= 1) $n *= 100;

            return (int) max(10, min(100, $n));
        };

        $priorityBadge = function (?string $level): ?array {
            if (! $level) return null;
            $urgency = match (true) {
                str_starts_with($level, 'P0') => 'حرج',
                str_starts_with($level, 'P1') => 'عاجل',
                str_starts_with($level, 'P2') => 'مرتفع',
                str_starts_with($level, 'P3'), str_starts_with($level, 'P4'), str_starts_with($level, 'P5') => 'مراقبة',
                default => 'سليم',
            };
            return ['label' => "{$level} · {$urgency}", 'urgent' => str_starts_with($level, 'P0') || str_starts_with($level, 'P1')];
        };

        $formatDuration = function (?string $date): string {
            if (! $date) return '—';
            $months = (int) \Illuminate\Support\Carbon::parse($date)->diffInMonths(now());
            if ($months < 1) return 'أقل من شهر';
            if ($months < 12) {
                return match (true) {
                    $months === 1 => 'شهر واحد',
                    $months === 2 => 'شهرين',
                    $months <= 10 => "{$months} أشهر",
                    default => "{$months} شهرًا",
                };
            }
            $years = intdiv($months, 12);

            return match (true) {
                $years === 1 => 'سنة واحدة',
                $years === 2 => 'سنتين',
                $years <= 10 => "{$years} سنوات",
                default => "{$years} سنة",
            };
        };
    @endphp

    <div class="er-wrap" dir="rtl">

        <div style="padding-top: 1.5rem;">
            <a href="{{ route('customers.index') }}" class="er-back">&rarr; رجوع للعملاء</a>
        </div>

        {{-- Header bar: logo + filters --}}
        <div class="er-header">
            <form method="GET" class="er-header-row">
                <div class="er-brand">
                    <div class="er-logo">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18Z" />
                            <path d="M6 12H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2" />
                            <path d="M18 9h2a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-2" />
                            <path d="M10 6h4" />
                            <path d="M10 10h4" />
                            <path d="M10 14h4" />
                            <path d="M10 18h4" />
                        </svg>
                    </div>
                    <div>
                        <div class="er-brand-title">محرك التوصيات</div>
                        <div class="er-brand-sub">{{ number_format($totalCount) }} عميل مصنّف</div>
                    </div>
                </div>

                <div class="er-filters">
                    <div class="er-field">
                        <label>Management</label>
                        <select name="management">
                            <option value="">الكل</option>
                            @foreach ($managementOptions as $option)
                                <option value="{{ $option }}" @selected($management === $option)>{{ $option }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="er-field">
                        <label>Rep Name</label>
                        <select name="rep_name" id="er-rep-select">
                            <option value="">الكل</option>
                            @foreach ($repOptions as $option)
                                <option value="{{ $option }}" @selected($repName === $option)>{{ $option }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="er-field">
                        <label>Customer Name</label>
                        <div class="er-combobox" id="er-customer-combobox">
                            <input type="text" id="er-customer-search" autocomplete="off" placeholder="ابحث عن عميل..." value="{{ $customerName }}">
                            <input type="hidden" name="customer_name" id="er-customer-hidden" value="{{ $customerName }}">
                            <div class="er-combobox-list" id="er-customer-list"></div>
                        </div>
                    </div>
                    <div class="er-field">
                        <label>Sales Owner</label>
                        <select name="sales_owner">
                            <option value="">غير محدد</option>
                            @foreach ($salesOwnerOptions as $option)
                                <option value="{{ $option }}" @selected($salesOwner === $option)>{{ $option }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="er-field">
                        <label>Credit Status</label>
                        <select name="credit_status">
                            <option value="">الكل</option>
                            @foreach ($creditStatusOptions as $option)
                                <option value="{{ $option }}" @selected($creditStatus === $option)>{{ $option }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="er-field">
                        <label>Sort By</label>
                        <select name="sort">
                            <option value="revenue_rank" @selected($sort === 'revenue_rank')>الأعلى إيرادًا</option>
                            <option value="sales_rank" @selected($sort === 'sales_rank')>الأعلى مبيعات</option>
                            <option value="days_since_last_purchase" @selected($sort === 'days_since_last_purchase')>الأكثر إهمالًا</option>
                            <option value="tenure" @selected($sort === 'tenure')>الأقدم تعاملًا معنا</option>
                            <option value="priority" @selected($sort === 'priority')>الأولوية (P0 الأول)</option>
                        </select>
                    </div>
                    @if ($priority)
                        <input type="hidden" name="priority" value="{{ $priority }}">
                    @endif

                    <a href="{{ route('elnasr-recommendations.index') }}" title="إعادة تعيين الفلاتر" class="er-icon-btn">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" />
                        </svg>
                    </a>
                    <button type="submit" class="er-apply-btn">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 3c2.755 0 5.455.232 8.083.678.533.09.917.556.917 1.096v1.044a2.25 2.25 0 0 1-.659 1.591l-5.432 5.432a2.25 2.25 0 0 0-.659 1.591v2.927a2.25 2.25 0 0 1-1.244 2.013L9.75 21v-6.568a2.25 2.25 0 0 0-.659-1.591L3.659 7.409A2.25 2.25 0 0 1 3 5.818V4.774c0-.54.384-1.006.917-1.096A48.32 48.32 0 0 1 12 3Z" />
                        </svg>
                        تصفية
                    </button>
                </div>
            </form>

            {{-- Priority quick-filter pills --}}
            <div class="er-pills">
                <a href="{{ route('elnasr-recommendations.index', array_filter(['management' => $management, 'rep_name' => $repName])) }}"
                   class="er-pill-link @if(! $priority) active @endif">
                    الكل ({{ $priorityCounts->sum() }})
                </a>
                @foreach ($priorityCounts as $level => $count)
                    <a href="{{ route('elnasr-recommendations.index', array_filter(['management' => $management, 'rep_name' => $repName, 'priority' => $level])) }}"
                       class="er-pill-link @if($priority === $level) active @endif">
                        {{ $level }} ({{ $count }})
                    </a>
                @endforeach
            </div>

            @if ($customerName && $customers->isNotEmpty())
                @php $qc = $customers->first(); @endphp
                <div class="er-quickstats">
                    <div>
                        <div class="er-quickstats-label">First Purchase</div>
                        <div class="er-quickstats-value">{{ $qc->historical_first_purchase ? \Illuminate\Support\Carbon::parse($qc->historical_first_purchase)->format('Y-m-d') : '—' }}</div>
                    </div>
                    <div>
                        <div class="er-quickstats-label">Last Purchase</div>
                        <div class="er-quickstats-value">{{ $qc->last_purchase_date ? \Illuminate\Support\Carbon::parse($qc->last_purchase_date)->format('Y-m-d') : '—' }}</div>
                    </div>
                    <div>
                        <div class="er-quickstats-label">Tenure</div>
                        <div class="er-quickstats-value">{{ $formatDuration($qc->historical_first_purchase) }}</div>
                    </div>
                    <div>
                        <div class="er-quickstats-label">Credit Limit</div>
                        <div class="er-quickstats-value">{{ $qc->credit_limit !== null ? number_format($qc->credit_limit) : '—' }}</div>
                    </div>
                    <div>
                        <div class="er-quickstats-label">Outstanding Balance</div>
                        <div class="er-quickstats-value @if(($qc->used_credit ?? 0) > 0) er-quickstats-value--due @endif">{{ $qc->used_credit !== null ? number_format($qc->used_credit) : '—' }}</div>
                    </div>
                    <div>
                        <div class="er-quickstats-label">Revenue Rank</div>
                        <div class="er-quickstats-value">{{ $qc->revenue_rank ? '#'.$qc->revenue_rank : '—' }}</div>
                    </div>
                    <div>
                        <div class="er-quickstats-label">Sales Rank</div>
                        <div class="er-quickstats-value">{{ $qc->sales_rank ? '#'.$qc->sales_rank : '—' }}</div>
                    </div>
                </div>
            @endif
        </div>

        {{-- Tabs --}}
        <div class="er-tabs">
            <button type="button" class="er-tab-btn active" data-tab="recommendations">Recommendations</button>
            <button type="button" class="er-tab-btn" data-tab="tables">Tables</button>
            <button type="button" class="er-tab-btn" data-tab="charts">Charts</button>
        </div>

        {{-- Tables tab: rep/customer-level monthly summary (shown once a rep or customer is filtered) --}}
        <div id="er-tab-tables" class="er-tab-panel">
        @if ($repSummary && $repSummary['has_data'])
            <div class="er-card" style="margin-bottom: 1.5rem;">
                <div class="er-identity" style="padding-bottom: 1rem;">
                    <div class="er-badges">
                        <span class="er-badge">{{ $repSummary['customer_count'] }} عميل</span>
                    </div>
                    <div>
                        <div class="er-customer-name">{{ $repSummary['rep_name'] }}</div>
                        <div class="er-customer-rep">{{ $repSummary['management'] ?? 'كل الإدارات' }}</div>
                    </div>
                </div>

                <div class="er-signals">
                    <div class="er-signals-top">
                        <div class="er-signals-title">1-MANAGMENT REP SALES-MTH</div>
                    </div>
                    <div class="er-table-wrap">
                        <table class="er-table">
                            <thead>
                                <tr>
                                    @foreach ($repSummary['months'] as $m)
                                        <th>{{ $m->label }}</th>
                                    @endforeach
                                    <th>Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    @foreach ($repSummary['months'] as $m)
                                        <td class="status">{{ number_format($m->sales, 0) }}</td>
                                    @endforeach
                                    <td class="dim">{{ number_format($repSummary['total_sales'], 0) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="er-signals">
                    <div class="er-signals-top">
                        <div class="er-signals-title">2-MANAGMENT REP COLLECTION-MTH</div>
                    </div>
                    <div class="er-table-wrap">
                        <table class="er-table">
                            <thead>
                                <tr>
                                    @foreach ($repSummary['months'] as $m)
                                        <th>{{ $m->label }}</th>
                                    @endforeach
                                    <th>Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    @foreach ($repSummary['months'] as $m)
                                        <td class="status">{{ number_format($m->collected, 0) }}</td>
                                    @endforeach
                                    <td class="dim">{{ number_format($repSummary['total_collected'], 0) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="er-signals" style="padding-bottom: 1.25rem;">
                    <div class="er-signals-top">
                        <div class="er-signals-title">Management Profit</div>
                    </div>
                    <div class="er-table-wrap">
                        <table class="er-table">
                            <thead>
                                <tr>
                                    @foreach ($repSummary['months'] as $m)
                                        <th>{{ $m->label }}</th>
                                    @endforeach
                                    <th>GP %</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    @foreach ($repSummary['months'] as $m)
                                        <td class="status">{{ $m->sales > 0 ? number_format(($m->profit / $m->sales) * 100, 2) . '%' : '—' }}</td>
                                    @endforeach
                                    <td class="dim">{{ $repSummary['total_sales'] > 0 ? number_format(($repSummary['total_profit'] / $repSummary['total_sales']) * 100, 2) . '%' : '—' }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                @if ($repSummary['aging']->isNotEmpty())
                    <div class="er-signals" style="padding-bottom: 1.25rem;">
                        <div class="er-signals-top">
                            <div class="er-signals-title">5-MANAGMENT REP DUE</div>
                        </div>
                        <div class="er-table-wrap">
                            <table class="er-table">
                                <thead>
                                    <tr>
                                        @foreach ($repSummary['aging'] as $bucket)
                                            <th>{{ $bucket->balance_aging }}</th>
                                        @endforeach
                                        <th>Total Balance</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        @foreach ($repSummary['aging'] as $bucket)
                                            <td class="status">{{ number_format($bucket->total, 0) }}</td>
                                        @endforeach
                                        <td class="dim">{{ number_format($repSummary['total_aging'], 0) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif
            </div>
        @elseif ($repSummary)
            <div class="er-empty">لا توجد بيانات مبيعات أو تحصيل مسجلة لـ "{{ $repSummary['rep_name'] }}" حتى الآن (لم يتم تسجيل أي فواتير أو تحصيلات).</div>
        @elseif ($selectionMade)
            <div class="er-empty">لا يوجد عملاء مطابقون لهذه الفلاتر.</div>
        @else
            <div class="er-empty">اختر مندوبًا أو عميلاً من الفلاتر بالأعلى عشان تشوف جداوله الشهرية.</div>
        @endif
        </div>

        {{-- Charts tab: same rep/customer-level data, visualized --}}
        <div id="er-tab-charts" class="er-tab-panel">
        @if ($repSummary && $repSummary['has_data'])
            <div class="er-charts-grid">
                <div class="er-chart-card">
                    <div class="er-chart-title">Sale Collection Across Month</div>
                    <div class="er-chart-box"><canvas id="er-chart-sales-collection"></canvas></div>
                </div>
                <div class="er-chart-card">
                    <div class="er-chart-title">GP% Across Month</div>
                    <div class="er-chart-box"><canvas id="er-chart-profit"></canvas></div>
                </div>
            </div>

            <div class="er-charts-grid">
                <div class="er-chart-card">
                    <div class="er-chart-title">Customer Count</div>
                    <div class="er-chart-box"><canvas id="er-chart-customer-count"></canvas></div>
                </div>
                <div class="er-chart-card">
                    <div class="er-chart-title">CEO Sales-Avg</div>
                    <div class="er-chart-box"><canvas id="er-chart-sales-avg"></canvas></div>
                </div>
            </div>

            <div class="er-charts-grid">
                <div class="er-chart-card">
                    <div class="er-chart-title">CEO Sales-Collection Ratio</div>
                    <div class="er-chart-box"><canvas id="er-chart-collection-ratio"></canvas></div>
                </div>
                <div class="er-chart-card">
                    <div class="er-chart-title">CEO Item Count</div>
                    <div class="er-chart-box"><canvas id="er-chart-item-count"></canvas></div>
                </div>
            </div>

            @if ($repSummary['aging']->isNotEmpty())
                <div class="er-charts-grid">
                    <div class="er-chart-card">
                        <div class="er-chart-title">CEO Due Aging Customer Count</div>
                        <div class="er-chart-box"><canvas id="er-chart-aging-count"></canvas></div>
                    </div>
                    <div class="er-chart-card">
                        <div class="er-chart-title">CEO Due Aging %</div>
                        <div class="er-chart-box"><canvas id="er-chart-aging"></canvas></div>
                    </div>
                </div>
            @endif
        @elseif ($repSummary)
            <div class="er-empty">لا توجد بيانات مبيعات أو تحصيل مسجلة لـ "{{ $repSummary['rep_name'] }}" حتى الآن (لم يتم تسجيل أي فواتير أو تحصيلات).</div>
        @elseif ($selectionMade)
            <div class="er-empty">لا يوجد عملاء مطابقون لهذه الفلاتر.</div>
        @else
            <div class="er-empty">اختر مندوبًا أو عميلاً من الفلاتر بالأعلى عشان تشوف الرسوم البيانية.</div>
        @endif
        </div>

        {{-- Recommendations tab: page intro + customer cards --}}
        <div id="er-tab-recommendations" class="er-tab-panel active">

        {{-- Page-level intro --}}
        <div class="er-intro">
            <div class="er-intro-eyebrow">Customer Behavior Report</div>
            <h1 class="er-intro-title">Sales Health & Recommended Action</h1>
            <p class="er-intro-sub">Sales behavior analysis and recommended action for each customer</p>
            @if ($lastUpdated)
                <p class="er-intro-sub">Last synced {{ \Illuminate\Support\Carbon::parse($lastUpdated)->diffForHumans() }}.</p>
            @endif
        </div>

        {{-- Report blocks, one per customer --}}
        <div class="er-blocks">
            @forelse ($customers as $c)
                @php
                    $badge = $priorityBadge($c->priority_level);
                    [$salesLabel, $salesValue] = $splitSegment($c->sales_growth_segment);
                    [$rhythmLabel, $rhythmValue] = $splitSegment($c->buying_rhythm_segment);
                    [$aovLabel, $aovValue] = $splitSegment($c->aov_growth_segment);
                    [$skuLabel, $skuValue] = $splitSegment($c->sku_growth_segment);
                    [$portfolioLabel, $portfolioValue] = $splitSegment($c->portfolio_behavior_segment);
                @endphp
                <div class="er-card">

                    <div class="er-identity">
                        <div class="er-badges">
                            @if ($badge)
                                <span class="er-badge @if($badge['urgent']) er-badge--urgent @endif">{{ $badge['label'] }}</span>
                            @endif
                            @if ($c->overall_sales_status)
                                <span class="er-badge">{{ $c->overall_sales_status }}</span>
                            @endif
                            @if ($c->document_issue_count)
                                <span class="er-badge er-badge--docs">🔔 {{ $c->document_issue_count }} مستندات مطلوبة</span>
                            @endif
                        </div>
                        <div>
                            <div class="er-customer-name">{{ $c->customer_name }}</div>
                            <div class="er-customer-rep">المندوب: {{ $c->rep_name ?? '—' }}</div>
                        </div>
                    </div>

                    <div class="er-main-grid">
                        <div class="er-segments">
                            @foreach ([
                                ['eyebrow' => 'نمو المبيعات (آخر 90 يوم)', 'label' => $salesLabel, 'value' => $salesValue, 'why' => $c->sales_growth_why],
                                ['eyebrow' => 'إيقاع الشراء', 'label' => $rhythmLabel, 'value' => $rhythmValue, 'why' => $c->buying_rhythm_why],
                                ['eyebrow' => 'نمو متوسط قيمة الطلب', 'label' => $aovLabel, 'value' => $aovValue, 'why' => $c->aov_growth_why],
                            ] as $card)
                                @if ($card['label'])
                                    @php $color = $statusColor($card['label']); @endphp
                                    <div class="er-segment-card">
                                        <div class="er-segment-top">
                                            <div class="er-segment-eyebrow">{{ $card['eyebrow'] }}</div>
                                            <span class="er-segment-pill er-color-{{ $color }}">{{ \Illuminate\Support\Str::of($card['label'])->words(2, '') }}</span>
                                        </div>
                                        <div class="er-segment-mid">
                                            <div class="er-segment-label">{{ $card['label'] }}</div>
                                            @if ($card['value'])
                                                <div class="er-segment-value er-text-{{ $color }}">{{ $card['value'] }}</div>
                                            @endif
                                        </div>
                                        <div class="er-bar-track">
                                            <div class="er-bar-fill er-bar-{{ $color }}" style="width: {{ $progressWidth($card['value']) }}%"></div>
                                        </div>
                                        @if ($card['why'])
                                            <div class="er-segment-why">{{ $card['why'] }}</div>
                                        @endif
                                    </div>
                                @endif
                            @endforeach
                        </div>

                        @if ($c->primary_recommendation)
                            <div class="er-critical">
                                <div class="er-critical-top">
                                    <div class="er-critical-eyebrow"><span class="er-critical-dot"></span>إجراء حرج مطلوب</div>
                                    @if ($badge)
                                        <span class="er-critical-badge">{{ $badge['label'] }}</span>
                                    @endif
                                </div>
                                <div class="er-critical-title">{{ $c->primary_recommendation }}</div>

                                @if ($c->final_why)
                                    <div class="er-critical-box">
                                        <div class="er-critical-label">السبب النهائي</div>
                                        <p class="er-critical-text">{{ $c->final_why }}</p>
                                    </div>
                                @endif

                                @if ($c->recommended_sales_rep_action)
                                    <div class="er-critical-action">
                                        <div class="er-critical-label">الإجراء المقترح للمندوب</div>
                                        <p class="er-critical-text">{{ $c->recommended_sales_rep_action }}</p>
                                    </div>
                                @endif
                            </div>
                        @endif
                    </div>

                    @if ($skuLabel || $portfolioLabel)
                        <div class="er-signals">
                            <div class="er-signals-top">
                                <div>
                                    <div class="er-signals-eyebrow">مؤشرات داعمة</div>
                                    <div class="er-signals-title">ديناميكية المحفظة والأصناف</div>
                                </div>
                                <span class="er-signals-dot"></span>
                            </div>
                            <div class="er-table-wrap">
                                <table class="er-table">
                                    <thead>
                                        <tr>
                                            <th>البعد</th>
                                            <th>حالة الفئة</th>
                                            <th>الدليل الفني</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @if ($skuLabel)
                                            <tr>
                                                <td class="dim">نمو الأصناف النشطة</td>
                                                <td class="status">{{ $c->sku_growth_segment }}</td>
                                                <td class="evidence">{{ $c->key_risk ?? $c->key_opportunity ?? '—' }}</td>
                                            </tr>
                                        @endif
                                        @if ($portfolioLabel)
                                            <tr>
                                                <td class="dim">الاحتفاظ بالمحفظة</td>
                                                <td class="status">{{ $c->portfolio_behavior_segment }}</td>
                                                <td class="evidence">{{ $c->key_opportunity ?? $c->key_risk ?? '—' }}</td>
                                            </tr>
                                        @endif
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @endif

                    @if ($c->documentInsights->isNotEmpty())
                        <details class="er-extra er-extra-docs">
                            <summary>🔔 المستندات والبيانات المطلوب تحديثها ({{ $c->documentInsights->count() }})</summary>
                            <table class="er-docs-table">
                                <thead>
                                    <tr>
                                        <th>الإجراء المطلوب</th>
                                        <th>السبب</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($c->documentInsights as $insight)
                                        <tr>
                                            <td class="action">{{ $insight->action }}</td>
                                            <td class="why">{{ $insight->why ?? '—' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </details>
                    @endif

                    @if ($c->monthlyStats->isNotEmpty())
                        <details class="er-extra er-extra-stats">
                            <summary>📊 المبيعات ونسبة الربح الشهرية ({{ $c->monthlyStats->count() }} شهر)</summary>
                            <table class="er-docs-table">
                                <thead>
                                    <tr>
                                        <th>الشهر</th>
                                        <th>المبيعات</th>
                                        <th>الربح الإجمالي</th>
                                        <th>نسبة الربح %</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($c->monthlyStats as $stat)
                                        <tr>
                                            <td class="action">{{ $stat->year }}-{{ str_pad($stat->month, 2, '0', STR_PAD_LEFT) }}</td>
                                            <td class="why">{{ number_format($stat->sales, 2) }}</td>
                                            <td class="why">{{ number_format($stat->profit, 2) }}</td>
                                            <td class="why">{{ $stat->sales > 0 ? number_format(($stat->profit / $stat->sales) * 100, 2) . '%' : '—' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </details>
                    @endif

                    @if ($c->invoiceProfit->isNotEmpty())
                        <details class="er-extra er-extra-invoices">
                            <summary>🧾 ربح كل فاتورة ({{ $c->invoiceProfit->count() }} فاتورة)</summary>
                            <div class="er-scroll-table">
                                <table class="er-docs-table">
                                    <thead>
                                        <tr>
                                            <th>التاريخ</th>
                                            <th>رقم الفاتورة</th>
                                            <th>المبلغ</th>
                                            <th>الربح الإجمالي</th>
                                            <th>نسبة الربح %</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($c->invoiceProfit as $invoice)
                                            <tr>
                                                <td class="action">{{ $invoice->transaction_date ? \Illuminate\Support\Carbon::parse($invoice->transaction_date)->format('Y-m-d') : '—' }}</td>
                                                <td class="action">{{ $invoice->transaction_number }}</td>
                                                <td class="why">{{ number_format($invoice->amount, 2) }}</td>
                                                <td class="why @if($invoice->gross_profit < 0) er-profit-neg @endif">{{ number_format($invoice->gross_profit, 2) }}</td>
                                                <td class="why @if($invoice->gross_profit < 0) er-profit-neg @endif">{{ $invoice->amount > 0 ? number_format(($invoice->gross_profit / $invoice->amount) * 100, 2) . '%' : '—' }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </details>
                    @endif

                    @if ($c->aging->isNotEmpty())
                        <details class="er-extra er-extra-aging">
                            <summary>⏳ الأرصدة المستحقة حسب العمر (إجمالي {{ number_format($c->aging->sum('total'), 2) }})</summary>
                            <div class="er-aging-pills">
                                @foreach ($c->aging as $bucket)
                                    <div class="er-aging-pill">
                                        {{ $bucket->balance_aging }}
                                        <b>{{ number_format($bucket->total, 2) }}</b>
                                    </div>
                                @endforeach
                            </div>
                        </details>
                    @endif

                    @if ($c->dueInvoices->isNotEmpty())
                        <details class="er-extra er-extra-due">
                            <summary>📄 الفواتير المستحقة حاليًا ({{ $c->dueInvoices->count() }})</summary>
                            <div class="er-scroll-table">
                                <table class="er-docs-table">
                                    <thead>
                                        <tr>
                                            <th>رقم الفاتورة</th>
                                            <th>تاريخ الفاتورة</th>
                                            <th>تاريخ الاستحقاق</th>
                                            <th>الرصيد</th>
                                            <th>العمر</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($c->dueInvoices as $due)
                                            <tr>
                                                <td class="action">{{ $due->transaction_number }}</td>
                                                <td class="why">{{ $due->transaction_date ? \Illuminate\Support\Carbon::parse($due->transaction_date)->format('Y-m-d') : '—' }}</td>
                                                <td class="why">{{ $due->inv_balance_due_date ? \Illuminate\Support\Carbon::parse($due->inv_balance_due_date)->format('Y-m-d') : '—' }}</td>
                                                <td class="why">{{ number_format($due->balance, 2) }}</td>
                                                <td class="why">{{ $due->balance_aging }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </details>
                    @endif

                    @if ($c->collections->isNotEmpty())
                        <details class="er-extra er-extra-collections">
                            <summary>💰 التحصيل الشهري (إجمالي {{ number_format($c->collections->sum('amount'), 2) }})</summary>
                            <table class="er-docs-table">
                                <thead>
                                    <tr>
                                        <th>الشهر</th>
                                        <th>المبلغ المحصّل</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($c->collections as $collection)
                                        <tr>
                                            <td class="action">{{ $collection->year }}-{{ str_pad($collection->month, 2, '0', STR_PAD_LEFT) }}</td>
                                            <td class="why">{{ number_format($collection->amount, 2) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </details>
                    @endif

                    @unless ($customerName)
                        <div class="er-footer">
                            <div>
                                <div class="er-footer-label">أول عملية شراء</div>
                                <div class="er-footer-value">{{ $c->historical_first_purchase ? \Illuminate\Support\Carbon::parse($c->historical_first_purchase)->format('Y-m-d') : '—' }}</div>
                            </div>
                            <div>
                                <div class="er-footer-label">آخر عملية شراء</div>
                                <div class="er-footer-value">{{ $c->last_purchase_date ? \Illuminate\Support\Carbon::parse($c->last_purchase_date)->format('Y-m-d') : '—' }}</div>
                            </div>
                            <div>
                                <div class="er-footer-label">مدة التعامل</div>
                                <div class="er-footer-value">{{ $formatDuration($c->historical_first_purchase) }}</div>
                            </div>
                            <div>
                                <div class="er-footer-label">حد الائتمان</div>
                                <div class="er-footer-value">{{ $c->credit_limit !== null ? number_format($c->credit_limit) : '—' }}</div>
                            </div>
                            <div>
                                <div class="er-footer-label">الرصيد المستحق</div>
                                <div class="er-footer-value @if(($c->used_credit ?? 0) > 0) er-footer-value--due @endif">{{ $c->used_credit !== null ? number_format($c->used_credit) : '—' }}</div>
                            </div>
                            <div>
                                <div class="er-footer-label">ترتيب الإيراد</div>
                                <div class="er-footer-value">{{ $c->revenue_rank ? '#'.$c->revenue_rank : '—' }}</div>
                            </div>
                            <div>
                                <div class="er-footer-label">ترتيب المبيعات</div>
                                <div class="er-footer-value">{{ $c->sales_rank ? '#'.$c->sales_rank : '—' }}</div>
                            </div>
                        </div>
                    @endunless
                </div>
            @empty
                <div class="er-empty">لا يوجد عملاء مطابقون لهذه الفلاتر.</div>
            @endforelse
        </div>

        <div class="er-pagination">
            {{ $customers->links() }}
        </div>

        </div>
    </div>

    <script>
        (function () {
            var options = @json($customerOptions);
            var repMap = @json($customerRepMap);
            var search = document.getElementById('er-customer-search');
            var hidden = document.getElementById('er-customer-hidden');
            var list = document.getElementById('er-customer-list');
            var wrap = document.getElementById('er-customer-combobox');
            var repSelect = document.getElementById('er-rep-select');
            if (! search || ! hidden || ! list || ! wrap) return;

            function visibleOptions() {
                var selectedRep = repSelect ? repSelect.value : '';
                if (! selectedRep) return options;

                return options.filter(function (name) {
                    return repMap[name] === selectedRep;
                });
            }

            function render(filter) {
                var term = (filter || '').trim().toLowerCase();
                var base = visibleOptions();
                var matches = term === ''
                    ? base.slice(0, 50)
                    : base.filter(function (name) {
                        return name.toLowerCase().indexOf(term) !== -1;
                    }).slice(0, 50);

                list.innerHTML = '';
                if (matches.length === 0) {
                    var empty = document.createElement('div');
                    empty.className = 'er-combobox-empty';
                    empty.textContent = 'لا يوجد عملاء مطابقون';
                    list.appendChild(empty);
                    return;
                }
                matches.forEach(function (name) {
                    var opt = document.createElement('div');
                    opt.className = 'er-combobox-option';
                    opt.textContent = name;
                    opt.addEventListener('mousedown', function (e) {
                        e.preventDefault();
                        search.value = name;
                        hidden.value = name;
                        list.classList.remove('open');
                    });
                    list.appendChild(opt);
                });
            }

            search.addEventListener('input', function () {
                hidden.value = search.value;
                render(search.value);
                list.classList.add('open');
            });
            search.addEventListener('focus', function () {
                search.select();
                render('');
                list.classList.add('open');
            });
            document.addEventListener('click', function (e) {
                if (! wrap.contains(e.target)) {
                    list.classList.remove('open');
                }
            });

            if (repSelect) {
                repSelect.addEventListener('change', function () {
                    var selectedRep = repSelect.value;
                    if (selectedRep && repMap[search.value] !== selectedRep) {
                        search.value = '';
                        hidden.value = '';
                    }
                    render(search.value);
                });
            }
        })();

        (function () {
            var buttons = document.querySelectorAll('.er-tab-btn');
            var panels = {
                recommendations: document.getElementById('er-tab-recommendations'),
                tables: document.getElementById('er-tab-tables'),
                charts: document.getElementById('er-tab-charts'),
            };
            var chartsInitialized = false;
            buttons.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var target = btn.getAttribute('data-tab');
                    buttons.forEach(function (b) { b.classList.remove('active'); });
                    btn.classList.add('active');
                    Object.keys(panels).forEach(function (key) {
                        if (panels[key]) panels[key].classList.toggle('active', key === target);
                    });
                    if (target === 'charts' && ! chartsInitialized) {
                        chartsInitialized = true;
                        initCharts();
                    }
                });
            });

            function initCharts() {
                if (typeof Chart === 'undefined') return;
                var repSummary = @json($repSummary);
                if (! repSummary) return;

                if (typeof ChartDataLabels !== 'undefined') {
                    Chart.register(ChartDataLabels);
                    Chart.defaults.set('plugins.datalabels', { display: false });
                }

                var pctLabelPlugin = {
                    display: true,
                    color: '#fff',
                    font: { weight: 'bold', size: 11 },
                    formatter: function (value, ctx) {
                        var data = ctx.chart.data.datasets[0].data.map(Number);
                        var total = data.reduce(function (a, b) { return a + b; }, 0);
                        var pct = total > 0 ? (Number(value) / total) * 100 : 0;
                        return pct.toFixed(1) + '%';
                    },
                };

                var labels = repSummary.months.map(function (m) { return m.label; });

                var salesEl = document.getElementById('er-chart-sales-collection');
                if (salesEl) {
                    new Chart(salesEl, {
                        type: 'line',
                        data: {
                            labels: labels,
                            datasets: [
                                { label: 'SALES', data: repSummary.months.map(function (m) { return m.sales; }), borderColor: '#4338ca', backgroundColor: 'rgba(67,56,202,.1)', tension: 0.3 },
                                { label: 'COLLECTION', data: repSummary.months.map(function (m) { return m.collected; }), borderColor: '#059669', backgroundColor: 'rgba(5,150,105,.1)', tension: 0.3 },
                            ],
                        },
                        options: { responsive: true, maintainAspectRatio: false },
                    });
                }

                var profitEl = document.getElementById('er-chart-profit');
                if (profitEl) {
                    new Chart(profitEl, {
                        type: 'line',
                        data: {
                            labels: labels,
                            datasets: [
                                {
                                    label: 'GP%',
                                    data: repSummary.months.map(function (m) { return m.sales > 0 ? (m.profit / m.sales) * 100 : 0; }),
                                    borderColor: '#d97706',
                                    backgroundColor: 'rgba(217,119,6,.1)',
                                    tension: 0.3,
                                },
                            ],
                        },
                        options: { responsive: true, maintainAspectRatio: false },
                    });
                }

                var chartColors = ['#4338ca', '#059669', '#d97706', '#dc2626', '#0891b2', '#a21caf', '#65a30d'];

                var customerCountEl = document.getElementById('er-chart-customer-count');
                if (customerCountEl) {
                    new Chart(customerCountEl, {
                        type: 'line',
                        data: {
                            labels: labels,
                            datasets: [
                                { label: 'CUSTOMER COUNT', data: repSummary.months.map(function (m) { return m.customer_count; }), borderColor: '#0891b2', backgroundColor: 'rgba(8,145,178,.1)', tension: 0.3 },
                            ],
                        },
                        options: { responsive: true, maintainAspectRatio: false },
                    });
                }

                var salesAvgEl = document.getElementById('er-chart-sales-avg');
                if (salesAvgEl) {
                    new Chart(salesAvgEl, {
                        type: 'line',
                        data: {
                            labels: labels,
                            datasets: [
                                { label: 'SALES AVG', data: repSummary.months.map(function (m) { return m.customer_count > 0 ? m.sales / m.customer_count : 0; }), borderColor: '#059669', backgroundColor: 'rgba(5,150,105,.1)', tension: 0.3 },
                            ],
                        },
                        options: { responsive: true, maintainAspectRatio: false },
                    });
                }

                var collectionRatioEl = document.getElementById('er-chart-collection-ratio');
                if (collectionRatioEl) {
                    new Chart(collectionRatioEl, {
                        type: 'line',
                        data: {
                            labels: labels,
                            datasets: [
                                { label: 'COLLECTION % vs SALES', data: repSummary.months.map(function (m) { return m.sales > 0 ? (m.collected / m.sales) * 100 : 0; }), borderColor: '#4338ca', backgroundColor: 'rgba(67,56,202,.1)', tension: 0.3 },
                            ],
                        },
                        options: { responsive: true, maintainAspectRatio: false },
                    });
                }

                var itemCountEl = document.getElementById('er-chart-item-count');
                if (itemCountEl) {
                    new Chart(itemCountEl, {
                        type: 'line',
                        data: {
                            labels: labels,
                            datasets: [
                                { label: 'ITEM COUNT', data: repSummary.months.map(function (m) { return m.item_count; }), borderColor: '#d97706', backgroundColor: 'rgba(217,119,6,.1)', tension: 0.3 },
                            ],
                        },
                        options: { responsive: true, maintainAspectRatio: false },
                    });
                }

                var agingCountEl = document.getElementById('er-chart-aging-count');
                if (agingCountEl && repSummary.aging_customer_count && repSummary.aging_customer_count.length) {
                    new Chart(agingCountEl, {
                        type: 'bar',
                        data: {
                            labels: repSummary.aging_customer_count.map(function (a) { return a.balance_aging; }),
                            datasets: [{
                                label: 'CUSTOMER COUNT',
                                data: repSummary.aging_customer_count.map(function (a) { return a.customer_count; }),
                                backgroundColor: '#4338ca',
                            }],
                        },
                        options: { responsive: true, maintainAspectRatio: false },
                    });
                }

                var agingEl = document.getElementById('er-chart-aging');
                if (agingEl && repSummary.aging && repSummary.aging.length) {
                    var agingTotal = repSummary.aging.reduce(function (sum, a) { return sum + Number(a.total); }, 0);
                    new Chart(agingEl, {
                        type: 'doughnut',
                        data: {
                            labels: repSummary.aging.map(function (a) { return a.balance_aging; }),
                            datasets: [{
                                data: repSummary.aging.map(function (a) { return a.total; }),
                                backgroundColor: chartColors,
                            }],
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                datalabels: pctLabelPlugin,
                                tooltip: {
                                    callbacks: {
                                        label: function (ctx) {
                                            var pct = agingTotal > 0 ? (ctx.parsed / agingTotal) * 100 : 0;
                                            return ctx.label + ': ' + pct.toFixed(2) + '%';
                                        },
                                    },
                                },
                            },
                        },
                    });
                }
            }
        })();
    </script>
</x-app-layout>
