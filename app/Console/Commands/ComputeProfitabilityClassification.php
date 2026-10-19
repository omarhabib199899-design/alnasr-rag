<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Replaces Zoho Analytics' "Profitability Classification" Query Table (see
 * SyncCustomerClassifications, type=profitability) with our own computation
 * — same request as Sales/Documents (2026-10-06), full SQL pasted by the
 * user matches this file's logic exactly, down to the day-window
 * boundaries.
 *
 * Source rows: ar_ledger_entries, category=SALES, entity_type=invoice only
 * (the original SQL's "Profitability_Staging" is built per invoice line
 * item with its own FIFO COGS calculation — we don't have that staging
 * layer, but ar_ledger_entries.sales_exc_vat/cogs/gross_profit are the same
 * Zoho-computed figures at the invoice level, already confirmed reliable
 * ON INVOICE ROWS specifically during today's earlier Gross Profit fix —
 * the unreliable-gross_profit problem that fix solved was specific to
 * CREDIT NOTE rows, which this classification's source table never
 * included in the first place). Credit notes are deliberately NOT included
 * here, matching the original SQL's scope exactly.
 *
 * LEFT JOIN from customers (not FROM ar_ledger_entries) for the same reason
 * as Sales Classification's own fix earlier today — a customer with zero
 * qualifying invoice rows still needs a row written (falls through to "No
 * Data"/"No Sales L6M" below), not silently absent from the whole command.
 *
 * Two outputs, concatenated with ' - ':
 * Profitability Level  — health tier from the L6M margin % (or Historical
 *   margin % if no L6M activity): <=3% Loss, <10% Low, <15% Healthy, else
 *   High (plus explicit Loss when margin is negative on zero sales).
 * Profitability Trend  — momentum: Current 45D margin % vs Previous 135D
 *   margin % (together = L6M), compared in PERCENTAGE-POINT difference, not
 *   relative %: >=+2pp Growing, >-2pp Stable, >=-5pp Declining, else Severe
 *   Decline. Special cases for a customer with sales in only one of the two
 *   sub-windows (New/Reactivated vs No Recent Sales) or neither (No Sales
 *   L6M).
 */
class ComputeProfitabilityClassification extends Command
{
    protected $signature = 'reports:compute-profitability-classification';

    protected $description = 'Compute Profitability Classification ourselves from ar_ledger_entries (replaces the Zoho Analytics "profitability" Query Table import)';

    public function handle(): int
    {
        $today = Carbon::today();

        $windows = [
            'l6m_from' => $today->copy()->subDays(179)->toDateString(),
            'historical_to' => $today->copy()->subDays(180)->toDateString(),
            'current45_from' => $today->copy()->subDays(44)->toDateString(),
            'prev135_from' => $today->copy()->subDays(179)->toDateString(),
            'prev135_to' => $today->copy()->subDays(45)->toDateString(),
        ];

        $this->info('Aggregating per-customer profitability figures from ar_ledger_entries...');

        $rows = DB::table('customers as c')
            ->leftJoin('ar_ledger_entries as e', function ($join) {
                $join->on('e.zoho_customer_id', '=', 'c.zoho_contact_id')
                    ->where('e.category', 'SALES')
                    ->where('e.entity_type', 'invoice')
                    // Matches the original SQL's WHERE NOT (sales=0 AND
                    // cogs=0) — drops degenerate rows with nothing in
                    // either field, keeps anything with a real value in
                    // either.
                    ->where(function ($q) {
                        $q->where('e.sales_exc_vat', '!=', 0)->orWhere('e.cogs', '!=', 0);
                    });
            })
            ->whereNotNull('c.zoho_contact_id')
            ->select('c.zoho_contact_id as zoho_customer_id')
            ->selectRaw('MAX(c.customer_number) as customer_number, MAX(c.customer_name) as customer_name')
            ->selectRaw("SUM(CASE WHEN e.transaction_date >= ? THEN e.sales_exc_vat ELSE 0 END) as l6m_sales", [$windows['l6m_from']])
            ->selectRaw("SUM(CASE WHEN e.transaction_date >= ? THEN e.gross_profit ELSE 0 END) as l6m_gp", [$windows['l6m_from']])
            ->selectRaw("SUM(CASE WHEN e.transaction_date <= ? THEN e.sales_exc_vat ELSE 0 END) as historical_sales", [$windows['historical_to']])
            ->selectRaw("SUM(CASE WHEN e.transaction_date <= ? THEN e.gross_profit ELSE 0 END) as historical_gp", [$windows['historical_to']])
            ->selectRaw("SUM(CASE WHEN e.transaction_date >= ? THEN e.sales_exc_vat ELSE 0 END) as current45_sales", [$windows['current45_from']])
            ->selectRaw("SUM(CASE WHEN e.transaction_date >= ? THEN e.gross_profit ELSE 0 END) as current45_gp", [$windows['current45_from']])
            ->selectRaw("SUM(CASE WHEN e.transaction_date BETWEEN ? AND ? THEN e.sales_exc_vat ELSE 0 END) as prev135_sales", [$windows['prev135_from'], $windows['prev135_to']])
            ->selectRaw("SUM(CASE WHEN e.transaction_date BETWEEN ? AND ? THEN e.gross_profit ELSE 0 END) as prev135_gp", [$windows['prev135_from'], $windows['prev135_to']])
            ->selectRaw("SUM(CASE WHEN e.transaction_date >= ? AND IFNULL(e.cogs, 0) = 0 AND e.sales_exc_vat > 0 THEN 1 ELSE 0 END) as l6m_zero_cogs_count", [$windows['l6m_from']])
            ->selectRaw("SUM(CASE WHEN e.transaction_date >= ? AND IFNULL(e.cogs, 0) = 0 AND e.sales_exc_vat > 0 THEN e.sales_exc_vat ELSE 0 END) as l6m_zero_cogs_sales", [$windows['l6m_from']])
            ->groupBy('c.zoho_contact_id')
            ->get();

        $this->info('Customers aggregated: '.$rows->count());

        $now = now();
        $batch = [];

        foreach ($rows as $r) {
            $l6mSales = (float) $r->l6m_sales;
            $l6mGp = (float) $r->l6m_gp;
            $historicalSales = (float) $r->historical_sales;
            $historicalGp = (float) $r->historical_gp;
            $current45Sales = (float) $r->current45_sales;
            $current45Gp = (float) $r->current45_gp;
            $prev135Sales = (float) $r->prev135_sales;
            $prev135Gp = (float) $r->prev135_gp;

            $tier = function (float $marginPct): string {
                return match (true) {
                    $marginPct <= 3 => 'Loss',
                    $marginPct < 10 => 'Low',
                    $marginPct < 15 => 'Healthy',
                    default => 'High',
                };
            };

            $level = match (true) {
                $l6mSales > 0 => $tier($l6mGp / $l6mSales * 100),
                $l6mSales == 0.0 && $l6mGp < 0 => 'Loss',
                $historicalSales > 0 => $tier($historicalGp / $historicalSales * 100),
                $historicalSales == 0.0 && $historicalGp < 0 => 'Loss',
                default => 'No Data',
            };

            $trend = match (true) {
                $current45Sales > 0 && $prev135Sales > 0 => match (true) {
                    ($current45Gp / $current45Sales * 100) - ($prev135Gp / $prev135Sales * 100) >= 2 => 'Growing',
                    ($current45Gp / $current45Sales * 100) - ($prev135Gp / $prev135Sales * 100) > -2 => 'Stable',
                    ($current45Gp / $current45Sales * 100) - ($prev135Gp / $prev135Sales * 100) >= -5 => 'Declining',
                    default => 'Severe Decline',
                },
                $current45Sales > 0 && $prev135Sales == 0.0 => 'New / Reactivated Profitability',
                $current45Sales == 0.0 && $prev135Sales > 0 => 'No Recent Sales',
                $current45Sales == 0.0 && $prev135Sales == 0.0 => 'No Sales L6M',
                default => 'No Data',
            };

            $zeroCogsCount = (int) $r->l6m_zero_cogs_count;
            $zeroCogsAudit = $zeroCogsCount > 0 ? 'REVIEW ZERO COGS' : 'OK';

            // trend='No Sales L6M' is the one case where $level falls all
            // the way back to historicalSales (>180 days stale) or 'No
            // Data' — confirmed live 2026-10-06 (manager report, CUS-128):
            // a customer dormant 14 months still showed "Healthy - No Sales
            // L6M" in green, because Healthy described their margin from
            // over 6 months ago, not anything current. That historical tier
            // isn't wrong, it's just not current — misleading as the
            // headline label, so it's dropped from the displayed
            // classification for this one trend value only (still saved
            // in full below, under 'level'/'trend' in `data`, for anyone
            // who wants it). Every other trend (Growing/Stable/Declining/
            // Severe Decline/New-Reactivated/No Recent Sales) keeps
            // pairing level+trend — those all have real L6M-window activity
            // backing $level, unlike this one case.
            $classification = $trend === 'No Sales L6M' ? $trend : "{$level} - {$trend}";

            $batch[] = [
                'customer_number' => $r->customer_number,
                'customer_name' => $r->customer_name,
                'type' => 'profitability',
                'classification' => $classification,
                'data' => json_encode([
                    'l6m_sales' => round($l6mSales, 2),
                    'l6m_gross_profit' => round($l6mGp, 2),
                    'l6m_gross_margin_pct' => $l6mSales > 0 ? round($l6mGp / $l6mSales * 100, 2) : null,
                    'historical_sales' => round($historicalSales, 2),
                    'historical_gross_profit' => round($historicalGp, 2),
                    'current_45d_sales' => round($current45Sales, 2),
                    'current_45d_gross_profit' => round($current45Gp, 2),
                    'current_45d_margin_pct' => $current45Sales > 0 ? round($current45Gp / $current45Sales * 100, 2) : null,
                    'previous_135d_sales' => round($prev135Sales, 2),
                    'previous_135d_gross_profit' => round($prev135Gp, 2),
                    'previous_135d_margin_pct' => $prev135Sales > 0 ? round($prev135Gp / $prev135Sales * 100, 2) : null,
                    'l6m_zero_cogs_invoice_count' => $zeroCogsCount,
                    'l6m_zero_cogs_sales' => round((float) $r->l6m_zero_cogs_sales, 2),
                    'zero_cogs_audit' => $zeroCogsAudit,
                    'level' => $level,
                    'trend' => $trend,
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

        $this->info('Done — '.count($batch).' profitability classifications computed and written.');

        return self::SUCCESS;
    }
}
