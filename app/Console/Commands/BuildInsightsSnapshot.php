<?php

namespace App\Console\Commands;

use App\Services\Customer360\Customer360Insights;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The Lovable portal's Entity 360 "Customer Actions" daily snapshot (insights.daily_snapshot_start +
 * start_generation + process_batch + collect_customer_actions + source_state / source_changed /
 * abort_generation), ported 2026-10-19.
 *
 * Theirs is pg_cron: a 06:00 Riyadh start plus a 30-second worker doing 35 customers per tick. Here one
 * run does both — start (or resume) the generation, then work the queue in batches of 35 with the same
 * checks between batches — scheduled daily at 06:00 Asia/Riyadh. Same rules:
 *  - a generation running for under 3 hours is resumed (theirs: the worker keeps going); older ones are failed first;
 *  - nothing starts while a required dataset is being synchronised; the previous snapshot stays active;
 *  - every customer of zoho_raw_customers is queued; each gets up to 3 attempts; actions come only from the
 *    Customer 360 Insights engines (Customer360Insights), never re-implemented here;
 *  - a re-sync of a required dataset during calculation, or any customer failing 3 times, fails the whole
 *    generation and removes its rows — no partial publication;
 *  - on publication the active pointer moves, the two previous complete generations are kept, older ones retired.
 */
class BuildInsightsSnapshot extends Command
{
    protected $signature = 'insights:snapshot {--batch=35} {--attempts=3}';

    protected $description = 'Build the daily Entity 360 Customer Actions snapshot (Lovable insights generation)';

    /** zoho_raw_sync_runs keys behind the seven Customer 360 engines (their target tables customers, ar_sales,
     *  sales_item_performance, sales_orders_not_invoiced, items, collection_classification, ar_risk_classification). */
    private const SOURCES = ['customers', 'items', 'query:ar_sales', 'query:sales_item_performance', 'query:sales_orders_not_invoiced',
        'query:collection_classification', 'query:ar_risk_classification'];

    public function handle(): int
    {
        $lock = Cache::lock('insights_snapshot_refresh', 4 * 3600);
        if (! $lock->get()) {
            $this->info('busy');

            return self::SUCCESS;
        }
        try {
            $g = $this->start();
            if ($g === null) {
                return self::SUCCESS;
            }
            do {
                $res = $this->processBatch($g, (int) $this->option('batch'), (int) $this->option('attempts'));
                if ($res['result'] === 'in_progress') {
                    $this->line("generation {$g}: {$res['done']}/{$res['total']}");
                }
            } while ($res['result'] === 'in_progress');
            $this->info(json_encode($res));

            return $res['result'] === 'published' ? self::SUCCESS : self::FAILURE;
        } finally {
            $lock->release();
        }
    }

    /** daily_snapshot_start + start_generation. Returns the generation to work on, or null when skipped. */
    private function start(): ?int
    {
        $run = DB::table('insights_generations')->where('status', 'running')->first();
        if ($run) {
            if (strtotime($run->started_at) > time() - 3 * 3600) {
                $this->info("resuming generation {$run->generation_id}");

                return (int) $run->generation_id;
            }
            $this->abort((int) $run->generation_id, 'stale: still running at next daily start');
        }
        $state = $this->sourceState();
        if ($state['active_runs'] > 0) {
            $this->warn('skipped: sync run active for a required dataset; previous snapshot kept');

            return null;
        }

        return DB::transaction(function () use ($state) {
            $g = (int) DB::table('insights_generations')->insertGetId(['status' => 'running', 'started_at' => now(), 'source_at_start' => json_encode($state)]);
            DB::statement('INSERT INTO insights_generation_queue (generation_id, customer_id) SELECT ?, customer_id FROM zoho_raw_customers', [$g]);
            DB::table('insights_generations')->where('generation_id', $g)
                ->update(['customers_total' => DB::table('insights_generation_queue')->where('generation_id', $g)->count()]);
            $this->info("started generation {$g}");

            return $g;
        });
    }

    /** insights.source_state — last successful sync per required dataset + runs still in progress. */
    private function sourceState(): array
    {
        $datasets = [];
        foreach (self::SOURCES as $key) {
            $datasets[$key] = DB::table('zoho_raw_sync_runs')->where('table_key', $key)->where('status', 'succeeded')->max('finished_at');
        }
        // A run killed mid-way never leaves 'running' (no cleanup on SIGKILL); one started over 6 hours ago is treated as dead
        // rather than blocking every future snapshot.
        $active = DB::table('zoho_raw_sync_runs')->whereIn('table_key', self::SOURCES)->where('status', 'running')
            ->where('started_at', '>', now()->subHours(6))->count();

        return ['datasets' => $datasets, 'active_runs' => $active];
    }

    /** insights.source_changed — null when unchanged since the generation started. */
    private function sourceChanged(int $g): ?string
    {
        $now = $this->sourceState();
        if ($now['active_runs'] > 0) {
            return 'sync run active for a required dataset';
        }
        $start = json_decode((string) DB::table('insights_generations')->where('generation_id', $g)->value('source_at_start'), true);

        return ($start['datasets'] ?? null) != $now['datasets'] ? 'required dataset re-synchronised during calculation' : null;
    }

    private function failGeneration(int $g, string $error): void
    {
        DB::transaction(function () use ($g, $error) {
            DB::table('insights_generations')->where('generation_id', $g)
                ->update(['status' => 'failed', 'finished_at' => now(), 'source_at_finish' => json_encode($this->sourceState()), 'error' => mb_substr($error, 0, 500)]);
            DB::table('insights_customer_actions')->where('generation_id', $g)->delete();
        });
    }

