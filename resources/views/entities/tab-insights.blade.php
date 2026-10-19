{{-- Entity Insights — their PortfolioClassificationsSection (+ ClassificationCustomersDrawer) then
     PortfolioCustomerActionsSection (+ PortfolioActionCustomersDrawer, daily snapshot built by `insights:snapshot`). --}}
@php
    use App\Support\C360Format as F;
    $groupTitles = ['sales' => 'Sales Classification', 'profitability' => 'Profitability Classification', 'collection' => 'Collection Classification',
        'payment_trend' => 'Payment Trend', 'ar_risk' => 'AR Risk Classification', 'documents' => 'Documents Classification'];
    $groups = collect(array_keys($groupTitles))->map(fn ($g) => ['g' => $g, 'items' => collect($classificationCounts)->where('group_key', $g)->values()])
        ->filter(fn ($x) => $x['items']->isNotEmpty());

    $categoryTitles = ['sales' => 'Sales', 'items' => 'Item Performance', 'credit_notes' => 'Credit Notes', 'sales_orders' => 'Sales Orders',
        'profitability' => 'Profitability', 'financial_health' => 'Financial Health', 'documentation' => 'Customer Documentation'];
    $actionTitles = [
        'stopped_buying' => 'Customer Stopped Buying', 'not_purchased_recently' => 'Customer Has Not Purchased Recently', 'not_buying_long_time' => 'Customer Not Buying for a Long Time',
        'late_purchase' => 'Late Purchase', 'sales_dropped' => 'Sales Dropped', 'smaller_orders' => 'Smaller Orders', 'long_inactive' => 'Long Inactive Customer',
        'fewer_items' => 'Fewer Items Purchased', 'stopped_items' => 'Stopped Buying Items', 'qty_dropped' => 'Item Quantities Dropped', 'different_items' => 'Buying Different Items',
        'products_approaching_expiry' => 'Products Approaching Expiry',
        'frequent_credit_notes' => 'Frequent Credit Notes', 'high_credit_note_value' => 'High Credit Note Value', 'credit_note_without_purchase' => 'Credit Note Without Recent Purchase',
        'old_draft' => 'Old Draft Sales Orders', 'partially_invoiced' => 'Partially Invoiced Sales Orders',
        'negative_profitability' => 'Negative Profitability', 'very_low_profitability' => 'Very Low Profitability', 'low_profitability' => 'Low Profitability', 'profitability_declined' => 'Profitability Declined',
        'monthly_collection_required' => 'Monthly Collection Required', 'customer_has_unapplied_credit' => 'Customer Has Unapplied Credit',
        'finance_recommendation' => 'Finance Department Recommendation Requires Review', 'cr_expired' => 'Commercial Registration Expired', 'cr_expiring_soon' => 'Commercial Registration Expiring Soon',
        'cr_expiry_missing' => 'Commercial Registration Expiry Date Missing', 'reconciliation_overdue' => 'Balance Reconciliation Overdue', 'reconciliation_due_soon' => 'Balance Reconciliation Due Soon',
        'reconciliation_date_missing' => 'Last Reconciliation Date Missing', 'reconciliation_date_invalid' => 'Invalid Reconciliation Date', 'tax_number_missing' => 'Tax Registration Number Missing',
        'cr_number_missing' => 'Commercial Registration Number Missing', 'gln_missing' => 'GLN Number Missing', 'moh_expired' => 'MOH / SFDA License Expired',
        'moh_expiring_soon' => 'MOH / SFDA License Expiring Soon', 'moh_expiry_missing' => 'MOH / SFDA License Expiry Date Missing', 'delivery_city_missing' => 'Delivery City Missing',
        'delivery_location_missing' => 'Delivery Location Missing', 'dispatch_warehouse_missing' => 'Dispatch Warehouse Information Missing',
    ];
    $actions = collect($actionCounts)->filter(fn ($r) => $r['customers'] > 0 && isset($actionTitles[$r['action_code']]));
    $actionGroups = $actions->groupBy('category')->sortBy(fn ($rows) => $rows->first()['category_order']);
    // dd-mm-yyyy HH:MM in Asia/Riyadh (their portfolioSnapshotDate)
    $snap = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v, config('app.timezone'))->setTimezone('Asia/Riyadh')->format('d-m-Y H:i') : F::DASH;
    $publishedAt = collect($actionCounts)->first()['published_at'] ?? ($snapshotStatus['published_at'] ?? null);
    $countsGen = collect($actionCounts)->first()['generation_id'] ?? null;
    $syncedAt = ($snapshotStatus && ($countsGen === null || $snapshotStatus['generation_id'] === $countsGen)) ? $snapshotStatus['source_synced_at'] : null;
