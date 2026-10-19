<?php

namespace App\Services\Customer360;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Port of the Lovable portal's Customer 360 Insights engines (approved
 * 2026-10-18): zoho.sales_behavior_metrics, item_behavior_lines,
 * item_expiry_batch_lines, credit_note_metrics, sales_order_metrics,
 * profitability_insight_metrics, financial_health_metrics,
 * customer_documentation_eval, and the public.customer_*_insights wrappers
 * that turn them into action rows. Same windows (Asia/Riyadh "today"), same
 * thresholds, same tie-breaks and display precision.
 */
class Customer360Insights
{
    private const AR = 'zoho_ar_sales';

    private const SIP = 'zoho_sales_item_performance';

    private Carbon $d;

    private ?object $customer;

    public function __construct(private readonly string $customerId)
    {
        $this->d = Carbon::parse(Carbon::now('Asia/Riyadh')->toDateString());
        $this->customer = DB::table('zoho_raw_customers')->where('customer_id', $customerId)->first();
    }

    private function day(int $offset = 0): string
    {
        return $this->d->copy()->addDays($offset)->toDateString();
    }

    private static function d(?string $v): ?string
    {
        return $v === null ? null : substr($v, 0, 10);
    }

    /** Whole days b − a (both Y-m-d). */
    private static function diff(string $a, string $b): int
    {
        return (int) round((strtotime($b.' 00:00:00 UTC') - strtotime($a.' 00:00:00 UTC')) / 86400);
    }

    /** Everything the Insights tab needs, in their panel order. */
    public function all(): array
    {
        if ($this->customer === null || ! Schema::hasTable(self::AR)) {
            return ['sales' => [], 'items' => [], 'expiry' => null, 'credit_notes' => [], 'sales_orders' => [], 'profitability' => [], 'financial' => null, 'documentation' => []];
        }

        return [
            'sales' => $this->sales(),
            'items' => $this->items(),
            'expiry' => $this->expiry(),
            'credit_notes' => $this->creditNotes(),
            'sales_orders' => $this->salesOrders(),
            'profitability' => $this->profitability(),
            'financial' => $this->financialHealth(),
            'documentation' => $this->documentation(),
        ];
    }

    /* ───────────── Sales behaviour (zoho.sales_behavior_metrics + customer_sales_insights) ───────────── */

