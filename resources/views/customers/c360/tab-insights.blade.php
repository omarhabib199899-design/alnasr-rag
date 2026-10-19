{{-- Insights — their CustomerInsightsPanel: Sales, Item Performance (+ expiry), Credit Notes, Sales Orders, Profitability,
     Financial Health, Customer Documentation. Same texts (c360*Insights en messages), same order, same empty state.
     $onlyAction (Entity 360 Customer Actions drawer — their onlyAction prop): one action of one customer, no header/empty text;
     $insightUrls: [items url, expiry url] of that customer for the View Items buttons. --}}
@php
    use App\Support\C360Format as F;
    $ins = $insights;
    // {name} placeholders → LTR tabular values (a trailing % stays with its number), the rest escaped — their rich() helper.
    $rich = function (string $tpl, array $v): string {
        return preg_replace_callback('/\{(\w+)\}(%?)/', fn ($m) => array_key_exists($m[1], $v)
            ? '<bdi dir="ltr" class="tnum">'.e($v[$m[1]]).$m[2].'</bdi>' : e($m[0]), e($tpl));
    };
    $money = fn ($v) => $v === null ? F::DASH : 'SAR '.F::money($v);
    $pctAbs = fn ($v) => $v === null ? F::DASH : (string) (int) round(abs($v));
    $num = fn ($v) => $v === null ? F::DASH : rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
    $T = [
        'sales' => [
            'stopped_buying' => ['Customer Stopped Buying', 'No purchase for {days} days (last purchase {date}), after purchases on {n} days in the previous 90-day period.', 'Contact the customer, find out why purchasing stopped, and agree on the next order.'],
            'not_purchased_recently' => ['Customer Has Not Purchased Recently', "{days} days since the last purchase (last purchase {date}) — more than twice this customer's normal interval of {gap} days.", 'Contact the customer to understand the reason for inactivity and work toward restarting regular purchases.'],
            'not_buying_long_time' => ['Customer Not Buying for a Long Time', 'No purchase for {days} days (last purchase {date}).', 'Contact the customer, identify why they stopped buying, and discuss what is needed to restart purchasing.'],
            'late_purchase' => ['Late Purchase', '{days} days since the last purchase (last purchase {date}); this customer normally buys every {gap} days.', 'Contact the customer, ask why the next order is delayed, and agree on an expected order date.'],
            'sales_dropped' => ['Sales Dropped', 'Sales decreased by {pct}% compared with the previous period (daily average): {cur} in the last 45 days vs {prev} in the previous 90 days.', 'Ask the customer why purchases have decreased and agree on steps to restore sales.'],
            'smaller_orders' => ['Smaller Orders', 'Average invoice value decreased by {pct}%: {cur} per invoice in the last 45 days ({ci} invoices) vs {prev} in the previous 90 days ({pi} invoices).', 'Find out why the customer is placing smaller orders and discuss how to restore the previous order value.'],
            'long_inactive' => ['Long Inactive Customer', 'No purchase for {days} days (last purchase {date}).', 'Include this customer in periodic win-back contact and check whether they are ready to buy again.'],
        ],
        'items' => [
            'fewer_items' => ['Fewer Items Purchased', 'The customer bought {prev} different items in the previous 60 days and {last} in the last 60 days ({diff} fewer, {pct}%).', 'Review the items the customer is no longer purchasing and discuss restoring them.'],
            'stopped_items' => ['Stopped Buying Items', '{n} items bought in the previous 60 days ({value}) were not bought in the last 60 days.', 'Ask the customer why they stopped purchasing these items and discuss restarting orders.'],
            'qty_dropped' => ['Item Quantities Dropped', '{n} of {retained} items bought in both periods were bought in lower quantities; their sales went from {prev} to {last}.', 'Review the items with lower quantities, identify the reason for the reduction, and discuss restoring previous order quantities.'],
            'different_items' => ['Buying Different Items', '{retained} items kept, {lost} items stopped and {new} new items started; {pct}% of the previous items were kept.', 'Review the items the customer stopped purchasing and the new items they started buying, and understand the reason for the change.'],
        ],
        'credit_notes' => [
            'frequent_credit_notes' => ['Frequent Credit Notes', 'The customer had {invoice_count} invoices and {credit_note_count} credit notes in the last 90 days, representing {frequency}%.', 'Contact the customer and investigate why credit notes are being issued so frequently.'],
            'high_credit_note_value' => ['High Credit Note Value', 'The customer received credit notes worth SAR {credit_note_value} against SAR {gross_sales} in gross sales over the last 90 days ({percentage}%).', 'Contact the customer, investigate the reasons for the high credit note value, and identify how to reduce future returns or adjustments.'],
            'credit_note_without_purchase' => ['Credit Note Without Recent Purchase', 'The customer received {credit_note_count_30d} credit note(s) in the last 30 days, while their last invoice was {days_since_last_invoice} days ago. Latest credit note: {last_credit_note_date}, SAR {last_credit_note_value}.', 'Contact the customer and investigate why a credit note was issued despite no purchases in the last 90 days. Verify the reason for the credit note.'],
        ],
        'sales_orders' => [
            'old_draft' => ['Old Draft Sales Orders', 'The customer has {count} draft sales order(s) older than 3 days. The oldest draft is {oldest_days} days old.', 'Please contact Data Entry to approve the items in the draft sales orders or cancel the drafts in the system.'],
            'partially_invoiced' => ['Partially Invoiced Sales Orders', 'The customer has {count} partially invoiced sales order(s) with items that have not yet been invoiced.', 'Please review the sales orders and coordinate with Purchasing to make the remaining uninvoiced items available.'],
        ],
        'profitability' => [
            'negative_profitability' => ['Negative Profitability', "The customer's gross profit margin over the last 45 days is {current_margin}%, with gross profit of SAR {current_gp}.", 'Please contact the Purchasing Manager to review the items being sold at a loss and address the negative profitability.'],
            'very_low_profitability' => ['Very Low Profitability', "The customer's gross profit margin over the last 45 days is {current_margin}%, which is below 5%.", 'Please contact the Purchasing Manager to review the reasons for the very low profitability and improve the margin.'],
            'low_profitability' => ['Low Profitability', "The customer's gross profit margin over the last 45 days is {current_margin}%, which is below 10%.", "The customer's profitability is low and needs improvement. Please review the product mix and selling prices to improve profitability."],
            'profitability_declined' => ['Profitability Declined', "The customer's gross profit margin declined from {previous_margin}% in the previous 90 days to {current_margin}% in the last 45 days, a decrease of {decline_pp} percentage points.", 'Please contact the Purchasing Manager to review the reasons for the decline in customer profitability over the last 45 days compared with the previous 90 days.'],
        ],
        'documentation' => [
            'finance_recommendation' => ['Finance Department Recommendation Requires Review', 'The Finance Department has recorded the following recommendation for this customer:', 'Please contact the Finance Department to review its recommendations regarding this customer.'],
            'cr_expired' => ['Commercial Registration Expired', "The customer's Commercial Registration expired on {date}.", 'Please contact the customer to obtain a renewed Commercial Registration Certificate.'],
            'cr_expiring_soon' => ['Commercial Registration Expiring Soon', "The customer's Commercial Registration expires on {date}, with {days} day(s) remaining.", 'Please contact the customer to obtain an updated Commercial Registration Certificate.'],
            'cr_expiry_missing' => ['Commercial Registration Expiry Date Missing', 'No Commercial Registration expiry date is recorded for this customer.', 'Please contact the customer to obtain the Commercial Registration Certificate and confirm its expiry date.'],
            'reconciliation_overdue' => ['Balance Reconciliation Overdue', "The customer's last recorded balance reconciliation was on {date}, {elapsed} days ago.", 'Please contact the customer to arrange the balance reconciliation before processing any Sales Order.'],
            'reconciliation_due_soon' => ['Balance Reconciliation Due Soon', "The customer's last recorded balance reconciliation was on {date}, {elapsed} days ago. The next reconciliation is due in {days} day(s).", 'Please contact the customer to arrange the next balance reconciliation within the remaining {days} day(s).'],
            'reconciliation_date_missing' => ['Last Reconciliation Date Missing', 'No last balance reconciliation date is recorded for this customer.', 'Please contact the customer to arrange the balance reconciliation before processing any Sales Order.'],
            'reconciliation_date_invalid' => ['Invalid Reconciliation Date', 'The recorded last reconciliation date is {date}, which is in the future.', "Please contact the Accounts Receivable Department to review and correct the customer's last reconciliation date."],
            'tax_number_missing' => ['Tax Registration Number Missing', "The customer's Tax Registration Number is not recorded.", 'Please contact the customer to obtain a copy of the Tax Registration Certificate.'],
            'cr_number_missing' => ['Commercial Registration Number Missing', "The customer's Commercial Registration Number is missing or contains a placeholder value.", 'Please contact the customer to obtain a copy of the Commercial Registration Certificate.'],
            'gln_missing' => ['GLN Number Missing', "The customer's GLN Number is missing or contains a placeholder value.", 'Please contact the customer to obtain the GLN Number.'],
            'moh_expired' => ['MOH / SFDA License Expired', "The customer's MOH / SFDA License expired on {date}.", 'Please contact the customer to obtain a renewed MOH / SFDA License.'],
            'moh_expiring_soon' => ['MOH / SFDA License Expiring Soon', "The customer's MOH / SFDA License expires on {date}, with {days} day(s) remaining.", 'Please contact the customer to obtain an updated MOH / SFDA License.'],
            'moh_expiry_missing' => ['MOH / SFDA License Expiry Date Missing', 'No MOH / SFDA License expiry date is recorded.', 'Please contact the customer to obtain the MOH / SFDA License and confirm its expiry date.'],
            'delivery_city_missing' => ['Delivery City Missing', "The customer's delivery city is not recorded.", "Please contact the Accounts Receivable Department to add the customer's delivery city."],
            'delivery_location_missing' => ['Delivery Location Missing', "The customer's delivery location is not recorded.", "Please contact the Accounts Receivable Department to add the customer's delivery location."],
            'dispatch_warehouse_missing' => ['Dispatch Warehouse Information Missing', "The warehouse used for dispatching the customer's goods is not recorded.", "Please contact the Accounts Receivable Department to add the warehouse information used for dispatching the customer's goods."],
        ],
    ];
    $fin = $ins['financial'];
    $allEmpty = ! $ins['sales'] && ! $ins['items'] && ! $ins['expiry'] && ! $ins['credit_notes'] && ! $ins['sales_orders'] && ! $ins['profitability'] && ! $fin && ! $ins['documentation'];
    $why = '<span style="color: var(--c360-label)">Why:</span> ';
    $act = '<span style="color: var(--c360-label)">Required Action:</span> ';
    $only = $onlyAction ?? null;
    $urls = $insightUrls ?? null;