    /** insights.abort_generation. */
    private function abort(int $g, string $reason): void
    {
        DB::table('insights_generations')->where('generation_id', $g)->where('status', 'running')
            ->update(['status' => 'failed', 'finished_at' => now(), 'error' => mb_substr($reason, 0, 500)]);
        DB::table('insights_customer_actions')->where('generation_id', $g)->delete();
    }

    /** insights.collect_customer_actions — action codes from the eight Customer 360 eligibility engines. */
    private function collect(int $g, string $customerId): int
    {
        $ins = new Customer360Insights($customerId);
        $codes = array_merge(
            array_column($ins->sales(), 'action_code'),
            array_column($ins->items(), 'action_code'),
            $ins->expiry() ? ['products_approaching_expiry'] : [],
            array_column($ins->creditNotes(), 'action_code'),
            array_column($ins->salesOrders(), 'action_code'),
            array_column($ins->profitability(), 'action_code'),
        );
        if ($fin = $ins->financialHealth()) {
            $codes[] = $fin['action_code'];
            if ($fin['has_unapplied_credit']) {
                $codes[] = 'customer_has_unapplied_credit';
            }
        }
        $codes = array_values(array_unique(array_merge($codes, array_column($ins->documentation(), 'action_code'))));
        DB::table('insights_customer_actions')->insert(array_map(fn ($c) => ['generation_id' => $g, 'customer_id' => $customerId, 'action_code' => $c], $codes));

        return count($codes);
    }

    /** insights.process_batch. */
    private function processBatch(int $g, int $limit, int $maxAttempts): array
    {
        if ($chg = $this->sourceChanged($g)) {
            $this->failGeneration($g, $chg);

            return ['result' => 'generation_failed', 'generation_id' => $g, 'reason' => $chg];
        }

        $bad = 0;
        $batch = DB::table('insights_generation_queue')->where('generation_id', $g)->whereIn('status', ['pending', 'failed'])
            ->where('attempts', '<', $maxAttempts)->orderBy('attempts')->orderBy('customer_id')->limit(max(1, min($limit, 200)))->pluck('customer_id');
        foreach ($batch as $cid) {
            $q = DB::table('insights_generation_queue')->where('generation_id', $g)->where('customer_id', $cid);
            try {
                DB::transaction(function () use ($g, $cid) {
                    DB::table('insights_customer_actions')->where('generation_id', $g)->where('customer_id', $cid)->delete();
                    $this->collect($g, (string) $cid);
                });
                $q->update(['status' => 'done', 'attempts' => DB::raw('attempts + 1'), 'processed_at' => now(), 'last_error' => null]);
            } catch (\Throwable $e) {
                $q->update(['status' => 'failed', 'attempts' => DB::raw('attempts + 1'), 'processed_at' => now(), 'last_error' => mb_substr($e->getMessage(), 0, 500)]);
                $bad++;
            }
        }
        $queue = DB::table('insights_generation_queue')->where('generation_id', $g);
        $done = (clone $queue)->where('status', 'done')->count();
        DB::table('insights_generations')->where('generation_id', $g)
            ->update(['batches' => DB::raw('batches + 1'), 'retries' => DB::raw("retries + {$bad}"), 'customers_done' => $done]);

        $remaining = (clone $queue)->where('status', '<>', 'done')->where('attempts', '<', $maxAttempts)->count();
        $failedFinal = (clone $queue)->where('status', 'failed')->where('attempts', '>=', $maxAttempts)->count();
        if ($failedFinal > 0 && $remaining === 0) {
            $this->failGeneration($g, "{$failedFinal} customer(s) failed after {$maxAttempts} attempts");

            return ['result' => 'generation_failed', 'generation_id' => $g, 'failed_customers' => $failedFinal];
        }
        if ($remaining > 0) {
            return ['result' => 'in_progress', 'generation_id' => $g, 'done' => $done, 'total' => $done + $remaining];
        }

        if ($chg = $this->sourceChanged($g)) { // re-check at publication time
            $this->failGeneration($g, $chg);

            return ['result' => 'generation_failed', 'generation_id' => $g, 'reason' => $chg];
        }
        $prev = DB::table('insights_active_generation')->value('generation_id');
        if ($prev !== null && $prev > $g) {
            DB::table('insights_generations')->where('generation_id', $g)->update(['status' => 'retired', 'finished_at' => now(), 'error' => 'superseded by newer generation']);

            return ['result' => 'superseded', 'generation_id' => $g];
        }
        DB::transaction(function () use ($g) {
            $now = now();
            DB::table('insights_generations')->where('generation_id', $g)->update([
                'status' => 'complete', 'finished_at' => $now, 'published_at' => $now, 'source_at_finish' => json_encode($this->sourceState()),
                'action_rows' => DB::table('insights_customer_actions')->where('generation_id', $g)->count(),
            ]);
            DB::table('insights_active_generation')->upsert([['singleton' => true, 'generation_id' => $g, 'published_at' => $now]], ['singleton'], ['generation_id', 'published_at']);
            $keep = DB::table('insights_generations')->where('status', 'complete')->where('generation_id', '<', $g)->orderByDesc('generation_id')->limit(2)->pluck('generation_id');
            DB::table('insights_generations')->where('status', 'complete')->where('generation_id', '<', $g)->whereNotIn('generation_id', $keep->all() ?: [0])->update(['status' => 'retired']);
            $dead = DB::table('insights_generations')->whereIn('status', ['retired', 'failed'])->pluck('generation_id');
            DB::table('insights_customer_actions')->whereIn('generation_id', $dead->all() ?: [0])->delete();
            DB::table('insights_generation_queue')->whereIn('generation_id', $dead->filter(fn ($id) => $id < $g - 5)->all() ?: [0])->delete();
        });

        return ['result' => 'published', 'generation_id' => $g];
    }
}
