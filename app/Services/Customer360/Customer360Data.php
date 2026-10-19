<?php

namespace App\Services\Customer360;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Port of the Al-Nasr Lovable portal's Customer 360 / Entity 360 server
 * functions (public.* in their Supabase project — see the export kept in
 * the session scratchpad, customer360_full_answers.md + lovable_functions.sql)
 * onto our copies of the same data: zoho_ar_sales, zoho_sales_item_performance,
 * zoho_sales_orders_not_invoiced, zoho_raw_customers / zoho_raw_items and the
 * classification outputs (all produced by zoho:sync-raw + zoho:compute-queries
 * from Dr. Ahmed's own queries). Manager directive 2026-10-19: their formulas
 * and data sources, exactly.
 *
 * Every method mirrors one of their functions: same periods (Asia/Riyadh
 * "today", 2024-04-01 cutoff), same filters, same rounding (PHP round() and
 * Postgres round(numeric) both round half away from zero), same NULL rules.
 * scope/value work like theirs: 'customer_id' + the Zoho customer id, or an
 * entity field (rep_name / management / sales_owner / data_entry) + its exact
 * stored value, so the Entity 360 pages can reuse this later.
 */
class Customer360Data
{
    public const D0 = '2024-04-01';

    public const SCOPES = ['customer_id', 'rep_name', 'management', 'sales_owner', 'data_entry'];

    private const AR = 'zoho_ar_sales';

    private const CUSTOMERS = 'zoho_raw_customers';

    private const SIP = 'zoho_sales_item_performance';

    private Carbon $today;

    private ?array $pf = null;

    public function __construct(private readonly string $scope, private readonly string $value)
    {
        if (! in_array($scope, self::SCOPES, true)) {
            throw new \InvalidArgumentException('invalid scope');
        }
        // Riyadh's calendar date, as midnight in the app timezone — so every Carbon::parse('Y-m-d') below is in the
        // same timezone and day differences are whole days.
        $this->today = Carbon::parse(Carbon::now('Asia/Riyadh')->toDateString());
    }

    public function today(): string
    {
        return $this->today->toDateString();
    }

    /* ───────────────────────── shared helpers ───────────────────────── */

    /** AR SALES is computed by zoho:compute-queries; until its first run the tabs that read it show their empty state. */
    private function arReady(): bool
    {
        static $ready = null;

        return $ready ??= Schema::hasTable(self::AR);
    }

    /** Customer ids in the scope (exact stored value of the scope field). */
    public function portfolio(): array
    {
        return $this->pf ??= DB::table(self::CUSTOMERS)
            ->where($this->scope, $this->value)
            ->pluck('customer_id')->map(fn ($id) => (string) $id)->all();
    }

    /** The seven reporting periods: key, order, start (>= 2024-04-01), end, Avg/Median granularity. */
    public function periods(): array
    {
        $d = $this->today;
        $raw = [
            ['lifetime', 1, Carbon::parse(self::D0), $d, 'month'],
            ['previous_year', 2, $d->copy()->subYear()->startOfYear(), $d->copy()->subYear()->endOfYear()->startOfDay(), 'month'],
            ['ytd', 3, $d->copy()->startOfYear(), $d, 'month'],
            ['previous_month', 4, $d->copy()->startOfMonth()->subMonth(), $d->copy()->startOfMonth()->subDay(), 'day'],
            ['mtd', 5, $d->copy()->startOfMonth(), $d, 'day'],
            ['yesterday', 6, $d->copy()->subDay(), $d->copy()->subDay(), 'none'],
            ['today', 7, $d, $d, 'none'],
        ];
        $d0 = Carbon::parse(self::D0);

        return array_map(fn ($p) => [
            'k' => $p[0], 'o' => $p[1], 's' => ($p[2]->lt($d0) ? $d0 : $p[2])->toDateString(), 'e' => $p[3]->toDateString(), 'gran' => $p[4],
        ], $raw);
    }

    /** First day of each of the rolling 12 calendar months (current month last). */
    private function months12(): array
    {
        $out = [];
        for ($i = 11; $i >= 0; $i--) {
            $out[] = $this->today->copy()->startOfMonth()->subMonthsNoOverflow($i)->toDateString();
        }

        return $out;
    }

    private static function d(?string $v): ?string
    {
        return $v === null ? null : substr($v, 0, 10);
    }

    private static function r(?float $v, int $p = 2): ?float
    {
        return $v === null ? null : round($v, $p);
    }

    private static function inRange(string $d, string $s, string $e): bool
    {
        return $d >= $s && $d <= $e;
    }

    private static function monthStart(string $d): string
    {
        return substr($d, 0, 7).'-01';
    }

    /**
     * Their Average / Median / Volatility statistics for one period, given this
     * scope's dated amounts ($rows: [date, amount]) and its first activity date.
     *
     * obs (Median, Volatility): zero-filled calendar-month buckets from
     * max(first activity month, period start month) to min(period end month,
     * month before today's) — the current month never counts; or zero-filled
     * days from max(first activity, period start) to period end for daily
     * periods. avg_obs (Average): non-zero month/day buckets inside the period,
     * current month included.
     *
     * @return array{av: ?float, med: ?float, vol: ?float, n: int}
     */
    private function bucketStats(array $p, array $rows, ?string $firstAct): array
    {
        if ($p['gran'] === 'none') {
            return ['av' => null, 'med' => null, 'vol' => null, 'n' => 0];
        }

        $byMonth = [];
        $byDay = [];
        $avgBuckets = [];
        foreach ($rows as [$td, $amt]) {
            $m = self::monthStart($td);
            $byMonth[$m] = ($byMonth[$m] ?? 0) + $amt;
            $byDay[$td] = ($byDay[$td] ?? 0) + $amt;
            if (self::inRange($td, $p['s'], $p['e'])) {
                $b = $p['gran'] === 'month' ? $m : $td;
                $avgBuckets[$b] = ($avgBuckets[$b] ?? 0) + $amt;
            }
        }

        $nonZero = array_filter($avgBuckets, fn ($v) => $v != 0);
        $av = $nonZero ? array_sum($nonZero) / count($nonZero) : null;

        $obs = [];
        if ($firstAct !== null) {
            if ($p['gran'] === 'month') {
                $from = max(self::monthStart($firstAct), self::monthStart($p['s']));
                $to = min(self::monthStart($p['e']), $this->today->copy()->startOfMonth()->subMonthNoOverflow()->toDateString());
                for ($m = Carbon::parse($from); $m->toDateString() <= $to; $m->addMonthNoOverflow()) {
                    $obs[] = $byMonth[$m->toDateString()] ?? 0;
                }
            } else {
                $from = max($firstAct, $p['s']);
                for ($day = Carbon::parse($from); $day->toDateString() <= $p['e']; $day->addDay()) {
                    $obs[] = $byDay[$day->toDateString()] ?? 0;
                }
            }
        }

        $n = count($obs);
        $med = null;
        $vol = null;
        if ($n > 0) {
            sort($obs);
            $a = intdiv($n + 1, 2);
            $b = intdiv($n + 2, 2);
            $med = ($obs[$a - 1] + $obs[$b - 1]) / 2; // rn IN ((c+1)/2, (c+2)/2): one middle value (odd) or two (even)
            $mean = array_sum($obs) / $n;
            $sd = sqrt(array_sum(array_map(fn ($v) => ($v - $mean) ** 2, $obs)) / $n);
            if ($p['gran'] === 'month' && $n >= 3 && $mean > 0) {
                $vol = round($sd / $mean * 100, 1);
            }
        }

        return ['av' => self::r($av), 'med' => self::r($med), 'vol' => $vol, 'n' => $n];
    }

    /** rank() among positive values (ties share a rank) + how many are positive; null rank when $mine <= 0 or absent. */
    private static function rankOf(?float $mine, array $values): array
    {
        $positive = array_filter($values, fn ($v) => $v > 0);
        if ($mine === null || $mine <= 0) {
            return [null, $positive ? count($positive) : null];
        }

        return [1 + count(array_filter($positive, fn ($v) => $v > $mine)), count($positive)];
    }

    /**
     * Per period, the sum of $amountExpr over $where grouped by $groupExpr (company-wide peers).
     *
     * @return array<string, array<string, float>> period key => group => sum
     */
    private function periodSums(string $from, string $where, array $bindings, string $groupExpr, string $amountExpr, string $dateExpr = 'transaction_date'): array
    {
        $periods = $this->periods();
        $cols = [];
        $b = [];
        foreach ($periods as $p) {
            $cols[] = "SUM(CASE WHEN {$dateExpr} BETWEEN ? AND ? THEN {$amountExpr} END) AS `{$p['k']}`";
            array_push($b, $p['s'], $p['e']);
        }
        $rows = DB::select('SELECT '.$groupExpr.' AS grp, '.implode(', ', $cols)." FROM {$from} WHERE {$where} GROUP BY grp", array_merge($b, $bindings));

        $out = array_fill_keys(array_column($periods, 'k'), []);
        foreach ($rows as $row) {
            foreach ($periods as $p) {
                if ($row->{$p['k']} !== null) {
                    $out[$p['k']][(string) $row->grp] = (float) $row->{$p['k']};
                }
            }
        }

        return $out;
    }

    /** Peer key expression for entity ranks: customer id, or the scope field's value on zoho_raw_customers (NULL/blank = not an entity). */
    private function peerJoin(string $alias = 'a'): array
    {
        $col = $this->scope === 'customer_id' ? 'c.customer_id' : "c.`{$this->scope}`";

        return [
            "{$this->tableAs(self::AR, $alias)} JOIN ".self::CUSTOMERS." c ON c.customer_id = {$alias}.customer_id",
            "NULLIF(TRIM({$col}), '') IS NOT NULL",
            $col,
        ];
    }

    private function tableAs(string $table, string $alias): string
    {
        return "{$table} {$alias}";
    }

    private function inPf(): array
    {
        $ids = $this->portfolio();

        return [$ids ? implode(',', array_fill(0, count($ids), '?')) : 'NULL', $ids];
    }

    /** This scope's AR SALES rows matching a WHERE fragment. */
    private function mine(string $where, array $bindings = [], string $cols = '*'): array
    {
        [$ph, $ids] = $this->inPf();

        return DB::select("SELECT {$cols} FROM ".self::AR." WHERE customer_id IN ({$ph}) AND {$where}", array_merge($ids, $bindings));
    }

    /* ───────────────────────── Overview ───────────────────────── */

    /** customer_overview — customer fields + classifications + relation time. */
    public function overview(): ?array
    {
        $c = DB::table(self::CUSTOMERS)->where('customer_id', $this->value)->first();
        if ($c === null) {
            return null;
        }
        $row = (array) $c;
        $num = $c->customer_number;
        // A classification output not computed yet (e.g. before its first run) shows as "—", like a customer with no row.
        $lookup = fn (string $table, string $col) => Schema::hasTable($table) ? DB::table($table)->where('customer_number', $num)->value($col) : null;
        $row['sales_classification'] = $lookup('zoho_sales_classification', 'sales_classification');
        $row['collection_classification'] = $lookup('zoho_collection_classification', 'collection_classification');
        $row['payment_timing_pattern'] = $lookup('zoho_collection_classification', 'payment_timing_pattern');
        $row['profitability_classification'] = $lookup('zoho_profitability_classification', 'profitability_classification');
        $row['documents_classification'] = $lookup('zoho_documents_classification', 'documents_classification');
        $row['final_risk'] = $lookup('zoho_ar_risk_classification', 'final_risk');

        $row['relation_years'] = $row['relation_months'] = null;
        if ($c->created_time) {
            $age = Carbon::parse(self::d($c->created_time))->diff($this->today);
            $row['relation_years'] = $age->y;
            $row['relation_months'] = $age->m;
        }

        return $row;
    }

    /** portfolio_target_achievement — Sales & Collection Target & Achievement (Annual = month_no null). */
    public function targetAchievement(): array
    {
        if (! $this->arReady()) {
            return [];
        }
        $pf = DB::table(self::CUSTOMERS)->where($this->scope, $this->value)->get();
        if ($pf->isEmpty()) {
            return [];
        }
        $y = (int) $this->today->year;
        $cm = (int) $this->today->month;
        $mon = ['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'];

        [$ph, $ids] = $this->inPf();
        $act = [];
        foreach (DB::select('SELECT CASE WHEN category = \'SALES\' THEN \'sales\' ELSE \'collection\' END kind, MONTH(transaction_date) m, SUM(kpi_amount) a
            FROM '.self::AR." WHERE customer_id IN ({$ph}) AND transaction_date >= ? AND transaction_date <= ?
            AND ((category = 'SALES' AND entity_type IN ('invoice','creditnote') AND transaction_date >= ?) OR category = 'COLLECTION')
            GROUP BY kind, m", array_merge($ids, ["{$y}-01-01", $this->today(), self::D0])) as $r) {
            $act[$r->kind][(int) $r->m] = (float) $r->a;
        }

        $out = [];
        foreach (['sales', 'collection'] as $kind) {
            foreach (array_merge(range(1, 12), [0]) as $m) {
                $field = $m === 0 ? "annual_{$kind}_tgt" : "{$mon[$m - 1]}_{$kind}_tgt";
                $vals = $pf->pluck($field)->filter(fn ($v) => $v !== null);
                $t = $vals->isEmpty() ? null : (float) $vals->sum();
                $fut = $m > $cm;
                $a = $fut ? null : ($m === 0 ? array_sum($act[$kind] ?? []) : ($act[$kind][$m] ?? 0.0));
                $out[] = [
                    'kind' => $kind, 'month_no' => $m === 0 ? null : $m, 'report_year' => $y,
                    'is_current_month' => $m > 0 && $m === $cm, 'is_future' => $fut,
                    'target' => $t, 'achievement' => $a,
                    'ach_pct' => ($fut || $t === null || $t == 0) ? null : round($a / $t * 100, 2),
                    'balance' => ($fut || $t === null) ? null : $t - $a,
                    'target_customers' => $vals->count(), 'portfolio_customers' => $pf->count(), 'as_of_date' => $this->today(),
                ];
            }
        }

        return $out;
    }

    /* ───────────────────────── Sales ───────────────────────── */

    /** customer_sales_kpis (Customer 360). */
    public function salesKpis(): array
    {
        if (! $this->arReady()) {
            return [];
        }
        $mine = $this->mine("category = 'SALES' AND entity_type IN ('invoice','creditnote') AND transaction_date >= ?", [self::D0],
            'transaction_id, transaction_date, entity_type, kpi_amount');
        $rows = array_map(fn ($r) => [self::d($r->transaction_date), (float) $r->kpi_amount], $mine);
        $firstAct = $rows ? min(array_column($rows, 0)) : null;

        $invoices = [];
        foreach ($mine as $r) {
            if ($r->entity_type === 'invoice') {
                $id = (string) $r->transaction_id;
                $invoices[$id]['td'] = min($invoices[$id]['td'] ?? '9999', self::d($r->transaction_date));
                $invoices[$id]['amt'] = ($invoices[$id]['amt'] ?? 0) + (float) $r->kpi_amount;
            }
        }

        $company = $this->periodSums(self::AR, "category = 'SALES' AND entity_type IN ('invoice','creditnote') AND transaction_date >= ?",
            [self::D0], 'customer_id', 'kpi_amount');

        $out = [];
        foreach ($this->periods() as $p) {
            $net = 0.0;
            $gross = 0.0;
            foreach ($mine as $r) {
                if (self::inRange(self::d($r->transaction_date), $p['s'], $p['e'])) {
                    $net += (float) $r->kpi_amount;
                    $gross += $r->entity_type === 'invoice' ? (float) $r->kpi_amount : 0;
                }
            }
            $inv = array_filter($invoices, fn ($i) => self::inRange($i['td'], $p['s'], $p['e']) && $i['amt'] > 0);
            $cnt = count($inv);
            $total = array_sum($company[$p['k']]);
            $myNet = $company[$p['k']][$this->value] ?? null;
            [$rank, $rankTotal] = self::rankOf($myNet, $company[$p['k']]);
            $st = $this->bucketStats($p, $rows, $firstAct);

            $out[] = [
                'period_key' => $p['k'], 'period_start' => $p['s'], 'period_end' => $p['e'],
                'net_sales' => $net, 'gross_sales' => $gross,
                'sales_average' => $st['av'], 'sales_median' => $st['med'], 'revenue_volatility_pct' => $st['vol'],
                'revenue_contribution_pct' => $total > 0 ? round($net / $total * 100, 2) : null,
                'revenue_rank' => $rank, 'revenue_rank_total' => $rank === null ? null : $rankTotal,
                'invoice_count' => $cnt,
                'average_invoice_value' => $cnt > 0 ? round($gross / $cnt, 2) : null,
                'largest_invoice' => $cnt > 0 ? max(array_column($inv, 'amt')) : null,
                'observations' => $st['n'], 'first_sales_activity' => $firstAct, 'as_of_date' => $this->today(),
            ];
        }

        return $out;
    }

    /**
     * portfolio_sales_kpis (Entity 360) — customer_sales_kpis for the scope's customers, except: Revenue Rank compares
     * same-type entity portfolios (customers whose scope field is NULL/blank are not an entity), and there is no
     * Average Invoice Value / Largest Invoice.
     */
    public function portfolioSalesKpis(): array
    {
        if (! $this->arReady() || ! $this->portfolio()) {
            return [];
        }
        $where = "category = 'SALES' AND entity_type IN ('invoice','creditnote') AND transaction_date >= ?";
        $mine = $this->mine($where, [self::D0], 'transaction_id, transaction_date, entity_type, kpi_amount');
        $rows = array_map(fn ($r) => [self::d($r->transaction_date), (float) $r->kpi_amount], $mine);
        $firstAct = $rows ? min(array_column($rows, 0)) : null;

        $invoices = [];
        foreach ($mine as $r) {
            if ($r->entity_type === 'invoice') {
                $id = (string) $r->transaction_id;
                $invoices[$id]['td'] = min($invoices[$id]['td'] ?? '9999', self::d($r->transaction_date));
                $invoices[$id]['amt'] = ($invoices[$id]['amt'] ?? 0) + (float) $r->kpi_amount;
            }
        }

        $company = $this->periodSums(self::AR, $where, [self::D0], "'all'", 'kpi_amount');
        [$from, $entWhere, $entCol] = $this->peerJoin();
        $peers = $this->periodSums($from, "a.category = 'SALES' AND a.entity_type IN ('invoice','creditnote') AND a.transaction_date >= ? AND {$entWhere}",
            [self::D0], $entCol, 'a.kpi_amount', 'a.transaction_date');

        $out = [];
        foreach ($this->periods() as $p) {
            $net = $gross = 0.0;
            foreach ($mine as $r) {
                if (self::inRange(self::d($r->transaction_date), $p['s'], $p['e'])) {
                    $net += (float) $r->kpi_amount;
                    $gross += $r->entity_type === 'invoice' ? (float) $r->kpi_amount : 0;
                }
            }
            $ct = $company[$p['k']]['all'] ?? null;
            [$rank, $rankTotal] = self::rankOf($peers[$p['k']][$this->value] ?? null, $peers[$p['k']]);
            $st = $this->bucketStats($p, $rows, $firstAct);

            $out[] = [
                'period_key' => $p['k'], 'period_start' => $p['s'], 'period_end' => $p['e'],
                'net_sales' => $net, 'gross_sales' => $gross,
                'sales_average' => $st['av'], 'sales_median' => $st['med'], 'revenue_volatility_pct' => $st['vol'],
                'revenue_contribution_pct' => ($ct !== null && $ct > 0) ? round($net / $ct * 100, 2) : null,
                'revenue_rank' => $rank, 'revenue_rank_total' => $rank === null ? null : $rankTotal,
                'invoice_count' => count(array_filter($invoices, fn ($i) => self::inRange($i['td'], $p['s'], $p['e']) && $i['amt'] > 0)),
                'observations' => $st['n'], 'first_sales_activity' => $firstAct,
                'portfolio_customers' => count($this->portfolio()), 'as_of_date' => $this->today(),
            ];
        }

        return $out;
    }

    /** customer_sales_monthly_12m / portfolio_sales_monthly_12m (same months and formulas; empty for an empty portfolio). */
    public function salesMonthly(): array
    {
        if (! $this->arReady() || ($this->scope !== 'customer_id' && ! $this->portfolio())) {
            return [];
        }
        $mine = $this->mine("category = 'SALES' AND entity_type IN ('invoice','creditnote') AND transaction_date >= ? AND transaction_date <= ?",
            [self::D0, $this->today()], 'transaction_id, transaction_date, entity_type, kpi_amount');

        $daily = [];
        $inv = [];
        foreach ($mine as $r) {
            $td = self::d($r->transaction_date);
            $daily[$td] = ($daily[$td] ?? 0) + (float) $r->kpi_amount;
            if ($r->entity_type === 'invoice') {
                $id = (string) $r->transaction_id;
                $inv[$id]['td'] = min($inv[$id]['td'] ?? '9999', $td);
                $inv[$id]['amt'] = ($inv[$id]['amt'] ?? 0) + (float) $r->kpi_amount;
            }
        }

        return array_map(function ($m) use ($mine, $daily, $inv) {
            $ym = substr($m, 0, 7);
            $net = array_sum(array_map(fn ($r) => str_starts_with(self::d($r->transaction_date), $ym) ? (float) $r->kpi_amount : 0, $mine));
            $days = array_filter($daily, fn ($v, $d) => str_starts_with($d, $ym) && $v != 0, ARRAY_FILTER_USE_BOTH);

            return [
                'month_start' => $m, 'is_current_month' => $m === $this->today->copy()->startOfMonth()->toDateString(),
                'net_sales' => $net,
                'sales_average_daily' => $days ? round(array_sum($days) / count($days), 2) : null,
                'nonzero_days' => count($days),
                'invoice_count' => count(array_filter($inv, fn ($i) => str_starts_with($i['td'], $ym) && $i['amt'] > 0)),
                'as_of_date' => $this->today(),
            ];
        }, $this->months12());
    }

    /**
     * customer_sales_target_vs_actual / collection_target_vs_actual — monthly
     * targets vs actuals for the current year, plus the Total row.
     */
    public function targetVsActual(string $kind): array
    {
        if (! $this->arReady()) {
            return [];
        }
        $pf = DB::table(self::CUSTOMERS)->where($this->scope, $this->value)->get();
        if ($pf->isEmpty()) {
            return [];
        }
        $y = (int) $this->today->year;
        $cm = (int) $this->today->month;
        $mon = ['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'];
        $filter = $kind === 'sales' ? "category = 'SALES' AND entity_type IN ('invoice','creditnote')" : "category = 'COLLECTION'";

        $act = [];
        foreach ($this->mine("{$filter} AND transaction_date >= ? AND transaction_date >= ? AND transaction_date <= ?",
            [self::D0, "{$y}-01-01", $this->today()], 'MONTH(transaction_date) m, kpi_amount') as $r) {
            $act[(int) $r->m] = ($act[(int) $r->m] ?? 0) + (float) $r->kpi_amount;
        }

        $months = [];
        foreach (range(1, 12) as $m) {
            $vals = $pf->pluck("{$mon[$m - 1]}_{$kind}_tgt")->filter(fn ($v) => $v !== null);
            $t = $vals->isEmpty() ? null : (float) $vals->sum();
            $fut = $m > $cm;
            $months[] = ['m' => $m, 't' => $t, 'fut' => $fut, 'cur' => $m === $cm, 'a' => $fut ? null : ($act[$m] ?? 0.0)];
        }
        $miss = count(array_filter($months, fn ($r) => $r['t'] === null));
        $tk = $kind === 'sales' ? 'sales_tgt' : 'collection_tgt';
        $ak = $kind === 'sales' ? 'actual_sales' : 'actual_collection';

        $out = [];
        foreach ($months as $r) {
            $out[] = [
                'row_kind' => 'month', 'month_no' => $r['m'], 'report_year' => $y, 'is_current_month' => $r['cur'], 'is_future' => $r['fut'],
                $tk => $r['t'], $ak => $r['a'],
                'ach_pct' => ($r['fut'] || $r['t'] === null || $r['t'] == 0) ? null : round($r['a'] / $r['t'] * 100, 2),
                'balance' => ($r['fut'] || $r['t'] === null) ? null : $r['t'] - $r['a'],
                'missing_target_months' => $miss, 'as_of_date' => $this->today(),
            ];
        }

        $withT = array_filter($months, fn ($r) => $r['t'] !== null);
        $elapsedPos = array_filter($months, fn ($r) => ! $r['fut'] && $r['t'] !== null && $r['t'] > 0);
        $elapsedT = array_filter($months, fn ($r) => ! $r['fut'] && $r['t'] !== null);
        $posT = array_sum(array_column($elapsedPos, 't'));
        $out[] = [
            'row_kind' => 'total', 'month_no' => null, 'report_year' => $y, 'is_current_month' => false, 'is_future' => false,
            $tk => $withT ? array_sum(array_column($withT, 't')) : null,
            $ak => array_sum(array_map(fn ($r) => $r['a'] ?? 0, array_filter($months, fn ($r) => ! $r['fut']))),
            'ach_pct' => ($elapsedPos && $posT > 0) ? round(array_sum(array_column($elapsedPos, 'a')) / $posT * 100, 2) : null,
            'balance' => $elapsedT ? array_sum(array_column($elapsedT, 't')) - array_sum(array_column($elapsedT, 'a')) : null,
            'missing_target_months' => $miss, 'as_of_date' => $this->today(),
        ];

        return $out;
    }

    /* ───────────────────────── Credit Note ───────────────────────── */

    /** Credit notes (one per transaction_id, signed) and invoices of the scope, from 2024-04-01 (optionally through today). */
    private function creditNoteInputs(bool $throughToday): array
    {
        $where = "category = 'SALES' AND entity_type IN ('invoice','creditnote') AND transaction_date >= ?";
        $b = [self::D0];
        if ($throughToday) {
            $where .= ' AND transaction_date <= ?';
            $b[] = $this->today();
        }
        $cn = [];
        $inv = [];
        foreach ($this->mine($where, $b, 'transaction_id, transaction_date, entity_type, kpi_amount') as $r) {
            $td = self::d($r->transaction_date);
            if ($r->entity_type === 'creditnote') {
                $id = (string) $r->transaction_id;
                $cn[$id]['td'] = min($cn[$id]['td'] ?? '9999', $td);
                $cn[$id]['amt'] = ($cn[$id]['amt'] ?? 0) + (float) $r->kpi_amount;
            } else {
                $inv[] = [$td, (float) $r->kpi_amount];
            }
        }

        return [$cn, $inv];
    }

    /** credit_note_kpis. */
    public function creditNoteKpis(): array
    {
        if (! $this->arReady()) {
            return [];
        }
        if (! $this->portfolio()) {
            return [];
        }
        [$cn, $inv] = $this->creditNoteInputs(false);

        return array_map(function ($p) use ($cn, $inv) {
            $in = array_filter($cn, fn ($c) => self::inRange($c['td'], $p['s'], $p['e']));
            $rv = $in ? -array_sum(array_column($in, 'amt')) : 0.0;
            $cnt = count(array_filter($in, fn ($c) => $c['amt'] < 0));
            $gross = array_sum(array_map(fn ($i) => self::inRange($i[0], $p['s'], $p['e']) ? $i[1] : 0, $inv));

            return [
                'period_key' => $p['k'], 'period_start' => $p['s'], 'period_end' => $p['e'],
                'return_value' => $rv, 'credit_notes_count' => $cnt,
                'return_average' => $cnt > 0 ? round($rv / $cnt, 2) : null,
                'gross_sales' => $gross,
                'return_rate_pct' => $gross > 0 ? round($rv / $gross * 100, 2) : null,
                'scope_customers' => count($this->portfolio()), 'as_of_date' => $this->today(),
            ];
        }, $this->periods());
    }

    /** credit_note_monthly_12m. */
    public function creditNoteMonthly(): array
    {
        if (! $this->arReady()) {
            return [];
        }
        if (! $this->portfolio()) {
            return [];
        }
        [$cn, $inv] = $this->creditNoteInputs(true);

        return array_map(function ($m) use ($cn, $inv) {
            $ym = substr($m, 0, 7);
            $in = array_filter($cn, fn ($c) => str_starts_with($c['td'], $ym));
            $rv = $in ? -array_sum(array_column($in, 'amt')) : 0.0;
            $gross = array_sum(array_map(fn ($i) => str_starts_with($i[0], $ym) ? $i[1] : 0, $inv));

            return [
                'month_start' => $m, 'is_current_month' => $m === $this->today->copy()->startOfMonth()->toDateString(),
                'return_value' => $rv, 'credit_notes_count' => count(array_filter($in, fn ($c) => $c['amt'] < 0)),
                'gross_sales' => $gross, 'return_rate_pct' => $gross > 0 ? round($rv / $gross * 100, 2) : null,
                'as_of_date' => $this->today(),
            ];
        }, $this->months12());
    }

    /* ───────────────────────── Profitability ───────────────────────── */

    /** profitability_kpis — SALES invoices only, stored gross_profit / gp_pct. */
    public function profitabilityKpis(): array
    {
        if (! $this->arReady()) {
            return [];
        }
        if (! $this->portfolio()) {
            return [];
        }
        $mine = $this->mine("category = 'SALES' AND entity_type = 'invoice' AND transaction_date >= ?", [self::D0],
            'transaction_id, transaction_date, gross_profit, gp_pct, sales_exc_vat, kpi_amount');

        $company = $this->periodSums(self::AR, "category = 'SALES' AND entity_type = 'invoice' AND transaction_date >= ?", [self::D0], "'all'", 'gross_profit');
        [$from, $entWhere, $entCol] = $this->peerJoin();
        $peers = $this->periodSums($from, "a.category = 'SALES' AND a.entity_type = 'invoice' AND a.transaction_date >= ? AND {$entWhere}",
            [self::D0], $entCol, 'a.gross_profit', 'a.transaction_date');

        return array_map(function ($p) use ($mine, $company, $peers) {
            $gp = $sales = $sev = 0.0;
            $gpps = [];
            $excl = 0;
            $ids = [];
            foreach ($mine as $r) {
                if (! self::inRange(self::d($r->transaction_date), $p['s'], $p['e'])) {
                    continue;
                }
                $gp += (float) $r->gross_profit;
                $sales += (float) $r->kpi_amount;
                $sev += (float) $r->sales_exc_vat;
                $ids[(string) $r->transaction_id] = true;
                // SQL three-valued logic: cond = gpp IS NOT NULL AND gpp >= -100 AND sev <> 0 (NULL when sev is NULL)
                if ($r->gp_pct === null || (float) $r->gp_pct < -100) {
                    $excl++;
                } elseif ($r->sales_exc_vat !== null) {
                    if ((float) $r->sales_exc_vat != 0) {
                        $gpps[] = (float) $r->gp_pct;
                    } else {
                        $excl++;
                    }
                }
            }
            $ct = $company[$p['k']]['all'] ?? null;
            [$rank, $rankTotal] = self::rankOf($peers[$p['k']][$this->value] ?? null, $peers[$p['k']]);

            return [
                'period_key' => $p['k'], 'period_start' => $p['s'], 'period_end' => $p['e'],
                'gross_profit' => $gp, 'gross_sales' => $sales,
                'gross_margin_pct' => $sev != 0 ? round($gp / $sev * 100, 2) : null,
                'company_gross_profit' => $ct ?? 0.0,
                'gp_contribution_pct' => ($ct !== null && $ct > 0) ? round($gp / $ct * 100, 2) : null,
                'gp_rank' => $rank, 'gp_rank_total' => $rank === null ? null : $rankTotal,
                'avg_gp_pct_per_invoice' => $gpps ? round(array_sum($gpps) / count($gpps), 2) : null,
                'gp_pct_observations' => count($gpps), 'gp_pct_excluded' => $excl, 'invoice_count' => count($ids),
                'scope_customers' => count($this->portfolio()), 'as_of_date' => $this->today(),
            ];
        }, $this->periods());
    }

    /** profitability_monthly_12m. */
    public function profitabilityMonthly(): array
    {
        if (! $this->arReady()) {
            return [];
        }
        if (! $this->portfolio()) {
            return [];
        }
        [$ph, $ids] = $this->inPf();
        $rows = DB::select("SELECT DATE_FORMAT(transaction_date, '%Y-%m-01') m,
                SUM(CASE WHEN customer_id IN ({$ph}) THEN gross_profit END) gp,
                SUM(CASE WHEN customer_id IN ({$ph}) THEN kpi_amount END) sales,
                SUM(CASE WHEN customer_id IN ({$ph}) THEN sales_exc_vat END) sev,
                SUM(gross_profit) cgp
            FROM ".self::AR." WHERE category = 'SALES' AND entity_type = 'invoice' AND transaction_date >= ? AND transaction_date >= ? AND transaction_date <= ?
            GROUP BY m", array_merge($ids, $ids, $ids, [self::D0, $this->months12()[0], $this->today()]));
        $by = [];
        foreach ($rows as $r) {
            $by[$r->m] = $r;
        }

        return array_map(function ($m) use ($by) {
            $r = $by[$m] ?? null;
            $gp = (float) ($r->gp ?? 0);
            $sev = (float) ($r->sev ?? 0);
            $cgp = (float) ($r->cgp ?? 0);

            return [
                'month_start' => $m, 'is_current_month' => $m === $this->today->copy()->startOfMonth()->toDateString(),
                'gross_profit' => $gp, 'gross_sales' => (float) ($r->sales ?? 0),
                'gross_margin_pct' => $sev != 0 ? round($gp / $sev * 100, 2) : null,
                'company_gross_profit' => $cgp,
                'gp_contribution_pct' => $cgp > 0 ? round($gp / $cgp * 100, 2) : null,
                'as_of_date' => $this->today(),
            ];
        }, $this->months12());
    }

    /* ───────────────────────── Collection ───────────────────────── */

    /** collection_kpis — signed COLLECTION kpi_amount; payment KPIs from actual payments only. */
    public function collectionKpis(): array
    {
        if (! $this->arReady()) {
            return [];
        }
        if (! $this->portfolio()) {
            return [];
        }
        $mine = $this->mine("category = 'COLLECTION' AND transaction_date >= ?", [self::D0], 'transaction_id, transaction_date, entity_type, kpi_amount');
        $rows = array_map(fn ($r) => [self::d($r->transaction_date), (float) $r->kpi_amount], $mine);
        $firstAct = $rows ? min(array_column($rows, 0)) : null;

        $pay = [];
        foreach ($mine as $r) {
            if (in_array($r->entity_type, ['customer_payment', 'invoice_payment'], true)) {
                $id = (string) $r->transaction_id;
                $pay[$id]['td'] = min($pay[$id]['td'] ?? '9999', self::d($r->transaction_date));
                $pay[$id]['amt'] = ($pay[$id]['amt'] ?? 0) + (float) $r->kpi_amount;
            }
        }

        $sales = [];
        foreach ($this->mine("category = 'SALES' AND entity_type IN ('invoice','creditnote') AND transaction_date >= ?", [self::D0],
            'transaction_date, kpi_amount') as $r) {
            $sales[] = [self::d($r->transaction_date), (float) $r->kpi_amount];
        }
        $company = $this->periodSums(self::AR, "category = 'COLLECTION' AND transaction_date >= ?", [self::D0], "'all'", 'kpi_amount');
        [$from, $entWhere, $entCol] = $this->peerJoin();
        $peers = $this->periodSums($from, "a.category = 'COLLECTION' AND a.transaction_date >= ? AND {$entWhere}", [self::D0], $entCol, 'a.kpi_amount', 'a.transaction_date');

        return array_map(function ($p) use ($rows, $firstAct, $pay, $sales, $company, $peers) {
            $net = array_sum(array_map(fn ($r) => self::inRange($r[0], $p['s'], $p['e']) ? $r[1] : 0, $rows));
            $sl = array_sum(array_map(fn ($r) => self::inRange($r[0], $p['s'], $p['e']) ? $r[1] : 0, $sales));
            $inPay = array_filter($pay, fn ($x) => self::inRange($x['td'], $p['s'], $p['e']));
            $payDays = [];
            foreach ($inPay as $x) {
                $payDays[$x['td']] = ($payDays[$x['td']] ?? 0) + $x['amt'];
            }
            ksort($payDays);
            $dates = array_keys($payDays);
            $gaps = [];
            for ($i = 1; $i < count($dates); $i++) {
                $gaps[] = Carbon::parse($dates[$i - 1])->diffInDays(Carbon::parse($dates[$i]));
            }
            $ct = $company[$p['k']]['all'] ?? null;
            [$rank, $rankTotal] = self::rankOf($peers[$p['k']][$this->value] ?? null, $peers[$p['k']]);
            $st = $this->bucketStats($p, $rows, $firstAct);
            $pc = count($inPay);
            $pt = array_sum(array_column($inPay, 'amt'));

            return [
                'period_key' => $p['k'], 'period_start' => $p['s'], 'period_end' => $p['e'],
                'net_collection' => $net, 'net_sales' => $sl,
                'collection_ratio_pct' => $sl != 0 ? round($net / $sl * 100, 2) : null,
                'collection_average' => $st['av'], 'collection_median' => $st['med'], 'collection_volatility_pct' => $st['vol'],
                'collection_contribution_pct' => ($ct !== null && $ct > 0) ? round($net / $ct * 100, 2) : null,
                'collection_rank' => $rank, 'collection_rank_total' => $rank === null ? null : $rankTotal,
                'payment_count' => $pc, 'total_payments' => $pt,
                'average_payment_value' => $pc > 0 ? round($pt / $pc, 2) : null,
                'largest_payment_day' => $payDays ? max($payDays) : null,
                'average_payment_interval_days' => $gaps ? round(array_sum($gaps) / count($gaps), 2) : null,
                'observations' => $st['n'], 'first_collection_activity' => $firstAct,
                'scope_customers' => count($this->portfolio()), 'as_of_date' => $this->today(),
            ];
        }, $this->periods());
    }

    /** collection_monthly_12m. */
    public function collectionMonthly(): array
    {
        if (! $this->arReady()) {
            return [];
        }
        if (! $this->portfolio()) {
            return [];
        }
        $mine = $this->mine("category = 'COLLECTION' AND transaction_date >= ? AND transaction_date <= ?", [self::D0, $this->today()],
            'transaction_id, transaction_date, entity_type, kpi_amount');
        $sales = $this->mine("category = 'SALES' AND entity_type IN ('invoice','creditnote') AND transaction_date >= ? AND transaction_date <= ?",
            [self::D0, $this->today()], 'transaction_date, kpi_amount');

        $daily = [];
        $pay = [];
        foreach ($mine as $r) {
            $td = self::d($r->transaction_date);
            $daily[$td] = ($daily[$td] ?? 0) + (float) $r->kpi_amount;
            if (in_array($r->entity_type, ['customer_payment', 'invoice_payment'], true)) {
                $id = (string) $r->transaction_id;
                $pay[$id]['td'] = min($pay[$id]['td'] ?? '9999', $td);
                $pay[$id]['amt'] = ($pay[$id]['amt'] ?? 0) + (float) $r->kpi_amount;
            }
        }

        return array_map(function ($m) use ($mine, $sales, $daily, $pay) {
            $ym = substr($m, 0, 7);
            $nc = array_sum(array_map(fn ($r) => str_starts_with(self::d($r->transaction_date), $ym) ? (float) $r->kpi_amount : 0, $mine));
            $ns = array_sum(array_map(fn ($r) => str_starts_with(self::d($r->transaction_date), $ym) ? (float) $r->kpi_amount : 0, $sales));
            $days = array_filter($daily, fn ($v, $d) => str_starts_with($d, $ym) && $v != 0, ARRAY_FILTER_USE_BOTH);
            $mp = array_filter($pay, fn ($x) => str_starts_with($x['td'], $ym));
            $pt = array_sum(array_column($mp, 'amt'));

            return [
                'month_start' => $m, 'is_current_month' => $m === $this->today->copy()->startOfMonth()->toDateString(),
                'net_collection' => $nc, 'net_sales' => $ns,
                'collection_ratio_pct' => $ns != 0 ? round($nc / $ns * 100, 2) : null,
                'collection_average_daily' => $days ? round(array_sum($days) / count($days), 2) : null,
                'nonzero_days' => count($days), 'payment_count' => count($mp), 'total_payments' => $pt,
                'average_payment_value' => $mp ? round($pt / count($mp), 2) : null,
                'as_of_date' => $this->today(),
            ];
        }, $this->months12());
    }

    /* ───────────────────────── Accounts Receivable ───────────────────────── */

    /** ar_overview — current snapshot. */
    public function arOverview(): ?array
    {
        if (! $this->arReady()) {
            return null;
        }
        if (! $this->portfolio()) {
            return null;
        }
        [$ph, $ids] = $this->inPf();
        $d = $this->today();
        $me = $this->today->copy()->endOfMonth()->toDateString();
        $elig = "entity_type IN ('invoice','customer_ob_invoice') AND balance > 0";
        $a = DB::selectOne("SELECT
                COALESCE(SUM(CASE WHEN customer_id IN ({$ph}) THEN statement_amount END), 0) oar,
                COALESCE(SUM(statement_amount), 0) c_oar,
                SUM(CASE WHEN customer_id IN ({$ph}) AND {$elig} THEN 1 ELSE 0 END) oic,
                COALESCE(SUM(CASE WHEN customer_id IN ({$ph}) AND {$elig} AND inv_balance_due_date < ? THEN balance END), 0) od,
                SUM(CASE WHEN customer_id IN ({$ph}) AND {$elig} AND inv_balance_due_date < ? THEN 1 ELSE 0 END) odc,
                COALESCE(SUM(CASE WHEN {$elig} AND inv_balance_due_date < ? THEN balance END), 0) c_od,
                COALESCE(SUM(CASE WHEN customer_id IN ({$ph}) AND {$elig} AND inv_balance_due_date <= ? THEN balance END), 0) dtm
            FROM ".self::AR, array_merge($ids, $ids, $ids, [$d], $ids, [$d], [$d], $ids, [$me]));

        $oar = (float) $a->oar;
        $cOar = (float) $a->c_oar;
        $od = (float) $a->od;
        $cOd = (float) $a->c_od;
        $dtm = (float) $a->dtm;

        return [
            'outstanding_ar' => $oar, 'outstanding_invoices_count' => (int) $a->oic, 'company_outstanding_ar' => $cOar,
            'outstanding_contribution_pct' => $cOar != 0 ? round($oar / $cOar * 100, 2) : null,
            'overdue_amount' => $od, 'overdue_invoices_count' => (int) $a->odc,
            'overdue_ratio_pct' => $oar != 0 ? round($od / $oar * 100, 2) : null,
            'company_overdue_amount' => $cOd,
            'overdue_contribution_pct' => $cOd != 0 ? round($od / $cOd * 100, 2) : null,
            'due_this_month' => $dtm, 'due_this_month_ratio_pct' => $oar != 0 ? round($dtm / $oar * 100, 2) : null,
            'month_end' => $me, 'scope_customers' => count($ids), 'as_of_date' => $d,
        ];
    }

    /** ar_aging — 7 buckets by invoice date (age = today − transaction_date). */
    public function arAging(): array
    {
        if (! $this->arReady()) {
            return [];
        }
        if (! $this->portfolio()) {
            return [];
        }
        $buckets = [[1, '0_30', 0, 30], [2, '31_60', 31, 60], [3, '61_90', 61, 90], [4, '91_120', 91, 120],
            [5, '121_150', 121, 150], [6, '151_180', 151, 180], [7, 'over_180', 181, null]];
        $rows = $this->mine("entity_type IN ('invoice','customer_ob_invoice') AND balance > 0 AND transaction_date IS NOT NULL", [],
            'transaction_date, balance');
        $ages = array_map(fn ($r) => [(int) Carbon::parse(self::d($r->transaction_date))->diffInDays($this->today, false), (float) $r->balance], $rows);

        $agg = [];
        foreach ($buckets as [$o, $k, $lo, $hi]) {
            $in = array_filter($ages, fn ($x) => $x[0] >= $lo && ($hi === null || $x[0] <= $hi));
            $agg[] = ['o' => $o, 'k' => $k, 'amt' => array_sum(array_column($in, 1)), 'cnt' => count($in)];
        }
        $tot = array_sum(array_column($agg, 'amt'));
        $fut = array_filter($ages, fn ($x) => $x[0] < 0);

        return array_map(fn ($b) => [
            'bucket_order' => $b['o'], 'bucket_key' => $b['k'], 'amount' => $b['amt'], 'record_count' => $b['cnt'],
            'pct_of_total' => $tot > 0 ? round($b['amt'] / $tot * 100, 2) : null, 'total_amount' => $tot,
            'future_dated_count' => count($fut), 'future_dated_amount' => array_sum(array_column($fut, 1)), 'as_of_date' => $this->today(),
        ], $agg);
    }

    /** ar_invoice_details — rows behind Outstanding / Overdue Invoices Count. */
    public function arInvoiceDetails(string $kind): array
    {
        if (! $this->arReady()) {
            return [];
        }
        if (! in_array($kind, ['outstanding', 'overdue'], true)) {
            throw new \InvalidArgumentException('invalid kind');
        }
        $where = "entity_type IN ('invoice','customer_ob_invoice') AND balance > 0".($kind === 'overdue' ? ' AND inv_balance_due_date < ?' : '');
        $rows = $this->mine($where.' ORDER BY transaction_date, transaction_number, transaction_id', $kind === 'overdue' ? [$this->today()] : [],
            'entity_type, transaction_number, transaction_date, inv_balance_due_date, statement_amount, balance');

        return array_map(fn ($r) => [
            'entity_type' => $r->entity_type, 'transaction_number' => $r->transaction_number,
            'transaction_date' => self::d($r->transaction_date), 'due_date' => self::d($r->inv_balance_due_date),
            'amount' => $r->statement_amount === null ? null : (float) $r->statement_amount, 'balance' => (float) $r->balance,
            'age_days' => $r->transaction_date === null ? null : (int) Carbon::parse(self::d($r->transaction_date))->diffInDays($this->today, false),
            'as_of_date' => $this->today(),
        ], $rows);
    }

    /* ───────────────────────── Credit Limit ───────────────────────── */

    /** credit_limit_overview. */
    public function creditLimitOverview(): ?array
    {
        if (! $this->arReady()) {
            return null;
        }
        $pf = DB::table(self::CUSTOMERS)->where($this->scope, $this->value)->pluck('credit_limit');
        if ($pf->isEmpty()) {
            return null;
        }
        $known = $pf->filter(fn ($v) => $v !== null)->map(fn ($v) => (float) $v);
        $cl = $known->isEmpty() ? null : $known->sum();
        [$ph, $ids] = $this->inPf();
        $used = (float) (DB::selectOne('SELECT COALESCE(SUM(statement_amount), 0) u FROM '.self::AR." WHERE customer_id IN ({$ph})", $ids)->u);

        $status = match (true) {
            $cl === null || $cl < 0 => 'not_available',
            $cl == 0 => 'no_credit_limit',
            $used < 0 => 'credit_balance',
            $used / $cl * 100 < 50 => 'low',
            $used / $cl * 100 < 80 => 'healthy',
            $used / $cl * 100 <= 100 => 'high',
            default => 'over_limit',
        };

        return [
            'credit_limit' => $cl, 'used_credit' => $used,
            'utilisation_pct' => ($cl !== null && $cl > 0) ? round($used / $cl * 100, 2) : null,
            'status_key' => $status, 'scope_customers' => $pf->count(),
            'limit_positive_count' => $known->filter(fn ($v) => $v > 0)->count(),
            'limit_zero_count' => $known->filter(fn ($v) => $v == 0)->count(),
            'limit_missing_count' => $pf->count() - $known->count(),
            'limit_negative_count' => $known->filter(fn ($v) => $v < 0)->count(),
            'as_of_date' => $this->today(),
        ];
    }

    /**
     * credit_dso — ((Opening AR + Closing AR) / 2) / Net Sales × days, rolling
     * 12/6/3 months ending at the cutoff: the Riyadh date of (the latest
     * successful raw Accrual Transactions pull − 2h) minus one day. Their
     * equivalent reads the latest successful 'AR SALES' export; ours starts from
     * the raw pull that AR SALES is computed from, which is the same moment.
     */
    public function creditDso(): array
    {
        if (! $this->arReady()) {
            return [];
        }
        if (! $this->portfolio()) {
            return [];
        }
        $t = DB::table('zoho_raw_sync_runs')->where('table_key', 'accrual_transactions')->where('status', 'succeeded')->max('started_at');
        if ($t === null) {
            return [];
        }
        $riyadh = Carbon::parse($t, config('app.timezone'))->setTimezone('Asia/Riyadh')->subHours(2);
        $cut = Carbon::parse($riyadh->toDateString())->subDay();
        $rows = $this->mine('1 = 1', [], 'transaction_date, statement_amount, category, entity_type, kpi_amount');

        $out = [];
        foreach ([['12m', 12], ['6m', 6], ['3m', 3]] as [$k, $mo]) {
            $s = $cut->copy()->subMonthsNoOverflow($mo)->addDay();
            $sd = $s->toDateString();
            $ed = $cut->toDateString();
            $open = $s->copy()->subDay()->toDateString();
            $o = $cl = $ns = 0.0;
            foreach ($rows as $r) {
                $td = self::d($r->transaction_date);
                if ($td === null) {
                    continue;
                }
                if ($td <= $open) {
                    $o += (float) $r->statement_amount;
                }
                if ($td <= $ed) {
                    $cl += (float) $r->statement_amount;
                }
                if ($r->category === 'SALES' && in_array($r->entity_type, ['invoice', 'creditnote'], true) && $td >= self::D0 && self::inRange($td, $sd, $ed)) {
                    $ns += (float) $r->kpi_amount;
                }
            }
            $days = $s->diffInDays($cut) + 1;
            $ok = $open >= self::D0;
            $out[] = [
                'period_key' => $k, 'period_months' => $mo, 'start_date' => $sd, 'end_date' => $ed, 'days' => (int) $days,
                'opening_ar' => $o, 'closing_ar' => $cl, 'average_ar' => ($o + $cl) / 2, 'net_sales' => $ns,
                'dso_days' => ($ns > 0 && $ok) ? round((($o + $cl) / 2) / $ns * $days, 1) : null,
                'coverage_ok' => $ok, 'cutoff_basis_run_started_at' => $t, 'scope_customers' => count($this->portfolio()),
            ];
        }

        return $out;
    }

    /* ───────────────────────── Sales Orders ───────────────────────── */

    private function soQuery(): \Illuminate\Database\Query\Builder
    {
        return DB::table('zoho_sales_orders_not_invoiced')->whereIn('customer_id', $this->portfolio() ?: ['__none__']);
    }

    /** sales_orders_status_summary. */
    public function soStatusSummary(): array
    {
        return $this->soQuery()
            ->selectRaw('sales_order_status, COUNT(DISTINCT item_id) item_count, COUNT(DISTINCT sales_order_id) order_count, COUNT(*) line_count, COALESCE(SUM(uninvoiced_sales_value), 0) total')
            ->groupBy('sales_order_status')->orderByRaw('sales_order_status IS NULL, sales_order_status')
            ->get()->map(fn ($r) => [
                'status' => $r->sales_order_status, 'item_count' => (int) $r->item_count, 'order_count' => (int) $r->order_count,
                'line_count' => (int) $r->line_count, 'total' => (float) $r->total,
            ])->all();
    }

    /** sales_orders_customer_lines — item-level rows as stored. */
    public function soCustomerLines(): array
    {
        return $this->soQuery()
            ->orderByRaw('order_date IS NULL, order_date DESC')->orderByDesc('sales_order_number')->orderBy('row_no')
            ->get(['sales_order_status', 'order_date', 'sales_order_number', 'item_name', 'ordered_qty', 'invoiced_qty', 'uninvoiced_qty', 'discount_pct', 'uninvoiced_sales_value', 'row_no'])
            ->map(fn ($r) => [
                'status' => $r->sales_order_status, 'order_date' => self::d($r->order_date), 'so_number' => $r->sales_order_number,
                'item_name' => $r->item_name, 'ordered_qty' => $r->ordered_qty === null ? null : (float) $r->ordered_qty,
                'invoiced_qty' => (float) $r->invoiced_qty, 'uninvoiced_qty' => (float) $r->uninvoiced_qty,
                'discount_pct' => $r->discount_pct === null ? null : rtrim(rtrim((string) $r->discount_pct, '0'), '.'),
                'value' => $r->uninvoiced_sales_value === null ? null : (float) $r->uninvoiced_sales_value, 'row_no' => (int) $r->row_no,
            ])->all();
    }

    /** sales_orders_entity_orders — one row per Sales Order. */
    public function soEntityOrders(): array
    {
        return $this->soQuery()
            ->selectRaw('sales_order_status, sales_order_id, order_date, sales_order_number, customer_id, customer_name, COALESCE(SUM(uninvoiced_sales_value), 0) value')
            ->groupBy('sales_order_id', 'order_date', 'sales_order_number', 'customer_id', 'customer_name', 'sales_order_status')
            ->orderByRaw('order_date IS NULL, order_date DESC')->orderByDesc('sales_order_number')->orderBy('sales_order_id')
            ->get()->map(fn ($r) => [
                'status' => $r->sales_order_status, 'so_id' => (string) $r->sales_order_id, 'order_date' => self::d($r->order_date),
                'so_number' => $r->sales_order_number, 'customer_name' => $r->customer_name, 'value' => (float) $r->value,
            ])->all();
    }

    /* ───────────────────────── Item Performance ───────────────────────── */

    /** Item lines of the scope (Invoice + Credit Note) in [from, to], with current Zoho Items attributes. */
    private function itemLines(string $from, string $to, bool $activityOnly): array
    {
        [$ph, $ids] = $this->inPf();
        $act = $activityOnly ? ' AND (COALESCE(s.item_total, 0) <> 0 OR COALESCE(s.qty, 0) <> 0)' : '';

        return DB::select('SELECT s.item_id, s.item_name sip_name, s.transaction_date, s.transaction_type, COALESCE(s.item_total, 0) amt, s.item_gross_profit gp, COALESCE(s.qty, 0) q,
                i.item_name, NULLIF(TRIM(i.vendor), \'\') ven, NULLIF(TRIM(i.marketing_company), \'\') mc, NULLIF(TRIM(i.owner), \'\') own
            FROM '.self::SIP.' s LEFT JOIN zoho_raw_items i ON i.item_id = s.item_id
            WHERE s.customer_id IN ('.$ph.") AND s.transaction_type IN ('Invoice', 'Credit Note') AND s.transaction_date BETWEEN ? AND ?{$act}",
            array_merge($ids, [$from, $to]));
    }

    /** item_owner_kpis — owner × 7 periods matrix. */
    public function itemOwnerKpis(): array
    {
        $periods = $this->periods();
        $d = $this->today();
        $pms = $periods[3]['s'];
        $lines = $this->itemLines(self::D0, $d, true);

        // item × period sums
        $ip = [];
        foreach ($lines as $l) {
            $td = self::d($l->transaction_date);
            $own = $l->own ?? '';
            foreach ($periods as $p) {
                if (! self::inRange($td, $p['s'], $p['e'])) {
                    continue;
                }
                $key = $p['k'].'|'.$l->item_id;
                $ip[$key] ??= ['k' => $p['k'], 'own' => $own, 'ns' => 0.0, 'inv' => 0.0, 'gp' => null, 'q' => 0.0];
                $ip[$key]['ns'] += (float) $l->amt;
                $ip[$key]['q'] += (float) $l->q;
                if ($l->transaction_type === 'Invoice') {
                    $ip[$key]['inv'] += (float) $l->amt;
                    if ($l->gp !== null) {
                        $ip[$key]['gp'] = ($ip[$key]['gp'] ?? 0) + (float) $l->gp;
                    }
                }
            }
        }

        $base = [];
        $life = [];
        foreach ($ip as $x) {
            $b = &$base[$x['own']][$x['k']];
            $b ??= ['ns' => 0.0, 'inv' => 0.0, 'gp' => 0.0, 'sku' => 0];
            $b['ns'] += $x['ns'];
            $b['inv'] += $x['inv'];
            $b['gp'] += $x['gp'] ?? 0;
            $b['sku'] += $x['q'] > 0 ? 1 : 0;
            unset($b);
            if ($x['k'] === 'lifetime') {
                $life[$x['own']] = ($life[$x['own']] ?? 0) + $x['ns'];
            }
        }
        uksort($life, fn ($a, $b) => [-$life[$a], $a === '' ? 1 : 0, $a] <=> [-$life[$b], $b === '' ? 1 : 0, $b]);

        $tot = [];
        foreach ($base as $own => $byK) {
            foreach ($byK as $k => $b) {
                $tot[$k]['ns'] = ($tot[$k]['ns'] ?? 0) + $b['ns'];
                $tot[$k]['gp'] = ($tot[$k]['gp'] ?? 0) + $b['gp'];
            }
        }

        // owner × bucket net sales (month before the previous month, day from the previous month on) → Avg Sales
        $ob = [];
        foreach ($lines as $l) {
            $td = self::d($l->transaction_date);
            $bk = $td >= $pms ? $td : self::monthStart($td);
            $ob[$l->own ?? ''][$bk] = ($ob[$l->own ?? ''][$bk] ?? 0) + (float) $l->amt;
        }

        $out = [];
        $ord = 0;
        foreach ($life as $own => $lifeNs) {
            $ord++;
            foreach ($periods as $p) {
                $av = null;
                if ($p['gran'] !== 'none') {
                    $lo = $p['gran'] === 'month' ? self::monthStart($p['s']) : $p['s'];
                    $agg = [];
                    foreach ($ob[$own] ?? [] as $bk => $v) {
                        if ($bk >= $lo && $bk <= $p['e']) {
                            $b = $p['gran'] === 'month' ? self::monthStart($bk) : $bk;
                            $agg[$b] = ($agg[$b] ?? 0) + $v;
                        }
                    }
                    $nz = array_filter($agg, fn ($v) => $v != 0);
                    $av = $nz ? round(array_sum($nz) / count($nz), 2) : null;
                }
                $b = $base[$own][$p['k']] ?? null;
                $t = $tot[$p['k']] ?? ['ns' => null, 'gp' => null];
                $out[] = [
                    'owner' => $own === '' ? null : $own, 'owner_order' => $ord, 'owner_lifetime_net_sales' => $lifeNs,
                    'period_key' => $p['k'], 'period_start' => $p['s'], 'period_end' => $p['e'],
                    'net_sales' => $b['ns'] ?? 0.0, 'avg_sales' => $av,
                    'sales_contribution_pct' => ($t['ns'] ?? 0) > 0 ? round(($b['ns'] ?? 0) / $t['ns'] * 100, 2) : null,
                    'sku_count' => $b['sku'] ?? 0, 'invoice_sales' => $b['inv'] ?? 0.0,
                    'profit_pct' => ($b !== null && $b['inv'] != 0) ? round($b['gp'] / $b['inv'] * 100, 2) : null,
                    'profit_contribution_pct' => ($t['gp'] ?? 0) > 0 ? round(($b['gp'] ?? 0) / $t['gp'] * 100, 2) : null,
                    'scope_net_sales' => $t['ns'] ?? 0.0, 'scope_invoice_gross_profit' => $t['gp'] ?? 0.0, 'as_of_date' => $d,
                ];
            }
        }

        return $out;
    }

    /** YTD and MTD windows used by the rankings and contribution charts. */
    private function ytdMtd(): array
    {
        $d0 = self::D0;

        return [
            'ytd' => [max($this->today->copy()->startOfYear()->toDateString(), $d0), $this->today()],
            'mtd' => [max($this->today->copy()->startOfMonth()->toDateString(), $d0), $this->today()],
        ];
    }

    /** One row per item with net sales (invoice + credit note) and invoice gross profit in the window. */
    private function itemTotals(string $from, string $to): array
    {
        $it = [];
        foreach ($this->itemLines($from, $to, false) as $l) {
            $id = (string) $l->item_id;
            $it[$id] ??= ['iid' => $id, 'nm' => $l->item_name, 'sip' => $l->sip_name, 'ven' => $l->ven, 'mc' => $l->mc, 'own' => $l->own, 'ns' => 0.0, 'gp' => null];
            $it[$id]['ns'] += (float) $l->amt;
            if ($l->transaction_type === 'Invoice' && $l->gp !== null) {
                $it[$id]['gp'] = ($it[$id]['gp'] ?? 0) + (float) $l->gp;
            }
        }

        return $it;
    }

    /** item_rankings — 8 fixed rankings (YTD / MTD × 4). */
    public function itemRankings(): array
    {
        $out = [];
        foreach ($this->ytdMtd() as $k => [$s, $e]) {
            $it = $this->itemTotals($s, $e);
            // Current Zoho Items name; the sales line's own name only if the item is missing from Items.
            $cand = [
                'items_sales' => [20, array_values(array_map(fn ($x) => ['iid' => $x['iid'], 'nm' => $x['nm'] ?? $x['sip'], 'v' => $x['ns']],
                    array_filter($it, fn ($x) => $x['ns'] > 0)))],
                'items_gp' => [20, array_values(array_map(fn ($x) => ['iid' => $x['iid'], 'nm' => $x['nm'] ?? $x['sip'], 'v' => $x['gp']],
                    array_filter($it, fn ($x) => $x['gp'] !== null && $x['gp'] > 0)))],
            ];
            foreach (['vendors_sales' => ['ven', 10], 'mc_sales' => ['mc', 20]] as $rk => [$dim, $lim]) {
                $g = [];
                foreach ($it as $x) {
                    $key = $x[$dim] ?? "\0";
                    $g[$key] = ($g[$key] ?? 0) + $x['ns'];
                }
                $rows = [];
                foreach ($g as $name => $v) {
                    if ($v > 0) {
                        $rows[] = ['iid' => null, 'nm' => $name === "\0" ? null : $name, 'v' => $v];
                    }
                }
                $cand[$rk] = [$lim, $rows];
            }

            foreach ($cand as $rk => [$lim, $rows]) {
                usort($rows, fn ($a, $b) => [-$a['v'], $a['nm'] === null ? 1 : 0, $a['nm'], $a['iid']] <=> [-$b['v'], $b['nm'] === null ? 1 : 0, $b['nm'], $b['iid']]);
                foreach (array_slice($rows, 0, $lim) as $i => $r) {
                    $out[] = [
                        'period_key' => $k, 'period_start' => $s, 'period_end' => $e, 'ranking_key' => $rk,
                        'row_position' => $i + 1, 'rank_no' => 1 + count(array_filter($rows, fn ($o) => $o['v'] > $r['v'])),
                        'item_id' => $r['iid'], 'name' => $r['nm'], 'value' => $r['v'],
                    ];
                }
            }
        }

        return $out;
    }

    /** item_contribution — 6 contribution charts × YTD/MTD, percentages only. */
    public function itemContribution(): array
    {
        $out = [];
        foreach ($this->ytdMtd() as $k => [$s, $e]) {
            $it = $this->itemTotals($s, $e);
            foreach (['vendor' => 'ven', 'marketing_company' => 'mc', 'owner' => 'own'] as $dim => $col) {
                $ent = [];
                foreach ($it as $x) {
                    $key = $x[$col] ?? "\0";
                    $ent[$key]['ns'] = ($ent[$key]['ns'] ?? 0) + $x['ns'];
                    if ($x['gp'] !== null) {
                        $ent[$key]['gp'] = ($ent[$key]['gp'] ?? 0) + $x['gp'];
                    }
                }
                foreach (['sales' => 'ns', 'profit' => 'gp'] as $met => $f) {
                    $m = [];
                    foreach ($ent as $name => $v) {
                        if (isset($v[$f])) {
                            $m[] = ['nm' => $name === "\0" ? null : $name, 'v' => $v[$f]];
                        }
                    }
                    $t = array_sum(array_column($m, 'v'));
                    $r = array_values(array_filter($m, fn ($x) => $x['v'] != 0));
                    usort($r, fn ($a, $b) => [-$a['v'], $a['nm'] === null ? 1 : 0, $a['nm']] <=> [-$b['v'], $b['nm'] === null ? 1 : 0, $b['nm']]);
                    if (! $r) {
                        continue;
                    }
                    $nneg = count(array_filter($r, fn ($x) => $x['v'] < 0));
                    $slices = [];
                    foreach (array_slice($r, 0, 10) as $i => $x) {
                        $slices[] = ['so' => $i + 1, 'nm' => $x['nm'], 'oth' => false, 'oc' => 0, 'ocn' => 0, 'v' => $x['v']];
                    }
                    $rest = array_slice($r, 10);
                    if ($rest) {
                        $slices[] = ['so' => 11, 'nm' => null, 'oth' => true, 'oc' => count($rest),
                            'ocn' => count(array_filter($rest, fn ($x) => $x['v'] < 0)), 'v' => array_sum(array_column($rest, 'v'))];
                    }
                    foreach ($slices as $sl) {
                        $out[] = [
                            'period_key' => $k, 'period_start' => $s, 'period_end' => $e, 'dimension' => $dim, 'metric' => $met,
                            'slice_order' => $sl['so'], 'name' => $sl['nm'], 'is_other' => $sl['oth'], 'other_count' => $sl['oc'],
                            'other_negative_count' => $sl['ocn'], 'pct' => $t > 0 ? round($sl['v'] / $t * 100, 2) : null,
                            'entity_count' => count($r), 'negative_entity_count' => $nneg, 'total_positive' => $t > 0,
                        ];
                    }
                }
            }
        }

        return $out;
    }
}