@endphp

@if (! $only)<p style="margin: 0; padding: 12px 0; font-size: 12px; color: var(--c360-label)">Live Update</p>@endif
@if ($allEmpty && ! $only)
    <p style="padding: 48px 0; text-align: center; font-size: 13px; color: var(--c360-label)">No Actions Required</p>
@else
<div class="c3-stack">
    {{-- Sales --}}
    @if ($ins['sales'])
        <section class="c3-card c3-ins">
            <header class="c3-card-h"><i data-lucide="trending-down" class="ico-18"></i><h2>Sales</h2></header>
            <div class="c3-card-b">
                <div class="c3-ins-list">
                    @foreach ($ins['sales'] as $r)
                        @php
                            [$title, $whyTpl, $action] = $T['sales'][$r['action_code']];
                            $small = $r['action_code'] === 'smaller_orders';
                            $v = [
                                'days' => (string) round($r['days_since_last_purchase'] ?? 0), 'date' => F::dmy($r['last_purchase_date']) ?: F::DASH,
                                'gap' => $r['normal_gap_days'] === null ? F::DASH : (string) max(1, round($r['normal_gap_days'])),
                                'n' => (string) round($r['prev90_purchase_days']),
                                'pct' => $pctAbs($small ? $r['aiv_change_pct'] : $r['sales_change_pct']),
                                'cur' => $money($small ? $r['current_aiv'] : $r['current_sales']),
                                'prev' => $money($small ? $r['previous_aiv'] : $r['previous_sales']),
                                'ci' => (string) round($r['current_invoices']), 'pi' => (string) round($r['previous_invoices']),
                            ];
                            if ($r['action_code'] === 'stopped_buying' && $r['normal_gap_days'] !== null) {
                                $whyHtml = $rich('No purchase for {days} days (last purchase {date}). This customer normally buys every {gap} days.', $v);
                            } else {
                                $whyHtml = $rich($whyTpl, $v);
                                if ($r['action_code'] === 'not_buying_long_time' && $r['normal_gap_days'] !== null) {
                                    $whyHtml .= $rich(' This customer normally buys every {gap} days.', $v);
                                }
                            }
                        @endphp
                        <article>
                            <div class="h"><h3>{{ $title }}</h3>
                                @if ($r['action_code'] === 'long_inactive')<span class="c3-chip">Low priority</span>@endif</div>
                            <p>{!! $why.$whyHtml !!}</p>
                            <p>{!! $act.e($action) !!}</p>
                        </article>
                    @endforeach
                </div>
                <p class="c3-note" style="margin-top: 12px">{!! $rich('Based on AR Sales as of {date}. Last 45 days vs the previous 90 days; normal buying interval from the last 6 completed months.', ['date' => F::dmy($ins['sales'][0]['as_of_date'])]) !!}</p>
            </div>
        </section>
    @endif

    {{-- Item Performance (+ Products Approaching Expiry) --}}
    @if ($ins['items'] || $ins['expiry'])
        <section class="c3-card c3-ins">
            <header class="c3-card-h"><i data-lucide="package" class="ico-18"></i><h2>Item Performance</h2></header>
            <div class="c3-card-b">
                <div class="c3-ins-list">
                    @foreach ($ins['items'] as $r)
                        @php
                            [$title, $whyTpl, $action] = $T['items'][$r['action_code']];
                            $v = match ($r['action_code']) {
                                'fewer_items' => ['prev' => (string) $r['prev_sku'], 'last' => (string) $r['last_sku'], 'diff' => (string) $r['sku_diff'], 'pct' => $pctAbs($r['sku_change_pct'])],
                                'stopped_items' => ['n' => (string) $r['lost_count'], 'value' => $money($r['lost_prev_value'])],
                                'qty_dropped' => ['n' => (string) $r['lower_count'], 'retained' => (string) $r['retained_count'], 'prev' => $money($r['lower_prev_value']), 'last' => $money($r['lower_last_value'])],
                                'different_items' => ['retained' => (string) $r['retained_count'], 'lost' => (string) $r['lost_count'], 'new' => (string) $r['new_count'],
                                    'pct' => $r['retention_pct'] === null ? F::DASH : (string) (int) round($r['retention_pct'])],
                            };
                            $meta = ['p0' => $r['p0'], 'p1' => $r['p1'], 'c0' => $r['c0'], 'd' => $r['as_of_date']];
                        @endphp
                        <article>
                            <div class="h"><h3>{{ $title }}</h3>
                                <button type="button" class="c3-iconbtn" title="View Items" aria-label="View Items"
                                        @click="openInsightDrawer('items', @js($r['action_code']), @js($title), @js($meta), @js($urls[0] ?? null))">
                                    <i data-lucide="list-checks" class="ico"></i></button></div>
                            <p>{!! $why.$rich($whyTpl, $v) !!}</p>
                            <p>{!! $act.e($action) !!}</p>
                        </article>
                    @endforeach
                    @if ($x = $ins['expiry'])
                        @php $meta = ['sales_start' => $x['sales_start'], 'd' => $x['as_of_date'], 'e0' => $x['expiry_start_label'], 'e1' => $x['expiry_end_label']]; @endphp
                        <article>
                            <div class="h"><h3>Products Approaching Expiry</h3>
                                <button type="button" class="c3-iconbtn" title="View Items" aria-label="View Items"
                                        @click="openInsightDrawer('expiry', 'products_approaching_expiry', 'Products Approaching Expiry', @js($meta), @js($urls[1] ?? null))">
                                    <i data-lucide="list-checks" class="ico"></i></button></div>
                            <p>{!! $why.$rich('The customer purchased {batch_count} batch(es) of {item_count} item(s) during the last 12 months that expire between {expiry_start} and {expiry_end}. This does not confirm the customer still holds these quantities.',
                                ['batch_count' => (string) $x['batch_count'], 'item_count' => (string) $x['item_count'], 'expiry_start' => $x['expiry_start_label'], 'expiry_end' => $x['expiry_end_label']]) !!}</p>
                            <p>{!! $act.e('Contact the customer to review the previously purchased batches approaching expiry and confirm whether any quantities remain in their inventory. Coordinate with the relevant Item Owner when necessary.') !!}</p>
                        </article>
                    @endif
                </div>
                @if ($first = $ins['items'][0] ?? null)
                    <p class="c3-note" style="margin-top: 12px">{!! $rich('Based on Sales Item Performance as of {date}: last 60 days ({c0} – {d}) vs previous 60 days ({p0} – {p1}). Invoice lines only; credit notes are not counted as purchases.',
                        ['date' => F::dmy($first['as_of_date']), 'c0' => F::dmy($first['c0']), 'd' => F::dmy($first['as_of_date']), 'p0' => F::dmy($first['p0']), 'p1' => F::dmy($first['p1'])]) !!}</p>
                @endif
            </div>
        </section>
    @endif

    {{-- Credit Notes --}}
    @if ($ins['credit_notes'])
        <section class="c3-card c3-ins">
            <header class="c3-card-h"><i data-lucide="receipt-text" class="ico-18"></i><h2>Credit Notes</h2></header>
            <div class="c3-card-b"><div class="c3-ins-list">
                @foreach ($ins['credit_notes'] as $r)
                    @php
                        [$title, $whyTpl, $action] = $T['credit_notes'][$r['action_code']];
                        $v = ['invoice_count' => $num($r['invoices_90d']), 'credit_note_count' => $num($r['credit_notes_90d']), 'frequency' => $num($r['frequency_pct']),
                            'credit_note_value' => F::money($r['credit_note_value_90d']), 'gross_sales' => F::money($r['gross_sales_90d']), 'percentage' => $num($r['value_pct']),
                            'credit_note_count_30d' => $num($r['credit_notes_30d']), 'days_since_last_invoice' => $num($r['days_since_last_invoice']),
                            'last_credit_note_date' => F::dmy($r['last_credit_note_date']) ?: F::DASH, 'last_credit_note_value' => F::money($r['last_credit_note_value'])];
                    @endphp
                    <article><h3>{{ $title }}</h3><p>{!! $why.$rich($whyTpl, $v) !!}</p><p>{!! $act.e($action) !!}</p></article>
                @endforeach
            </div></div>
        </section>
    @endif

    {{-- Sales Orders --}}
    @if ($ins['sales_orders'])
        <section class="c3-card c3-ins">
            <header class="c3-card-h"><i data-lucide="clipboard-list" class="ico-18"></i><h2>Sales Orders</h2></header>
            <div class="c3-card-b"><div class="c3-ins-list">
                @foreach ($ins['sales_orders'] as $r)
                    @php [$title, $whyTpl, $action] = $T['sales_orders'][$r['action_code']]; @endphp
                    <article><h3>{{ $title }}</h3>
                        <p>{!! $why.$rich($whyTpl, ['count' => (string) $r['order_count'], 'oldest_days' => $r['oldest_days'] === null ? F::DASH : (string) $r['oldest_days']]) !!}</p>
                        <p>{!! $act.e($action) !!}</p></article>
                @endforeach
            </div></div>
        </section>
    @endif

    {{-- Profitability --}}
    @if ($ins['profitability'])
        <section class="c3-card c3-ins">
            <header class="c3-card-h"><i data-lucide="percent" class="ico-18"></i><h2>Profitability</h2></header>
            <div class="c3-card-b"><div class="c3-ins-list">
                @foreach ($ins['profitability'] as $r)
                    @php
                        [$title, $whyTpl, $action] = $T['profitability'][$r['action_code']];
                        $v = ['current_margin' => $r['current_margin_display'] ?? F::DASH, 'previous_margin' => $r['previous_margin_display'] ?? F::DASH,
                            'decline_pp' => $r['decline_pp_display'] ?? F::DASH, 'current_gp' => F::money($r['current_gp'])];
                    @endphp
                    <article><h3>{{ $title }}</h3><p>{!! $why.$rich($whyTpl, $v) !!}</p><p>{!! $act.e($action) !!}</p></article>
                @endforeach
            </div></div>
        </section>
    @endif

    {{-- Financial Health --}}
    @if ($fin)
        @php
            $fv = ['due' => F::money($fin['due_by_month_end']), 'overdue' => F::money($fin['overdue_amount']), 'month_end' => F::dmy($fin['month_end'])];
            $keys = [$fin['has_unapplied_credit']
                ? "Before requesting payment, ask the Finance Department to review and apply the customer's available credit against outstanding invoices, then arrange collection of the remaining balance due by {month_end}."
                : ($fin['overdue_amount'] > 0 ? 'Contact the customer to arrange collection of SAR {due} by {month_end}, including SAR {overdue} already overdue. Prioritize the overdue balance.'
                    : 'Contact the customer to arrange collection of SAR {due} by {month_end}.')];
            if ($fin['has_unapplied_credit'] && $fin['overdue_amount'] > 0) {
                $keys[] = 'Prioritize the overdue balance.';
            }
            $keys[] = ['Late' => "Follow up closely because of the customer's late collection behavior.", 'Very Late' => "Follow up closely because of the customer's very late collection behavior.",
                'Severe Delay' => "Follow up closely because of the customer's severe collection delays."][$fin['collection_classification'] ?? ''] ?? null;
            $keys[] = ['High' => 'Coordinate with the Collection Manager due to high AR risk.', 'RISK REVIEW' => "Request a review of the customer's risk classification with the responsible team.",
                'Medium' => 'Monitor the account closely.'][$fin['ar_risk'] ?? ''] ?? null;
            $keys[] = ['Early Month' => 'The customer typically pays early in the month (days 1–10). Plan follow-up accordingly.', 'Mid Month' => 'The customer typically pays in the middle of the month (days 11–20). Plan follow-up accordingly.',
                'Late Month' => 'The customer typically pays toward the end of the month (days 21–31). Plan follow-up accordingly.',
                'Random / Mixed' => 'The customer has no consistent payment timing pattern. Follow up regularly until collection is completed.'][$fin['payment_pattern'] ?? ''] ?? null;
            $actionHtml = implode(' ', array_map(fn ($k) => $rich($k, $fv), array_filter($keys)));
            $cls = fn ($v) => '<span dir="auto">'.e($v ?? 'Not Available').'</span>';
        @endphp
        <section class="c3-card c3-ins">
            <header class="c3-card-h"><i data-lucide="wallet" class="ico-18"></i><h2>Financial Health</h2></header>
            <div class="c3-card-b"><div class="c3-ins-list">
                @if (! $only || $only === 'monthly_collection_required')
                <article>
                    <h3>Monthly Collection Required</h3>
                    <div><p style="color: var(--c360-label)">Why:</p>
                        <ul class="c3-ins-ul">
                            <li><span>Due by Month-End:</span> {!! $rich('SAR {x}', ['x' => $fv['due']]) !!}</li>
                            <li><span>Already Overdue:</span> {!! $rich('SAR {x}', ['x' => $fv['overdue']]) !!}</li>
                            <li><span>Collection Classification:</span> {!! $cls($fin['collection_classification']) !!}</li>
                            <li><span>AR Risk Classification:</span> {!! $cls($fin['ar_risk']) !!}</li>
                            <li><span>Payment Pattern:</span> {!! $cls($fin['payment_pattern']) !!}</li>
                            <li><span>Collection Deadline:</span> <bdi dir="ltr" class="tnum">{{ $fv['month_end'] }}</bdi></li>
                        </ul></div>
                    <p>{!! $act.$actionHtml !!}</p>
                </article>
                @endif
                @if ($fin['has_unapplied_credit'] && (! $only || $only === 'customer_has_unapplied_credit'))
                    <article><h3>Customer Has Unapplied Credit</h3>
                        <p>{!! $why.e('The customer has available credit that may need to be applied against outstanding invoices.') !!}</p>
                        <p>{!! $act.e("Please contact the Finance Department to review and apply the customer's available credit against outstanding invoices before requesting payment.") !!}</p></article>
                @endif
            </div></div>
        </section>
    @endif

    {{-- Customer Documentation --}}
    @if ($ins['documentation'])
        <section class="c3-card c3-ins">
            <header class="c3-card-h"><i data-lucide="file-text" class="ico-18"></i><h2>Customer Documentation</h2></header>
            <div class="c3-card-b"><div class="c3-ins-list">
                @foreach ($ins['documentation'] as $r)
                    @php
                        [$title, $whyTpl, $action] = $T['documentation'][$r['action_code']];
                        $v = ['date' => F::dmy($r['value_date']) ?: F::DASH, 'days' => $r['remaining_days'] === null ? F::DASH : (string) $r['remaining_days'],
                            'elapsed' => $r['elapsed_days'] === null ? F::DASH : (string) $r['elapsed_days']];
                    @endphp
                    <article><h3>{{ $title }}</h3>
                        <p>{!! $why.$rich($whyTpl, $v) !!}</p>
                        @if ($r['action_code'] === 'finance_recommendation')<div style="word-break: break-word"><span dir="auto" style="white-space: pre-wrap">{{ $r['value_text'] }}</span></div>@endif
                        <p>{!! $act.$rich($action, $v) !!}</p></article>
                @endforeach
            </div></div>
        </section>
    @endif
</div>
@endif
