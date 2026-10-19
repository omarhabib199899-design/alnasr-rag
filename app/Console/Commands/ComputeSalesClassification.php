<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Replaces Zoho Analytics' "Sales Classification" Query Table (see
 * SyncCustomerClassifications, type=sales) with our own computation —
 * requested by Dr. Ahmed 2026-10-05 after a real, confirmed case (CUS-5):
 * the original formula compares the last 45 days against the PRECEDING 45
 * days only, so two consecutive weak periods can read as "Strong Growth"
 * even while the customer is in a genuine multi-month decline (real data:
 * 362K → 284K → 164K → 106K → 10K → 69K across 6 months, last invoice 27
 * days ago, yet classified "Strategic - Strong Growth" because 69K vs the
 * prior 45D's 35K is +96%, comfortably over the +25% threshold — both
 * numbers true, the comparison baseline just wasn't meaningful).
 *
 * Only ONE thing changes from the original Zoho SQL (full formula pasted
 * by the user 2026-10-05, matches this file's logic everywhere else
 * exactly): the Growth % baseline is no longer the immediately preceding
 * 45-day window — it's this customer's own last-12-months average,
 * prorated to a 45-day-equivalent (L12M Net Sales × 45/365). Explicit
 * user decision after discussing 6 vs 12 months: 12 was chosen specifically
 * because it covers a full seasonal cycle (a 6-month baseline could itself
 * land inside a seasonal trough and reproduce the same illusion one level
 * up) and because L12M Net Sales was already a field this system computes
 * for Customer Segment, keeping both numbers internally consistent.
 * Thresholds (Strong Growth >= 25%, Growing 10-25%, Stable -10% to 10%,
 * Declining -25% to -10%, Severe Decline < -25%), the Lifecycle rules
 * (New/Reactivated/Lost/No Sales, checked BEFORE the growth buckets, same
 * priority order as the original), and Customer Segment's Pareto
 * cumulative-revenue logic (Strategic/Key Account/Core/Emerging) are all
 * unchanged — confirmed explicitly in scope with the user, not assumed.
 *
 * Writes into the same customer_classifications table (type='sales') the
 * Zoho-sourced sync used to populate — CustomerController/the blade don't
 * need to change at all, they just read whichever source last wrote there.
 * See routes/console.php for why 'sales' was removed from
 * reports:sync-customer-classifications's schedule in favor of this.
 */
class ComputeSalesClassification extends Command
{
    protected $signature = 'reports:compute-sales-classification';

    protected $description = 'Compute Sales Classification ourselves from ar_ledger_entries (replaces the Zoho Analytics "sales" Query Table import)';

    private const STRONG_GROWTH = 25.0;

    private const GROWING = 10.0;

    private const STABLE_LOWER = -10.0;

    private const DECLINING_LOWER = -25.0;

    public function handle(): int
    {
        $today = Carbon::today();

        $windows = [
            'l12m_from' => $today->copy()->subDays(364)->toDateString(),
            'current45_from' => $today->copy()->subDays(44)->toDateString(),
            'windowb_from' => $today->copy()->subDays(134)->toDateString(),
            'windowb_to' => $today->copy()->subDays(45)->toDateString(),
            'last135_from' => $today->copy()->subDays(134)->toDateString(),
            'historical_before' => $today->copy()->subDays(134)->toDateString(),
        ];
        $todayStr = $today->toDateString();

        $this->info('Aggregating per-customer sales figures from ar_ledger_entries...');

        // Starts FROM customers (LEFT JOIN the ledger), not FROM
        // ar_ledger_entries — a customer with ZERO sales rows used to be
        // silently absent from this whole computation (an inner
        // GROUP-BY-only query can't produce a row for them), leaving them
        // stuck on "Not synced yet" forever even though the Lifecycle
        // logic below already has a real answer for them ('No Sales' status
        // + 'Dormant' segment, since L12M = 0). Confirmed live 2026-10-06,
        // CUS-1530/CUS-1531 (today's new customers, zero ar_ledger_entries
        // rows) — same structural gap Collection Classification had.
        $rows = DB::table('customers as c')
            ->leftJoin('ar_ledger_entries as e', function ($join) {
                $join->on('e.zoho_customer_id', '=', 'c.zoho_contact_id')
                    ->where('e.category', 'SALES')
                    ->whereIn('e.entity_type', ['invoice', 'creditnote']);
            })
            ->whereNotNull('c.zoho_contact_id')
            ->select('c.zoho_contact_id as zoho_customer_id')
            ->selectRaw('MAX(c.customer_number) as customer_number, MAX(c.customer_name) as customer_name')
            ->selectRaw("SUM(CASE WHEN e.transaction_date >= ? THEN e.statement_amount ELSE 0 END) as l12m_net_sales", [$windows['l12m_from']])
            ->selectRaw("SUM(CASE WHEN e.transaction_date >= ? THEN e.statement_amount ELSE 0 END) as current_45d_net_sales", [$windows['current45_from']])
            ->selectRaw('MIN(CASE WHEN e.entity_type = \'invoice\' AND e.statement_amount > 0 THEN e.transaction_date ELSE NULL END) as first_positive_invoice_date')
            ->selectRaw('MAX(CASE WHEN e.entity_type = \'invoice\' AND e.statement_amount > 0 THEN e.transaction_date ELSE NULL END) as last_positive_invoice_date')
            ->selectRaw('SUM(CASE WHEN e.entity_type = \'invoice\' AND e.statement_amount > 0 THEN 1 ELSE 0 END) as total_positive_invoice_count')
            ->selectRaw("SUM(CASE WHEN e.entity_type = 'invoice' AND e.statement_amount > 0 AND e.transaction_date >= ? THEN 1 ELSE 0 END) as current_45d_invoice_count", [$windows['current45_from']])
            ->selectRaw("SUM(CASE WHEN e.entity_type = 'invoice' AND e.statement_amount > 0 AND e.transaction_date BETWEEN ? AND ? THEN 1 ELSE 0 END) as windowb_invoice_count", [$windows['windowb_from'], $windows['windowb_to']])
            ->selectRaw("SUM(CASE WHEN e.entity_type = 'invoice' AND e.statement_amount > 0 AND e.transaction_date < ? THEN 1 ELSE 0 END) as historical_invoice_count", [$windows['historical_before']])
            ->selectRaw("SUM(CASE WHEN e.entity_type = 'invoice' AND e.statement_amount > 0 AND e.transaction_date >= ? THEN 1 ELSE 0 END) as last135_invoice_count", [$windows['last135_from']])
            ->groupBy('c.zoho_contact_id')
            ->get();

        $this->info('Customers aggregated: '.$rows->count());

        // --- Customer Segment: company-wide Pareto ranking on positive L12M Net Sales ---
        // Ties (identical L12M Net Sales) break arbitrarily rather than by
        // customer_number like the original SQL — negligible real-world
        // impact (exact monetary ties are rare) and not worth the risk of
        // getting Laravel's multi-key sort syntax subtly wrong on stdClass
        // rows.
        $ranked = $rows->filter(fn ($r) => (float) $r->l12m_net_sales > 0)
            ->sortByDesc(fn ($r) => (float) $r->l12m_net_sales)
            ->values();
        $grandTotal = $ranked->sum(fn ($r) => (float) $r->l12m_net_sales);
        $cumulativeBefore = [];
        $running = 0.0;
        foreach ($ranked as $r) {
            $cumulativeBefore[$r->zoho_customer_id] = $grandTotal > 0 ? ($running / $grandTotal) * 100 : 0;
            $running += (float) $r->l12m_net_sales;
        }

        $now = now();
        $batch = [];

        foreach ($rows as $r) {
            $l12m = (float) $r->l12m_net_sales;
            $current45 = (float) $r->current_45d_net_sales;

            $segment = match (true) {
                $l12m < 0 => 'Review / Negative Net Sales',
                $l12m == 0.0 => 'Dormant',
                ($cumulativeBefore[$r->zoho_customer_id] ?? 100) < 50 => 'Strategic',
                ($cumulativeBefore[$r->zoho_customer_id] ?? 100) < 80 => 'Key Account',
                ($cumulativeBefore[$r->zoho_customer_id] ?? 100) < 95 => 'Core',
                default => 'Emerging',
            };

            // 45-day-equivalent share of the last-12-months average —
            // explicit user decision 2026-10-05 (see class docblock), the
            // one formula change from the original Zoho SQL.
            $baseline45 = $l12m * 45 / 365;
            $growthPct = $baseline45 > 0 ? round((($current45 - $baseline45) / $baseline45) * 100, 2) : null;

            $isNew = $r->first_positive_invoice_date !== null
                && $r->first_positive_invoice_date >= $windows['current45_from'] && $r->first_positive_invoice_date <= $todayStr;
            $isReactivated = (int) $r->current_45d_invoice_count > 0
                && (int) $r->windowb_invoice_count === 0
                && (int) $r->historical_invoice_count > 0;
            $isLost = (int) $r->last135_invoice_count === 0 && (int) $r->historical_invoice_count > 0;
            $isNoSales = (int) $r->total_positive_invoice_count === 0;

            // Order matters — first match wins, same priority as the
            // original SQL's CASE: Lifecycle states first (growth % is
            // meaningless for a brand-new or dormant-then-returned
            // customer), then the growth-bucket ladder from the top down.
            $status = match (true) {
                $isNew => 'New',
                $isReactivated => 'Reactivated',
                $isLost => 'Lost',
                $isNoSales => 'No Sales',
                $growthPct === null => 'Review - No Positive Baseline',
                $growthPct >= self::STRONG_GROWTH => 'Strong Growth',
                $growthPct >= self::GROWING => 'Growing',
                $growthPct > self::STABLE_LOWER => 'Stable',
                $growthPct >= self::DECLINING_LOWER => 'Declining',
                default => 'Severe Decline',
            };

            $classification = "{$segment} - {$status}";

            $batch[] = [
                'customer_number' => $r->customer_number,
                'customer_name' => $r->customer_name,
                'type' => 'sales',
                'classification' => $classification,
                'data' => json_encode([
                    'l12m_net_sales' => round($l12m, 2),
                    'current_45d_net_sales' => round($current45, 2),
                    'baseline_45d_equivalent' => round($baseline45, 2),
                    'growth_pct' => $growthPct,
                    'first_positive_invoice_date' => $r->first_positive_invoice_date,
                    'last_positive_invoice_date' => $r->last_positive_invoice_date,
                    'segment' => $segment,
                    'status' => $status,
                ]),
                'synced_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($batch, 500) as $chunk) {
            DB::table('customer_classifications')->upsert(
                $chunk,
                ['customer_number', 'type'],
                ['customer_name', 'classification', 'data', 'synced_at', 'updated_at']
            );
        }

        $this->info('Done — '.count($batch).' sales classifications computed and written.');

        return self::SUCCESS;
    }
}