    public function sales(): array
    {
        $d = $this->day();
        $c0 = $this->day(-44);
        $p0 = $this->day(-134);
        $p1 = $this->day(-45);
        $h0 = $this->d->copy()->startOfMonth()->subMonthsNoOverflow(6)->toDateString();
        $h1 = $this->d->copy()->startOfMonth()->subDay()->toDateString();

        $s = array_map(fn ($r) => ['id' => (string) $r->transaction_id, 'et' => $r->entity_type, 'td' => self::d($r->transaction_date), 'amt' => (float) $r->kpi_amount],
            DB::select('SELECT transaction_id, entity_type, transaction_date, kpi_amount FROM '.self::AR."
                WHERE category = 'SALES' AND entity_type IN ('invoice','creditnote') AND transaction_date >= '2024-04-01' AND customer_id = ?", [$this->customerId]));

        // invoices fully reversed by a same-amount credit note within 14 days are not purchases
        $cns = array_filter($s, fn ($r) => $r['et'] === 'creditnote');
        $inv = array_filter($s, function ($i) use ($cns) {
            if ($i['et'] !== 'invoice' || $i['amt'] <= 0) {
                return false;
            }
            $limit = Carbon::parse($i['td'])->addDays(14)->toDateString();
            foreach ($cns as $c) {
                if (abs(-$c['amt'] - $i['amt']) < 0.01 && $c['td'] >= $i['td'] && $c['td'] <= $limit) {
                    return false;
                }
            }

            return true;
        });

        $in = fn ($td, $a, $b) => $td >= $a && $td <= $b;
        $curNet = array_sum(array_map(fn ($r) => $in($r['td'], $c0, $d) ? $r['amt'] : 0, $s));
        $prevNet = array_sum(array_map(fn ($r) => $in($r['td'], $p0, $p1) ? $r['amt'] : 0, $s));
        $curI = array_filter($inv, fn ($r) => $in($r['td'], $c0, $d));
        $prevI = array_filter($inv, fn ($r) => $in($r['td'], $p0, $p1));
        $histDays = array_values(array_unique(array_map(fn ($r) => $r['td'], array_filter($inv, fn ($r) => $in($r['td'], $h0, $h1)))));
        sort($histDays);
        $lastBuy = $inv ? max(array_column($inv, 'td')) : null;
        $ds = $lastBuy ? self::diff($lastBuy, $d) : null;
        $hdays = count($histDays);
        $gap = $hdays >= 3 ? self::diff($histDays[0], end($histDays)) / ($hdays - 1) : null;
        $ratio = ($gap !== null && $gap > 0) ? $ds / $gap : null;
        $chg = $prevNet > 0 ? (($curNet / 45) / ($prevNet / 90) - 1) * 100 : null;
        $curInv = count($curI);
        $prevInv = count($prevI);
        $curAiv = $curInv >= 2 ? array_sum(array_column($curI, 'amt')) / $curInv : null;
        $prevAiv = $prevInv >= 3 ? array_sum(array_column($prevI, 'amt')) / $prevInv : null;
        $aivChg = ($curAiv !== null && $prevAiv > 0) ? round(($curAiv / $prevAiv - 1) * 100, 2) : null;
        $prevDays = count(array_unique(array_column($prevI, 'td')));

        $inactivity = match (true) {
            $lastBuy === null => null,
            $ds > 365 => 'long_inactive',
            $ds > 90 => ($ratio !== null && $ratio <= 1.25) ? null : (($ratio !== null && $ratio <= 2) ? 'late_purchase' : 'not_buying_long_time'),
            $ratio !== null && ($ds - $gap) < 7 => null, // rhythm actions only when the delay exceeds the normal gap by >= 7 days
            $ratio !== null => $ratio > 2 && $ds >= 45 ? 'stopped_buying' : ($ratio > 2 ? 'not_purchased_recently' : ($ratio > 1.25 ? 'late_purchase' : null)),
            $ds >= 45 && $prevDays >= 2 => 'stopped_buying',
            default => null,
        };
        $chgR = $chg === null ? null : round($chg, 2);

        $codes = [];
        if ($inactivity !== null) {
            $codes[] = $inactivity;
        }
        if ($chgR !== null && $chgR <= -10 && $curInv > 0 && ! in_array($inactivity, ['stopped_buying', 'not_buying_long_time', 'long_inactive'], true)) {
            $codes[] = 'sales_dropped';
        }
        if ($aivChg !== null && $aivChg <= -10) {
            $codes[] = 'smaller_orders';
        }
        $urgency = ['stopped_buying' => 1, 'not_purchased_recently' => 2, 'not_buying_long_time' => 3, 'late_purchase' => 4, 'sales_dropped' => 5, 'smaller_orders' => 6];
        usort($codes, fn ($a, $b) => ($urgency[$a] ?? 9) <=> ($urgency[$b] ?? 9));

        return array_map(fn ($code) => [
            'action_code' => $code, 'as_of_date' => $d, 'last_purchase_date' => $lastBuy, 'days_since_last_purchase' => $ds,
            'normal_gap_days' => $gap === null ? null : round($gap, 1), 'hist_purchase_days' => $hdays, 'prev90_purchase_days' => $prevDays,
            'current_sales' => $curNet, 'previous_sales' => $prevNet, 'sales_change_pct' => $chgR,
            'current_invoices' => $curInv, 'previous_invoices' => $prevInv,
            'current_aiv' => $curAiv === null ? null : round($curAiv, 2), 'previous_aiv' => $prevAiv === null ? null : round($prevAiv, 2),
            'aiv_change_pct' => $aivChg,
        ], $codes);
    }

    /* ───────────── Item behaviour (zoho.item_behavior_lines + customer_item_insights[_items]) ───────────── */

    public function itemLines(): array
    {
        $d = $this->day();
        $c0 = $this->day(-59);
        $p1 = $this->day(-60);
        $p0 = $this->day(-119);
        $rows = DB::select('SELECT item_id, item_name, transaction_date, qty, COALESCE(item_total, 0) v FROM '.self::SIP."
            WHERE customer_id = ? AND transaction_type = 'Invoice' AND qty > 0 AND transaction_date BETWEEN ? AND ?", [$this->customerId, $p0, $d]);

        $agg = [];
        foreach ($rows as $r) {
            $id = (string) $r->item_id;
            $td = self::d($r->transaction_date);
            $a = &$agg[$id];
            $a ??= ['item_id' => $id, 'nm' => null, 'nm_td' => '', 'pq' => 0.0, 'cq' => 0.0, 'pv' => 0.0, 'cv' => 0.0];
            // name from the latest line (ties: alphabetically first)
            if ($td > $a['nm_td'] || ($td === $a['nm_td'] && strcmp((string) $r->item_name, (string) $a['nm']) < 0)) {
                $a['nm'] = $r->item_name;
                $a['nm_td'] = $td;
            }
            if ($td <= $p1) {
                $a['pq'] += (float) $r->qty;
                $a['pv'] += (float) $r->v;
            } else {
                $a['cq'] += (float) $r->qty;
                $a['cv'] += (float) $r->v;
            }
            unset($a);
        }

        return array_values(array_map(fn ($a) => [
            'item_id' => $a['item_id'], 'item_name' => $a['nm'], 'prev_qty' => $a['pq'], 'last_qty' => $a['cq'],
            'prev_value' => $a['pv'], 'last_value' => $a['cv'], 'qty_diff' => $a['cq'] - $a['pq'], 'value_diff' => $a['cv'] - $a['pv'],
            'prev_active' => $a['pq'] > 0, 'last_active' => $a['cq'] > 0,
            'item_status' => ($a['pq'] > 0 && $a['cq'] == 0) ? 'lost' : (($a['pq'] == 0 && $a['cq'] > 0) ? 'new' : ($a['cq'] < $a['pq'] ? 'lower' : 'retained')),
            'p0' => $p0, 'p1' => $p1, 'c0' => $c0, 'as_of_date' => $d,
        ], $agg));
    }

    public function items(): array
    {
        $x = $this->itemLines();
        if (! $x) {
            return [];
        }
        $cnt = fn (callable $f) => count(array_filter($x, $f));
        $sum = fn (string $status, string $field) => array_sum(array_map(fn ($r) => $r['item_status'] === $status ? $r[$field] : 0, $x));
        $ps = $cnt(fn ($r) => $r['prev_active']);
        $ls = $cnt(fn ($r) => $r['last_active']);
        $lc = $cnt(fn ($r) => $r['item_status'] === 'lost');
        $nc = $cnt(fn ($r) => $r['item_status'] === 'new');
        $rc = $cnt(fn ($r) => $r['prev_active'] && $r['last_active']);
        $wc = $cnt(fn ($r) => $r['item_status'] === 'lower');

        $codes = [];
        if ($ls < $ps) {
            $codes[] = 'fewer_items';
        }
        if ($lc >= 1) {
            $codes[] = 'stopped_items';
        }
        if ($wc >= 1) {
            $codes[] = 'qty_dropped';
        }
        if ($lc >= 1 && $nc >= 1) {
            $codes[] = 'different_items';
        }

        return array_map(fn ($code) => [
            'action_code' => $code, 'prev_sku' => $ps, 'last_sku' => $ls, 'sku_diff' => $ps - $ls,
            'sku_change_pct' => $ps > 0 ? round(($ls / $ps - 1) * 100, 2) : null,
            'lost_count' => $lc, 'new_count' => $nc, 'retained_count' => $rc, 'retention_pct' => $ps > 0 ? round($rc / $ps * 100, 2) : null,
            'lower_count' => $wc, 'lost_prev_value' => $sum('lost', 'prev_value'), 'lower_prev_value' => $sum('lower', 'prev_value'),
            'lower_last_value' => $sum('lower', 'last_value'),
            'p0' => $x[0]['p0'], 'p1' => $x[0]['p1'], 'c0' => $x[0]['c0'], 'as_of_date' => $x[0]['as_of_date'],
        ], $codes);
    }

    /** customer_item_insights_items — rows behind one item action (max 20; different_items 10 lost + 10 new, topped up). */
    public function itemInsightItems(string $action): array
    {
        if (! in_array($action, ['fewer_items', 'stopped_items', 'qty_dropped', 'different_items'], true)) {
            throw new \InvalidArgumentException('invalid action');
        }
        $q = array_values(array_filter($this->itemLines(), fn ($r) => match ($action) {
            'qty_dropped' => $r['item_status'] === 'lower',
            'different_items' => in_array($r['item_status'], ['lost', 'new'], true),
            default => $r['item_status'] === 'lost',
        }));
        $byStatus = [];
        foreach ($q as $r) {
            $byStatus[$r['item_status']][] = $r;
        }
        foreach ($byStatus as $st => &$rows) {
            usort($rows, fn ($a, $b) => [$st === 'new' ? -$a['last_value'] : $a['value_diff'], $a['qty_diff'], $a['item_id']]
                <=> [$st === 'new' ? -$b['last_value'] : $b['value_diff'], $b['qty_diff'], $b['item_id']]);
        }
        unset($rows);

        $nl = count($byStatus['lost'] ?? []);
        $nn = count($byStatus['new'] ?? []);
        if ($action === 'different_items') {
            $ll = min($nl, max(10, 20 - min($nn, 10)));
            $ln = min($nn, max(10, 20 - min($nl, 10)));
            $out = array_merge(array_slice($byStatus['lost'] ?? [], 0, $ll), array_slice($byStatus['new'] ?? [], 0, $ln));
        } else {
            $out = [];
            foreach ($byStatus as $rows) {
                $out = array_merge($out, array_slice($rows, 0, 20));
            }
        }
        $owners = DB::table('zoho_raw_items')->whereIn('item_id', array_column($out, 'item_id') ?: ['0'])->pluck('owner', 'item_id');

        return array_map(fn ($r) => [
            'item_id' => $r['item_id'], 'item_name' => $r['item_name'], 'item_status' => $r['item_status'],
            'prev_qty' => $r['prev_qty'], 'last_qty' => $r['last_qty'], 'value_diff' => $r['value_diff'], 'total_qualifying' => count($q),
            'owner' => trim((string) ($owners[$r['item_id']] ?? '')) === '' ? null : $owners[$r['item_id']],
        ], $out);
    }

    /* ───────────── Products approaching expiry (zoho.item_expiry_batch_lines) ───────────── */

    private function expiryWindow(): array
    {
        return [
            'd' => $this->day(),
            's0' => $this->d->copy()->subMonthsNoOverflow(12)->toDateString(),
            'e0' => $this->d->copy()->startOfMonth()->addMonthNoOverflow()->toDateString(),
            'e1' => $this->d->copy()->startOfMonth()->addMonthsNoOverflow(4)->subDay()->toDateString(),
        ];
    }

    public function expiryLines(): array
    {
        $w = $this->expiryWindow();
        $inv = DB::select('SELECT item_id, batch, DATE(expiry) expiry, SUM(qty) sold, COUNT(DISTINCT transaction_id) n_inv,
                MIN(DATE(transaction_date)) f_inv, MAX(DATE(transaction_date)) l_inv
            FROM '.self::SIP." WHERE transaction_type = 'Invoice' AND customer_id = ? AND transaction_date BETWEEN ? AND ?
              AND DATE(expiry) BETWEEN ? AND ? AND NULLIF(TRIM(batch), '') IS NOT NULL AND qty > 0
            GROUP BY item_id, batch, DATE(expiry)", [$this->customerId, $w['s0'], $w['d'], $w['e0'], $w['e1']]);

        // Same-batch returns: credit-note rows (qty < 0) for the same item + batch + expiry, dated from the first
        // in-window invoice of that combination up to today — one query for all combinations, summed per key below.
        $returns = [];
        if ($inv) {
            foreach (DB::select('SELECT item_id, batch, DATE(expiry) expiry, DATE(transaction_date) td, -qty rq FROM '.self::SIP."
                WHERE customer_id = ? AND transaction_type = 'Credit Note' AND qty < 0 AND DATE(transaction_date) <= ?
                  AND DATE(expiry) BETWEEN ? AND ?", [$this->customerId, $w['d'], $w['e0'], $w['e1']]) as $c) {
                $returns["{$c->item_id}|{$c->batch}|{$c->expiry}"][] = [$c->td, (float) $c->rq];
            }
        }

        $out = [];
        foreach ($inv as $i) {
            $rq = 0.0;
            foreach ($returns["{$i->item_id}|{$i->batch}|{$i->expiry}"] ?? [] as [$td, $q]) {
                if ($td >= $i->f_inv) {
                    $rq += $q;
                }
            }
            $net = (float) $i->sold - $rq;
            if ($net > 0) {
                $out[] = ['item_id' => (string) $i->item_id, 'batch' => $i->batch, 'expiry' => $i->expiry, 'sold_qty' => (float) $i->sold,
                    'returned_qty' => $rq, 'net_qty' => $net, 'invoice_count' => (int) $i->n_inv] + $w;
            }
        }

        return $out;
    }

    private static function monYear(string $iso): string
    {
        return Carbon::parse($iso)->format('M Y');
    }

    public function expiry(): ?array
    {
        $l = $this->expiryLines();
        if (! $l) {
            return null;
        }
        $w = $this->expiryWindow();

        return [
            'item_count' => count(array_unique(array_column($l, 'item_id'))), 'batch_count' => count($l),
            'as_of_date' => $w['d'], 'sales_start' => $w['s0'], 'expiry_start' => $w['e0'], 'expiry_end' => $w['e1'],
            'expiry_start_label' => self::monYear($w['e0']), 'expiry_end_label' => self::monYear($w['e1']),
        ];
    }

    public function expiryItems(): array
    {
        $l = $this->expiryLines();
        $items = DB::table('zoho_raw_items')->whereIn('item_id', array_column($l, 'item_id') ?: ['0'])->get(['item_id', 'item_name', 'owner'])->keyBy('item_id');
        $rows = array_map(fn ($r) => $r + [
            'item_name' => $items[$r['item_id']]->item_name ?? null,
            'owner' => trim((string) ($items[$r['item_id']]->owner ?? '')) === '' ? null : $items[$r['item_id']]->owner,
            'expiry_label' => self::monYear($r['expiry']),
        ], $l);
        usort($rows, fn ($a, $b) => [$a['expiry'], $a['item_name'], $a['batch']] <=> [$b['expiry'], $b['item_name'], $b['batch']]);

        return $rows;
    }

    /* ───────────── Credit notes (zoho.credit_note_metrics + customer_credit_note_insights) ───────────── */

    public function creditNotes(): array
    {
        $d = $this->day();
        $n0 = $this->day(-89);
        $t0 = $this->day(-29);
        $s = DB::select('SELECT transaction_id, entity_type, transaction_date, COALESCE(kpi_amount, 0) amt FROM '.self::AR."
            WHERE category = 'SALES' AND entity_type IN ('invoice','creditnote') AND customer_id = ?", [$this->customerId]);
        if (! $s) {
            return [];
        }
        $in = fn ($r, $a) => self::d($r->transaction_date) >= $a && self::d($r->transaction_date) <= $d;
        $distinct = fn (array $rows) => count(array_unique(array_map(fn ($r) => (string) $r->transaction_id, $rows)));
        $inv90 = $distinct(array_filter($s, fn ($r) => $r->entity_type === 'invoice' && $in($r, $n0)));
        $cn90 = $distinct(array_filter($s, fn ($r) => $r->entity_type === 'creditnote' && $in($r, $n0)));
        $cn30rows = array_filter($s, fn ($r) => $r->entity_type === 'creditnote' && $in($r, $t0));
        $cn30 = $distinct($cn30rows);
        $gross90 = array_sum(array_map(fn ($r) => $r->entity_type === 'invoice' && (float) $r->amt > 0 && $in($r, $n0) ? (float) $r->amt : 0, $s));
        $cnv90 = abs(array_sum(array_map(fn ($r) => $r->entity_type === 'creditnote' && $in($r, $n0) ? (float) $r->amt : 0, $s)));
        $invDates = array_map(fn ($r) => self::d($r->transaction_date), array_filter($s, fn ($r) => $r->entity_type === 'invoice'));
        $lastInv = $invDates ? max($invDates) : null;
        usort($cn30rows, fn ($a, $b) => [$b->transaction_date, (string) $b->transaction_id] <=> [$a->transaction_date, (string) $a->transaction_id]);
        $lcn = $cn30rows[0] ?? null;

        $flags = [
            'frequent_credit_notes' => $inv90 > 0 && $cn90 * 100 >= 15 * $inv90,
            'high_credit_note_value' => $inv90 > 0 && $gross90 > 0 && $cnv90 * 100 >= 5 * $gross90,
            'credit_note_without_purchase' => $inv90 === 0 && $cn30 >= 1 && $lastInv !== null && $lastInv < $n0,
        ];

        return array_values(array_map(fn ($code) => [
            'action_code' => $code, 'as_of_date' => $d, 'invoices_90d' => $inv90, 'credit_notes_90d' => $cn90,
            'frequency_pct' => $inv90 > 0 ? round($cn90 / $inv90 * 100, 2) : null,
            'gross_sales_90d' => $gross90, 'credit_note_value_90d' => $cnv90,
            'value_pct' => $gross90 > 0 ? round($cnv90 / $gross90 * 100, 2) : null,
            'credit_notes_30d' => $cn30, 'last_invoice_date' => $lastInv, 'days_since_last_invoice' => $lastInv ? self::diff($lastInv, $d) : null,
            'last_credit_note_date' => $lcn ? self::d($lcn->transaction_date) : null, 'last_credit_note_value' => $lcn ? abs((float) $lcn->amt) : null,
        ], array_keys(array_filter($flags))));
    }

    /* ───────────── Sales orders (zoho.sales_order_metrics + customer_sales_order_insights) ───────────── */

    public function salesOrders(): array
    {
        $d = $this->day();
        $o = DB::table('zoho_sales_orders_not_invoiced')->where('customer_id', $this->customerId)
            ->whereIn('sales_order_status', ['draft', 'partially_invoiced'])->distinct()->get(['sales_order_id', 'sales_order_status', 'order_date']);
        if ($o->isEmpty()) {
            return [];
        }
        $oldDrafts = $o->filter(fn ($r) => $r->sales_order_status === 'draft' && $r->order_date && self::diff(self::d($r->order_date), $d) > 3);
        $partial = $o->where('sales_order_status', 'partially_invoiced')->pluck('sales_order_id')->unique()->count();
        $out = [];
        if ($oldDrafts->pluck('sales_order_id')->unique()->count() > 0) {
            $out[] = ['action_code' => 'old_draft', 'order_count' => $oldDrafts->pluck('sales_order_id')->unique()->count(),
                'oldest_days' => $oldDrafts->map(fn ($r) => self::diff(self::d($r->order_date), $d))->max()];
        }
        if ($partial > 0) {
            $out[] = ['action_code' => 'partially_invoiced', 'order_count' => $partial, 'oldest_days' => null];
        }

        return $out;
    }

    /* ───────────── Profitability (zoho.profitability_insight_metrics + customer_profitability_insights) ───────────── */

    public function profitability(): array
    {
        $d = $this->day();
        $c0 = $this->day(-44);
        $p0 = $this->day(-134);
        $p1 = $this->day(-45);
        $m = DB::selectOne('SELECT COUNT(*) n,
                COUNT(DISTINCT CASE WHEN transaction_date >= ? THEN transaction_id END) c_inv,
                COALESCE(SUM(CASE WHEN transaction_date >= ? THEN sales_exc_vat END), 0) c_sev,
                COALESCE(SUM(CASE WHEN transaction_date >= ? THEN gross_profit END), 0) c_gp,
                COUNT(DISTINCT CASE WHEN transaction_date <= ? THEN transaction_id END) p_inv,
                COALESCE(SUM(CASE WHEN transaction_date <= ? THEN sales_exc_vat END), 0) p_sev,
                COALESCE(SUM(CASE WHEN transaction_date <= ? THEN gross_profit END), 0) p_gp
            FROM '.self::AR." WHERE category = 'SALES' AND entity_type = 'invoice' AND customer_id = ? AND transaction_date BETWEEN ? AND ?",
            [$c0, $c0, $c0, $p1, $p1, $p1, $this->customerId, $p0, $d]);
        if ((int) $m->n === 0) {
            return [];
        }
        $cur = ((int) $m->c_inv > 0 && (float) $m->c_sev > 0) ? (float) $m->c_gp / (float) $m->c_sev * 100 : null;
        $prev = ((int) $m->p_inv > 0 && (float) $m->p_sev > 0) ? (float) $m->p_gp / (float) $m->p_sev * 100 : null;
        if ($cur === null) {
            return [];
        }

        // display precision: 2 decimals, up to 4 only when needed so a rounded value never crosses 0/5/10 or hides a real decline
        $dCur = 4;
        foreach ([2, 3, 4] as $k) {
            $r = round($cur, $k);
            if (($r < 0) === ($cur < 0) && ($r < 5) === ($cur < 5) && ($r < 10) === ($cur < 10)) {
                $dCur = $k;
                break;
            }
        }
        $dDec = null;
        if ($prev !== null && $cur < $prev) {
            $dDec = 4;
            foreach ([2, 3, 4] as $k) {
                if (round($prev, $k) > round($cur, $k) && round($prev - $cur, $k) > 0) {
                    $dDec = $k;
                    break;
                }
            }
        }
        $dd = max($dCur, $dDec ?? 2);
        $fmt = function (float $v) use ($dd) {
            $s = number_format(round($v, $dd), $dd, '.', '');

            return preg_match('/^-0\.?0*$/', $s) ? ltrim($s, '-') : $s;
        };

        $codes = [];
        if ($cur < 0) {
            $codes[] = ['negative_profitability', 1];
        } elseif ($cur < 5) {
            $codes[] = ['very_low_profitability', 1];
        } elseif ($cur < 10) {
            $codes[] = ['low_profitability', 1];
        }
        if ($prev !== null && $cur < $prev) {
            $codes[] = ['profitability_declined', 2];
        }
        usort($codes, fn ($a, $b) => [$a[1], $a[0]] <=> [$b[1], $b[0]]);

        return array_map(fn ($c) => [
            'action_code' => $c[0], 'as_of_date' => $d, 'current_gp' => (float) $m->c_gp, 'current_margin' => $cur, 'previous_margin' => $prev,
            'current_margin_display' => $fmt($cur), 'previous_margin_display' => $prev === null ? null : $fmt($prev),
            'decline_pp_display' => $c[0] === 'profitability_declined' ? $fmt($prev - $cur) : null,
        ], $codes);
    }

    /* ───────────── Financial health (zoho.financial_health_metrics + customer_financial_health_insights) ───────────── */

    public function financialHealth(): ?array
    {
        $o = (new Customer360Data('customer_id', $this->customerId))->arOverview();
        if (! $o || $o['due_this_month'] <= 0) {
            return null;
        }
        $num = $this->customer->customer_number;
        $coll = DB::table('zoho_collection_classification')->where('customer_number', $num)->first();
        $risk = DB::table('zoho_ar_risk_classification')->where('customer_number', $num)->first();
        $clean = fn ($v) => ($v === null || trim((string) $v) === '') ? null : trim((string) $v);
        $credit = (float) ($risk->available_credit_for_offset ?? 0);

        return [
            'action_code' => 'monthly_collection_required', 'as_of_date' => $o['as_of_date'], 'month_end' => $o['month_end'],
            'due_by_month_end' => $o['due_this_month'], 'overdue_amount' => $o['overdue_amount'],
            'collection_classification' => $clean($coll->collection_classification ?? null),
            'payment_pattern' => $clean($coll->payment_timing_pattern ?? null),
            'ar_risk' => $clean($risk->final_risk ?? null), 'has_unapplied_credit' => $credit > 0,
        ];
    }

    /* ───────────── Documentation (zoho.customer_documentation_eval) ───────────── */

    public function documentation(): array
    {
        $c = $this->customer;
        $d = $this->day();
        $blank = fn ($v) => $v === null || trim((string) $v) === '';
        $placeholder = fn ($v) => $v === null || in_array(strtoupper(trim((string) $v)), ['', '-', '0', 'N/A'], true);
        $date = fn ($v) => $v === null ? null : self::d((string) $v);
        $plus30 = $this->day(30);
        $out = [];
        $add = function (?string $code, int $ord, ?string $txt = null, ?string $dt = null, ?int $el = null, ?int $rem = null) use (&$out, $d) {
            if ($code !== null) {
                $out[] = ['action_code' => $code, 'action_order' => $ord, 'as_of_date' => $d, 'value_text' => $txt, 'value_date' => $dt, 'elapsed_days' => $el, 'remaining_days' => $rem];
            }
        };

        $add($blank($c->finance_dept_recommendation) ? null : 'finance_recommendation', 1, $c->finance_dept_recommendation);

        $cr = $date($c->cr_expiry_date);
        $add($cr === null ? 'cr_expiry_missing' : ($cr < $d ? 'cr_expired' : ($cr <= $plus30 ? 'cr_expiring_soon' : null)),
            $cr === null ? 4 : ($cr < $d ? 2 : 3), null, $cr, null, ($cr !== null && $cr >= $d) ? self::diff($d, $cr) : null);

        $rec = $date($c->last_reconciliation_date);
        $el = ($rec !== null && $rec <= $d) ? self::diff($rec, $d) : null;
        $add($rec === null ? 'reconciliation_date_missing' : ($rec > $d ? 'reconciliation_date_invalid' : ($el >= 60 ? 'reconciliation_overdue' : ($el >= 45 ? 'reconciliation_due_soon' : null))),
            $rec === null ? 7 : ($rec > $d ? 8 : ($el >= 60 ? 5 : 6)), null, $rec, $el, ($el !== null && $el >= 45 && $el <= 59) ? 60 - $el : null);

        $add($blank($c->tax_registration_number) ? 'tax_number_missing' : null, 9);
        $add($placeholder($c->cr_no) ? 'cr_number_missing' : null, 10);
        $add($placeholder($c->gln_no) ? 'gln_missing' : null, 11);

        $moh = $date($c->moh_sfda_license_expiry_date);
        $add($moh === null ? 'moh_expiry_missing' : ($moh < $d ? 'moh_expired' : ($moh <= $plus30 ? 'moh_expiring_soon' : null)),
            $moh === null ? 14 : ($moh < $d ? 12 : 13), null, $moh, null, ($moh !== null && $moh >= $d) ? self::diff($d, $moh) : null);

        $add($blank($c->branch_1_city) ? 'delivery_city_missing' : null, 15);
        $add($blank($c->location_branch_1) ? 'delivery_location_missing' : null, 16);
        $add($blank($c->wh_city) ? 'dispatch_warehouse_missing' : null, 17);

        usort($out, fn ($a, $b) => $a['action_order'] <=> $b['action_order']);

        return $out;
    }
}