@endphp
<style>
    .c3-pgrp { display: flex; flex-direction: column; gap: 8px; padding: 12px 0; }
    .c3-pgrp + .c3-pgrp { border-top: 1px solid var(--c360-border); }
    @media (min-width: 640px) { .c3-pgrp { flex-direction: row; align-items: flex-start; } .c3-pgrp > .t { width: 14rem; flex-shrink: 0; padding-top: 8px; } }
    .c3-pgrp > .t { margin: 0; font-size: 13px; font-weight: 600; color: var(--c360-heading); }
    .c3-cls { display: inline-flex; align-items: center; gap: 12px; border-radius: 6px; border: 1px solid var(--c360-border); background: var(--c360-card);
        padding: 8px 12px; text-align: start; cursor: pointer; font-family: inherit; transition: border-color .15s, background .15s; }
    .c3-cls:hover, .c3-cls.on { border-color: var(--c360-accent); } .c3-cls.on { background: var(--c360-th); }
    .c3-cls .v { display: block; font-size: 13px; font-weight: 500; color: var(--c360-value); }
    .c3-cls .n { display: block; font-size: 12px; color: var(--c360-label); font-variant-numeric: tabular-nums; }
    .c3-cls svg, .c3-act svg { width: 16px; height: 16px; flex-shrink: 0; color: var(--c360-label); }
    .c3-act { display: flex; width: 100%; min-height: 36px; align-items: center; justify-content: space-between; gap: 12px; border-radius: 6px; border: 1px solid var(--c360-border);
        background: var(--c360-card); padding: 8px 12px; text-align: start; font-size: 13px; color: var(--c360-value); cursor: pointer; font-family: inherit; box-shadow: 0 1px 2px rgba(0,0,0,.05); }
    .c3-act:hover { border-color: var(--c360-accent); background: var(--c360-th); }
    [dir="rtl"] .c3-cls svg, [dir="rtl"] .c3-act svg { transform: rotate(180deg); }
</style>

<div class="c3-stack">
    {{-- Classifications --}}
    <section class="c3-card">
        <header class="c3-card-h"><i data-lucide="tags" class="ico-18"></i><h2>Classifications</h2></header>
        <div style="padding: 4px 16px">
            @if ($groups->isEmpty())
                <p style="padding: 16px 0; font-size: 13px; color: var(--c360-label)">No classified customers in this portfolio</p>
            @else
                @foreach ($groups as $grp)
                    <div class="c3-pgrp">
                        <div class="t">{{ $groupTitles[$grp['g']] }}</div>
                        <div style="display: flex; flex-wrap: wrap; gap: 8px">
                            @foreach ($grp['items'] as $r)
                                <button type="button" class="c3-cls" :class="cls && cls.group === @js($grp['g']) && cls.value === @js($r['class_value']) && 'on'"
                                        :aria-pressed="!! (cls && cls.group === @js($grp['g']) && cls.value === @js($r['class_value']))"
                                        @click="openClassification(@js($grp['g']), @js($groupTitles[$grp['g']]), @js($r['class_value']), {{ (int) $r['customers'] }})">
                                    <span><span class="v" dir="auto">{{ $r['class_value'] }}</span><span class="n"><bdi dir="ltr">{{ F::int($r['customers']) }} customers</bdi></span></span>
                                    <i data-lucide="chevron-right"></i>
                                </button>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            @endif
        </div>
    </section>

    {{-- Customer Actions --}}
    <section class="c3-card" style="box-shadow: 0 1px 2px rgba(0,0,0,.05)">
        <header class="c3-card-h"><i data-lucide="list-checks" class="ico-18"></i><h2>Customer Actions</h2></header>
        <div style="padding: 4px 16px">
            @if (! $publishedAt && $actionGroups->isEmpty())
                <p style="padding: 16px 0; font-size: 13px; color: var(--c360-label)">Customer Actions data has not been generated yet.</p>
            @else
                @if ($publishedAt)
                    <div style="padding: 12px 0; font-size: 12px; color: var(--c360-label)">
                        <p style="margin: 0; font-weight: 600; color: var(--c360-heading)">Daily Snapshot</p>
                        <p style="margin: 0">These insights are based on the last successful data sync before 6:00 AM (Riyadh time).</p>
                        @if ($syncedAt)<p style="margin: 0">Last Data Sync: <bdi dir="ltr">{{ $snap($syncedAt) }}</bdi></p>@endif
                        <p style="margin: 0">Snapshot Generated: <bdi dir="ltr">{{ $snap($publishedAt) }}</bdi></p>
                    </div>
                @endif
                @if ($actionGroups->isEmpty())
                    <p style="padding: 16px 0; font-size: 13px; color: var(--c360-label)">No customer actions for this portfolio.</p>
                @else
                    @foreach ($actionGroups as $category => $items)
                        <div class="c3-pgrp">
                            <h3 class="t">{{ $categoryTitles[$category] }}</h3>
                            <div style="display: flex; min-width: 0; flex: 1; flex-direction: column; gap: 8px">
                                @foreach ($items as $r)
                                    <button type="button" class="c3-act"
                                            @click="openAction(@js($r['action_code']), @js($actionTitles[$r['action_code']]), {{ (int) $r['generation_id'] }}, {{ (int) $r['customers'] }}, @js($snap($r['published_at'])))">
                                        <span style="min-width: 0; word-break: break-word">{{ $actionTitles[$r['action_code']] }} — <bdi dir="ltr" class="tnum">{{ F::int($r['customers']) }} customers</bdi></span>
                                        <i data-lucide="chevron-right"></i>
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                @endif
            @endif
        </div>
    </section>
</div>
